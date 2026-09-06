<?php

namespace Jayanta\Jeeves\Tests\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Tests\Support\RecordingProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * NQ-001 — reproduction.
 *
 * `sql.database_connection` ships as `null`, and `QueryOrchestrator` falls
 * through to `DB::select()` — the application's own connection, with whatever
 * privileges the app writes with. Model-authored SQL therefore executes with
 * write access, and SELECT-only in `SqlValidator` is the entire thing standing
 * between a validator bypass and a write.
 *
 * The assertion is deliberately about the **resolved connection**, not about
 * one line of code. A guard bolted to the single call site that executes SQL
 * today leaves the next call site free to reintroduce the hole while this test
 * stays green — which is this project's signature defect, found three times
 * already. So the question asked here is "which connection did the query
 * actually run on", answered by listening to the query event.
 *
 * Three ways the guarantee can be violated, all covered:
 *   1. no connection configured at all
 *   2. one configured, but naming the application's default
 *   3. (privileges) — deferred to `jeeves:doctor`, which proves on MySQL
 *      and PostgreSQL that the connection cannot write by trying to create a
 *      table on it. Not assertable here: SQLite has no users or grants.
 */
class NQ001GeneratedSqlNeverRunsOnTheAppConnectionTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // A second, distinct connection the package is allowed to use.
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/period-carry-schemas');

        $app['config']->set('database.connections.nq_readonly', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    private function seedOrders(): void
    {
        Schema::dropIfExists('ps_orders');
        Schema::create('ps_orders', function ($t) {
            $t->id();
            $t->string('region');
            $t->date('placed_on');
            $t->decimal('revenue', 12, 2);
        });
        DB::table('ps_orders')->insert(['region' => 'West', 'placed_on' => '2026-07-01', 'revenue' => 100]);
    }

    /** @return array{0: array, 1: array<int, string|null>} result, connections actually used */
    private function askAndRecordConnections(): array
    {
        $used = [];
        DB::listen(function ($q) use (&$used) {
            $used[] = $q->connectionName;
        });

        $provider = new RecordingProvider;
        $provider->sqlResponse = [
            'success' => true,
            'data' => [
                'sql' => 'SELECT SUM(revenue) AS revenue FROM ps_orders',
                'dataset' => 'ps_orders',
                'metric' => 'revenue',
                'query_type' => 'aggregation',
            ],
        ];
        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        $result = $this->app->make(QueryOrchestrator::class)->query('total amount');

        return [$result, $used];
    }

    #[Test]
    public function generated_sql_does_not_execute_when_no_connection_is_configured()
    {
        $this->seedOrders();
        config(['jeeves.sql.database_connection' => null]);

        [$result, $used] = $this->askAndRecordConnections();

        $default = config('database.default');

        $this->assertNotContains(
            $default,
            $used,
            "NQ-001: generated SQL executed on the application's own connection ('{$default}'), "
                . 'which is the connection the app writes with. sql.database_connection is null '
                . 'and nothing refused. Connections used: ' . json_encode($used)
        );

        $this->assertSame('error', $result['status'] ?? null, 'the question should have been refused');
    }

    #[Test]
    public function generated_sql_does_not_execute_when_the_configured_connection_is_the_app_default()
    {
        $this->seedOrders();

        // Configured, but naming the application's own connection - the same
        // hole with a value in the config file, which reads as safe.
        config(['jeeves.sql.database_connection' => config('database.default')]);

        [$result, $used] = $this->askAndRecordConnections();

        $default = config('database.default');

        $this->assertNotContains(
            $default,
            $used,
            "NQ-001: sql.database_connection names the application's default ('{$default}'), so "
                . 'generated SQL still runs with the app\'s write privileges. Connections used: '
                . json_encode($used)
        );

        $this->assertSame('error', $result['status'] ?? null, 'the question should have been refused');
    }

    /**
     * THE COUNTERWEIGHT. Refusing everything would satisfy both tests above.
     * A properly configured, distinct connection must still work.
     */
    #[Test]
    public function a_distinct_configured_connection_is_used_and_the_question_is_answered()
    {
        config(['jeeves.sql.database_connection' => 'nq_readonly']);

        Schema::connection('nq_readonly')->dropIfExists('ps_orders');
        Schema::connection('nq_readonly')->create('ps_orders', function ($t) {
            $t->id();
            $t->string('region');
            $t->date('placed_on');
            $t->decimal('revenue', 12, 2);
        });
        DB::connection('nq_readonly')->table('ps_orders')
            ->insert(['region' => 'West', 'placed_on' => '2026-07-01', 'revenue' => 100]);

        [$result, $used] = $this->askAndRecordConnections();

        $this->assertSame(
            'success',
            $result['status'] ?? null,
            'a correctly configured connection was refused: ' . json_encode($result)
        );
        $this->assertContains('nq_readonly', $used, 'the configured connection was not the one used');
    }
}
