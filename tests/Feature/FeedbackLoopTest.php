<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Jayanta\Jeeves\Contracts\SchemaIntrospectorInterface;
use Jayanta\Jeeves\Engine\PromptBuilder;
use Jayanta\Jeeves\Feedback\FeedbackStore;
use Jayanta\Jeeves\Schema\Introspectors\MysqlIntrospector;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Does submitted feedback actually reach the next prompt?
 *
 * The package advertises that corrections teach the model, and the wiring
 * looks right by inspection -  but "looks right" is how the ai-guard
 * integration passed review while never once firing. A feature that silently
 * does nothing is worse than one that does not exist, because nobody goes
 * looking for it.
 *
 * So this closes the loop for real: record a correction, build the prompt the
 * next question would use, and require the correction to be in it.
 */
class FeedbackLoopTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // NQ-001: an app connection to seed through, and a separate
        // name the package reads through, over one database.
        $this->isolatedDatabase($app, 'nq_feedback');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The package ships the feedback table; load it the way an app would.
        $this->artisan('migrate', ['--force' => true])->run();

        $this->app->instance(SchemaIntrospectorInterface::class, new MysqlIntrospector);
    }

    private function store(): FeedbackStore
    {
        return $this->app->make(FeedbackStore::class);
    }

    private function prompt(string $dataset = 'test_orders'): string
    {
        return $this->app->make(PromptBuilder::class)->buildSqlPrompt($dataset, 'top customers by amount');
    }

    #[Test]
    public function a_recorded_correction_appears_in_the_next_prompt()
    {
        $this->store()->recordCorrection(
            'total revenue last month',
            'test_orders',
            'SELECT SUM(amount) FROM public.orders',
            'Cancelled orders must be excluded from revenue',
            "SELECT SUM(amount) FROM public.orders WHERE status <> 'cancelled'",
            'wrong_filter'
        );

        $prompt = $this->prompt();

        $this->assertStringContainsString('PAST CORRECTIONS', $prompt);
        $this->assertStringContainsString('total revenue last month', $prompt);
        $this->assertStringContainsString('Cancelled orders must be excluded from revenue', $prompt);
        $this->assertStringContainsString("WHERE status <> 'cancelled'", $prompt);
    }

    #[Test]
    public function corrections_do_not_leak_between_datasets()
    {
        // A correction about one dataset is noise at best and misleading at
        // worst when the question is about another.
        $this->store()->recordCorrection(
            'total revenue last month',
            'test_orders',
            'SELECT 1',
            'Cancelled orders must be excluded',
            null,
            'wrong_filter'
        );

        $this->assertStringNotContainsString(
            'Cancelled orders must be excluded',
            $this->prompt('test_districts')
        );
    }

    #[Test]
    public function a_positive_signal_is_not_replayed_as_a_correction()
    {
        $this->store()->recordPositive('top customers', 'test_orders', 'SELECT 1');

        $this->assertStringNotContainsString('PAST CORRECTIONS', $this->prompt());
    }

    #[Test]
    public function no_corrections_means_no_corrections_section()
    {
        $this->assertStringNotContainsString('PAST CORRECTIONS', $this->prompt());
    }

    #[Test]
    public function a_missing_feedback_table_does_not_break_prompt_building()
    {
        // Feedback is optional: an app that never ran the migration, or turned
        // the feature off, must still be able to ask questions.
        $this->app['db']->connection()->statement('DROP TABLE IF EXISTS jeeves_feedback');

        $prompt = $this->prompt();

        $this->assertStringContainsString('public.orders', $prompt);
        $this->assertStringNotContainsString('PAST CORRECTIONS', $prompt);
    }

    #[Test]
    public function statistics_reflect_what_was_recorded()
    {
        $this->store()->recordCorrection('q1', 'test_orders', 'SELECT 1', 'wrong column', null, 'wrong_column');
        $this->store()->recordPositive('q2', 'test_orders', 'SELECT 1');

        $stats = $this->store()->getStatistics();

        $this->assertIsArray($stats);
        $this->assertNotEmpty($stats);
    }
}
