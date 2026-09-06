<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Engine\ErrorCode;
use Jayanta\Jeeves\Engine\PromptBuilder;
use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Tests\Support\RecordingProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * A rule the schema cannot imply must hold on BOTH routes.
 *
 * `required_filter` is how an adopter states something introspection can never
 * recover: cancelled orders never count towards a total. Nothing in the column
 * types, names or foreign keys says so.
 *
 * It was enforced on one route and requested on the other. Intent mode appends
 * it to the SQL in SqlBuilder, so the model cannot omit it. SQL generation put
 * a line in the prompt and hoped. A model that ignored it produced a total
 * including every cancelled order, reported success, and nothing downstream
 * re-checked. The check now sits where SQL executes, so both routes are held
 * to it.
 *
 * That check is a LITERAL string match, and it has to stay one: it decides
 * whether rows the schema says must never be counted were excluded, so a loose
 * match that accepted an equivalent-looking predicate would be guessing about
 * the one thing nobody may guess about. Failing closed has a cost - a model
 * writing `status NOT IN ('cancelled')` against a rule written
 * `status != 'cancelled'` is refused, correct SQL and all - so the PROMPT is
 * what gives: it asks for the predicate character for character. Loosening the
 * guard to meet the model would trade a refusal for a wrong number.
 *
 * That is the shape of every defect this release has fixed: a guarantee that
 * holds on the path it was written for and not the one beside it. And it is
 * worse here than most, because the whole point of the setting is that the
 * answer is WRONG without it -  a user who writes the rule has been told the
 * package will apply it.
 *
 * Fixture: 100 paid + 200 paid + 500 cancelled. The rule makes the answer 300;
 * without it, 800.
 */
class RequiredFilterIsEnforcedTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/required-filter-schemas');
        $app['config']->set('jeeves.cache.enabled', false);
        $app['config']->set('jeeves.verification.enabled', false);
    }

    private function seedOrders(): void
    {
        Schema::dropIfExists('nq_orders');
        Schema::create('nq_orders', function ($t) {
            $t->id();
            $t->string('status');
            $t->decimal('revenue', 12, 2);
        });

        foreach ([['paid', 100], ['paid', 200], ['cancelled', 500]] as [$s, $r]) {
            DB::table('nq_orders')->insert(['status' => $s, 'revenue' => $r]);
        }
    }

    /** A model that ignores the prompt line must not produce an unfiltered total. */
    private function disobedientProvider(string $sql): RecordingProvider
    {
        $provider = new RecordingProvider;
        $provider->sqlResponse = [
            'success' => true,
            'data' => [
                'sql' => $sql,
                'dataset' => 'nq_orders',
                'metric' => 'revenue',
                'query_type' => 'aggregation',
            ],
        ];
        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        return $provider;
    }

    private function total(array $r): float
    {
        $row = $r['rows'][0] ?? null;

        return (float) (is_array($row) ? ($row['revenue'] ?? 0) : ($row->revenue ?? 0));
    }

    /**
     * THE DEFECT. sql_generation mode, model omits the filter it was told to
     * include. 800 is every order; 300 is the answer the rule defines.
     */
    #[Test]
    public function sql_generation_does_not_return_a_total_the_rule_forbids()
    {
        config(['jeeves.query_mode' => 'sql_generation']);
        $this->seedOrders();
        $this->disobedientProvider('SELECT SUM(revenue) AS revenue FROM nq_orders');

        $result = $this->app->make(QueryOrchestrator::class)->query('total revenue', 'nq_orders');

        // The reason code, not just the number. `total()` returns 0.0 for any
        // error response because an error carries no rows, so asserting only
        // `!= 800.0` passes when the query fails for ANY reason at all -
        // proven by swapping this refusal for a rate-limit error and watching
        // the whole suite stay green. A schema rule reported as a 429 with
        // `retryable: true` sends the caller into a retry loop against
        // something the engine has already marked unretriable, which is the
        // failure Rule 0 names, running backwards.
        $this->assertSame('error', $result['status'] ?? null, json_encode($result));

        $this->assertSame(
            ErrorCode::CANNOT_ANSWER,
            $result['error_code'] ?? null,
            'the query was rejected, but not as an unanswerable one: ' . json_encode($result)
        );

        $this->assertNotEquals(
            800.0,
            $this->total($result),
            'the model ignored the required filter and the engine served the unfiltered total -  a rule '
                . 'the adopter wrote specifically to make this answer correct was treated as a suggestion'
        );
    }

    /** And when it obeys, the answer is unchanged and not double-filtered. */
    #[Test]
    public function an_obedient_model_is_left_alone()
    {
        config(['jeeves.query_mode' => 'sql_generation']);
        $this->seedOrders();
        $this->disobedientProvider("SELECT SUM(revenue) AS revenue FROM nq_orders WHERE status != 'cancelled'");

        $result = $this->app->make(QueryOrchestrator::class)->query('total revenue', 'nq_orders');

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        $this->assertEquals(300.0, $this->total($result), 'the correctly filtered answer was altered');
    }

    /** Intent mode already enforced it; this pins that it still does. */
    #[Test]
    public function intent_mode_still_enforces_the_rule()
    {
        config(['jeeves.query_mode' => 'intent']);
        $this->seedOrders();

        $provider = new RecordingProvider;
        $provider->intentResponse = [
            'success' => true,
            'dataset' => 'nq_orders',
            'metric' => 'revenue',
            'query_type' => 'aggregation',
            'limit' => 10,
            'order' => 'desc',
            'group_value' => null,
            'confidence' => 0.95,
            'needs_clarification' => false,
        ];
        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        $result = $this->app->make(QueryOrchestrator::class)->query('total revenue', 'nq_orders');

        $this->assertEquals(300.0, $this->total($result), 'intent mode stopped applying the required filter');
    }

    /**
     * The prompt has to ask for the predicate VERBATIM, or the guard is a trap.
     *
     * requiredFilterMissing() is a literal match and must stay one - loosening
     * it to accept an equivalent predicate would be guessing about whether rows
     * the schema forbids were excluded. The consequence is that a model writing
     * a correct-but-differently-spelled filter is refused, and the dataset is
     * unanswerable until someone notices. Asking for the exact characters is
     * what makes the check satisfiable rather than merely strict.
     */
    #[Test]
    public function the_prompt_asks_for_the_required_filter_character_for_character()
    {
        $prompt = $this->app->make(PromptBuilder::class)->buildSqlPrompt('nq_orders', 'total revenue');

        $this->assertStringContainsString(
            "status != 'cancelled'",
            $prompt,
            'the prompt does not carry the predicate the guard will look for'
        );

        $this->assertMatchesRegularExpression(
            '/EXACTLY as written|character for character/i',
            $prompt,
            'the prompt asks for the filter but not for it verbatim, so a model is free to write '
                . 'an equivalent form that the literal guard then refuses'
        );

        $this->assertStringContainsString(
            'NOT IN',
            $prompt,
            'the prompt does not warn against the specific rewrite models actually produce'
        );
    }
}
