<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Illuminate\Testing\PendingCommand;
use Jayanta\Jeeves\Contracts\SchemaIntrospectorInterface;
use Jayanta\Jeeves\Tests\TestCase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

/**
 * Re-running discovery must not destroy what a human wrote.
 *
 * The generated file has two layers. The structural layer -  which columns
 * exist and their types -  comes from the database and should be refreshed.
 * The human layer -  descriptions, aliases, llm_instructions, computed_metrics,
 * example_queries, and the judgement calls about which column is a measure -
 * cannot be recovered from the database and is what makes the dataset usable.
 *
 * Before --merge existed the only choices were to skip the file (keeping a
 * stale page that no longer matches the schema) or --force it (regenerating
 * from scratch and losing every hand-written line). Neither is a workable
 * answer to "a migration added a column".
 */
class DiscoverMergeTest extends TestCase
{
    private string $outputPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outputPath = sys_get_temp_dir() . '/nq-merge-' . getmypid();
        $this->cleanOutput();
        @mkdir($this->outputPath, 0755, true);
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

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    /** @param array<int, array<string, mixed>> $columns */
    private function fakeIntrospector(array $columns): void
    {
        $i = Mockery::mock(SchemaIntrospectorInterface::class);
        $i->shouldReceive('getDriver')->andReturn('sqlite');
        $i->shouldReceive('getDialect')->andReturn('sqlite');
        $i->shouldReceive('getSchemas')->andReturn(['main']);
        $i->shouldReceive('listTables')->andReturn([
            ['name' => 'orders', 'short_name' => 'orders', 'type' => 'table', 'row_estimate' => 5, 'comment' => ''],
        ]);
        $i->shouldReceive('getRelationships')->andReturn([]);
        $i->shouldReceive('getColumns')->andReturn($columns);

        $this->app->instance(SchemaIntrospectorInterface::class, $i);
    }

    private function column(string $name, string $type, string $role): array
    {
        return ['name' => $name, 'type' => $type, 'comment' => '', 'suggested_role' => $role];
    }

    private function file(): string
    {
        return $this->outputPath . '/orders.php';
    }

    private function load(): array
    {
        return include $this->file();
    }

    /** A file as it looks after someone has curated it by hand. */
    private function writeCuratedFile(): void
    {
        file_put_contents($this->file(), <<<'PHP'
<?php

return [
    'name' => 'Customer Orders',
    'description' => 'Everything customers have bought from us',
    'aliases' => ['orders', 'sales', 'purchases'],
    'connection' => null,
    'llm_instructions' => 'Exclude cancelled orders from all revenue figures.',
    'tables' => [
        'primary' => [
            'name' => 'orders',
            'description' => 'Order lines',
            'group_column' => 'customer_name',
            'required_filter' => "status <> 'draft'",
            'columns' => [
                'customer_name' => [
                    'type' => 'varchar',
                    'description' => 'Who placed the order',
                    'aliases' => ['client', 'buyer'],
                    'filterable' => true,
                    'groupable' => true,
                ],
                'revenue' => [
                    'type' => 'decimal',
                    'description' => 'Line revenue in rupees',
                    'unit' => 'INR',
                    'aggregatable' => true,
                    'sortable' => true,
                ],
            ],
        ],
    ],
    'computed_metrics' => [
        'avg_order' => ['expression' => 'ROUND(AVG(revenue), 2)', 'description' => 'Average order value'],
    ],
    'example_queries' => [
        ['natural' => 'top customers', 'sql' => 'SELECT customer_name FROM orders'],
    ],
    'max_limit' => 25,
    'default_metric' => 'revenue',
    'defaults' => ['order' => 'DESC', 'limit' => 10],
];
PHP);
    }

    private function discover(array $options = []): PendingCommand
    {
        return $this->artisan('jeeves:discover', array_merge([
            '--output' => $this->outputPath,
        ], $options));
    }

    #[Test]
    public function without_a_flag_an_existing_file_is_left_alone()
    {
        $this->writeCuratedFile();
        $before = file_get_contents($this->file());
        $this->fakeIntrospector([$this->column('customer_name', 'varchar', 'dimension')]);

        $this->discover()->assertExitCode(0);

        $this->assertSame($before, file_get_contents($this->file()));
    }

    #[Test]
    public function merge_keeps_every_hand_written_value()
    {
        $this->writeCuratedFile();
        $this->fakeIntrospector([
            $this->column('customer_name', 'varchar', 'dimension'),
            $this->column('revenue', 'decimal', 'measure'),
        ]);

        $this->discover(['--merge' => true])->assertExitCode(0);

        $schema = $this->load();

        $this->assertSame('Customer Orders', $schema['name']);
        $this->assertSame('Everything customers have bought from us', $schema['description']);
        $this->assertSame(['orders', 'sales', 'purchases'], $schema['aliases']);
        $this->assertSame('Exclude cancelled orders from all revenue figures.', $schema['llm_instructions']);
        $this->assertArrayHasKey('avg_order', $schema['computed_metrics']);
        $this->assertCount(1, $schema['example_queries']);
        $this->assertSame(25, $schema['max_limit']);
        $this->assertSame('revenue', $schema['default_metric']);
    }

    #[Test]
    public function merge_keeps_curated_table_level_settings()
    {
        $this->writeCuratedFile();
        $this->fakeIntrospector([$this->column('customer_name', 'varchar', 'dimension')]);

        $this->discover(['--merge' => true])->assertExitCode(0);

        $primary = $this->load()['tables']['primary'];

        $this->assertSame('customer_name', $primary['group_column']);
        $this->assertSame("status <> 'draft'", $primary['required_filter']);
    }

    #[Test]
    public function merge_keeps_per_column_descriptions_aliases_and_units()
    {
        $this->writeCuratedFile();
        $this->fakeIntrospector([
            $this->column('customer_name', 'varchar', 'dimension'),
            $this->column('revenue', 'decimal', 'measure'),
        ]);

        $this->discover(['--merge' => true])->assertExitCode(0);

        $columns = $this->load()['tables']['primary']['columns'];

        $this->assertSame('Who placed the order', $columns['customer_name']['description']);
        $this->assertSame(['client', 'buyer'], $columns['customer_name']['aliases']);
        $this->assertSame('INR', $columns['revenue']['unit']);
        $this->assertTrue($columns['revenue']['aggregatable']);
    }

    #[Test]
    public function merge_adds_a_column_that_appeared_in_the_database()
    {
        $this->writeCuratedFile();
        $this->fakeIntrospector([
            $this->column('customer_name', 'varchar', 'dimension'),
            $this->column('revenue', 'decimal', 'measure'),
            $this->column('region', 'varchar', 'dimension'),
        ]);

        $this->discover(['--merge' => true])
            ->expectsOutputToContain('new column(s): region')
            ->assertExitCode(0);

        $columns = $this->load()['tables']['primary']['columns'];

        $this->assertArrayHasKey('region', $columns);
        // Existing curation untouched by the addition.
        $this->assertSame('Who placed the order', $columns['customer_name']['description']);
    }

    #[Test]
    public function merge_drops_a_column_that_no_longer_exists()
    {
        // Keeping it would go on telling the model about a column that is gone,
        // which is the silent failure doctor exists to catch.
        $this->writeCuratedFile();
        $this->fakeIntrospector([$this->column('customer_name', 'varchar', 'dimension')]);

        $this->discover(['--merge' => true])
            ->expectsOutputToContain('gone from the database: revenue')
            ->assertExitCode(0);

        $this->assertArrayNotHasKey('revenue', $this->load()['tables']['primary']['columns']);
    }

    #[Test]
    public function force_still_regenerates_from_scratch()
    {
        $this->writeCuratedFile();
        $this->fakeIntrospector([$this->column('customer_name', 'varchar', 'dimension')]);

        $this->discover(['--force' => true])->assertExitCode(0);

        // --force is documented as discarding curation; that must stay true so
        // "start clean" remains available.
        $this->assertNotSame('Customer Orders', $this->load()['name']);
    }

    #[Test]
    public function a_file_that_does_not_parse_is_never_clobbered_by_merge()
    {
        file_put_contents($this->file(), "<?php\n\nreturn [ 'name' => 'Broken\n");
        $before = file_get_contents($this->file());
        $this->fakeIntrospector([$this->column('customer_name', 'varchar', 'dimension')]);

        $this->discover(['--merge' => true])
            ->expectsOutputToContain('did not parse');

        $this->assertSame($before, file_get_contents($this->file()));
    }

    #[Test]
    public function a_merged_file_is_still_valid_php_that_returns_an_array()
    {
        $this->writeCuratedFile();
        $this->fakeIntrospector([
            $this->column('customer_name', 'varchar', 'dimension'),
            $this->column('region', 'varchar', 'dimension'),
        ]);

        $this->discover(['--merge' => true])->assertExitCode(0);

        $code = file_get_contents($this->file());
        token_get_all($code, TOKEN_PARSE); // throws ParseError if broken
        $this->assertIsArray($this->load());
    }
}
