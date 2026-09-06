<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Tests\Support\RecordingProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The two cache routes that survived the previous three fixes.
 *
 * Each earlier fix closed the route it was written for and left the one beside
 * it open. This pins both remaining ones, found by an adversarial review that
 * reproduced each with a cache-on/cache-off counterfactual rather than by
 * reading.
 *
 * ROUTE 1 -  the dataset guard is skipped when the asking dataset is unknown.
 * The discard in query() reads `$askingDataset !== null && ...`, and
 * resolveAskingDataset() returns null whenever more than one dataset is
 * registered and nothing in the question, the hint, or the conversation names
 * one. So a row cached WITH a dataset hint is served verbatim to the same
 * wording asked WITHOUT one -  which is the widget's ordinary shape: a
 * dataset-scoped page caches it, the general chat page reads it back.
 *
 * ROUTE 2 -  conversation follow-ups are cached in SQL-generation mode.
 * processWithIntent() guards its cache use with $inConversation.
 * processWithSqlGeneration()'s _sql_result branch has no equivalent, and
 * store() runs unconditionally. So a follow-up that escalates is written to a
 * TEXT-keyed cache, and another session's identically-worded follow-up reads
 * it -  carrying the first session's measure. docs/CONVERSATIONS.md states the
 * opposite in as many words.
 */
class CacheCrossingRemainingRoutesTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/fuzzy-cache-isolation-schemas');
        $app['config']->set('jeeves.cache.enabled', true);
        $app['config']->set('jeeves.query_mode', 'sql_generation');
    }

    private function seedTables(): void
    {
        foreach (['nq_orders' => [100, 200, 50], 'nq_products' => [30, 45, 15]] as $table => $rows) {
            Schema::dropIfExists($table);
            Schema::create($table, function ($t) {
                $t->id();
                $t->decimal('revenue', 12, 2);
            });
            foreach ($rows as $r) {
                DB::table($table)->insert(['revenue' => $r]);
            }
        }
    }

    private function provider(): RecordingProvider
    {
        $provider = new RecordingProvider;
        $provider->sqlResponse = function (string $prompt) {
            $table = str_contains($prompt, 'nq_products') ? 'nq_products' : 'nq_orders';

            return [
                'success' => true,
                'data' => [
                    'sql' => "SELECT SUM(revenue) AS revenue FROM {$table}",
                    'dataset' => $table,
                    'query_type' => 'aggregation',
                ],
            ];
        };
        $this->app->instance(LlmProviderInterface::class, $provider);

        return $provider;
    }

    private function total(array $r): float
    {
        $row = $r['rows'][0] ?? null;

        return (float) (is_array($row) ? ($row['revenue'] ?? 0) : ($row->revenue ?? 0));
    }

    /**
     * ROUTE 1. nq_orders totals 350, nq_products totals 90 -  both readable
     * from the fixture. A hinted question caches under nq_products; the same
     * words with no hint must not be answered from it.
     */
    #[Test]
    public function a_row_cached_with_a_dataset_hint_is_not_served_to_the_same_words_without_one()
    {
        $this->artisan('migrate', ['--force' => true])->run();
        $this->seedTables();
        $provider = $this->provider();

        $question = 'what is the total please';
        $orchestrator = $this->app->make(QueryOrchestrator::class);

        $hinted = $orchestrator->query($question, 'nq_products');
        $this->assertEquals(90.0, $this->total($hinted), 'precondition: the hinted ask answers nq_products (30+45+15)');

        $callsAfterFirst = count($provider->methodsCalled());

        // Same words, no hint. resolveAskingDataset() cannot place this, so the
        // guard must NOT be skipped -  an unplaceable question can be served
        // only by a row that was itself cached without a dataset.
        $unhinted = $orchestrator->query($question);

        $this->assertGreaterThan(
            $callsAfterFirst,
            count($provider->methodsCalled()),
            'the unhinted question was answered from a row cached under a specific dataset, with no provider call'
        );

        // Deliberately NOT asserting which number came back. With no hint the
        // prompt lists every dataset, so which one the model picks is the
        // model's call, and pinning it here would test the stub rather than
        // the engine. What matters is that the answer was GENERATED for this
        // question instead of replayed from a row cached under a scope this
        // question never had.
        $this->assertFalse(
            $unhinted['metadata']['cache_hit'] ?? false,
            'the unhinted question reports itself as cached'
        );
    }

    /**
     * ROUTE 2. A conversation turn must not be written to the text-keyed
     * cache, because the key carries no session -  another session asking the
     * same follow-up words reads it back.
     */
    #[Test]
    public function a_conversation_turn_is_not_written_to_the_text_keyed_cache()
    {
        $this->artisan('migrate', ['--force' => true])->run();
        $this->seedTables();
        $provider = $this->provider();

        $orchestrator = $this->app->make(QueryOrchestrator::class);
        $table = config('jeeves.cache.table_name', 'jeeves_cache');

        // Asserted on the STORE rather than on a cross-session read. A
        // cross-session read needs two sessions on the SAME dataset differing
        // only by measure, because the dataset guard masks anything else -  and
        // the underlying promise is simpler than that anyway:
        // docs/CONVERSATIONS.md says a follow-up is never WRITTEN to the query
        // cache. processWithIntent honours it; the SQL-generation path does not.
        $before = DB::table($table)->count();

        $result = $orchestrator->query(
            'and the total there',
            'nq_orders',
            ['state' => ['dataset' => 'nq_orders', 'metric' => 'revenue']]
        );

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));

        $this->assertSame(
            $before,
            DB::table($table)->count(),
            'a conversation turn was written to the text-keyed query cache; the key carries no session, '
                . 'so another session asking the same follow-up words reads this row back'
        );
    }
}
