<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jayanta\Jeeves\Cache\TwoTierQueryCache;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Contracts\QueryCacheInterface;
use Jayanta\Jeeves\Contracts\ScopesCacheByDataset;
use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Tests\Support\RecordingProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The smaller half of the second review batch.
 *
 * None of these returns a wrong number. Each makes the package cost more,
 * degrade worse, or lie in a log -  which is how the expensive ones started.
 */
class BatchTwoMinorsTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/fuzzy-cache-isolation-schemas');
        $app['config']->set('jeeves.cache.enabled', true);
    }

    /**
     * A missing cache table is a miss, not a failed question.
     *
     * cache.enabled defaults to true, so an install that has not run
     * `php artisan migrate` queries a table that does not exist, and the
     * QueryException reached the orchestrator's catch: every question came
     * back "An error occurred processing your query", with nothing naming a
     * cache, a table or a migration. First thing a new adopter sees.
     */
    #[Test]
    public function a_missing_cache_table_degrades_to_a_miss_rather_than_failing_every_question()
    {
        Schema::dropIfExists(config('jeeves.cache.table_name', 'jeeves_cache'));
        Schema::dropIfExists('nq_orders');
        Schema::create('nq_orders', function ($t) {
            $t->id();
            $t->decimal('revenue', 12, 2);
        });
        DB::table('nq_orders')->insert(['revenue' => 100]);

        config(['jeeves.query_mode' => 'sql_generation']);

        $provider = new RecordingProvider;
        $provider->sqlResponse = [
            'success' => true,
            'data' => [
                'sql' => 'SELECT SUM(revenue) AS revenue FROM nq_orders',
                'dataset' => 'nq_orders',
                'query_type' => 'aggregation',
            ],
        ];
        $this->app->instance(LlmProviderInterface::class, $provider);

        $result = $this->app->make(QueryOrchestrator::class)->query('total revenue', 'nq_orders');

        $this->assertSame(
            'success',
            $result['status'] ?? null,
            'a package installed but not migrated answers nothing at all, and says only "An error '
                . 'occurred processing your query": ' . json_encode($result)
        );
    }

    /**
     * A cache that declares the capability must be asked through it, whatever
     * class in its own hierarchy happens to declare find().
     */
    #[Test]
    public function a_non_bundled_scope_capable_cache_is_asked_for_the_scope()
    {
        $cache = new ConcreteScopedCache;
        $this->app->instance(QueryCacheInterface::class, $cache);
        $this->app->forgetInstance(QueryOrchestrator::class);

        config(['jeeves.query_mode' => 'sql_generation']);

        $provider = new RecordingProvider;
        $provider->sqlResponse = [
            'success' => true,
            'data' => ['sql' => 'SELECT 1 AS revenue', 'dataset' => 'nq_orders', 'query_type' => 'aggregation'],
        ];
        $this->app->instance(LlmProviderInterface::class, $provider);

        $this->app->make(QueryOrchestrator::class)->query('total revenue', 'nq_orders');

        $this->assertGreaterThan(
            0,
            $cache->scopedCalls,
            'a cache that implements ScopesCacheByDataset was never asked through it, because find() '
                . 'happened to be declared on its own abstract base rather than on the concrete class'
        );
    }
}

/** A cache whose find() lives on its own base, not on TwoTierQueryCache. */
abstract class AdopterCacheBase implements QueryCacheInterface
{
    public function find(string $query): ?array
    {
        return null;
    }

    public function store(string $query, array $intent): bool
    {
        return true;
    }

    public function getStatistics(): array
    {
        return [];
    }

    public function clear(?string $dataset = null, int $olderThanDays = 0, int $minHits = 0): int
    {
        return 0;
    }
}

class ConcreteScopedCache extends AdopterCacheBase implements ScopesCacheByDataset
{
    public int $scopedCalls = 0;

    public function findForDataset(string $query, ?string $datasetHint = null): ?array
    {
        $this->scopedCalls++;

        return null;
    }
}
