<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jayanta\Jeeves\Cache\TwoTierQueryCache;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Contracts\QueryCacheInterface;
use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Tests\Support\RecordingProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Two ways an adopter's cache stopped being called, both silent.
 *
 * A. A CUSTOM QueryCacheInterface IS NEVER READ.
 * findInCache() used to return null outright -  bypassing the cache -  whenever
 * an asking dataset was known and the cache did not implement
 * ScopesCacheByDataset. resolveAskingDataset() returns the sole key
 * unconditionally on a single-dataset install, which is the common shape, so
 * the bypass fired on every question. store() still ran, so the table filled
 * up and the hit count stayed at zero: the cache looked installed and did
 * nothing.
 *
 * The bypass existed because a cache that cannot filter by dataset might
 * return a row from the wrong one. That reason has since gone: eligibility is
 * decided in query() against `_asking_scope`, which travels inside the intent
 * blob every cache already stores and returns. The guard is universal, so the
 * bypass is redundant.
 *
 * B. A SUBCLASS THAT OVERRIDES find() IS SKIPPED.
 * Overriding find() on the bundled cache is the natural way to add a tenant or
 * permission gate. But TwoTierQueryCache implements ScopesCacheByDataset, the
 * subclass inherits that, and the engine therefore calls findForDataset() -
 * which does not go through find(). The gate never runs and nothing says so.
 * On a multi-tenant install that is a cross-tenant read.
 *
 * A subclass in that shape now gets find() called, so its override is
 * honoured. The cost is the fuzzy tier, which needs the scope the other method
 * carries; losing a fuzzy hit costs one API call, and honouring the gate is
 * not optional.
 */
class CustomCacheIsNotSilentlyBypassedTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        // Single dataset: resolveAskingDataset() always returns its key, which
        // is what used to make the bypass unconditional.
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/single-dataset-schemas');
        $app['config']->set('jeeves.cache.enabled', true);
        $app['config']->set('jeeves.query_mode', 'intent');
    }

    private function seedOrders(): void
    {
        Schema::dropIfExists('nq_orders');
        Schema::create('nq_orders', function ($t) {
            $t->id();
            $t->decimal('revenue', 12, 2);
        });
        foreach ([100, 200, 50] as $r) {
            DB::table('nq_orders')->insert(['revenue' => $r]);
        }
    }

    private function stubProvider(): RecordingProvider
    {
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

        return $provider;
    }

    /** A. The adopter's own cache must actually be read. */
    #[Test]
    public function a_custom_cache_implementation_is_consulted()
    {
        $this->artisan('migrate', ['--force' => true])->run();
        $this->seedOrders();
        $provider = $this->stubProvider();

        $cache = new RecordingCache;
        $this->app->instance(QueryCacheInterface::class, $cache);
        $this->app->forgetInstance(QueryOrchestrator::class);

        $question = 'total revenue';
        $orchestrator = $this->app->make(QueryOrchestrator::class);

        $orchestrator->query($question);
        $this->assertNotEmpty($cache->stored, 'precondition: the custom cache was written to');

        $callsAfterFirst = count($provider->methodsCalled());
        $orchestrator->query($question);

        $this->assertGreaterThan(
            0,
            $cache->findCalls,
            'the custom cache was never read: findInCache() bypassed it because a dataset was known and it '
                . 'does not implement ScopesCacheByDataset, which on a single-dataset install is every question'
        );
        $this->assertSame(
            $callsAfterFirst,
            count($provider->methodsCalled()),
            'the custom cache was read but its row was not used'
        );
    }

    /** B. A tenant gate bolted onto the bundled cache must run. */
    #[Test]
    public function a_subclass_that_overrides_find_has_its_override_honoured()
    {
        $this->artisan('migrate', ['--force' => true])->run();
        $this->seedOrders();
        $this->stubProvider();

        $cache = new GatedCache;
        $this->app->instance(QueryCacheInterface::class, $cache);
        $this->app->forgetInstance(QueryOrchestrator::class);

        $orchestrator = $this->app->make(QueryOrchestrator::class);
        $orchestrator->query('total revenue');
        $orchestrator->query('total revenue');

        $this->assertGreaterThan(
            0,
            $cache->gateChecks,
            'the subclass override of find() never ran, so a tenant or permission gate added the obvious '
                . 'way is silently off and another tenant\'s cached answer is served'
        );
    }
}

/** An adopter's own cache: the interface, nothing more. */
class RecordingCache implements QueryCacheInterface
{
    public array $stored = [];

    public int $findCalls = 0;

    public function find(string $query): ?array
    {
        $this->findCalls++;

        return $this->stored[$query] ?? null;
    }

    public function store(string $query, array $intent): bool
    {
        $this->stored[$query] = [
            'cached' => true,
            'cache_match_type' => 'exact',
            'intent' => $intent,
            'dataset' => $intent['dataset'] ?? null,
        ];

        return true;
    }

    public function clear(?string $dataset = null, int $olderThanDays = 0, int $minHits = 0): int
    {
        $this->stored = [];

        return 0;
    }

    public function getStatistics(): array
    {
        return ['entries' => count($this->stored)];
    }
}

/** The bundled cache with a gate bolted on the obvious way. */
class GatedCache extends TwoTierQueryCache
{
    public int $gateChecks = 0;

    public function find(string $query): ?array
    {
        $this->gateChecks++;

        return parent::find($query);
    }
}
