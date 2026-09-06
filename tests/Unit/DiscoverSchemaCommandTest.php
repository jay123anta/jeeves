<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Contracts\SchemaIntrospectorInterface;
use Jayanta\Jeeves\Schema\SchemaRegistry;
use Jayanta\Jeeves\Tests\TestCase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

/**
 * Schema files are loaded on every request and their example queries become
 * few-shot guidance in every prompt. Both are silent failure modes, so
 * generation is held to the same bar as runtime code.
 */
class DiscoverSchemaCommandTest extends TestCase
{
    private string $outputPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outputPath = sys_get_temp_dir() . '/nq-discover-' . getmypid();
        $this->cleanOutput();
    }

    protected function tearDown(): void
    {
        $this->cleanOutput();
        Mockery::close();
        parent::tearDown();
    }

    private function cleanOutput(): void
    {
        if (is_dir($this->outputPath)) {
            foreach (glob($this->outputPath . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->outputPath);
        }
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // NQ-001: an app connection to seed through, and a separate
        // name the package reads through, over one database.
        $this->isolatedDatabase($app, 'testing');
    }

    /**
     * @param  array  $tables  Entries as returned by the introspector
     */
    private function fakeIntrospector(array $tables, array $columnsByTable): void
    {
        $introspector = Mockery::mock(SchemaIntrospectorInterface::class);
        $introspector->shouldReceive('getDriver')->andReturn('sqlite');
        $introspector->shouldReceive('getDialect')->andReturn('sqlite');
        $introspector->shouldReceive('getSchemas')->andReturn(['main']);
        $introspector->shouldReceive('listTables')->andReturn($tables);
        $introspector->shouldReceive('getRelationships')->andReturn([]);
        $introspector->shouldReceive('getColumns')->andReturnUsing(
            fn ($table) => $columnsByTable[$table] ?? []
        );

        $this->app->instance(SchemaIntrospectorInterface::class, $introspector);
    }

    private function ordersTable(array $columns): void
    {
        $this->fakeIntrospector(
            [['name' => 'orders', 'short_name' => 'orders', 'type' => 'table', 'row_estimate' => 10, 'comment' => '']],
            ['orders' => $columns]
        );
    }

    private function defaultColumns(): array
    {
        return [
            ['name' => 'customer_name', 'type' => 'varchar', 'comment' => '', 'suggested_role' => 'dimension'],
            ['name' => 'revenue', 'type' => 'decimal', 'comment' => '', 'suggested_role' => 'measure'],
        ];
    }

    private function generatedFile(): string
    {
        return $this->outputPath . '/orders.php';
    }

    /**
     * Load a generated schema file, syntax-checking it first.
     *
     * `include` on a file with a syntax error is an uncatchable fatal that
     * kills the whole PHP process, and PHPUnit can only report it as
     * "Premature end of PHP process" -  no file, no line, no message. Parsing
     * first turns that dead end into an ordinary assertion failure that names
     * the problem and prints the offending source.
     *
     * @return array<string, mixed>
     */
    private function loadGenerated(?string $path = null): array
    {
        $path = $path ?? $this->generatedFile();

        $this->assertFileExists($path);
        $code = file_get_contents($path);

        try {
            token_get_all($code, TOKEN_PARSE);
        } catch (\ParseError $e) {
            $this->fail(
                "Generated schema file is not valid PHP: {$e->getMessage()}\n"
                . "--- {$path} ---\n{$code}"
            );
        }

        $value = include $path;
        $this->assertIsArray($value, "Generated schema file did not return an array: {$path}");

        return $value;
    }

    private function runDiscover(array $options = []): PendingCommand
    {
        return $this->artisan('jeeves:discover', array_merge([
            '--output' => $this->outputPath,
            '--force' => true,
        ], $options));
    }

    // =====================================================================

    #[Test]
    public function a_column_comment_containing_an_apostrophe_still_produces_a_loadable_file()
    {
        // Regression: naive string interpolation wrote
        //   'description' => 'Customer's full name'
        // -  a PHP parse error. Schema files are loaded on every request, so
        // that took down the whole application after a successful-looking run.
        $this->ordersTable([
            ['name' => 'customer_name', 'type' => 'varchar', 'comment' => "Customer's full name", 'suggested_role' => 'dimension'],
            ['name' => 'revenue', 'type' => 'decimal', 'comment' => 'Order value in "local" currency', 'suggested_role' => 'measure'],
        ]);

        $this->runDiscover()->assertExitCode(0);

        $schema = $this->loadGenerated();

        $this->assertIsArray($schema);
        $this->assertSame(
            "Customer's full name",
            $schema['tables']['primary']['columns']['customer_name']['description']
        );
        $this->assertSame(
            'Order value in "local" currency',
            $schema['tables']['primary']['columns']['revenue']['description']
        );
    }

    #[Test]
    public function backslashes_and_newlines_in_comments_survive_generation()
    {
        $this->ordersTable([
            ['name' => 'path', 'type' => 'varchar', 'comment' => 'Windows path C:\\temp\\x and a\nnewline', 'suggested_role' => 'dimension'],
        ]);

        $this->runDiscover()->assertExitCode(0);

        $schema = $this->loadGenerated();

        $this->assertSame(
            'Windows path C:\\temp\\x and a\nnewline',
            $schema['tables']['primary']['columns']['path']['description']
        );
    }

    #[Test]
    public function measures_are_marked_aggregatable_so_totals_group_correctly()
    {
        $this->ordersTable($this->defaultColumns());

        $this->runDiscover()->assertExitCode(0);

        $columns = $this->loadGenerated()['tables']['primary']['columns'];

        $this->assertTrue($columns['revenue']['aggregatable']);
        $this->assertTrue($columns['customer_name']['groupable']);
        $this->assertArrayNotHasKey('aggregatable', $columns['customer_name']);
    }

    #[Test]
    public function framework_tables_are_skipped_by_default()
    {
        $this->fakeIntrospector([
            ['name' => 'orders', 'short_name' => 'orders', 'type' => 'table', 'row_estimate' => 1, 'comment' => ''],
            ['name' => 'migrations', 'short_name' => 'migrations', 'type' => 'table', 'row_estimate' => 1, 'comment' => ''],
            ['name' => 'failed_jobs', 'short_name' => 'failed_jobs', 'type' => 'table', 'row_estimate' => 1, 'comment' => ''],
            ['name' => 'telescope_entries', 'short_name' => 'telescope_entries', 'type' => 'table', 'row_estimate' => 1, 'comment' => ''],
            ['name' => 'jeeves_cache', 'short_name' => 'jeeves_cache', 'type' => 'table', 'row_estimate' => 1, 'comment' => ''],
        ], [
            'orders' => $this->defaultColumns(),
            'migrations' => $this->defaultColumns(),
            'failed_jobs' => $this->defaultColumns(),
            'telescope_entries' => $this->defaultColumns(),
            'jeeves_cache' => $this->defaultColumns(),
        ]);

        $this->runDiscover()->assertExitCode(0);

        $this->assertFileExists($this->generatedFile());
        $this->assertFileDoesNotExist($this->outputPath . '/migrations.php');
        $this->assertFileDoesNotExist($this->outputPath . '/failed_jobs.php');
        $this->assertFileDoesNotExist($this->outputPath . '/telescope_entries.php');
        $this->assertFileDoesNotExist($this->outputPath . '/jeeves_cache.php');
    }

    #[Test]
    public function all_tables_flag_includes_framework_tables()
    {
        $this->fakeIntrospector([
            ['name' => 'migrations', 'short_name' => 'migrations', 'type' => 'table', 'row_estimate' => 1, 'comment' => ''],
        ], ['migrations' => $this->defaultColumns()]);

        $this->runDiscover(['--all-tables' => true])->assertExitCode(0);

        $this->assertFileExists($this->outputPath . '/migrations.php');
    }

    #[Test]
    public function dry_run_writes_nothing()
    {
        $this->ordersTable($this->defaultColumns());

        $this->runDiscover(['--dry-run' => true])
            ->expectsOutputToContain('Would create')
            ->assertExitCode(0);

        $this->assertFileDoesNotExist($this->generatedFile());
    }

    // =====================================================================
    // AI example-query validation
    // =====================================================================

    private function fakeAi(array $data): void
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldIgnoreMissing();
        $llm->shouldReceive('getName')->andReturn('test');
        $llm->shouldReceive('generateSql')->andReturn(['success' => true, 'data' => $data]);

        $this->app->instance(LlmProviderInterface::class, $llm);
    }

    #[Test]
    public function ai_example_queries_that_are_not_select_are_dropped()
    {
        Schema::create('orders', function ($table) {
            $table->id();
            $table->string('customer_name');
            $table->decimal('revenue', 12, 2);
        });

        $this->ordersTable($this->defaultColumns());
        $this->fakeAi([
            'name' => 'Orders',
            'description' => 'Order lines',
            'aliases' => ['sales'],
            'llm_instructions' => 'Use SUM(revenue).',
            'example_queries' => [
                ['natural' => 'total revenue', 'sql' => 'SELECT SUM(revenue) AS total FROM orders'],
                ['natural' => 'wipe the table', 'sql' => 'DELETE FROM orders'],
            ],
        ]);

        $this->runDiscover(['--ai' => true])->assertExitCode(0);

        $examples = $this->loadGenerated()['example_queries'];

        $this->assertCount(1, $examples);
        $this->assertSame('total revenue', $examples[0]['natural']);
    }

    #[Test]
    public function ai_example_queries_referencing_a_hallucinated_column_are_dropped()
    {
        // The single most damaging failure: a made-up column in an example
        // teaches the AI that column exists, poisoning every later query.
        Schema::create('orders', function ($table) {
            $table->id();
            $table->string('customer_name');
            $table->decimal('revenue', 12, 2);
        });

        $this->ordersTable($this->defaultColumns());
        $this->fakeAi([
            'name' => 'Orders',
            'description' => 'Order lines',
            'aliases' => [],
            'llm_instructions' => 'x',
            'example_queries' => [
                ['natural' => 'total revenue', 'sql' => 'SELECT SUM(revenue) AS total FROM orders'],
                ['natural' => 'total profit', 'sql' => 'SELECT SUM(profit_margin) AS total FROM orders'],
            ],
        ]);

        $this->runDiscover(['--ai' => true])->assertExitCode(0);

        $examples = $this->loadGenerated()['example_queries'];

        $this->assertCount(1, $examples);
        $this->assertStringNotContainsString('profit_margin', json_encode($examples));
    }

    #[Test]
    public function ai_example_queries_touching_another_table_are_dropped()
    {
        Schema::create('orders', function ($table) {
            $table->id();
            $table->string('customer_name');
            $table->decimal('revenue', 12, 2);
        });
        Schema::create('salaries', function ($table) {
            $table->id();
            $table->decimal('amount', 12, 2);
        });

        $this->ordersTable($this->defaultColumns());
        $this->fakeAi([
            'name' => 'Orders',
            'description' => 'Order lines',
            'aliases' => [],
            'llm_instructions' => 'x',
            'example_queries' => [
                ['natural' => 'salaries', 'sql' => 'SELECT SUM(amount) AS total FROM salaries'],
            ],
        ]);

        $this->runDiscover(['--ai' => true])->assertExitCode(0);

        $this->assertSame([], $this->loadGenerated()['example_queries']);
    }

    #[Test]
    public function malformed_ai_computed_metrics_are_discarded_rather_than_written()
    {
        Schema::create('orders', function ($table) {
            $table->id();
            $table->string('customer_name');
            $table->decimal('revenue', 12, 2);
        });

        $this->ordersTable($this->defaultColumns());
        $this->fakeAi([
            'name' => 'Orders',
            'description' => 'Order lines',
            'aliases' => [],
            'llm_instructions' => 'x',
            'computed_metrics' => [
                'avg_revenue' => ['expression' => 'AVG(revenue)', 'description' => 'Average', 'unit' => '$'],
                'broken' => ['description' => 'no expression'],
                'also_broken' => 'not-an-array',
            ],
            'example_queries' => [],
        ]);

        $this->runDiscover(['--ai' => true])->assertExitCode(0);

        $metrics = $this->loadGenerated()['computed_metrics'];

        $this->assertArrayHasKey('avg_revenue', $metrics);
        $this->assertArrayNotHasKey('broken', $metrics);
        $this->assertArrayNotHasKey('also_broken', $metrics);
        $this->assertSame('AVG(revenue)', $metrics['avg_revenue']['expression']);
    }

    #[Test]
    public function generated_files_are_valid_php_and_load_through_the_registry()
    {
        $this->ordersTable([
            ['name' => 'customer_name', 'type' => 'varchar', 'comment' => "It's the buyer", 'suggested_role' => 'dimension'],
            ['name' => 'revenue', 'type' => 'decimal', 'comment' => 'Value', 'suggested_role' => 'measure'],
        ]);

        $this->runDiscover()->assertExitCode(0);

        // Exercise the real loader, not just include()
        $registry = new SchemaRegistry($this->outputPath);

        $this->assertTrue($registry->has('orders'));
        $this->assertSame('orders', $registry->getTableName('orders'));
        $this->assertSame('customer_name', $registry->getGroupColumn('orders'));
    }
}
