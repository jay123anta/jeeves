<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Jayanta\Jeeves\Engine\SqlBuilder;
use Jayanta\Jeeves\Schema\SchemaRegistry;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * "How many orders by status" cannot be answered from aggregatable columns -
 * counting rows is not summing a measure. With no metric to resolve, the
 * question fell through to the schema default and came back as revenue per
 * status: the right grouping, the wrong question, and a number with nothing
 * about it to suggest it was answering something else.
 */
class CountMetricTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/groupby-schemas');
    }

    private function registry(): SchemaRegistry
    {
        return $this->app->make(SchemaRegistry::class);
    }

    #[Test]
    public function every_dataset_can_be_counted_without_declaring_anything()
    {
        $metrics = $this->registry()->getComputedMetrics('gb_sales');

        $this->assertArrayHasKey(SchemaRegistry::COUNT_METRIC, $metrics);
        $this->assertSame('COUNT(*)', $metrics[SchemaRegistry::COUNT_METRIC]['expression']);
    }

    #[Test]
    public function counting_words_resolve_to_the_count_metric()
    {
        foreach (['count', 'how many', 'number', 'record_count', 'volume'] as $word) {
            $this->assertSame(
                SchemaRegistry::COUNT_METRIC,
                $this->registry()->resolveMetric('gb_sales', $word),
                "'{$word}' should ask for a count"
            );
        }
    }

    #[Test]
    public function counting_produces_count_not_a_sum_of_some_measure()
    {
        $result = $this->app->make(SqlBuilder::class)->buildQuery([
            'dataset' => 'gb_sales',
            'metric' => 'how many',
            'group_by' => 'status',
        ]);

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame('status', $result['group_column']);
        $this->assertStringContainsString('COUNT(*)', $result['sql']);
        $this->assertStringNotContainsString('SUM(', $result['sql']);
    }

    /**
     * COUNT(*) already aggregates. Wrapping it again would be a SQL error, and
     * the guard that prevents it is the same one computed metrics rely on.
     */
    #[Test]
    public function the_count_expression_is_never_double_wrapped()
    {
        $result = $this->app->make(SqlBuilder::class)->buildQuery([
            'dataset' => 'gb_sales',
            'metric' => 'count',
        ]);

        $this->assertTrue($result['success']);
        $this->assertStringNotContainsString('SUM(COUNT', $result['sql']);
        $this->assertStringNotContainsString('COUNT(COUNT', $result['sql']);
    }

    #[Test]
    public function a_named_measure_still_wins_over_counting()
    {
        // "revenue by region" must not become "number of rows by region".
        $result = $this->app->make(SqlBuilder::class)->buildQuery([
            'dataset' => 'gb_sales',
            'metric' => 'revenue',
            'group_by' => 'region',
        ]);

        $this->assertSame('revenue', $result['metric']);
        $this->assertStringContainsString('SUM(revenue)', $result['sql']);
    }

    #[Test]
    public function the_model_is_told_the_count_metric_exists()
    {
        $list = $this->registry()->getDatasetListForLlm();
        $sales = collect($list)->firstWhere('key', 'gb_sales');

        $this->assertArrayHasKey(SchemaRegistry::COUNT_METRIC, $sales['metrics']);
    }

    #[Test]
    public function a_schema_that_defines_its_own_counting_metric_keeps_it()
    {
        // Counting DISTINCT customers is not counting rows, and a schema that
        // says so must not be silently overridden.
        config(['jeeves.schema.config_path' => __DIR__ . '/../Stubs/count-schemas']);
        $this->app->forgetInstance(SchemaRegistry::class);

        $metrics = $this->app->make(SchemaRegistry::class)->getComputedMetrics('own_count');

        $this->assertSame('COUNT(DISTINCT customer_name)', $metrics['record_count']['expression']);
    }

    #[Test]
    public function the_built_in_can_be_turned_off()
    {
        config(['jeeves.sql.implicit_count_metric' => false]);

        $this->assertArrayNotHasKey(
            SchemaRegistry::COUNT_METRIC,
            $this->registry()->getComputedMetrics('gb_sales')
        );
    }
}
