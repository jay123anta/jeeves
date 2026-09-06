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
 * Two more instances of one pattern: a rejection that lives at the consumption
 * site while the flag lives at the entry.
 *
 * A. A SQL-GENERATION RECIPE READ BACK BY INTENT MODE DROPS ITS WHERE CLAUSE.
 * processWithSqlGeneration() caches {dataset, metric, group_value, limit,
 * order, query_type, _sql_result}. Two readers exist. The SQL-generation one
 * replays _sql_result verbatim. processWithIntent() hands the same row to
 * normalizeIntent() and then SqlBuilder -  and the intent contract has no slot
 * for a WHERE predicate. That predicate exists ONLY inside _sql_result['sql'],
 * which this reader never opens.
 *
 * The row exists in the first place BECAUSE the question needed something the
 * contract could not express. Rebuilding it through the contract is guaranteed
 * to lose exactly that. A filtered total silently becomes an unfiltered one,
 * status success, zero provider calls. Tier 2 rows have no TTL, so the row
 * keeps doing it.
 *
 * B. cache_hit IS TRUE WHEN NO ROW WAS USED.
 * It is set the moment a row is found. Both readers then independently refuse
 * to use one mid-conversation. So a conversation turn reports cache_hit true
 * while the provider generated the answer -  and verification.skip_on_cache_hit
 * reads that flag and skips QueryVerifier on brand-new SQL. The same wrong flag
 * reaches auditLog() and the QuestionAnswered event.
 */
class CacheRecipeAndFlagHonestyTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/fuzzy-cache-isolation-schemas');
        $app['config']->set('jeeves.cache.enabled', true);
    }

    private function seedOrders(): void
    {
        Schema::dropIfExists('nq_orders');
        Schema::create('nq_orders', function ($t) {
            $t->id();
            $t->string('status');
            $t->decimal('revenue', 12, 2);
        });
        foreach ([['pending', 100], ['shipped', 200], ['shipped', 50]] as [$s, $r]) {
            DB::table('nq_orders')->insert(['status' => $s, 'revenue' => $r]);
        }
    }

    private function total(array $r): float
    {
        $row = $r['rows'][0] ?? null;

        return (float) (is_array($row) ? ($row['revenue'] ?? 0) : ($row->revenue ?? 0));
    }

    /**
     * A. Hand-checkable: pending is 100; the whole table is 100+200+50 = 350.
     * The second ask must not turn "pending" into "everything".
     */
    #[Test]
    public function a_sql_recipe_is_never_rebuilt_by_intent_mode_without_its_predicate()
    {
        config(['jeeves.query_mode' => 'sql_generation']);
        $this->artisan('migrate', ['--force' => true])->run();
        $this->seedOrders();

        $provider = new RecordingProvider;
        $provider->sqlResponse = [
            'success' => true,
            'data' => [
                'sql' => "SELECT SUM(revenue) AS revenue FROM nq_orders WHERE status = 'pending'",
                'dataset' => 'nq_orders',
                'metric' => 'revenue',
                'query_type' => 'aggregation',
            ],
        ];
        $this->app->instance(LlmProviderInterface::class, $provider);

        $question = 'total revenue for pending orders';

        $first = $this->app->make(QueryOrchestrator::class)->query($question, 'nq_orders');
        $this->assertEquals(100.0, $this->total($first), 'precondition: the filtered answer is 100');

        // The recipe row is now cached. Read it back through INTENT mode.
        config(['jeeves.query_mode' => 'intent']);
        $second = $this->app->make(QueryOrchestrator::class)->query($question, 'nq_orders');

        $this->assertNotEquals(
            350.0,
            $this->total($second),
            'the WHERE clause was dropped rebuilding the cached recipe: a question about pending orders '
                . 'was answered with the total for every row'
        );
    }

    /**
     * B. A conversation turn is never served from cache, so it must never
     * report itself as cached -  the verifier, the audit log and the
     * QuestionAnswered event all read that flag.
     */
    #[Test]
    public function a_conversation_turn_never_reports_a_cache_hit()
    {
        config(['jeeves.query_mode' => 'sql_generation']);
        $this->artisan('migrate', ['--force' => true])->run();
        $this->seedOrders();

        $provider = new RecordingProvider;
        $provider->sqlResponse = [
            'success' => true,
            'data' => [
                'sql' => 'SELECT SUM(revenue) AS revenue FROM nq_orders',
                'dataset' => 'nq_orders',
                'metric' => 'revenue',
                'query_type' => 'aggregation',
            ],
        ];
        $this->app->instance(LlmProviderInterface::class, $provider);

        $orchestrator = $this->app->make(QueryOrchestrator::class);
        $question = 'what is the total';

        // Populate the cache with a plain, non-conversation ask.
        $orchestrator->query($question, 'nq_orders');

        // The same words inside a conversation. Both readers refuse the row,
        // so the provider answers -  and the flag must say so.
        $inConv = $orchestrator->query($question, 'nq_orders', ['state' => ['dataset' => 'nq_orders']]);

        $this->assertSame('success', $inConv['status'] ?? null, json_encode($inConv));
        $this->assertFalse(
            $inConv['metadata']['cache_hit'] ?? false,
            'a conversation turn the provider answered reports cache_hit true, which skips QueryVerifier '
                . 'and records a provider-billed answer as cached'
        );
    }

    /** And a real hit must still say so -  the fix must not blanket-clear. */
    #[Test]
    public function a_genuine_repeat_still_reports_a_cache_hit()
    {
        config(['jeeves.query_mode' => 'sql_generation']);
        $this->artisan('migrate', ['--force' => true])->run();
        $this->seedOrders();

        $provider = new RecordingProvider;
        $provider->sqlResponse = [
            'success' => true,
            'data' => [
                'sql' => 'SELECT SUM(revenue) AS revenue FROM nq_orders',
                'dataset' => 'nq_orders',
                'metric' => 'revenue',
                'query_type' => 'aggregation',
            ],
        ];
        $this->app->instance(LlmProviderInterface::class, $provider);

        $orchestrator = $this->app->make(QueryOrchestrator::class);
        $question = 'what is the total';

        $orchestrator->query($question, 'nq_orders');
        $callsAfterFirst = count($provider->methodsCalled());

        $second = $orchestrator->query($question, 'nq_orders');

        $this->assertSame($callsAfterFirst, count($provider->methodsCalled()), 'the cache stopped hitting entirely');
        $this->assertTrue($second['metadata']['cache_hit'] ?? false, 'a real hit no longer reports itself');
    }
}
