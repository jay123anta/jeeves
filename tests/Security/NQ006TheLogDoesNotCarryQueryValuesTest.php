<?php

namespace Jayanta\Jeeves\Tests\Security;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Tests\Support\RecordingProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * NQ-006 - a failed query wrote the user's own words into the application log.
 *
 * When execution throws, the orchestrator logged `['sql' => $sql, 'error' =>
 * $e->getMessage()]`. Both carry values:
 *
 *  - On the `sql_generation` route the statement is written by the model with
 *    every literal inline (NQ-005), so a filter value the user typed is IN the
 *    SQL text.
 *  - Laravel appends `(Connection: sqlite, SQL: select ...)` to a QueryException
 *    message with the bindings already interpolated, so the raw driver message
 *    carries the statement too - which is exactly the text `sanitizeDbError()`
 *    exists to withhold from the user, written to disk instead.
 *
 * The application log is a different audience with a different retention
 * policy: it is shipped to log aggregators, read by people who were never
 * granted access to the data, and kept long after the request. "It is only in
 * the log" is a claim about who can read the log.
 *
 * Rule 2 is not breached - nothing here reaches a provider - but the same
 * principle applies one hop later, and the failure is quieter because nobody
 * is watching a log line the way they watch an API call.
 */
class NQ006TheLogDoesNotCarryQueryValuesTest extends TestCase
{
    /**
     * A value distinctive enough that finding it anywhere is proof, not
     * coincidence. It stands in for the thing a real question carries - a
     * customer name, an account number, a diagnosis.
     */
    private const SENSITIVE = 'Rekha-Stores-9f21-CONFIDENTIAL';

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.query_mode', 'sql_generation');
        $app['config']->set('jeeves.cache.enabled', false);
        $app['config']->set('jeeves.verification.enabled', false);
    }

    /**
     * One entry per log record, with its context flattened to strings.
     *
     * @return array<int, array{message: string, values: array<int, string>}>
     */
    private function captureLogWhile(callable $work): array
    {
        $records = [];

        Log::listen(function (MessageLogged $entry) use (&$records) {
            $values = [];

            array_walk_recursive($entry->context, function ($value) use (&$values) {
                if (is_scalar($value)) {
                    $values[] = (string) $value;
                }
            });

            $records[] = ['message' => $entry->message, 'values' => $values];
        });

        $work();

        return $records;
    }

    #[Test]
    public function a_failed_query_does_not_write_the_users_value_to_the_log(): void
    {
        Schema::create('orders', function ($t) {
            $t->id();
            $t->string('customer');
        });
        DB::table('orders')->insert(['customer' => 'someone else']);

        // The model writes a statement that will fail at execution - the column
        // does not exist - and carries the user's value inline, which is what
        // the sql_generation route always does.
        $provider = new RecordingProvider;
        $provider->sqlResponse = ['success' => true, 'data' => [
            'sql' => "SELECT COUNT(*) AS record_count FROM orders WHERE no_such_column = '" . self::SENSITIVE . "' LIMIT 50",
            'dataset' => 'test_orders',
            'metric' => 'record_count',
            'query_type' => 'aggregation',
        ]];
        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        $records = $this->captureLogWhile(function () {
            $this->app->make(QueryOrchestrator::class)
                ->query('how many orders for ' . self::SENSITIVE, 'test_orders');
        });

        $failures = array_values(array_filter(
            $records,
            static fn (array $r) => str_contains($r['message'], 'SQL execution failed')
        ));

        // Non-vacuity: the failure path must actually have been taken. Without
        // this, a run where the query unexpectedly SUCCEEDED - or never
        // executed - would log nothing and pass.
        $this->assertNotSame(
            [],
            $failures,
            'The execution-failure log line was never written, so this test did not '
            . 'observe the code path it is about. Fix the fixture before trusting it.'
        );

        $leaked = [];

        foreach ($failures as $record) {
            foreach ($record['values'] as $value) {
                if (str_contains($value, self::SENSITIVE)) {
                    $leaked[] = $value;
                }
            }
        }

        $this->assertSame(
            [],
            $leaked,
            "The user's value reached the application log:\n  " . implode("\n  ", $leaked)
        );

        // Redaction that removes the diagnosis along with the value is a
        // different failure, not a fix. The masked statement must still say
        // which table and which column, or the next person debugging this
        // turns the flag on in production and we are back where we started.
        $sqlLogged = implode(' ', $failures[0]['values']);

        $this->assertStringContainsString('orders', $sqlLogged, 'the masked statement lost the table');
        $this->assertStringContainsString('no_such_column', $sqlLogged, 'the masked statement lost the column');
        $this->assertStringContainsString('?', $sqlLogged, 'nothing was masked');
    }

    /**
     * The opt-in, pinned. An adopter debugging a specific failure can have the
     * whole statement, and the config comment says what that costs.
     */
    #[Test]
    public function the_debug_flag_restores_the_full_statement(): void
    {
        config(['jeeves.sql.log_failing_statement' => true]);

        Schema::create('orders', function ($t) {
            $t->id();
            $t->string('customer');
        });

        $provider = new RecordingProvider;
        $provider->sqlResponse = ['success' => true, 'data' => [
            'sql' => "SELECT COUNT(*) AS record_count FROM orders WHERE no_such_column = '" . self::SENSITIVE . "' LIMIT 50",
            'dataset' => 'test_orders',
            'metric' => 'record_count',
            'query_type' => 'aggregation',
        ]];
        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        $records = $this->captureLogWhile(function () {
            $this->app->make(QueryOrchestrator::class)->query('how many orders', 'test_orders');
        });

        $failures = array_values(array_filter(
            $records,
            static fn (array $r) => str_contains($r['message'], 'SQL execution failed')
        ));

        $this->assertNotSame([], $failures, 'the execution-failure line was never written');

        $this->assertStringContainsString(
            self::SENSITIVE,
            implode(' ', $failures[0]['values']),
            'the debug flag was set and the statement was still masked'
        );
    }

    /**
     * And the driver's raw message never goes to the log whatever the flag
     * says. It repeats the statement with the bindings interpolated and the
     * database file path, and the flag is about the statement, not about that.
     */
    #[Test]
    public function the_raw_driver_message_is_never_logged_even_with_the_flag_on(): void
    {
        config(['jeeves.sql.log_failing_statement' => true]);

        Schema::create('orders', function ($t) {
            $t->id();
            $t->string('customer');
        });

        $provider = new RecordingProvider;
        $provider->sqlResponse = ['success' => true, 'data' => [
            'sql' => 'SELECT COUNT(*) AS record_count FROM orders WHERE no_such_column = 1 LIMIT 50',
            'dataset' => 'test_orders',
            'metric' => 'record_count',
            'query_type' => 'aggregation',
        ]];
        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        $records = $this->captureLogWhile(function () {
            $this->app->make(QueryOrchestrator::class)->query('how many orders', 'test_orders');
        });

        $failures = array_values(array_filter(
            $records,
            static fn (array $r) => str_contains($r['message'], 'SQL execution failed')
        ));

        $this->assertNotSame([], $failures, 'the execution-failure line was never written');

        $logged = implode(' ', $failures[0]['values']);

        $this->assertStringNotContainsString(
            'Connection:',
            $logged,
            "Laravel's own suffix reached the log, which carries the interpolated "
            . 'statement and the database path'
        );
    }
}
