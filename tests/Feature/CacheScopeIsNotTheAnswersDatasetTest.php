<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Contracts\QueryCacheInterface;
use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Tests\Support\RecordingProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * One column was doing two incompatible jobs.
 *
 * `dataset` holds the dataset the ANSWER turned out to be about -  the one the
 * model chose -  and jeeves:cache-cleanup --dataset and the stats command
 * both read it that way. The dataset-isolation fix then reused it for
 * something else: deciding whether a row is eligible to answer THIS question,
 * by comparing it against what the asker's scope resolves to.
 *
 * Those are different values and conflating them broke both jobs.
 *
 * COST. An unhinted question on a multi-dataset install resolves to no asking
 * scope -  resolveAskingDataset() returns null once keyword detection fails and
 * more than one dataset is registered. Its own cached row carries the dataset
 * the model picked, which is never null. So the row mismatches the very
 * question that created it, every repeat pays for a fresh API call, and the
 * cache is off for exactly the questions users repeat most. That was a
 * regression introduced by the isolation fix, not a pre-existing defect.
 *
 * STALENESS. store()'s update branch rewrites `intent` and `contract_version`
 * and nothing else, so once the same wording is asked about a second dataset
 * the column keeps the first answer's value forever. cache-cleanup --dataset
 * then deletes nothing and the documented remedy for a stale answer silently
 * does not work.
 *
 * The fix records the asker's SCOPE separately, in the intent blob under
 * `_asking_scope` -  no new column, and no change to QueryCacheInterface, which
 * a previous attempt broke for every third-party implementor by adding a
 * parameter. `dataset` goes back to meaning the answer's dataset, and is now
 * kept current on update.
 */
class CacheScopeIsNotTheAnswersDatasetTest extends TestCase
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

    /**
     * THE COST. Two datasets are registered and the question names neither, so
     * there is no asking scope on either ask. Both asks carry the SAME scope
     * -  none -  so the second must be served from the first.
     */
    #[Test]
    public function an_unscoped_question_repeated_verbatim_hits_its_own_cached_row()
    {
        $this->artisan('migrate', ['--force' => true])->run();
        $this->seedTables();
        $provider = $this->provider();

        $orchestrator = $this->app->make(QueryOrchestrator::class);
        $question = 'give me the overall figure please';

        $first = $orchestrator->query($question);
        $this->assertSame('success', $first['status'] ?? null, json_encode($first));
        $callsAfterFirst = count($provider->methodsCalled());

        $second = $orchestrator->query($question);

        $this->assertSame(
            $callsAfterFirst,
            count($provider->methodsCalled()),
            'an unscoped question never hits its own cached row on a multi-dataset install, so every '
                . 'repeat pays for another API call -  the cache is off for the questions users repeat most'
        );
        $this->assertTrue($second['metadata']['cache_hit'] ?? false);
    }

    /**
     * THE GUARANTEE IT MUST NOT COST. A row cached under an explicit scope is
     * still not eligible for the same words asked without one. This is the
     * widget's ordinary shape and the reason the guard exists at all.
     */
    #[Test]
    public function a_scoped_row_is_still_refused_to_an_unscoped_question()
    {
        $this->artisan('migrate', ['--force' => true])->run();
        $this->seedTables();
        $provider = $this->provider();

        $orchestrator = $this->app->make(QueryOrchestrator::class);
        $question = 'what is the total please';

        $orchestrator->query($question, 'nq_products');
        $callsAfterFirst = count($provider->methodsCalled());

        $unscoped = $orchestrator->query($question);

        $this->assertGreaterThan(
            $callsAfterFirst,
            count($provider->methodsCalled()),
            'a row cached under an explicit dataset scope was replayed to a question that carried no scope'
        );
        $this->assertFalse($unscoped['metadata']['cache_hit'] ?? false);
    }

    /**
     * THE STALENESS. cache-cleanup --dataset is the documented remedy for a
     * stale cached answer. It reads the `dataset` column, so the column has to
     * describe the answer currently stored, not the one that was stored first.
     */
    #[Test]
    public function the_dataset_column_follows_the_answer_when_a_row_is_rewritten()
    {
        $this->artisan('migrate', ['--force' => true])->run();
        $this->seedTables();

        $cache = $this->app->make(QueryCacheInterface::class);
        $table = config('jeeves.cache.table_name', 'jeeves_cache');
        $question = 'the usual summary';

        $cache->store($question, ['dataset' => 'nq_orders', 'metric' => 'revenue', 'query_type' => 'aggregation']);
        $this->assertSame('nq_orders', DB::table($table)->where('original_query', $question)->value('dataset'));

        // Same wording, now answered about the other dataset.
        $cache->store($question, ['dataset' => 'nq_products', 'metric' => 'revenue', 'query_type' => 'aggregation']);

        $this->assertSame(
            'nq_products',
            DB::table($table)->where('original_query', $question)->value('dataset'),
            'the dataset column kept the first answer\'s value, so cache-cleanup --dataset=nq_products '
                . 'deletes nothing and the documented remedy for a stale answer does not work'
        );
    }
}
