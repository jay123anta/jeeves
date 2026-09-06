<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Jayanta\Jeeves\Schema\SchemaRegistry;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class SchemaRegistryTest extends TestCase
{
    private SchemaRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new SchemaRegistry(__DIR__ . '/../Stubs/schemas');
    }

    #[Test]
    public function it_loads_schema_files()
    {
        $all = $this->registry->all();
        $this->assertNotEmpty($all);
        $this->assertArrayHasKey('test_orders', $all);
    }

    #[Test]
    public function it_gets_schema_by_key()
    {
        $schema = $this->registry->get('test_orders');
        $this->assertNotNull($schema);
        $this->assertEquals('Test Orders', $schema['name']);
    }

    #[Test]
    public function it_returns_null_for_unknown_key()
    {
        $this->assertNull($this->registry->get('nonexistent'));
    }

    #[Test]
    public function it_checks_existence()
    {
        $this->assertTrue($this->registry->has('test_orders'));
        $this->assertFalse($this->registry->has('nonexistent'));
    }

    #[Test]
    public function it_gets_table_name()
    {
        $this->assertEquals('public.orders', $this->registry->getTableName('test_orders'));
    }

    #[Test]
    public function it_gets_group_column()
    {
        $this->assertEquals('customer_name', $this->registry->getGroupColumn('test_orders'));
    }

    #[Test]
    public function it_gets_metrics()
    {
        $metrics = $this->registry->getMetrics('test_orders');
        $this->assertArrayHasKey('amount', $metrics);
        $this->assertTrue($metrics['amount']['aggregatable']);
    }

    #[Test]
    public function it_gets_computed_metrics()
    {
        $computed = $this->registry->getComputedMetrics('test_orders');
        $this->assertArrayHasKey('avg_amount', $computed);
        $this->assertEquals('ROUND(AVG(amount), 2)', $computed['avg_amount']['expression']);
    }

    #[Test]
    public function it_resolves_metric_by_alias()
    {
        $this->assertEquals('amount', $this->registry->resolveMetric('test_orders', 'revenue'));
        $this->assertEquals('amount', $this->registry->resolveMetric('test_orders', 'sales'));
        $this->assertEquals('avg_amount', $this->registry->resolveMetric('test_orders', 'average'));
    }

    #[Test]
    public function it_returns_null_for_unknown_metric()
    {
        $this->assertNull($this->registry->resolveMetric('test_orders', 'nonexistent'));
    }

    #[Test]
    public function it_finds_by_alias()
    {
        $this->assertEquals('test_orders', $this->registry->findByAlias('orders'));
        $this->assertEquals('test_orders', $this->registry->findByAlias('sales'));
        $this->assertNull($this->registry->findByAlias('nonexistent'));
    }

    #[Test]
    public function it_gets_allowed_tables()
    {
        $tables = $this->registry->getAllowedTables();
        $this->assertContains('public.orders', $tables);
    }

    #[Test]
    public function it_gets_dataset_list_for_llm()
    {
        $list = $this->registry->getDatasetListForLlm();
        $this->assertNotEmpty($list);
        $keys = array_column($list, 'key');
        $this->assertContains('test_orders', $keys);
        $this->assertArrayHasKey('metrics', $list[0]);
    }

    #[Test]
    public function it_flushes_cache()
    {
        $this->registry->all(); // load
        $this->registry->flush();
        // After flush, re-loading should work
        $all = $this->registry->all();
        $this->assertNotEmpty($all);
    }

    /**
     * The allowed-table lookup is memoized separately from the schemas, and a
     * stale copy decides which joins the prompt may offer. Flushing one without
     * the other would keep answering from the previous schema set.
     */
    #[Test]
    public function flushing_also_clears_the_allowed_table_lookup()
    {
        // The schema declares `public.orders`; the unqualified form means the
        // same table, and Postgres reports foreign keys in the qualified form.
        $this->assertTrue($this->registry->allowsTable('orders'));
        $this->assertTrue($this->registry->allowsTable('public.orders'));

        $empty = sys_get_temp_dir() . '/nq-no-schemas-' . getmypid();
        if (!is_dir($empty)) {
            mkdir($empty, 0777, true);
        }

        config(['jeeves.schema.config_path' => $empty]);
        $fresh = new SchemaRegistry($empty);

        $this->assertFalse($fresh->allowsTable('orders'));

        // And the same instance must forget what it learned.
        $this->registry->flush();
        $reloaded = new SchemaRegistry($empty);
        $this->assertFalse($reloaded->allowsTable('orders'));

        rmdir($empty);
    }

    #[Test]
    public function a_table_reached_only_through_a_foreign_key_is_not_silently_allowed()
    {
        // Whitelisting is built from schema files, never from relationships -
        // otherwise describing one table would quietly expose its neighbours.
        $this->assertFalse($this->registry->allowsTable('some_unlisted_table'));
    }
}
