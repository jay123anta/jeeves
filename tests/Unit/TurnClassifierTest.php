<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Jayanta\Jeeves\Conversation\QueryState;
use Jayanta\Jeeves\Conversation\TurnClassifier;
use Jayanta\Jeeves\Schema\SchemaRegistry;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * What kind of turn is this?
 *
 * The distinction is not cosmetic. A drill-down changes only the breakdown and
 * leaves everything else standing; a refinement merges whatever the model
 * re-read from the sentence, which means a re-guessed measure can overwrite the
 * one already established. Getting the label wrong therefore risks getting the
 * answer wrong, not just the badge.
 *
 * Found in a browser: "total amount by city" then "breakdown by client" was
 * reported as a refinement. Nothing had been narrowed -  the totals matched at
 * 12,100 -  and the sentence is plainly a drill-down. The cause was one space:
 * every drill pattern read `break\s+…`, so the one-word spelling people
 * actually type fell through to the refinement default.
 */
class TurnClassifierTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/groupby-schemas');
    }

    private function classify(string $question): string
    {
        $classifier = new TurnClassifier($this->app->make(SchemaRegistry::class));

        // A turn already on the table, so there is something to continue.
        $state = QueryState::fromIntent(
            ['dataset' => 'gb_sales', 'metric' => 'revenue', 'group_by' => 'region'],
            1
        );

        return $classifier->classify($question, $state);
    }

    public static function drillDowns(): array
    {
        return [
            // The one that was misread. One word, no space.
            'breakdown by X' => ['breakdown by customer_name'],
            'break down by X' => ['break down by customer_name'],
            'break-down by X' => ['break-down by customer_name'],
            'break that down' => ['break that down by customer_name'],
            'break it down' => ['break it down'],
            'split by X' => ['split by customer_name'],
            'split that' => ['split that by customer_name'],
            'group by X' => ['group by customer_name'],
            'grouped by X' => ['grouped by customer_name'],
            'drill into X' => ['drill into customer_name'],
            'by which X' => ['by which customer_name'],
        ];
    }

    #[DataProvider('drillDowns')]
    #[Test]
    public function a_change_of_breakdown_is_a_drill_down(string $question)
    {
        $this->assertSame(TurnClassifier::DRILL_DOWN, $this->classify($question), $question);
    }

    /**
     * The words also appear in self-contained questions, and those are new.
     * This one names its own measure -  it is asking something, not adjusting
     * something -  and inheriting the last turn's filters would quietly narrow
     * an answer nobody asked to narrow.
     *
     * 'revenue' because it is a real metric on the fixture. A word the schema
     * does not know is not "naming a measure", and using one would have made
     * this pass for the wrong reason.
     */
    #[Test]
    public function a_question_that_names_its_own_measure_is_new_even_with_a_drill_word()
    {
        $this->assertSame(
            TurnClassifier::NEW_QUERY,
            $this->classify('what is the breakdown of revenue by region')
        );
    }

    /**
     * Except when it points at the last answer in so many words. "That" beats
     * naming a measure: it can only mean the thing on screen. Same measure as
     * the case above, so the difference being tested really is the "that".
     */
    #[Test]
    public function pointing_at_the_last_answer_wins_over_naming_a_measure()
    {
        $this->assertSame(
            TurnClassifier::DRILL_DOWN,
            $this->classify('break that down by revenue')
        );
    }

    #[Test]
    public function narrowing_is_still_a_refinement()
    {
        $this->assertSame(TurnClassifier::REFINEMENT, $this->classify('only in West'));
    }

    #[Test]
    public function a_fresh_question_is_still_new()
    {
        $this->assertSame(TurnClassifier::NEW_QUERY, $this->classify('how many orders by status'));
    }

    #[Test]
    public function the_first_turn_of_a_conversation_is_always_new()
    {
        $classifier = new TurnClassifier($this->app->make(SchemaRegistry::class));

        $this->assertSame(
            TurnClassifier::NEW_QUERY,
            $classifier->classify('breakdown by customer_name', new QueryState([], 0))
        );
    }
}
