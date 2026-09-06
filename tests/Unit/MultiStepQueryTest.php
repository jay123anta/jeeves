<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Jayanta\Jeeves\Engine\QueryPlanner;
use Jayanta\Jeeves\Engine\StepSynthesizer;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * "Compare revenue this year with last year" is two queries and a subtraction.
 * Answered as one, it returns whichever half the model wrote SQL for and drops
 * the other silently -  the same shape of failure as a dropped GROUP BY.
 *
 * Planning costs an API call, so the gate that decides whether to plan matters
 * as much as the planning: an ordinary question must take the ordinary path at
 * the ordinary price.
 */
class MultiStepQueryTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/groupby-schemas');
    }

    private function planner(): QueryPlanner
    {
        return $this->app->make(QueryPlanner::class);
    }

    #[Test]
    public function ordinary_questions_never_trigger_a_planning_call()
    {
        // Every one of these is answerable by a single SELECT. Planning them
        // would double the cost of the common case for nothing.
        foreach ([
            'top 5 customers by revenue',
            'revenue by region',
            'how many orders by status',
            'total revenue',
            'show me Talukdar & Co',
        ] as $query) {
            $this->assertFalse(
                $this->planner()->looksMultiStep($query),
                "'{$query}' needs one query, not a plan"
            );
        }
    }

    #[Test]
    public function questions_about_two_things_are_recognised()
    {
        foreach ([
            'compare revenue this year vs last year',
            'revenue this year versus last year',
            'difference between West and East',
            'revenue compared to last quarter',
            'year on year revenue',
            'show revenue and also the order count',
        ] as $query) {
            $this->assertTrue(
                $this->planner()->looksMultiStep($query),
                "'{$query}' asks about more than one thing"
            );
        }
    }

    #[Test]
    public function planning_can_be_switched_off_entirely()
    {
        config(['jeeves.chat.multi_step' => false]);

        $this->assertFalse($this->planner()->looksMultiStep('compare this year vs last year'));
    }

    #[Test]
    public function a_comparison_of_two_totals_is_computed_in_php()
    {
        // Never by asking a model to subtract two numbers it can already see.
        $result = (new StepSynthesizer)->synthesize('compare', [
            ['question' => 'revenue in 2026', 'status' => 'success', 'metric' => 'revenue',
                'rows' => [['revenue' => 21011088]], 'answer' => 'Total revenue: 21,011,088'],
            ['question' => 'revenue in 2025', 'status' => 'success', 'metric' => 'revenue',
                'rows' => [['revenue' => 18244900]], 'answer' => 'Total revenue: 18,244,900'],
        ], true);

        $this->assertSame(15.2, $result['comparison']['change_pct']);
        $this->assertSame('up', $result['comparison']['direction']);
        $this->assertStringContainsString('15.2', $result['answer']);
    }

    #[Test]
    public function a_fall_is_reported_as_a_fall()
    {
        $result = (new StepSynthesizer)->synthesize('compare', [
            ['question' => 'this year', 'status' => 'success', 'metric' => 'revenue', 'rows' => [['revenue' => 80]]],
            ['question' => 'last year', 'status' => 'success', 'metric' => 'revenue', 'rows' => [['revenue' => 100]]],
        ], true);

        $this->assertSame(-20.0, $result['comparison']['change_pct']);
        $this->assertSame('down', $result['comparison']['direction']);
    }

    /**
     * A percentage between two things that are not comparable is a number
     * invented by the package and presented with the same confidence as a real
     * one. Refusing to produce it is the whole point.
     */
    #[Test]
    public function incomparable_steps_produce_no_percentage()
    {
        $ranking = ['question' => 'top regions', 'status' => 'success', 'metric' => 'revenue',
            'rows' => [['region' => 'West', 'revenue' => 5], ['region' => 'East', 'revenue' => 4]]];
        $total = ['question' => 'total revenue', 'status' => 'success', 'metric' => 'revenue',
            'rows' => [['revenue' => 9]]];

        $result = (new StepSynthesizer)->synthesize('q', [$ranking, $total], true);

        $this->assertNull($result['comparison'], 'a ranking and a total are not comparable');
    }

    #[Test]
    public function two_different_metrics_are_never_compared()
    {
        $result = (new StepSynthesizer)->synthesize('q', [
            ['question' => 'revenue', 'status' => 'success', 'metric' => 'revenue', 'rows' => [['revenue' => 100]]],
            ['question' => 'orders', 'status' => 'success', 'metric' => 'record_count', 'rows' => [['record_count' => 50]]],
        ], true);

        $this->assertNull($result['comparison'], 'revenue against a row count is not a 50% fall');
    }

    #[Test]
    public function dividing_by_zero_does_not_produce_a_percentage()
    {
        $result = (new StepSynthesizer)->synthesize('q', [
            ['question' => 'this year', 'status' => 'success', 'metric' => 'revenue', 'rows' => [['revenue' => 500]]],
            ['question' => 'last year', 'status' => 'success', 'metric' => 'revenue', 'rows' => [['revenue' => 0]]],
        ], true);

        $this->assertNull($result['comparison']['change_pct']);
        $this->assertSame('not comparable', $result['comparison']['direction']);
    }

    #[Test]
    public function a_failed_step_is_reported_rather_than_hidden()
    {
        // Three numbers out of four beats none, provided the answer says so.
        $result = (new StepSynthesizer)->synthesize('q', [
            ['question' => 'revenue in 2026', 'status' => 'success', 'metric' => 'revenue',
                'rows' => [['revenue' => 10]], 'answer' => 'Total revenue: 10'],
            ['question' => 'revenue in 1900', 'status' => 'error', 'answer' => 'No data found'],
        ], false);

        $this->assertStringContainsString('1 of 2 steps could not be answered', $result['answer']);
    }

    #[Test]
    public function every_step_failing_is_stated_plainly()
    {
        $result = (new StepSynthesizer)->synthesize('q', [
            ['question' => 'a', 'status' => 'error'],
            ['question' => 'b', 'status' => 'error'],
        ], true);

        $this->assertStringContainsString('None of the steps', $result['answer']);
        $this->assertNull($result['comparison']);
    }
}
