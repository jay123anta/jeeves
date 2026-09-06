<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Jayanta\Jeeves\Schema\Introspectors\SqliteIntrospector;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * SQLite is what `laravel new` creates, so it is the database most people who
 * try this package already have. Requiring them to install MySQL before the
 * package would do anything at all was the single biggest barrier to a first
 * successful install.
 *
 * Run against a real SQLite database, not a mock: PRAGMA output is the thing
 * being parsed, and a hand-written fixture of what PRAGMA "probably returns"
 * would test nothing but my assumptions.
 */
class SqliteIntrospectorTest extends TestCase
{
    private SqliteIntrospector $introspector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->introspector = $this->app->make(SqliteIntrospector::class);

        // Raw DDL rather than the schema builder: the point is to control the
        // DECLARED types and the exact foreign key forms, including the ones
        // Laravel's builder would never produce.
        DB::statement('CREATE TABLE sq_customers (
            id INTEGER PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            joined_on DATE
        )');

        DB::statement('CREATE TABLE sq_orders (
            id INTEGER PRIMARY KEY,
            customer_id INTEGER,
            notes TEXT,
            quantity INT DEFAULT 0,
            unit_price NUMERIC(10,2),
            weight REAL,
            is_paid BOOLEAN,
            payload BLOB,
            ordered_at DATETIME,
            order_date DATE,
            untyped,
            FOREIGN KEY (customer_id) REFERENCES sq_customers(id)
        )');

        // A foreign key with the target column OMITTED -  meaning "the primary
        // key". Common in hand-written SQLite and the form that produced a
        // JOIN with nothing on one side.
        DB::statement('CREATE TABLE sq_implicit (
            id INTEGER PRIMARY KEY,
            customer_id INTEGER REFERENCES sq_customers
        )');

        // A two-column primary key, and a composite foreign key onto it.
        DB::statement('CREATE TABLE sq_regions (
            tenant_id INTEGER NOT NULL,
            region_code VARCHAR(10) NOT NULL,
            region_name VARCHAR(80),
            PRIMARY KEY (tenant_id, region_code)
        )');

        DB::statement('CREATE TABLE sq_sales (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER,
            region_code VARCHAR(10),
            revenue NUMERIC(12,2),
            FOREIGN KEY (tenant_id, region_code) REFERENCES sq_regions(tenant_id, region_code)
        )');

        DB::statement('CREATE VIEW sq_paid_orders AS SELECT * FROM sq_orders WHERE is_paid = 1');
        DB::statement('CREATE UNIQUE INDEX sq_orders_customer_unique ON sq_orders (customer_id, order_date)');
    }

    #[Test]
    public function it_lists_tables_and_views_but_not_sqlite_internals()
    {
        $names = array_column($this->introspector->listTables(), 'name');

        $this->assertContains('sq_orders', $names);
        $this->assertContains('sq_paid_orders', $names);

        foreach ($names as $name) {
            $this->assertStringNotContainsString('sqlite_', $name);
        }
    }

    #[Test]
    public function a_view_is_labelled_as_a_view()
    {
        $tables = collect($this->introspector->listTables())->keyBy('name');

        $this->assertSame('table', $tables['sq_orders']['type']);
        $this->assertSame('view', $tables['sq_paid_orders']['type']);
    }

    /**
     * SQLite types are advisory and wildly inconsistent in the wild. They are
     * normalised by affinity, with date and boolean checked first -  both have
     * NUMERIC affinity, but a DATE column is meant as a date and the time
     * filter depends on recognising it.
     */
    #[Test]
    public function declared_types_are_normalised_by_affinity()
    {
        $columns = collect($this->introspector->getColumns('sq_orders'))->keyBy('name');

        $this->assertSame('integer', $columns['quantity']['type'], 'INT');
        $this->assertSame('varchar', $columns['notes']['type'], 'TEXT');
        $this->assertSame('decimal', $columns['unit_price']['type'], 'NUMERIC(10,2)');
        $this->assertSame('decimal', $columns['weight']['type'], 'REAL');
        $this->assertSame('boolean', $columns['is_paid']['type'], 'BOOLEAN');
        $this->assertSame('blob', $columns['payload']['type'], 'BLOB');
        $this->assertSame('timestamp', $columns['ordered_at']['type'], 'DATETIME');
        $this->assertSame('date', $columns['order_date']['type'], 'DATE');
        $this->assertSame('varchar', $columns['untyped']['type'], 'no declared type at all');
    }

    #[Test]
    public function a_date_column_is_recognised_so_periods_can_be_applied()
    {
        // The whole time-filter feature depends on this one mapping.
        $columns = collect($this->introspector->getColumns('sq_orders'))->keyBy('name');

        $this->assertSame('date', $columns['order_date']['type']);
    }

    #[Test]
    public function column_facts_come_through()
    {
        $columns = collect($this->introspector->getColumns('sq_customers'))->keyBy('name');

        $this->assertTrue($columns['id']['is_primary']);
        $this->assertFalse($columns['name']['is_primary']);
        $this->assertFalse($columns['name']['nullable']);
        $this->assertTrue($columns['joined_on']['nullable']);
        $this->assertSame(120, $columns['name']['max_length']);
    }

    #[Test]
    public function a_foreign_key_is_not_classified_as_a_number_to_total()
    {
        $columns = collect($this->introspector->getColumns('sq_orders'))->keyBy('name');

        $this->assertSame('foreign_key', $columns['customer_id']['suggested_role']);
        $this->assertSame('measure', $columns['quantity']['suggested_role']);
    }

    #[Test]
    public function an_explicit_foreign_key_is_read()
    {
        $fks = $this->introspector->getRelationships('sq_orders');

        $this->assertCount(1, $fks);
        $this->assertSame('customer_id', $fks[0]['column']);
        $this->assertSame('sq_customers', $fks[0]['referenced_table']);
        $this->assertSame('id', $fks[0]['referenced_column']);
    }

    /**
     * `REFERENCES sq_customers` with no column means the primary key. PRAGMA
     * reports NULL there, and an unresolved NULL becomes a JOIN condition with
     * nothing on the right-hand side.
     */
    #[Test]
    public function a_foreign_key_with_an_implicit_target_resolves_to_the_primary_key()
    {
        $fks = $this->introspector->getRelationships('sq_implicit');

        $this->assertCount(1, $fks);
        $this->assertSame('id', $fks[0]['referenced_column']);
        $this->assertNotNull($fks[0]['referenced_column']);
    }

    /**
     * A composite key must come back as ONE constraint with its columns paired
     * by position. Two constraints, or a mispaired one, produces a join on half
     * a key -  which matches rows it should not and inflates every total.
     */
    #[Test]
    public function a_composite_foreign_key_is_reported_as_one_constraint()
    {
        $fks = $this->introspector->getRelationships('sq_sales');

        $this->assertCount(2, $fks);

        $constraints = array_unique(array_column($fks, 'constraint_name'));
        $this->assertCount(1, $constraints, 'both columns belong to the same key');

        $pairs = [];
        foreach ($fks as $fk) {
            $pairs[$fk['column']] = $fk['referenced_column'];
        }

        $this->assertSame(
            ['tenant_id' => 'tenant_id', 'region_code' => 'region_code'],
            $pairs,
            'columns must pair by position, not cross over'
        );
    }

    #[Test]
    public function indexes_are_reported_with_their_columns()
    {
        $indexes = collect($this->introspector->getIndexes('sq_orders'))->keyBy('name');

        $this->assertArrayHasKey('sq_orders_customer_unique', $indexes);
        $this->assertTrue($indexes['sq_orders_customer_unique']['is_unique']);
        $this->assertSame(
            ['customer_id', 'order_date'],
            $indexes['sq_orders_customer_unique']['columns']
        );
    }

    #[Test]
    public function it_identifies_itself_as_sqlite()
    {
        $this->assertSame('sqlite', $this->introspector->getDriver());
        $this->assertSame('sqlite', $this->introspector->getDialect());
        $this->assertSame(['main'], $this->introspector->getSchemas());
    }

    #[Test]
    public function a_qualified_name_from_another_driver_still_resolves()
    {
        // Someone carrying a schema file over from Postgres will have
        // `public.sq_orders` in it.
        $columns = $this->introspector->getColumns('main.sq_orders');

        $this->assertNotEmpty($columns);
    }
}
