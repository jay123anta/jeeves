<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Contracts\QueryCacheInterface;
use Jayanta\Jeeves\Engine\ErrorCode;
use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Tests\Support\RecordingProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * A refusal must name the thing that is actually broken.
 *
 * An adopter renames a table and updates their schema file. A recipe cached
 * under the old name is replayed, the validator refuses it against the
 * schema-derived whitelist - correctly, and with a message that says exactly
 * that - and the message was then thrown away and replaced with:
 *
 *   "Could not understand the query. Try mentioning a dataset name.
 *    Available: Test Orders (test_orders), Test Districts (test_districts)"
 *
 * The question was read perfectly. The dataset IS named. Following the advice
 * cannot work, and the real cause - a stale cache row naming a table that no
 * longer exists - is invisible. Rows carry no expiry, so it says this for ever
 * at zero provider calls, which is the shape Rule 0 names by name.
 *
 * The replacement happens in retryWithRefinedPrompt, and only AFTER its
 * regeneration strategy has declined, so nothing here weakens that recovery.
 */
class AStaleRecipeSaysWhatIsWrongTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        // Two datasets, so nothing is auto-detected as "the only one", and no
        // required_filter, so the validator is the first thing to object.
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/schemas');
        $app['config']->set('jeeves.cache.enabled', true);
        $app['config']->set('jeeves.verification.enabled', false);
        $app['config']->set('jeeves.query_mode', 'sql_generation');
    }

    /** Words that name no dataset, so the retry cannot regenerate its way out. */
    private const QUESTION = 'grand sum please';

    private function plantRecipeNamingAMissingTable(): void
    {
        $this->app->make(QueryCacheInterface::class)->store(self::QUESTION, [
            'dataset' => 'test_orders',
            'metric' => 'total',
            'query_type' => 'aggregation',
            '_asking_scope' => null,
            '_sql_result' => [
                'success' => true,
                // The table this recipe was cached under. The schema now names
                // public.orders, so the whitelist refuses this one.
                'sql' => 'SELECT SUM(total) AS total FROM public.orders_2024',
                'bindings' => [],
                'dataset' => 'test_orders',
                'dataset_name' => 'Test Orders',
                'metric' => 'total',
                'query_type' => 'aggregation',
                'limit' => 10,
                'order' => 'DESC',
            ],
        ]);
    }

    #[Test]
    public function a_recipe_the_whitelist_refuses_is_reported_as_refused_sql()
    {
        $this->artisan('migrate', ['--force' => true])->run();
        $this->plantRecipeNamingAMissingTable();

        $provider = new RecordingProvider;
        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        $result = $this->app->make(QueryOrchestrator::class)->query(self::QUESTION);

        $this->assertSame('error', $result['status'] ?? null, json_encode($result));

        $this->assertSame(
            ErrorCode::UNSAFE_SQL,
            $result['error_code'] ?? null,
            'the honest refusal was replaced by a verdict on the wording: ' . json_encode($result)
        );

        $this->assertStringNotContainsString(
            'Could not understand the query',
            $result['message'] ?? '',
            'the user was told their question was the problem when a stale cache row was'
        );

        // The recovery this path offers is rewording, and rewording cannot fix
        // a cached row. Saying so for free is right; saying the wrong thing for
        // free is what this test exists for.
        $this->assertSame(
            [],
            $provider->methodsCalled(),
            'a refusal that rewording cannot fix should not also cost a provider call'
        );
    }

    /**
     * The counterweight. Marking every unsafe-SQL refusal unretriable would
     * also pass the test above, and would kill the recovery that makes model
     * SQL naming a bad column answer correctly on the second call.
     */
    #[Test]
    public function a_question_that_names_its_dataset_is_still_regenerated()
    {
        $this->artisan('migrate', ['--force' => true])->run();

        $question = 'total for test orders';

        $this->app->make(QueryCacheInterface::class)->store($question, [
            'dataset' => 'test_orders',
            'metric' => 'total',
            'query_type' => 'aggregation',
            '_asking_scope' => null,
            '_sql_result' => [
                'success' => true,
                'sql' => 'SELECT SUM(total) AS total FROM public.orders_2024',
                'bindings' => [],
                'dataset' => 'test_orders',
                'dataset_name' => 'Test Orders',
                'metric' => 'total',
                'query_type' => 'aggregation',
                'limit' => 10,
                'order' => 'DESC',
            ],
        ]);

        $provider = new RecordingProvider;
        $provider->sqlResponse = [
            'success' => true,
            'data' => [
                'sql' => 'SELECT SUM(total) AS total FROM public.orders',
                'dataset' => 'test_orders',
                'metric' => 'total',
                'query_type' => 'aggregation',
            ],
        ];
        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        $this->app->make(QueryOrchestrator::class)->query($question);

        $this->assertNotEmpty(
            $provider->methodsCalled(),
            'a stale recipe whose dataset IS identifiable must still be regenerated rather '
                . 'than refused outright'
        );
    }
}
