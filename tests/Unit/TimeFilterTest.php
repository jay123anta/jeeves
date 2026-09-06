<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Jayanta\Jeeves\Engine\SqlBuilder;
use Jayanta\Jeeves\Schema\SchemaRegistry;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * "Revenue last month" had nowhere in the intent to put the period, so the
 * filter was dropped and the answer covered all time -  correctly totalled,
 * confidently worded, and about a period nobody asked for.
 *
 * The fourth defect of that family in three days, after the dimension, the
 * metric and the clarification target. Time is not an edge case: most business
 * questions carry one.
 */
class TimeFilterTest extends TestCase
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
    public function a_period_narrows_the_query()
    {
        $result = $this->build(['date_from' => '2026-07-01', 'date_to' => '2026-07-31']);

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertStringContainsString('order_date >= ?', $result['sql']);
        $this->assertStringContainsString('order_date <= ?', $result['sql']);
        $this->assertSame(['2026-07-01', '2026-07-31'], $result['bindings']);
    }

    #[Test]
    public function dates_are_bound_never_interpolated()
    {
        // The dates come from a model. They are checked AND bound: the check
        // stops a malformed value reaching the driver, the binding means
        // nothing is concatenated even if the check were loosened later.
        $result = $this->build(['date_from' => '2026-07-01']);

        $this->assertStringNotContainsString('2026-07-01', $result['sql']);
        $this->assertContains('2026-07-01', $result['bindings']);
    }

    #[Test]
    public function an_open_ended_period_works_from_either_end()
    {
        $from = $this->build(['date_from' => '2026-01-01']);
        $this->assertStringContainsString('>= ?', $from['sql']);
        $this->assertStringNotContainsString('<= ?', $from['sql']);

        $to = $this->build(['date_to' => '2026-01-01']);
        $this->assertStringContainsString('<= ?', $to['sql']);
        $this->assertStringNotContainsString('>= ?', $to['sql']);
    }

    #[Test]
    public function no_period_means_no_where_clause()
    {
        $result = $this->build([]);

        $this->assertTrue($result['success']);
        $this->assertStringNotContainsString('WHERE', $result['sql']);
        $this->assertSame([], $result['bindings']);
        $this->assertNull($result['time_filter']);
    }

    #[Test]
    public function a_malformed_date_is_refused_rather_than_ignored()
    {
        // Ignoring it would answer over all time -  the original bug exactly.
        foreach (['last month', '2026-13-45', "2026-01-01' OR 1=1--", '01/07/2026'] as $bad) {
            $result = $this->build(['date_from' => $bad]);

            $this->assertFalse($result['success'], "'{$bad}' should be refused");
            $this->assertStringNotContainsString($bad, $result['sql'] ?? '');
        }
    }

    #[Test]
    public function a_reversed_period_is_read_the_way_it_was_meant()
    {
        $result = $this->build(['date_from' => '2026-07-31', 'date_to' => '2026-07-01']);

        $this->assertTrue($result['success']);
        $this->assertSame(['2026-07-01', '2026-07-31'], $result['bindings']);
    }

    #[Test]
    public function a_dataset_with_no_date_column_says_so()
    {
        config(['jeeves.schema.config_path' => __DIR__ . '/../Stubs/groupby-schemas']);
        $this->app->forgetInstance(SchemaRegistry::class);
        $this->app->forgetInstance(SqlBuilder::class);

        $result = $this->app->make(SqlBuilder::class)->buildQuery([
            'dataset' => 'gb_sales',
            'metric' => 'revenue',
            'date_from' => '2026-07-01',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('no date column', $result['error']);
    }

    /**
     * The name match is an OR. Without parentheses around it, AND-ing the
     * period on binds only to the second half and the period is effectively
     * lost -  silently, and only for filtered questions.
     */
    #[Test]
    public function a_period_survives_alongside_a_name_filter()
    {
        $result = $this->build([
            'group_value' => 'West',
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
        ]);

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertMatchesRegularExpression('/WHERE \(.*\) AND /s', $result['sql']);
        $this->assertStringContainsString('order_date >= ?', $result['sql']);
        $this->assertSame(
            ['West', '%West%', '2026-07-01', '2026-07-31', 'West'],
            $result['bindings'],
            'bindings must stay in the order the placeholders appear'
        );
    }

    #[Test]
    public function the_schema_chooses_which_date_column_is_meant()
    {
        // A table may carry order_date, shipped_at and created_at; they answer
        // different questions and the wording cannot disambiguate them.
        $registry = $this->app->make(SchemaRegistry::class);

        $this->assertSame('order_date', $registry->getDateColumn('tf_sales'));
    }

    #[Test]
    public function the_period_is_reported_back_in_the_result()
    {
        $result = $this->build(['date_from' => '2026-07-01', 'date_to' => '2026-07-31']);

        $this->assertSame('2026-07-01 to 2026-07-31', $result['time_filter']);
        $this->assertSame('order_date', $result['time_column']);
    }
}
