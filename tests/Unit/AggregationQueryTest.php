<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Jayanta\Jeeves\Engine\SqlBuilder;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * "What is the total revenue" is one number, not a list of every row.
 *
 * The model said so all along -  query_type came back as 'aggregation' -  and
 * the response formatter had a branch ready for it. The builder did not: it
 * only ever produced a ranking or a detail view, so the field was read in the
 * SQL-generation path and silently dropped in intent mode.
 *
 * Found by the accuracy benchmark, which scored 0/3 on the easiest questions
 * in the set while passing harder ones. No unit test had noticed, because
 * every test asserted on rankings.
 */
class AggregationQueryTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/time-schemas');
    }

    private function build(array $intent): array
    {
        return $this->app->make(SqlBuilder::class)->buildQuery($intent + [
            'dataset' => 'tf_sales',
            'metric' => 'revenue',
        ]);
    }

    #[Test]
    public function a_total_is_one_number_not_a_ranking()
    {
        $result = $this->build(['query_type' => 'aggregation']);

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame('aggregation', $result['query_type']);
        $this->assertStringContainsString('SUM(revenue)', $result['sql']);
        $this->assertStringNotContainsString('GROUP BY', $result['sql']);
        $this->assertStringNotContainsString('LIMIT', $result['sql']);
    }

    #[Test]
    public function counting_everything_uses_count_not_sum_of_count()
    {
        // record_count is COUNT(*), which already aggregates. Wrapping it again
        // is invalid SQL, not merely untidy.
        $result = $this->build(['metric' => 'record_count', 'query_type' => 'aggregation']);

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertStringContainsString('COUNT(*)', $result['sql']);
        $this->assertStringNotContainsString('SUM(COUNT', $result['sql']);
    }

    #[Test]
    public function a_total_still_respects_the_period()
    {
        $result = $this->build([
            'query_type' => 'aggregation',
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
        ]);

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertStringContainsString('WHERE order_date >= ?', $result['sql']);
        $this->assertSame(['2026-07-01', '2026-07-31'], $result['bindings']);
    }

    /**
     * A named breakdown beats the model's own label. "Revenue by region" is a
     * grouping even when the intent also calls it an aggregation -  otherwise
     * fixing totals would break breakdowns, which were only just fixed.
     */
    #[Test]
    public function a_requested_breakdown_wins_over_an_aggregation_label()
    {
        $result = $this->build(['query_type' => 'aggregation', 'group_by' => 'region']);

        $this->assertSame('ranking', $result['query_type']);
        $this->assertStringContainsString('GROUP BY region', $result['sql']);
    }

    #[Test]
    public function a_named_record_wins_over_an_aggregation_label()
    {
        $result = $this->build(['query_type' => 'aggregation', 'group_value' => 'West']);

        $this->assertSame('group_detail', $result['query_type']);
        $this->assertContains('West', $result['bindings']);
    }

    #[Test]
    public function ordinary_questions_are_still_rankings()
    {
        // The regression guard: without a query_type nothing changes.
        $result = $this->build([]);

        $this->assertSame('ranking', $result['query_type']);
        $this->assertStringContainsString('LIMIT', $result['sql']);
    }

    #[Test]
    public function the_public_aggregation_entry_point_goes_through_the_same_path()
    {
        // It used to build SQL of its own that applied no period and returned
        // none of the metadata the formatter reads.
        $result = $this->app->make(SqlBuilder::class)->buildAggregationQuery([
            'dataset' => 'tf_sales',
            'metric' => 'revenue',
            'date_from' => '2026-07-01',
        ]);

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame('aggregation', $result['query_type']);
        $this->assertStringContainsString('WHERE order_date >= ?', $result['sql']);
        $this->assertArrayHasKey('metric_unit', $result);
        $this->assertArrayHasKey('group_column', $result);
    }
}
