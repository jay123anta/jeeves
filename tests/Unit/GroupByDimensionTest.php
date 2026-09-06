<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Jayanta\Jeeves\Engine\ResponseFormatter;
use Jayanta\Jeeves\Engine\SqlBuilder;
use Jayanta\Jeeves\Schema\SchemaRegistry;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * "revenue by region" is the most ordinary question anyone asks of a sales
 * table, and intent mode used to answer it with a customer ranking: the
 * dimension had nowhere to live in the parsed intent, so it was dropped and the
 * schema's default group_column was used instead.
 *
 * Nothing about the result looked wrong. It was correctly formatted, correctly
 * totalled, confidently worded, and about a different question.
 */
class GroupByDimensionTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/groupby-schemas');
    }

    private function build(array $intent): array
    {
        return $this->app->make(SqlBuilder::class)->buildQuery($intent + [
            'dataset' => 'gb_sales',
            'metric' => 'revenue',
        ]);
    }

    #[Test]
    public function a_requested_dimension_is_what_the_rows_are_grouped_by()
    {
        $result = $this->build(['group_by' => 'region']);

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame('region', $result['group_column']);
        $this->assertStringContainsString('GROUP BY region', $result['sql']);
        $this->assertStringNotContainsString('customer_name', $result['sql']);
    }

    #[Test]
    public function the_schema_default_still_applies_when_no_dimension_is_named()
    {
        $result = $this->build([]);

        $this->assertTrue($result['success']);
        $this->assertSame('customer_name', $result['group_column']);
        $this->assertStringContainsString('customer_name', $result['sql']);
    }

    #[Test]
    public function a_dimension_can_be_named_by_its_alias()
    {
        $result = $this->build(['group_by' => 'territory']);

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame('region', $result['group_column']);
    }

    /**
     * Falling back to the default is precisely the bug. An unusable dimension
     * has to be reported, because the alternative is answering a question the
     * user did not ask and giving them no way to notice.
     */
    #[Test]
    public function an_unknown_dimension_is_refused_rather_than_silently_defaulted()
    {
        $result = $this->build(['group_by' => 'shoe_size']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('shoe_size', $result['error']);
        $this->assertStringContainsString('region', $result['error'], 'the error should list what IS available');
    }

    #[Test]
    public function a_measure_is_not_an_acceptable_dimension()
    {
        // Grouping by an amount yields one row per distinct value -  never what
        // was meant, and it looks plausible enough to go unnoticed.
        $result = $this->build(['group_by' => 'revenue']);

        $this->assertFalse($result['success']);
    }

    #[Test]
    public function the_registry_only_offers_columns_marked_groupable()
    {
        $registry = $this->app->make(SchemaRegistry::class);
        $groupable = $registry->getGroupableColumns('gb_sales');

        $this->assertContains('region', $groupable);
        $this->assertContains('customer_name', $groupable);
        $this->assertNotContains('revenue', $groupable);
    }

    #[Test]
    public function the_answer_sentence_names_the_dimension_it_grouped_by()
    {
        // "Top 5 by revenue: West, Central" reads identically whether those are
        // regions or customers -  the reader cannot tell which question was
        // answered, which is how the original bug stayed invisible.
        $formatter = $this->app->make(ResponseFormatter::class);
        $method = new \ReflectionMethod($formatter, 'humanizeDimension');

        $this->assertSame('regions', $method->invoke($formatter, 'region'));
        $this->assertSame('customers', $method->invoke($formatter, 'customer_name'));
        $this->assertSame('statuses', $method->invoke($formatter, 'status'));
        $this->assertSame('product categories', $method->invoke($formatter, 'product_category'));
    }

    #[Test]
    public function the_dataset_list_tells_the_model_which_dimensions_exist()
    {
        // Intent mode cannot ask for a breakdown it was never told about.
        $list = $this->app->make(SchemaRegistry::class)->getDatasetListForLlm();
        $orders = collect($list)->firstWhere('key', 'gb_sales');

        $this->assertContains('region', $orders['dimensions']);
        $this->assertSame('customer_name', $orders['default_dimension']);
    }
}
