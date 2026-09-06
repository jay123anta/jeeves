<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Contracts\QueryCacheInterface;
use Jayanta\Jeeves\Engine\ErrorCode;
use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Engine\QueryVerifier;
use Jayanta\Jeeves\Schema\SchemaRegistry;
use Jayanta\Jeeves\Tests\Support\RecordingProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * A rule that holds on the paths someone remembered is not enforced.
 *
 * `required_filter` was checked at the two sites where SQL is GENERATED. SQL
 * reaches the database by more routes than that, and each missed one is a
 * silent wrong number -  the exact failure the setting exists to prevent:
 *
 *  1. QueryVerifier rewrites `sql` AFTER the check, and its fix is trusted
 *     without re-verification (`reverify_fixes` defaults to false), so a
 *     rewrite that drops the filter executes unchallenged. Verification is ON
 *     by default.
 *  2. That rewritten SQL is then stored as the `_sql_result` cache recipe, and
 *     the replay branch hands it to the executor with no guard at all. Tier 2
 *     rows never expire, so one bad row answers that question forever.
 *  3. The check was keyed on the dataset the MODEL reported, so a mislabelled
 *     or absent `dataset` disarmed it completely.
 *
 * This is the pattern the project keeps producing -  a guard attached to call
 * sites rather than to the thing guarded -  so the check now sits at the single
 * point where SQL executes, and is keyed on the tables the SQL actually names.
 *
 * Fixture: 100 paid + 200 paid + 500 cancelled. The rule makes the answer 300;
 * without it, 800.
 */
class RequiredFilterHoldsOnEveryPathTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/required-filter-paths-schemas');
        $app['config']->set('jeeves.cache.enabled', false);
        $app['config']->set('jeeves.verification.enabled', false);
        $app['config']->set('jeeves.query_mode', 'sql_generation');
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

    private function seedVisits(): void
    {
        Schema::dropIfExists('nq_visits');
        Schema::create('nq_visits', function ($t) {
            $t->id();
            $t->string('source');
            $t->integer('nq_orders');
            $t->decimal('revenue', 12, 2);
        });
        DB::table('nq_visits')->insert(['source' => 'search', 'nq_orders' => 3, 'revenue' => 42]);
    }

    private function provider(array $data): RecordingProvider
    {
        $provider = new RecordingProvider;
        $provider->sqlResponse = ['success' => true, 'data' => $data];
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
     * Refused, AND refused for the stated reason.
     *
     * `total()` returns 0.0 for any error response, because an error carries no
     * rows — so `assertNotEquals(800.0, $this->total($result))` passes when the
     * query fails for ANY reason whatsoever. Every test in this file rested on
     * that, and the gap is not theoretical: replacing this refusal with a
     * rate-limit error left all 700 tests green.
     *
     * That failure is the one Rule 0 names, running backwards. A rule the
     * adopter wrote would be reported as HTTP 429 with `retryable: true`, and a
     * client would back off and retry forever against something the engine has
     * already marked `_unretriable`. The reason code is the assertion; the
     * number alone cannot see it.
     */
    private function assertRefusedByTheRule(array $result): void
    {
        $this->assertSame('error', $result['status'] ?? null, json_encode($result));

        $this->assertSame(
            ErrorCode::CANNOT_ANSWER,
            $result['error_code'] ?? null,
            'the query was rejected, but not as an unanswerable one — an adopter\'s schema rule '
                . 'reported under any other code sends the caller down the wrong recovery path: '
                . json_encode($result)
        );

        $this->assertNotEquals(
            800.0,
            $this->total($result),
            'the forbidden total was served'
        );
    }

    /**
     * LEAK 1. The model obeys, the verifier "fixes" the filter away, and the
     * rewrite executes without ever being re-checked.
     */
    #[Test]
    public function a_verifier_rewrite_cannot_drop_the_filter()
    {
        config(['jeeves.verification.enabled' => true]);
        $this->seedOrders();

        $this->provider([
            'sql' => "SELECT SUM(revenue) AS revenue FROM nq_orders WHERE status != 'cancelled'",
            'dataset' => 'nq_orders',
            'metric' => 'revenue',
            'query_type' => 'aggregation',
        ]);

        // A verifier that helpfully "simplifies" the WHERE clause away. This
        // is not a strawman: the fix is applied with reverify_fixes false, so
        // whatever it returns is what runs.
        $this->app->instance(QueryVerifier::class, new class($this->app->make(LlmProviderInterface::class), $this->app->make(SchemaRegistry::class)) extends QueryVerifier
        {
            public function verify(string $question, string $sql, ?string $datasetKey): array
            {
                return [
                    'passed' => true,
                    'confidence' => 0.99,
                    'attempt' => 1,
                    'issues' => null,
                    'fixed_sql' => 'SELECT SUM(revenue) AS revenue FROM nq_orders',
                ];
            }
        });
        $this->app->forgetInstance(QueryOrchestrator::class);

        $result = $this->app->make(QueryOrchestrator::class)->query('total revenue', 'nq_orders');

        $this->assertRefusedByTheRule($result);
    }

    /**
     * THE ARMING RULE, and its known limitation, both pinned.
     *
     * The rule is armed by the dataset being answered. An attempt to arm it
     * instead by the tables the SQL names, so a mislabelled `dataset` could not
     * disarm it, was reverted: matching table names against arbitrary model SQL
     * failed in both directions at once. `FROM a, b` hid the guarded table
     * completely and served the forbidden total; a bare `FROM orders` armed
     * every `schema.orders` dataset on a multi-scheme database and refused
     * correct queries with a message naming a different dataset's filter; and a
     * table pulled in by `required_join` armed a rule the builder had no way to
     * satisfy, making that dataset permanently unanswerable.
     *
     * So the narrower rule stands, with the hole stated rather than papered
     * over: a model that reports the WRONG dataset while querying a guarded
     * table is not caught here. The SQL is still whitelist-validated, and
     * closing it properly means consuming SqlValidator's real table extractor
     * rather than adding a second one.
     */
    #[Test]
    public function the_rule_is_armed_by_the_dataset_being_answered()
    {
        $this->seedOrders();

        $this->provider([
            'sql' => 'SELECT SUM(revenue) AS revenue FROM nq_orders',
            'dataset' => 'nq_orders',
            'metric' => 'revenue',
            'query_type' => 'aggregation',
        ]);

        $result = $this->app->make(QueryOrchestrator::class)->query('total revenue', 'nq_orders');

        $this->assertRefusedByTheRule($result);
    }

    /**
     * The cost, measured rather than assumed: a dataset with no rule of its own
     * is never refused, whatever its neighbours declare.
     */
    #[Test]
    public function an_unguarded_dataset_is_never_refused()
    {
        $this->seedOrders();
        $this->seedVisits();

        $this->provider([
            'sql' => 'SELECT SUM(revenue) AS revenue FROM nq_visits',
            'dataset' => 'nq_visits',
            'metric' => 'revenue',
            'query_type' => 'aggregation',
        ]);

        $result = $this->app->make(QueryOrchestrator::class)->query('total visits revenue', 'nq_visits');

        $this->assertSame(
            'success',
            $result['status'] ?? null,
            'a dataset that declares no required_filter was refused: ' . json_encode($result)
        );
    }

    /**
     * LEAK 3, and the one that outlives the request. A recipe cached from a
     * rewrite is replayed verbatim by a branch that reaches the executor
     * without passing any generation site. Tier 2 rows never expire, so the
     * wrong total is served for that wording forever -  and the provider is
     * never consulted again to notice.
     */
    #[Test]
    public function a_cached_recipe_is_re_checked_when_it_is_replayed()
    {
        config([
            'jeeves.cache.enabled' => true,
            'jeeves.verification.enabled' => false,
        ]);
        $this->artisan('migrate', ['--force' => true])->run();
        $this->seedOrders();

        // Plant the recipe the old code could store: unfiltered SQL, cached as
        // a finished `_sql_result` for this exact wording.
        $this->app->make(QueryCacheInterface::class)->store(
            'total revenue',
            [
                'dataset' => 'nq_orders',
                'metric' => 'revenue',
                'group_value' => null,
                'limit' => 10,
                'order' => 'DESC',
                'query_type' => 'aggregation',
                '_asking_scope' => 'nq_orders',
                '_sql_result' => [
                    'success' => true,
                    'sql' => 'SELECT SUM(revenue) AS revenue FROM nq_orders',
                    'bindings' => [],
                    'dataset' => 'nq_orders',
                    'dataset_name' => 'Orders',
                    'metric' => 'revenue',
                    'metric_unit' => '₹',
                    'query_type' => 'aggregation',
                    'limit' => 10,
                    'order' => 'DESC',
                ],
            ]
        );

        $this->provider([
            'sql' => "SELECT SUM(revenue) AS revenue FROM nq_orders WHERE status != 'cancelled'",
            'dataset' => 'nq_orders',
            'metric' => 'revenue',
            'query_type' => 'aggregation',
        ]);

        $result = $this->app->make(QueryOrchestrator::class)->query('total revenue', 'nq_orders');

        // The forbidden total must not be served...
        $this->assertNotEquals(
            800.0,
            $this->total($result),
            'a cached recipe that omits the required filter was replayed straight to the database; '
                . 'rows never expire, so this wording would return the forbidden total for ever'
        );

        // ...and the question must still be ANSWERABLE. Refusing was the first
        // fix and it was worse than it looked: rows have no expiry and the
        // provider is never re-consulted, so a row cached before the adopter
        // wrote the rule refused permanently, and the fuzzy tier handed the
        // same dead row to every rewording. The refusal even said "ask again",
        // which could not work.
        $this->assertSame(
            'success',
            $result['status'] ?? null,
            'a stale recipe left the question permanently unanswerable instead of being discarded '
                . 'and regenerated: ' . json_encode($result)
        );

        $this->assertEquals(
            300.0,
            $this->total($result),
            'the regenerated answer should obey the rule: 100 + 200 paid, with the 500 cancelled '
                . 'row excluded'
        );
    }

    /**
     * And the row heals, so the cost is one provider call rather than one per
     * ask for ever. store() overwrites on the unique question hash the moment
     * a fresh answer succeeds.
     */
    #[Test]
    public function a_discarded_recipe_is_replaced_rather_than_re_fetched_every_time()
    {
        config([
            'jeeves.cache.enabled' => true,
            'jeeves.verification.enabled' => false,
        ]);
        $this->artisan('migrate', ['--force' => true])->run();
        $this->seedOrders();

        $this->app->make(QueryCacheInterface::class)->store('total revenue', [
            'dataset' => 'nq_orders',
            'metric' => 'revenue',
            'query_type' => 'aggregation',
            '_asking_scope' => 'nq_orders',
            '_sql_result' => [
                'success' => true,
                'sql' => 'SELECT SUM(revenue) AS revenue FROM nq_orders',
                'bindings' => [],
                'dataset' => 'nq_orders',
                'metric' => 'revenue',
                'query_type' => 'aggregation',
            ],
        ]);

        $provider = $this->provider([
            'sql' => "SELECT SUM(revenue) AS revenue FROM nq_orders WHERE status != 'cancelled'",
            'dataset' => 'nq_orders',
            'metric' => 'revenue',
            'query_type' => 'aggregation',
        ]);

        $orchestrator = $this->app->make(QueryOrchestrator::class);
        $orchestrator->query('total revenue', 'nq_orders');
        $second = $orchestrator->query('total revenue', 'nq_orders');

        $this->assertEquals(300.0, $this->total($second));
        $this->assertTrue(
            $second['metadata']['cache_hit'] ?? false,
            'the healed row was not reused, so every later ask pays for the provider again: '
                . json_encode($second['metadata'] ?? [])
        );
    }

    /**
     * The cost of keying on the SQL TEXT, which is how the first version of
     * this fix worked. A column named after a neighbouring table armed that
     * table's rule and refused a correct query -  quoting a filter on `status`,
     * a column this table does not have, and marking it unretriable. So the
     * error was wrong, the suggested remedy was impossible, and there was no
     * way out.
     */
    #[Test]
    public function a_column_named_after_a_guarded_table_does_not_arm_its_rule()
    {
        $this->seedOrders();
        $this->seedVisits();

        $this->provider([
            'sql' => 'SELECT source, SUM(nq_orders) AS nq_orders FROM nq_visits GROUP BY source LIMIT 10',
            'dataset' => 'nq_visits',
            'metric' => 'nq_orders',
            'query_type' => 'ranking',
        ]);

        $result = $this->app->make(QueryOrchestrator::class)->query('orders per source', 'nq_visits');

        $this->assertSame(
            'success',
            $result['status'] ?? null,
            'a correct query was refused because a COLUMN was spelled like a guarded table: '
                . json_encode($result)
        );
    }

    /** The same, for a table name that appears only inside a string literal. */
    #[Test]
    public function a_table_name_in_a_string_literal_does_not_arm_the_rule()
    {
        $this->seedOrders();
        $this->seedVisits();

        $this->provider([
            'sql' => "SELECT COUNT(*) AS record_count FROM nq_visits WHERE source = 'nq_orders'",
            'dataset' => 'nq_visits',
            'metric' => 'record_count',
            'query_type' => 'aggregation',
        ]);

        $result = $this->app->make(QueryOrchestrator::class)->query('visits from orders', 'nq_visits');

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
    }

    /**
     * NQ-008, LEAK 4. The rule is satisfied by TEXT, so text that does nothing
     * satisfies it.
     *
     * The check asks whether the normalised SQL contains the normalised filter.
     * A comment is part of the string and not part of the query, so a filter
     * written inside one passes the check and constrains nothing. The answer
     * comes back as 800 - every row, including the 500 the adopter's rule says
     * must never be counted - reported as a success.
     *
     * This repository has met this bug before, in another guard: `pg_sleep/**​/(10)`
     * defeated a rule added hours earlier, because `\s*` does not match a
     * comment (`57c8b72`). Same lesson, opposite direction - there a comment
     * hid something forbidden, here it supplies something required.
     */
    #[Test]
    public function a_required_filter_written_inside_a_comment_does_not_satisfy_the_rule()
    {
        $this->seedOrders();

        $this->provider([
            'sql' => "SELECT /* status != 'cancelled' */ SUM(revenue) AS revenue FROM nq_orders LIMIT 50",
            'dataset' => 'nq_orders',
            'metric' => 'revenue',
            'query_type' => 'aggregation',
        ]);

        $result = $this->app->make(QueryOrchestrator::class)->query('total revenue', 'nq_orders');

        $this->assertRefusedByTheRule($result);
    }

    /**
     * NQ-008, the same shape with a line comment - which also has to survive
     * the newline being normalised to a space.
     */
    #[Test]
    public function a_required_filter_in_a_line_comment_does_not_satisfy_the_rule_either()
    {
        $this->seedOrders();

        $this->provider([
            'sql' => "SELECT SUM(revenue) AS revenue FROM nq_orders LIMIT 50 -- status != 'cancelled'\n",
            'dataset' => 'nq_orders',
            'metric' => 'revenue',
            'query_type' => 'aggregation',
        ]);

        $result = $this->app->make(QueryOrchestrator::class)->query('total revenue', 'nq_orders');

        $this->assertRefusedByTheRule($result);
    }

    /** And the obedient case still passes through untouched. */
    #[Test]
    public function an_obedient_query_is_still_served()
    {
        $this->seedOrders();

        $this->provider([
            'sql' => "SELECT SUM(revenue) AS revenue FROM nq_orders WHERE status != 'cancelled'",
            'dataset' => 'nq_orders',
            'metric' => 'revenue',
            'query_type' => 'aggregation',
        ]);

        $result = $this->app->make(QueryOrchestrator::class)->query('total revenue', 'nq_orders');

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        $this->assertEquals(300.0, $this->total($result), 'the correctly filtered answer was altered');
    }
}
