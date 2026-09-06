<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Contracts\QueryCacheInterface;
use Jayanta\Jeeves\Engine\IntentCoverage;
use Jayanta\Jeeves\Tests\Support\RecordingProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Three claims made by this release that the code does not keep.
 *
 * A. `jeeves:debug --execute` prints "This prompt would be REFUSED, not
 *    sent:" and then sends it. The refusal notice was added to stop the
 *    debugger showing something other than what happens, and it does not gate
 *    the send it describes.
 *
 * B. In the shipped default `query_mode: auto`, most questions are answered by
 *    intent parsing, whose prompt is built inside the provider. The debugger
 *    always builds a SQL-generation prompt, so it shows one the engine never
 *    sends -  and then, new in this release, declares it REFUSED for a question
 *    the engine answers without difficulty, because prompts.max_chars does not
 *    apply to intent mode at all.
 *
 * C. `jeeves:cache-cleanup --dataset=X` still deletes nothing for any row
 *    in active use. clear() ANDs its filters and the command passes its
 *    --days=30 and --min-hits=2 defaults alongside --dataset, so only rows that
 *    are simultaneously stale AND barely used are removed. docs/CACHING.md
 *    recommends the flag for exactly the opposite case -  a schema file changed
 *    and its cached answers are now wrong -  where the rows are being hit daily.
 */
class DebugAndCleanupTellTheTruthTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/single-dataset-schemas');
        $app['config']->set('jeeves.cache.enabled', true);
    }

    /** A. A refused prompt must not then be sent. */
    #[Test]
    public function debug_execute_does_not_send_a_prompt_it_just_called_refused()
    {
        config([
            'jeeves.query_mode' => 'sql_generation',
            'jeeves.prompts.max_chars' => 50,
        ]);

        $provider = new RecordingProvider;
        $this->app->instance(LlmProviderInterface::class, $provider);

        $this->artisan('jeeves:debug', ['query' => 'total revenue', '--execute' => true])
            ->assertExitCode(0);

        $this->assertSame(
            [],
            $provider->methodsCalled(),
            'the debugger printed "would be REFUSED, not sent" and then sent it'
        );
    }

    /**
     * B. In auto mode a question the intent contract covers is answered by
     *    intent parsing, and max_chars never applies to it. Saying it would be
     *    refused is telling the user their question will fail when it will not.
     */
    #[Test]
    public function debug_does_not_claim_a_refusal_that_auto_mode_would_never_hit()
    {
        config([
            'jeeves.query_mode' => 'auto',
            'jeeves.prompts.max_chars' => 50,
        ]);

        $coverage = $this->app->make(IntentCoverage::class);
        $question = 'total revenue';
        $this->assertNull(
            $coverage->exceeds($question),
            'precondition: this question stays inside the intent contract, so auto mode never builds a SQL prompt for it'
        );

        $this->artisan('jeeves:debug', ['query' => $question])
            ->doesntExpectOutputToContain('would be REFUSED')
            ->assertExitCode(0);
    }

    /** C. Purging a dataset must purge the dataset. */
    #[Test]
    public function cache_cleanup_by_dataset_removes_rows_that_are_in_active_use()
    {
        $this->artisan('migrate', ['--force' => true])->run();

        $table = config('jeeves.cache.table_name', 'jeeves_cache');
        $cache = $this->app->make(QueryCacheInterface::class);

        $cache->store('the usual summary', ['dataset' => 'nq_orders', 'metric' => 'revenue']);

        // Fresh and popular: exactly the row someone runs --dataset to purge
        // after changing a schema file, and exactly the one the day/hit
        // defaults protect.
        DB::table($table)->update(['hit_count' => 25, 'last_hit_at' => now()]);
        $this->assertSame(1, DB::table($table)->where('dataset', 'nq_orders')->count());

        $this->artisan('jeeves:cache-cleanup', ['--dataset' => 'nq_orders'])->assertExitCode(0);

        $this->assertSame(
            0,
            DB::table($table)->where('dataset', 'nq_orders')->count(),
            'purging a dataset left its rows in place, because --days=30 and --min-hits=2 were ANDed in '
                . 'behind the user\'s back -  so the documented remedy for a stale answer still does nothing'
        );
    }

    /** And a bare run must keep its documented conservative defaults. */
    #[Test]
    public function a_bare_cleanup_still_only_removes_old_and_unused_rows()
    {
        $this->artisan('migrate', ['--force' => true])->run();

        $table = config('jeeves.cache.table_name', 'jeeves_cache');
        $cache = $this->app->make(QueryCacheInterface::class);

        $cache->store('busy question', ['dataset' => 'nq_orders', 'metric' => 'revenue']);
        DB::table($table)->update(['hit_count' => 25, 'last_hit_at' => now()]);

        $this->artisan('jeeves:cache-cleanup')->assertExitCode(0);

        $this->assertSame(
            1,
            DB::table($table)->count(),
            'a bare cleanup deleted a fresh, popular row; it is documented to remove only what is both old and unused'
        );
    }
}
