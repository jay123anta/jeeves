<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jayanta\Jeeves\Cache\TwoTierQueryCache;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Tests\Support\RecordingProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The fourth review round's two behavioural changes.
 */
class FuzzyOffAndValidatedOnlyTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/single-dataset-schemas');
        $app['config']->set('jeeves.cache.enabled', true);
        $app['config']->set('jeeves.query_mode', 'sql_generation');
    }

    private function seedOrders(): void
    {
        Schema::dropIfExists('nq_orders');
        Schema::create('nq_orders', function ($t) {
            $t->id();
            $t->decimal('revenue', 12, 2);
        });
        DB::table('nq_orders')->insert(['revenue' => 100]);
    }

    /**
     * A question differing from a cached one by a single VALUE must not be
     * answered from it. At the shipped 0.85 threshold the pair below scores
     * 0.858 -  above it -  so this is only safe because the tier is off.
     */
    #[Test]
    public function a_one_value_difference_is_not_smoothed_over_by_default()
    {
        $this->artisan('migrate', ['--force' => true])->run();

        $cache = $this->app->make(TwoTierQueryCache::class);
        $scope = 'nq_orders';

        $cache->store('total revenue by region for pending orders in grade a', [
            'dataset' => $scope,
            'metric' => 'revenue',
            '_asking_scope' => $scope,
        ]);

        $this->assertNull(
            $cache->findForDataset('total revenue by region for pending orders in grade b', $scope),
            'grade B was answered from grade A\'s cached row -  the two score 0.858 against a 0.85 '
                . 'threshold, and the longer the question the worse it gets'
        );
    }

    /**
     * The old opt-in is inert, and turning it on must not bring it back.
     *
     * `cache.fuzzy_matching` and `cache.similarity_threshold` are still read
     * from a published config so that an app carrying them keeps booting, but
     * nothing consults them any more. An adopter who set them years ago and
     * upgrades must get the safe behaviour, not the one they configured -
     * which is the whole point of removing the tier rather than defaulting it
     * off again.
     */
    #[Test]
    public function turning_the_old_fuzzy_setting_on_does_not_bring_it_back()
    {
        $this->artisan('migrate', ['--force' => true])->run();
        config(['jeeves.cache.fuzzy_matching' => true, 'jeeves.cache.similarity_threshold' => 0.5]);

        $cache = $this->app->make(TwoTierQueryCache::class);
        $scope = 'nq_orders';

        $cache->store('total revenue for the year', [
            'dataset' => $scope,
            'metric' => 'revenue',
            '_asking_scope' => $scope,
        ]);

        // "years" is a distinct token from "year" after normalisation, so this
        // cannot be an exact hit. It used to be a fuzzy one.
        $this->assertNull(
            $cache->findForDataset('total revenue for the years', $scope),
            'a differently worded question was answered from cache with the removed tier '
                . 're-enabled by config, so the setting is still wired to something'
        );

        // The counterweight: the cache still answers the SAME question. Without
        // this, deleting the cache outright would also pass the assertion above.
        $this->assertNotNull(
            $cache->findForDataset('total revenue for the year', $scope),
            'the exact tier stopped working, which is not what removing the fuzzy tier was for'
        );
    }

    /**
     * SQL the validator rejects must not be cached. It has no expiry, and the
     * replay branch does not re-validate -  so one bad generation made that
     * wording permanently unanswerable, with the provider never asked again.
     */
    #[Test]
    public function sql_that_fails_validation_is_not_cached()
    {
        $this->artisan('migrate', ['--force' => true])->run();
        $this->seedOrders();

        // Every generation names a table outside the schema whitelist, so
        // SqlValidator rejects it and the refinement retry cannot rescue the
        // ask. (Rejecting only the FIRST generation is not enough to test
        // this: the retry then succeeds and the question is answered, which
        // is correct behaviour and reveals nothing about what was stored.)
        $invalid = new RecordingProvider;
        $invalid->sqlResponse = [
            'success' => true,
            'data' => [
                'sql' => 'SELECT SUM(amount) AS revenue FROM secrets',
                'dataset' => 'nq_orders',
                'query_type' => 'aggregation',
            ],
        ];
        $this->app->instance(LlmProviderInterface::class, $invalid);

        $orchestrator = $this->app->make(QueryOrchestrator::class);
        $question = 'total revenue please';

        $first = $orchestrator->query($question, 'nq_orders');
        $this->assertNotSame('success', $first['status'] ?? null, 'precondition: the SQL must be rejected');

        $table = config('jeeves.cache.table_name', 'jeeves_cache');
        $this->assertSame(
            0,
            DB::table($table)->count(),
            'SQL the validator rejected was written to a cache with no expiry, so this question is now '
                . 'permanently unanswerable and the provider will never be asked again'
        );

        // And the question recovers once the model produces usable SQL -  it is
        // not permanently poisoned by the rejected generation.
        $good = new RecordingProvider;
        $good->sqlResponse = [
            'success' => true,
            'data' => [
                'sql' => 'SELECT SUM(revenue) AS revenue FROM nq_orders',
                'dataset' => 'nq_orders',
                'query_type' => 'aggregation',
            ],
        ];
        $this->app->instance(LlmProviderInterface::class, $good);
        $this->app->forgetInstance(QueryOrchestrator::class);

        $second = $this->app->make(QueryOrchestrator::class)->query($question, 'nq_orders');
        $this->assertSame('success', $second['status'] ?? null, json_encode($second));
    }
}
