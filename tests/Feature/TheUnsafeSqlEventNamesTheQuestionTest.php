<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Events\UnsafeSqlRejected;
use Jayanta\Jeeves\Tests\Support\RecordingProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * `UnsafeSqlRejected` carries the question, which is the half that tells you
 * what happened.
 *
 * The event is the package's security signal: it fires when generated SQL is
 * refused by the validator, and a burst of them from one user is the thing
 * worth looking at. Its own docblock calls the question "usually the more
 * telling half" - the SQL says what was attempted, the question says what the
 * person typed to get there.
 *
 * It was always empty. The field reads
 * `$metadata['original_query'] ?? $queryResult['question'] ?? ''`, and neither
 * is set at that point: the controller writes `original_query` into metadata
 * AFTER the orchestrator has returned, and `question` belongs to the steps of a
 * decomposed answer rather than to a query result. So every listener anyone
 * wrote against this event received a blank string for the field the event
 * exists to carry.
 */
class TheUnsafeSqlEventNamesTheQuestionTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.query_mode', 'sql_generation');
        $app['config']->set('jeeves.cache.enabled', false);
        $app['config']->set('jeeves.verification.enabled', false);
    }

    #[Test]
    public function the_event_carries_the_question_that_produced_the_sql(): void
    {
        Event::fake([UnsafeSqlRejected::class]);

        $provider = new RecordingProvider;
        $provider->sqlResponse = ['success' => true, 'data' => [
            // A table that is not in the schema whitelist, so the validator
            // refuses it and the event fires.
            'sql' => 'SELECT * FROM secret_admin_table LIMIT 50',
            'dataset' => 'test_orders',
            'metric' => 'record_count',
            'query_type' => 'aggregation',
        ]];
        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        $this->app->make(QueryOrchestrator::class)
            ->query('how many orders are in the secret table', 'test_orders');

        Event::assertDispatched(UnsafeSqlRejected::class, function (UnsafeSqlRejected $e) {
            $this->assertNotSame(
                '',
                $e->question,
                'the security event fired with an empty question - the field its own '
                . 'docblock calls "usually the more telling half"'
            );

            $this->assertStringContainsString('secret table', $e->question);

            // And the other half is still there, so this did not pass by the
            // event having changed shape.
            $this->assertStringContainsString('secret_admin_table', $e->sql);

            return true;
        });
    }
}
