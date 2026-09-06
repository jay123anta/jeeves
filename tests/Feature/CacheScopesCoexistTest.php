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
 * Refusing to SHARE a row between scopes is right. Refusing to STORE both is
 * not, and that is what shipped.
 *
 * `query_hash` is UNIQUE and derived from the question's text alone, so there
 * is exactly one row per wording. store() finds that row and updates it. Two
 * dataset-scoped pages asking the same words therefore take turns overwriting
 * each other:
 *
 *   orders page asks   -> row scope=orders
 *   products page asks -> scope mismatch, miss, regenerate, UPDATE row to products
 *   orders page asks   -> scope mismatch, miss, regenerate, UPDATE row to orders
 *
 * Neither page ever gets a hit, forever, and each pays an API call every time.
 * The cache is not merely conservative here -  it is 0% for any wording used on
 * more than one scoped page, which on a multi-tenant or multi-page install is
 * the normal case rather than an edge one.
 *
 * The scope belongs in the key. It is already in the hash's other dimension -
 * `generateHash()` folds in the contract version for exactly this reason -  so
 * a scoped question and an unscoped one simply address different rows.
 *
 * (#11) And in the shipped default `auto` mode, a question whose cached row
 * carries a finished SQL recipe was still routed to intent mode first, which
 * cannot use that shape and calls parseIntent before falling back to the
 * reader that can. Every repeat paid for a provider call whose result was then
 * discarded in favour of the cached SQL.
 */
class CacheScopesCoexistTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/fuzzy-cache-isolation-schemas');
        $app['config']->set('jeeves.cache.enabled', true);
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
     * Two scoped pages, same wording, alternating. Both must end up cached and
     * both must keep answering their own dataset. nq_orders is 350,
     * nq_products is 90 -  both readable from the fixture.
     */
    #[Test]
    public function two_scoped_pages_asking_the_same_words_both_get_cache_hits()
    {
        config(['jeeves.query_mode' => 'sql_generation']);
        $this->artisan('migrate', ['--force' => true])->run();
        $this->seedTables();
        $provider = $this->provider();

        $orchestrator = $this->app->make(QueryOrchestrator::class);
        $question = 'what is the total';

        // Warm both scopes.
        $this->assertEquals(350.0, $this->total($orchestrator->query($question, 'nq_orders')));
        $this->assertEquals(90.0, $this->total($orchestrator->query($question, 'nq_products')));

        $callsAfterWarming = count($provider->methodsCalled());

        // Now both must be served from their own rows.
        $orders = $orchestrator->query($question, 'nq_orders');
        $products = $orchestrator->query($question, 'nq_products');

        $this->assertSame(
            $callsAfterWarming,
            count($provider->methodsCalled()),
            'each page evicted the other\'s cached row, so neither ever gets a hit and both pay an API '
                . 'call on every ask, forever'
        );

        // And still the right numbers, which is the property the scope rule exists for.
        $this->assertEquals(350.0, $this->total($orders), 'the orders page was served another dataset\'s row');
        $this->assertEquals(90.0, $this->total($products), 'the products page was served another dataset\'s row');
    }

    /** Both rows must actually exist, rather than one having replaced the other. */
    #[Test]
    public function both_scopes_keep_their_own_row()
    {
        config(['jeeves.query_mode' => 'sql_generation']);
        $this->artisan('migrate', ['--force' => true])->run();
        $this->seedTables();
        $this->provider();

        $orchestrator = $this->app->make(QueryOrchestrator::class);
        $question = 'what is the total';
        $table = config('jeeves.cache.table_name', 'jeeves_cache');

        $orchestrator->query($question, 'nq_orders');
        $orchestrator->query($question, 'nq_products');

        $this->assertSame(
            2,
            DB::table($table)->count(),
            'one wording asked under two scopes produced a single row; query_hash is unique on the text '
                . 'alone, so the second ask overwrote the first'
        );
    }

    /**
     * #6 -  a lookup the engine throws away must not be counted as reuse.
     *
     * hit_count is incremented inside the cache the moment it returns a row,
     * before the engine has decided whether the row is usable. A conversation
     * turn is never served from cache, so every follow-up whose words matched
     * something inflated that row's hit_count -  and jeeves:cache-stats,
     * plus --min-hits, read it.
     */
    #[Test]
    public function a_conversation_turn_does_not_count_as_reuse_of_a_row_it_cannot_use()
    {
        config(['jeeves.query_mode' => 'sql_generation']);
        $this->artisan('migrate', ['--force' => true])->run();
        $this->seedTables();
        $this->provider();

        $orchestrator = $this->app->make(QueryOrchestrator::class);
        $table = config('jeeves.cache.table_name', 'jeeves_cache');
        $question = 'what is the total';

        $orchestrator->query($question, 'nq_orders');
        $hitsAfterWarming = (int) DB::table($table)->value('hit_count');

        // Same words, now inside a conversation. The row cannot be used.
        $orchestrator->query($question, 'nq_orders', ['state' => ['dataset' => 'nq_orders']]);

        $this->assertSame(
            $hitsAfterWarming,
            (int) DB::table($table)->value('hit_count'),
            'a conversation turn counted as a hit on a row it is forbidden from using, so cache-stats '
                . 'reports reuse that never happened and --min-hits protects a row nobody reads'
        );
    }

    /**
     * #11 -  auto mode must not pay for an intent call it is about to discard.
     */
    #[Test]
    public function auto_mode_replays_a_cached_sql_recipe_without_an_intent_call()
    {
        config(['jeeves.query_mode' => 'auto']);
        $this->artisan('migrate', ['--force' => true])->run();
        $this->seedTables();

        $provider = new RecordingProvider;
        $provider->sqlResponse = [
            'success' => true,
            'data' => [
                'sql' => 'SELECT SUM(revenue) AS revenue FROM nq_orders WHERE revenue > 60',
                'dataset' => 'nq_orders',
                'query_type' => 'aggregation',
            ],
        ];
        $this->app->instance(LlmProviderInterface::class, $provider);

        $orchestrator = $this->app->make(QueryOrchestrator::class);

        // A question the intent contract cannot express, so auto escalates and
        // caches a SQL recipe.
        $question = 'total revenue for orders with more than 60 revenue';
        $first = $orchestrator->query($question, 'nq_orders');
        $this->assertSame('success', $first['status'] ?? null, json_encode($first));

        $callsAfterFirst = count($provider->methodsCalled());
        $second = $orchestrator->query($question, 'nq_orders');

        $this->assertSame(
            $callsAfterFirst,
            count($provider->methodsCalled()),
            'auto mode routed a cached SQL recipe through intent mode first, paying for a parseIntent call '
                . 'whose result it then discarded in favour of the cached SQL: '
                . json_encode($provider->methodsCalled())
        );
        $this->assertTrue($second['metadata']['cache_hit'] ?? false);
    }
}
