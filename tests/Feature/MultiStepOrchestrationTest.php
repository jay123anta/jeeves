<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Engine\QueryPlanner;
use Jayanta\Jeeves\Tests\Support\RecordingProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * End to end: a question about two things is answered by two real queries
 * against a real table, and combined.
 *
 * The assertion that matters most here is the count of planning calls. Each
 * step re-enters the orchestrator, and if a step could be planned in turn the
 * recursion has no floor -  the first comparison question would spend API calls
 * until something ran out.
 */
class MultiStepOrchestrationTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/groupby-schemas');
        $app['config']->set('jeeves.query_mode', 'intent');
        $app['config']->set('jeeves.cache.enabled', false);
        $app['config']->set('jeeves.feedback.enabled', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('gb_sales', function ($t) {
            $t->id();
            $t->string('customer_name');
            $t->string('region');
            $t->string('status');
            $t->decimal('revenue', 12, 2);
        });

        DB::table('gb_sales')->insert([
            ['customer_name' => 'A', 'region' => 'West', 'status' => 'delivered', 'revenue' => 300],
            ['customer_name' => 'B', 'region' => 'West', 'status' => 'delivered', 'revenue' => 200],
            ['customer_name' => 'C', 'region' => 'East', 'status' => 'delivered', 'revenue' => 400],
        ]);
    }

    /** Plans on request, and resolves each step to a region filter. */
    private function scriptedProvider(): RecordingProvider
    {
        $provider = new RecordingProvider;

        $provider->sqlResponse = fn ($prompt) => [
            'success' => true,
            'data' => [
                'steps' => ['revenue in West', 'revenue in East'],
                'comparison' => true,
            ],
        ];

        $provider->intentResponse = fn ($text) => [
            'success' => true,
            'dataset' => 'gb_sales',
            'metric' => 'revenue',
            'group_value' => str_contains($text, 'West') ? 'West' : 'East',
            'group_by' => 'region',
            'limit' => 10,
            'order' => 'desc',
            'confidence' => 0.9,
            'needs_clarification' => false,
        ];

        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);
        $this->app->forgetInstance(QueryPlanner::class);

        return $provider;
    }

    #[Test]
    public function a_comparison_question_runs_one_query_per_step_and_combines_them()
    {
        $this->scriptedProvider();

        $result = $this->app->make(QueryOrchestrator::class)
            ->query('compare revenue in West vs revenue in East');

        $this->assertSame('success', $result['status']);
        $this->assertSame('multi_step', $result['type']);
        $this->assertCount(2, $result['steps']);

        $this->assertSame('revenue in West', $result['steps'][0]['question']);
        $this->assertSame('revenue in East', $result['steps'][1]['question']);
        $this->assertSame('success', $result['steps'][0]['status']);
        $this->assertSame('success', $result['steps'][1]['status']);
    }

    #[Test]
    public function the_numbers_come_from_the_database_and_the_maths_is_ours()
    {
        $this->scriptedProvider();

        $result = $this->app->make(QueryOrchestrator::class)
            ->query('compare revenue in West vs revenue in East');

        // West totals 500, East 400 -  25% higher.
        $this->assertSame(500.0, $result['comparison']['first_value']);
        $this->assertSame(400.0, $result['comparison']['second_value']);
        $this->assertSame(25.0, $result['comparison']['change_pct']);
        $this->assertSame('up', $result['comparison']['direction']);
    }

    #[Test]
    public function a_step_is_never_itself_decomposed()
    {
        $provider = $this->scriptedProvider();

        $this->app->make(QueryOrchestrator::class)
            ->query('compare revenue in West vs revenue in East');

        $planningCalls = count(array_filter(
            $provider->methodsCalled(),
            fn ($m) => $m === 'generateSql'
        ));

        $this->assertSame(1, $planningCalls, 'planning must happen once, not once per step');
    }

    #[Test]
    public function an_ordinary_question_costs_no_planning_call_and_is_unchanged()
    {
        $provider = $this->scriptedProvider();

        $result = $this->app->make(QueryOrchestrator::class)->query('revenue in West');

        $this->assertSame('success', $result['status']);
        $this->assertNotSame('multi_step', $result['type'] ?? null);
        $this->assertNotContains('generateSql', $provider->methodsCalled());
    }

    #[Test]
    public function a_planner_failure_leaves_the_question_answerable()
    {
        // Planning is an enhancement. If it fails the question must still be
        // answered the ordinary way, not turned into an error.
        $provider = $this->scriptedProvider();
        $provider->sqlResponse = ['success' => false, 'error' => 'rate limited'];

        $result = $this->app->make(QueryOrchestrator::class)
            ->query('compare revenue in West vs revenue in East');

        $this->assertSame('success', $result['status']);
        $this->assertNotSame('multi_step', $result['type'] ?? null);
    }

    /**
     * Decomposition adds a planning call, which is a new thing leaving the
     * server. It carries the question and the dataset list -  never a value
     * read out of the database.
     */
    #[Test]
    public function the_planning_call_transmits_no_data()
    {
        $provider = $this->scriptedProvider();

        $this->app->make(QueryOrchestrator::class)
            ->query('compare revenue in West vs revenue in East');

        $transmitted = $provider->transmitted();

        // Figures and customer names exist only in the rows fetched locally.
        foreach (['300', '200', '400', 'customer_name":"A'] as $value) {
            $this->assertStringNotContainsString(
                $value,
                $transmitted,
                "a value from the result set reached the provider: {$value}"
            );
        }
    }

    #[Test]
    public function answers_carry_suggested_follow_ups()
    {
        $this->scriptedProvider();

        $result = $this->app->make(QueryOrchestrator::class)->query('revenue in West');

        $this->assertNotEmpty($result['next_steps'] ?? []);
        $this->assertArrayHasKey('label', $result['next_steps'][0]);
        $this->assertArrayHasKey('query', $result['next_steps'][0]);
    }
}
