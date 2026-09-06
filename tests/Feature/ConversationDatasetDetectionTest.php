<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Jayanta\Jeeves\Conversation\QueryState;
use Jayanta\Jeeves\Conversation\TurnClassifier;
use Jayanta\Jeeves\Schema\SchemaRegistry;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The package has to work against any application's database, so nothing in it
 * may assume a particular domain.
 *
 * Deciding whether a short utterance names a DIFFERENT dataset -  and so starts
 * a new question -  or is a value to filter the current one by used to match a
 * hardcoded list of dataset names from the project this package was extracted
 * from. On every other application that list matched nothing, so short
 * follow-ups were always treated as record lookups, and an unrelated app that
 * happened to mention one of those words was told it had switched dataset.
 *
 * The decision now belongs to TurnClassifier, and it asks the registry. These
 * cases run against the test schemas (test_orders, test_districts), which share
 * no vocabulary with that list.
 */
class ConversationDatasetDetectionTest extends TestCase
{
    /** A conversation already in progress, so there is something to refine. */
    private function classify(string $text): string
    {
        $state = new QueryState(['dataset' => 'test_orders', 'metric' => 'amount'], 1);

        return $this->app->make(TurnClassifier::class)->classify($text, $state);
    }

    #[Test]
    public function it_recognises_a_dataset_registered_by_this_application()
    {
        // 'test_orders' declares aliases: orders, sales, revenue. Naming one
        // means asking something new, not narrowing what is on screen.
        $this->assertSame(TurnClassifier::NEW_QUERY, $this->classify('orders'));
        $this->assertSame(TurnClassifier::NEW_QUERY, $this->classify('sales'));
        $this->assertSame(TurnClassifier::NEW_QUERY, $this->classify('what about orders'));
    }

    #[Test]
    public function it_does_not_recognise_vocabulary_from_some_other_project()
    {
        // The old hardcoded list. None of these are datasets here, so treating
        // them as such was wrong on every application but one.
        foreach (['basundhara', 'nrega', 'pmay', 'nfsa', 'rti', 'housing', 'waste'] as $foreign) {
            $this->assertSame(
                TurnClassifier::REFINEMENT,
                $this->classify($foreign),
                "'{$foreign}' is not a dataset in this application and must not start a new question"
            );
        }
    }

    #[Test]
    public function a_customer_name_is_not_mistaken_for_a_dataset()
    {
        // The whole point: these are values to filter by, not datasets to
        // switch to.
        $this->assertSame(TurnClassifier::REFINEMENT, $this->classify('Kalita Stores'));
        $this->assertSame(TurnClassifier::REFINEMENT, $this->classify('North'));
    }

    #[Test]
    public function a_dataset_name_inside_a_longer_word_does_not_count()
    {
        // Substring matching would fire on "reorder" for a dataset called
        // "order", which is how a value gets mistaken for a dataset.
        $this->assertSame(TurnClassifier::REFINEMENT, $this->classify('reordering'));
        $this->assertSame(TurnClassifier::NEW_QUERY, $this->classify('salesforce integration platform costs'));
    }

    #[Test]
    public function it_handles_an_application_with_no_schemas_at_all()
    {
        config(['jeeves.schema.config_path' => __DIR__ . '/../Stubs/does-not-exist']);
        $this->app->forgetInstance(SchemaRegistry::class);
        $this->app->forgetInstance(TurnClassifier::class);

        $this->assertSame(TurnClassifier::REFINEMENT, $this->classify('anything'));
    }
}
