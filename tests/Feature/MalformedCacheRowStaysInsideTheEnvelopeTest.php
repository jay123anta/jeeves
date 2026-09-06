<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Tests\Support\RecordingProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * A bad row in the cache must not become a framework 500.
 *
 * query() wrapped everything in `catch (\Exception)`. A TypeError extends
 * \Error, not \Exception, so it went straight past -  taking the whole error
 * envelope with it. The caller got a stack trace instead of a Jeeves
 * response: no `error_code` to branch on, no `retryable`, no Retry-After, and
 * the QuestionFailed event never fired.
 *
 * Reaching it needs no bug in the caller, only a row whose `intent` column is
 * present and is not an array -  a truncated write, a hand-edited row, a
 * restored backup from a different schema, a third-party cache returning the
 * decoded blob in a different shape. normalizeIntent(array $intent) then
 * throws. Tier 2 rows have no expiry, so that question stays a hard 500
 * forever, for everyone, with nothing in the response to say why.
 */
class MalformedCacheRowStaysInsideTheEnvelopeTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/fuzzy-cache-isolation-schemas');
        $app['config']->set('jeeves.cache.enabled', true);
        $app['config']->set('jeeves.query_mode', 'intent');
    }

    #[Test]
    public function a_cached_intent_of_the_wrong_shape_returns_an_error_response_not_a_crash()
    {
        $this->artisan('migrate', ['--force' => true])->run();

        Schema::dropIfExists('nq_orders');
        Schema::create('nq_orders', function ($t) {
            $t->id();
            $t->decimal('revenue', 12, 2);
        });
        DB::table('nq_orders')->insert(['revenue' => 100]);

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

        $orchestrator = $this->app->make(QueryOrchestrator::class);
        $question = 'total revenue';

        // Asked with NO dataset hint, against two registered datasets, so
        // resolveAskingDataset() cannot place it and the asking scope is null.
        // That is what makes the bad row reachable: the entry guard reads
        // `$cached['intent']['_asking_scope'] ?? null`, which on a string
        // intent yields null, matches a null asking scope, and passes the row
        // straight to normalizeIntent(array $intent). With a hint the guard
        // discards it first and the crash never happens -  which is why this
        // has to be the unhinted route.
        //
        // Warm the cache the ordinary way, then corrupt the stored blob so the
        // row is present, current, and the wrong shape.
        $orchestrator->query($question);

        $table = config('jeeves.cache.table_name', 'jeeves_cache');
        DB::table($table)->update(['intent' => json_encode('not an array')]);

        // Tier 1 would serve the good copy from memory and hide it.
        $this->app->make(Factory::class)->store()->flush();

        $result = $orchestrator->query($question);

        // A MISS, not an error. This assertion was originally 'error' with
        // error_code internal_error, and that was an improvement on the
        // uncaught TypeError it replaced but still the wrong answer: Tier 2
        // rows have no expiry and the failing row was never rewritten, so the
        // question stayed permanently unanswerable, for everyone, with a
        // message naming nothing anyone could act on.
        //
        // A row the engine cannot read is a row the engine does not have. It
        // costs one API call, the answer is correct, and the bad row is
        // overwritten on the way past. The \Throwable catch stays as a
        // backstop for everything else.
        $this->assertIsArray($result);
        $this->assertSame(
            'success',
            $result['status'] ?? null,
            'an unreadable cache row still breaks its question instead of being ignored: ' . json_encode($result)
        );
        $this->assertFalse($result['metadata']['cache_hit'] ?? true);

        // And it does not stay broken: the next ask is served normally.
        $again = $orchestrator->query($question);
        $this->assertSame('success', $again['status'] ?? null, json_encode($again));
    }
}
