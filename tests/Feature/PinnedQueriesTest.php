<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Events\QuestionAnswered;
use Jayanta\Jeeves\Tests\Support\RecordingProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * `pinned_queries`: an exact question tied to reviewed SQL, answered the same
 * way every time with no model call.
 *
 * The project this was learned from pinned eighteen of its own questions this
 * way. That is legitimate for stability and it is also how a regression suite
 * of those questions can pass indefinitely - which is why the config comment
 * says so, and why these tests prove the pinned SQL is still validated rather
 * than trusted.
 */
class PinnedQueriesTest extends TestCase
{
    private const SQL = 'SELECT status, SUM(total) AS total FROM vo_orders GROUP BY status ORDER BY total DESC LIMIT 10';

    private ?RecordingProvider $provider = null;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/value-alias-orders-schemas');
        $app['config']->set('jeeves.query_mode', 'sql_generation');
        $app['config']->set('jeeves.cache.enabled', false);
        $app['config']->set('jeeves.verification.enabled', false);
    }

    private function seedTables(): void
    {
        Schema::dropIfExists('vo_orders');
        Schema::create('vo_orders', function (Blueprint $t) {
            $t->id();
            $t->string('status');
            $t->string('country');
            $t->decimal('total', 10, 2);
        });
        DB::table('vo_orders')->insert([
            ['status' => 'cancelled', 'country' => 'United Kingdom', 'total' => 100],
            ['status' => 'shipped', 'country' => 'France', 'total' => 250],
        ]);

        Schema::dropIfExists('vo_returns');
        Schema::create('vo_returns', function (Blueprint $t) {
            $t->id();
            $t->string('status');
            $t->integer('qty');
        });
    }

    private function pin(array $entries): void
    {
        config()->set('jeeves.pinned_queries', $entries);
    }

    private function ask(string $question): array
    {
        $this->provider = new RecordingProvider;
        $this->provider->sqlResponse = ['success' => true, 'data' => [
            'sql' => 'SELECT COUNT(*) AS n FROM vo_orders',
            'dataset' => 'vo_orders',
            'metric' => 'total',
            'query_type' => 'aggregation',
        ]];
        $this->provider->intentResponse = [
            'success' => true,
            'dataset' => 'vo_orders',
            'metric' => 'total',
            'needs_clarification' => false,
            'confidence' => 0.9,
        ];

        $this->app->instance(LlmProviderInterface::class, $this->provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        return $this->app->make(QueryOrchestrator::class)->query($question);
    }

    private function providerCalls(): int
    {
        return count($this->provider?->calls ?? []);
    }

    #[Test]
    public function a_pinned_question_runs_its_sql_without_a_model_call(): void
    {
        $this->seedTables();
        $this->pin([['question' => 'revenue by status', 'sql' => self::SQL, 'dataset' => 'vo_orders']]);

        $result = $this->ask('revenue by status');

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        $this->assertSame(0, $this->providerCalls(), 'a pinned question called the provider');
        $this->assertSame('shipped', ((array) $result['rows'][0])['status'] ?? null);
        $this->assertTrue($result['metadata']['pinned_query'] ?? false);
    }

    /** Case, punctuation and spacing are not part of the question. */
    #[Test]
    public function wording_differences_that_are_not_words_still_match(): void
    {
        $this->seedTables();
        $this->pin([['question' => 'revenue by status', 'sql' => self::SQL, 'dataset' => 'vo_orders']]);

        $result = $this->ask('  Revenue   by STATUS? ');

        $this->assertTrue($result['metadata']['pinned_query'] ?? false, json_encode($result));
        $this->assertSame(0, $this->providerCalls());
    }

    #[Test]
    public function one_pinned_query_can_answer_several_phrasings(): void
    {
        $this->seedTables();
        $this->pin([['question' => ['revenue by status', 'status wise revenue'], 'sql' => self::SQL]]);

        $result = $this->ask('status-wise revenue');

        $this->assertTrue($result['metadata']['pinned_query'] ?? false, json_encode($result));
    }

    /**
     * COUNTERWEIGHT. Nothing fuzzier than case and punctuation: a different
     * question takes the normal route and pays for it.
     */
    #[Test]
    public function a_different_question_takes_the_normal_route(): void
    {
        $this->seedTables();
        $this->pin([['question' => 'revenue by status', 'sql' => self::SQL, 'dataset' => 'vo_orders']]);

        $result = $this->ask('revenue by country');

        $this->assertArrayNotHasKey('pinned_query', $result['metadata'] ?? []);
        $this->assertGreaterThan(0, $this->providerCalls(), 'a near-miss ran pinned SQL for a different question');
    }

    /**
     * RULE 4. Pinned SQL is the install's own, and it is STILL validated. A
     * pinned statement naming a table outside the schema files is refused.
     */
    #[Test]
    public function pinned_sql_is_validated_like_any_other(): void
    {
        $this->seedTables();
        $this->pin([[
            'question' => 'everything in the users table',
            'sql' => 'SELECT * FROM users LIMIT 10',
            'dataset' => 'vo_orders',
        ]]);

        $result = $this->ask('everything in the users table');

        $this->assertSame('error', $result['status'] ?? null, json_encode($result));
        $this->assertSame('unsafe_sql', $result['error_code'] ?? null);
        $this->assertSame(0, $this->providerCalls());
    }

    /**
     * A pinned answer leaves through the same door as every other: timed,
     * audited and announced. A second exit that forgot any of those would
     * make pinned questions invisible to whoever listens for QuestionAnswered.
     */
    #[Test]
    public function a_pinned_answer_is_timed_and_announced(): void
    {
        $this->seedTables();
        Event::fake([QuestionAnswered::class]);
        $this->pin([['question' => 'revenue by status', 'sql' => self::SQL, 'dataset' => 'vo_orders']]);

        $result = $this->ask('revenue by status');

        $this->assertArrayHasKey('processing_time_ms', $result['metadata'] ?? []);
        Event::assertDispatched(QuestionAnswered::class);
    }

    /** Without a dataset, the one table the SQL names decides it. */
    #[Test]
    public function the_dataset_is_inferred_from_the_one_table_the_sql_names(): void
    {
        $this->seedTables();
        $this->pin([['question' => 'revenue by status', 'sql' => self::SQL]]);

        $result = $this->ask('revenue by status');

        $this->assertSame('vo_orders', $result['parsed_query']['dataset'] ?? null, json_encode($result));
    }

    /**
     * REVIEW FINDING. The shape retry ran on pinned SQL too: a pinned question
     * that reads like "which X has the most" and returns several rows was
     * regenerated by the model, and the model's answer replaced the reviewed
     * one - the exact variance a pinned query exists to rule out.
     */
    #[Test]
    public function a_superlative_pinned_question_is_never_regenerated(): void
    {
        $this->seedTables();
        $this->pin([['question' => 'which status has the most revenue', 'sql' => self::SQL, 'dataset' => 'vo_orders']]);

        $result = $this->ask('which status has the most revenue');

        $this->assertSame(0, $this->providerCalls(), 'reviewed SQL was replaced by a model regeneration');
        $this->assertCount(2, $result['rows'] ?? [], 'the answer returned was not the reviewed one');
    }

    /**
     * REVIEW FINDING. Value aliases and typo correction rewrote values inside
     * reviewed SQL. Pinned SQL runs exactly as it was written.
     */
    #[Test]
    public function reviewed_sql_is_run_exactly_as_written(): void
    {
        $this->seedTables();
        $this->pin([[
            'question' => 'void orders',
            'sql' => "SELECT status, SUM(total) AS total FROM vo_orders WHERE status = 'void' GROUP BY status LIMIT 10",
            'dataset' => 'vo_orders',
        ]]);

        $result = $this->ask('void orders');

        $this->assertArrayNotHasKey('value_aliases_applied', $result['metadata'] ?? [], 'reviewed SQL was rewritten');
        $this->assertEmpty($result['rows'] ?? [], json_encode($result));
    }
}
