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
 * The rule must hold when the schema declares a qualified table name.
 *
 * `jeeves:discover` stores whatever the introspector reports, and
 * PostgresIntrospector reports `schema.table` -  so on the platform this
 * package was extracted from, `tables.primary.name` is `public.orders`, not
 * `orders`. The validator and `SchemaRegistry::allowsTable()` deliberately
 * accept a BARE reference against a qualified whitelist entry, because a model
 * routinely drops the qualifier and refusing that would be useless.
 *
 * Which means a rule matched by searching the SQL text for the declared name
 * never fired: `public.orders` does not appear in `SELECT … FROM orders`. The
 * query passed validation, missed the rule entirely, and returned a total
 * including every row the adopter said must never be counted -  while the
 * previous, cruder, dataset-keyed check had refused it.
 *
 * A fix that quietly withdraws protection on the most affected platform is
 * worse than the defect it replaces, because it looks like progress.
 *
 * Fixture: 100 paid + 200 paid + 500 cancelled. The rule makes the answer 300;
 * without it, 800. The schema declares `main.qt_orders`; SQLite's default
 * schema is `main`, so this is the same shape as Postgres.
 */
class RequiredFilterSurvivesQualifiedNamesTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/qualified-table-schemas');
        $app['config']->set('jeeves.cache.enabled', false);
        $app['config']->set('jeeves.verification.enabled', false);
        $app['config']->set('jeeves.query_mode', 'sql_generation');
    }

    private function seedOrders(): void
    {
        Schema::dropIfExists('qt_orders');
        Schema::create('qt_orders', function ($t) {
            $t->id();
            $t->string('status');
            $t->decimal('revenue', 12, 2);
        });

        foreach ([['paid', 100], ['paid', 200], ['cancelled', 500]] as [$s, $r]) {
            DB::table('qt_orders')->insert(['status' => $s, 'revenue' => $r]);
        }
    }

    private function answer(string $sql): array
    {
        $provider = new RecordingProvider;
        $provider->sqlResponse = ['success' => true, 'data' => [
            'sql' => $sql,
            'dataset' => 'qt_orders',
            'metric' => 'revenue',
            'query_type' => 'aggregation',
        ]];
        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        return $this->app->make(QueryOrchestrator::class)->query('total revenue', 'qt_orders');
    }

    private function total(array $r): float
    {
        $row = $r['rows'][0] ?? null;

        return (float) (is_array($row) ? ($row['revenue'] ?? 0) : ($row->revenue ?? 0));
    }

    /** THE REGRESSION: bare reference, qualified declaration. */
    #[Test]
    public function a_bare_reference_still_arms_a_qualified_rule()
    {
        $this->seedOrders();

        $result = $this->answer('SELECT SUM(revenue) AS revenue FROM qt_orders');

        $this->assertNotEquals(
            800.0,
            $this->total($result),
            'the schema declares main.qt_orders and the model wrote FROM qt_orders, so the rule '
                . 'never matched and every cancelled row counted -  the exact arrangement '
                . 'jeeves:discover produces on PostgreSQL'
        );
    }

    /** And the qualified reference, which is what discover's own prompt encourages. */
    #[Test]
    public function a_qualified_reference_arms_a_qualified_rule()
    {
        $this->seedOrders();

        $result = $this->answer('SELECT SUM(revenue) AS revenue FROM main.qt_orders');

        $this->assertNotEquals(800.0, $this->total($result));
    }

    /** Obeying it, written either way, still answers. */
    #[Test]
    public function an_obedient_query_is_served()
    {
        $this->seedOrders();

        $result = $this->answer("SELECT SUM(revenue) AS revenue FROM qt_orders WHERE status != 'cancelled'");

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        $this->assertEquals(300.0, $this->total($result));
    }
}
