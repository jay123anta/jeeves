<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Jayanta\Jeeves\Engine\SqlBuilder;
use Jayanta\Jeeves\Schema\Introspectors\MysqlIntrospector;
use Jayanta\Jeeves\Schema\SchemaRegistry;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Runs the real introspector, and real generated SQL, against a real
 * MySQL or MariaDB server.
 *
 * MySQL was the one supported database with no integration coverage, and a bug
 * lived in exactly that gap: the named-record lookup emitted `ILIKE`, which is
 * PostgreSQL-only and a hard syntax error here, so every "details for X" query
 * failed for every MySQL adopter. Unit tests could not see it because the
 * builder only produces a string; Postgres coverage could not see it because
 * there the string is valid. Only executing it here catches that class of bug.
 *
 * Skips when no server is reachable, so local runs stay service-free; CI
 * provides both MySQL and MariaDB.
 */
class MysqlIntrospectorTest extends TestCase
{
    private const CONNECTION = 'nq_mysqltest';

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('database.connections.' . self::CONNECTION, [
            'driver' => 'mysql',
            'host' => env('NQ_MYSQL_HOST', '127.0.0.1'),
            'port' => env('NQ_MYSQL_PORT', '3306'),
            'database' => env('NQ_MYSQL_DATABASE', 'nq_mysql_test'),
            'username' => env('NQ_MYSQL_USERNAME', 'root'),
            'password' => env('NQ_MYSQL_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        try {
            DB::connection(self::CONNECTION)->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('No MySQL/MariaDB server reachable: ' . $e->getMessage());
        }

        $conn = DB::connection(self::CONNECTION);
        $conn->statement('DROP TABLE IF EXISTS nq_stock_moves');
        $conn->statement("
            CREATE TABLE nq_stock_moves (
                id INT AUTO_INCREMENT PRIMARY KEY,
                bin_code VARCHAR(40) NOT NULL COMMENT 'Storage bin identifier',
                units_held INT NOT NULL COMMENT 'Units moved',
                moved_on DATE NULL
            ) ENGINE=InnoDB COMMENT='Stock movements per bin'
        ");
        // 'DECOY' is deliberately the top-ranked bin. If a lookup ever loses
        // its filter and falls back to a ranking query, it returns DECOY -
        // which is how the "specific record answered with the top row" bug
        // stays visible instead of passing by coincidence.
        //
        // ACME_CORP / ACMEXCORP differ only where an unescaped underscore
        // would act as a LIKE wildcard.
        $conn->table('nq_stock_moves')->insert([
            ['bin_code' => 'DECOY', 'units_held' => 9999, 'moved_on' => '2026-01-01'],
            ['bin_code' => 'A-01', 'units_held' => 120, 'moved_on' => '2026-01-05'],
            ['bin_code' => 'A-01', 'units_held' => 30, 'moved_on' => '2026-02-11'],
            ['bin_code' => 'ACME_CORP', 'units_held' => 5, 'moved_on' => '2026-02-20'],
            ['bin_code' => 'ACMEXCORP', 'units_held' => 4321, 'moved_on' => '2026-02-21'],
            ['bin_code' => "O'Brien Bay", 'units_held' => 75, 'moved_on' => '2026-03-02'],
        ]);
    }

    protected function tearDown(): void
    {
        try {
            DB::connection(self::CONNECTION)->statement('DROP TABLE IF EXISTS nq_stock_moves');
        } catch (\Throwable $e) {
            // Nothing to clean up.
        }

        parent::tearDown();
    }

    private function introspector(): MysqlIntrospector
    {
        config(['jeeves.sql.database_connection' => self::CONNECTION]);

        return new MysqlIntrospector;
    }

    #[Test]
    public function it_reports_the_dialect_and_driver()
    {
        $this->assertSame('mysql', $this->introspector()->getDriver(self::CONNECTION));
        $this->assertSame('mysql', $this->introspector()->getDialect(self::CONNECTION));
    }

    #[Test]
    public function it_lists_the_table_with_its_comment()
    {
        $tables = collect($this->introspector()->listTables(self::CONNECTION))->keyBy('short_name');

        $this->assertTrue($tables->has('nq_stock_moves'));
        $this->assertSame('table', $tables['nq_stock_moves']['type']);
        $this->assertSame('Stock movements per bin', $tables['nq_stock_moves']['comment']);
    }

    #[Test]
    public function it_reads_columns_with_types_comments_and_the_primary_key()
    {
        $columns = collect($this->introspector()->getColumns('nq_stock_moves', self::CONNECTION))
            ->keyBy('name');

        $this->assertSame('Storage bin identifier', $columns['bin_code']['comment']);
        $this->assertTrue($columns['id']['is_primary']);
        $this->assertFalse($columns['bin_code']['is_primary']);
        $this->assertTrue($columns['moved_on']['nullable']);
        $this->assertFalse($columns['units_held']['nullable']);
        $this->assertSame(40, $columns['bin_code']['max_length']);
    }

    #[Test]
    public function it_reads_the_primary_key_index()
    {
        $indexes = $this->introspector()->getIndexes('nq_stock_moves', self::CONNECTION);

        $this->assertNotEmpty($indexes);
        $this->assertTrue(
            collect($indexes)->contains(fn ($i) => $i['is_primary'] && in_array('id', $i['columns'], true))
        );
    }

    #[Test]
    public function it_lists_user_schemas_without_the_system_ones()
    {
        $schemas = $this->introspector()->getSchemas(self::CONNECTION);

        $this->assertNotContains('information_schema', $schemas);
        $this->assertNotContains('performance_schema', $schemas);
        $this->assertNotContains('mysql', $schemas);
    }

    /**
     * The regression that motivated this whole suite. ILIKE parses fine on
     * PostgreSQL and is a syntax error here, so only executing the generated
     * SQL against a real server proves the lookup works.
     */
    #[Test]
    public function the_named_record_lookup_executes_on_mysql()
    {
        $row = $this->lookup('A-01');

        $this->assertSame('A-01', $row['bin_code'], 'DECOY here means the filter was lost');
        // Both movements for the bin must be totalled.
        $this->assertEquals(150, $row['units_held']);
    }

    #[Test]
    public function the_named_record_lookup_is_case_insensitive_on_mysql()
    {
        $this->assertSame('A-01', $this->lookup('a-01')['bin_code']);
    }

    /**
     * A value containing digits used to be rejected by the sanitiser, and a
     * rejected value meant no filter -  so this question was answered with the
     * top-ranked bin instead. DECOY exists to make that visible.
     */
    #[Test]
    public function an_identifier_with_digits_is_not_silently_dropped()
    {
        foreach (['A-01', "O'Brien Bay"] as $identifier) {
            $row = $this->lookup($identifier);
            $this->assertNotSame('DECOY', $row['bin_code'], "filter lost for '{$identifier}'");
        }
    }

    /** An unescaped underscore is a LIKE wildcard and would match ACMEXCORP. */
    #[Test]
    public function an_underscore_matches_literally_rather_than_as_a_wildcard()
    {
        $row = $this->lookup('ACME_CORP');

        $this->assertSame('ACME_CORP', $row['bin_code']);
        $this->assertEquals(5, $row['units_held']);
    }

    #[Test]
    public function a_ranking_query_executes_and_aggregates_on_mysql()
    {
        $built = $this->build(['group_value' => null]);

        $rows = array_map(fn ($r) => (array) $r, DB::connection(self::CONNECTION)->select($built['sql'], $built['bindings']));
        $byBin = array_column($rows, 'units_held', 'bin_code');

        $this->assertEquals(150, $byBin['A-01']);
        $this->assertEquals(75, $byBin["O'Brien Bay"]);
    }

    // ------------------------------------------------------------------

    private function build(array $overrides): array
    {
        config(['jeeves.schema.config_path' => __DIR__ . '/../Stubs/mysql-schemas']);

        $builder = new SqlBuilder(new SchemaRegistry(__DIR__ . '/../Stubs/mysql-schemas'));

        $result = $builder->buildQuery(array_merge([
            'dataset' => 'stock_moves',
            'metric' => 'units_held',
            'limit' => 5,
            'order' => 'desc',
            'group_value' => null,
        ], $overrides));

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertStringNotContainsStringIgnoringCase('ILIKE', $result['sql']);

        return $result;
    }

    /** Build the named-record lookup, run it, and return the single row. */
    private function lookup(string $value): array
    {
        $built = $this->build(['group_value' => $value, 'limit' => 1]);

        $this->assertSame(
            'group_detail',
            $built['query_type'],
            "'{$value}' did not produce a filtered lookup -  it became a ranking query"
        );

        $rows = DB::connection(self::CONNECTION)->select($built['sql'], $built['bindings']);
        $this->assertCount(1, $rows);

        return (array) $rows[0];
    }
}
