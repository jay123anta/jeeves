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
 * A dataset mismatch is a MISS, and must be reported as one.
 *
 * NQ-003-FIX made a cached entry belonging to a different dataset fall
 * through to fresh generation instead of being retargeted. Correct. But
 * `metadata['cache_hit']` is set the moment the cache returns ANYTHING, before
 * anything downstream decides whether that entry is usable -  so the fall-through
 * left the flag reading true for a question that in fact went to the provider.
 *
 * Two consequences, one cosmetic and one not:
 *
 *   The response tells an operator the answer came from cache when it did not.
 *
 *   QueryVerifier is SKIPPED. `verification.skip_on_cache_hit` defaults to true
 *   and is read straight off that flag, so the self-check on generated SQL is
 *   disabled for exactly the SQL that was just generated. A cached result was
 *   verified when it was first stored; freshly generated SQL has not been, and
 *   this is the one path where the flag says otherwise.
 *
 * That is the same shape as every other defect this release: a guard that holds
 * on the path it was written for and not on the one added beside it.
 */
class CacheMissIsNotReportedAsAHitTest extends TestCase
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

    /** Answers whichever dataset the prompt it is given names. */
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

    #[Test]
    public function a_dataset_mismatch_is_not_reported_as_a_cache_hit()
    {
        $this->artisan('migrate', ['--force' => true])->run();
        $this->seedTables();
        $provider = $this->provider();

        $question = 'what is the total';

        $first = $this->app->make(QueryOrchestrator::class)->query($question, 'nq_orders');
        $this->assertSame('success', $first['status'] ?? null, json_encode($first));

        // Same words, different dataset: NQ-003-FIX makes this a miss.
        $second = $this->app->make(QueryOrchestrator::class)->query($question, 'nq_products');

        $this->assertSame('success', $second['status'] ?? null, json_encode($second));
        $row = $second['rows'][0] ?? null;
        $this->assertEquals(
            90.0,
            (float) (is_array($row) ? ($row['revenue'] ?? 0) : ($row->revenue ?? 0)),
            'precondition: the miss produced the right number (30+45+15)'
        );

        // THE ASSERTION. It went to the provider, so it was not a cache hit,
        // and must not claim to be one.
        $this->assertFalse(
            $second['metadata']['cache_hit'] ?? false,
            'a question that reached the provider is reported as a cache hit: ' . json_encode($second['metadata'] ?? [])
        );

        $this->assertArrayNotHasKey(
            'cache_match_type',
            $second['metadata'] ?? [],
            'a miss carries a match type, which can only describe an entry that was not used'
        );
    }

    /**
     * The consequence that is not cosmetic: the verifier is gated on that flag.
     */
    #[Test]
    public function freshly_generated_sql_is_still_verified_after_a_mismatch()
    {
        config(['jeeves.verification.enabled' => true]);
        config(['jeeves.verification.skip_on_cache_hit' => true]);

        $this->artisan('migrate', ['--force' => true])->run();
        $this->seedTables();
        $this->provider();

        $orchestrator = $this->app->make(QueryOrchestrator::class);
        $orchestrator->query('what is the total', 'nq_orders');
        $second = $orchestrator->query('what is the total', 'nq_products');

        // With cache_hit wrongly true, skip_on_cache_hit disables the
        // self-check on SQL the provider produced moments earlier.
        $this->assertFalse(
            $second['metadata']['cache_hit'] ?? false,
            'skip_on_cache_hit reads this flag, so a wrong value silently disables verification on generated SQL'
        );
    }

    /** A genuine repeat must still be a hit -  the fix must not blanket-clear it. */
    #[Test]
    public function an_identical_repeat_is_still_reported_as_a_hit()
    {
        $this->artisan('migrate', ['--force' => true])->run();
        $this->seedTables();
        $provider = $this->provider();

        $question = 'what is the total';
        $orchestrator = $this->app->make(QueryOrchestrator::class);

        $orchestrator->query($question, 'nq_orders');
        $callsAfterFirst = count($provider->methodsCalled());

        $second = $orchestrator->query($question, 'nq_orders');

        $this->assertSame(
            $callsAfterFirst,
            count($provider->methodsCalled()),
            'the repeat went to the provider; the cache is no longer hitting at all'
        );
        $this->assertTrue(
            $second['metadata']['cache_hit'] ?? false,
            'a real cache hit is no longer reported as one'
        );
    }
}
