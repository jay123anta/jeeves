<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Jayanta\Jeeves\Conversation\QueryState;
use Jayanta\Jeeves\Conversation\StateValidator;
use Jayanta\Jeeves\Conversation\TurnClassifier;
use Jayanta\Jeeves\Schema\SchemaRegistry;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The conversation carries a structured state rather than a transcript.
 *
 * Resolving a follow-up by rewriting it into a new sentence lost something on
 * every turn -  "only in West" became "show only in West details in Orders" and
 * the metric disappeared -  and made every turn a fresh interpretation of the
 * whole dialogue, so an early misreading propagated unpredictably.
 *
 * With slots, the merge is deterministic PHP, the model resolves one
 * instruction against a compact object, and going back a turn is a restore
 * rather than another guess.
 */
class ConversationStateTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/groupby-schemas');
    }

    private function classifier(): TurnClassifier
    {
        return $this->app->make(TurnClassifier::class);
    }

    private function started(): QueryState
    {
        return new QueryState(['dataset' => 'gb_sales', 'metric' => 'revenue', 'group_by' => 'customer_name'], 1);
    }

    // ------------------------------------------------------------ classify

    #[Test]
    public function the_first_turn_is_always_a_new_query()
    {
        $this->assertSame(
            TurnClassifier::NEW_QUERY,
            $this->classifier()->classify('anything at all', new QueryState)
        );
    }

    #[Test]
    public function a_narrowing_instruction_is_a_refinement()
    {
        foreach (['only in West', 'just delivered ones', 'now for last month', 'what about Europe'] as $q) {
            $this->assertSame(TurnClassifier::REFINEMENT, $this->classifier()->classify($q, $this->started()), $q);
        }
    }

    /**
     * A correction changes one thing and leaves the rest standing. Read as a
     * new question, "actually make that East" silently dropped every other
     * narrowing in force -  the user corrected the region and lost the category.
     */
    #[Test]
    public function a_correction_is_a_refinement_not_a_new_question()
    {
        foreach ([
            'actually make that East',
            'no, the North region',
            'change that to pending',
            'switch to Electronics',
        ] as $q) {
            $this->assertSame(TurnClassifier::REFINEMENT, $this->classifier()->classify($q, $this->started()), $q);
        }
    }

    #[Test]
    public function filters_accumulate_rather_than_replacing_one_another()
    {
        // "Only in West" then "and what about Electronics?" means BOTH.
        $next = $this->started()
            ->merge(['filter_column' => 'region', 'group_value' => 'West'], 2)
            ->merge(['filter_column' => 'product_category', 'group_value' => 'Electronics'], 3);

        $filters = $next->get('filters');

        $this->assertCount(2, $filters);
        $this->assertSame(['region', 'product_category'], array_column($filters, 'column'));
        $this->assertSame(['West', 'Electronics'], array_column($filters, 'value'));
    }

    #[Test]
    public function naming_the_same_column_again_corrects_it_rather_than_stacking()
    {
        // "Actually, East" is a correction of the region, not a second region.
        $next = $this->started()
            ->merge(['filter_column' => 'region', 'group_value' => 'West'], 2)
            ->merge(['filter_column' => 'product_category', 'group_value' => 'Electronics'], 3)
            ->merge(['filter_column' => 'region', 'group_value' => 'East'], 4);

        $filters = $next->get('filters');

        $this->assertCount(2, $filters, 'still two filters, not three');
        $this->assertSame('Electronics', $filters[0]['value'], 'the category survives the correction');
        $this->assertSame('East', $filters[1]['value']);
    }

    #[Test]
    public function every_live_filter_appears_in_the_summary()
    {
        // A line showing one filter while two are applied invites the reader
        // to trust a narrowing they cannot see.
        $summary = $this->started()
            ->merge(['filter_column' => 'region', 'group_value' => 'West'], 2)
            ->merge(['filter_column' => 'product_category', 'group_value' => 'Electronics'], 3)
            ->summary($this->app->make(SchemaRegistry::class));

        $this->assertStringContainsString('region is West', $summary);
        $this->assertStringContainsString('product category is Electronics', $summary);
    }

    #[Test]
    public function asking_for_more_detail_is_a_drill_down()
    {
        foreach (['break that down by region', 'in which category', 'split it by status'] as $q) {
            $this->assertSame(TurnClassifier::DRILL_DOWN, $this->classifier()->classify($q, $this->started()), $q);
        }
    }

    #[Test]
    public function asking_about_the_answer_is_a_reference()
    {
        foreach (['why is that?', 'explain', 'tell me more'] as $q) {
            $this->assertSame(TurnClassifier::REFERENCE, $this->classifier()->classify($q, $this->started()), $q);
        }
    }

    /**
     * The failure users actually notice: they ask something unrelated and get
     * results still filtered by a region from three turns ago. A refinement
     * misread as new merely loses inherited context and is repeated; a new
     * question misread as a refinement returns a confident wrong number.
     */
    #[Test]
    public function a_question_that_names_its_own_measure_is_never_a_refinement()
    {
        foreach ([
            'revenue by status',
            'how many orders by region',
            'only revenue by region',      // starts like a refinement, is not
        ] as $q) {
            $this->assertSame(TurnClassifier::NEW_QUERY, $this->classifier()->classify($q, $this->started()), $q);
        }
    }

    // --------------------------------------------------------------- merge

    #[Test]
    public function a_refinement_keeps_what_it_does_not_mention()
    {
        $next = $this->started()->merge(['group_value' => 'West', 'filter_column' => 'region'], 2);

        $this->assertSame('revenue', $next->get('metric'), 'the metric must survive');
        $this->assertSame('customer_name', $next->get('group_by'));
        $this->assertSame('West', $next->get('group_value'));
        $this->assertSame('region', $next->get('filter_column'));
    }

    #[Test]
    public function a_new_filter_value_never_keeps_the_old_filter_column()
    {
        // Otherwise "Grocery" is matched against whatever column the previous
        // filter used, which is how a category was looked for among customers.
        $next = $this->started()
            ->merge(['group_value' => 'West', 'filter_column' => 'region'], 2)
            ->merge(['group_value' => 'Grocery'], 3);

        $this->assertSame('Grocery', $next->get('group_value'));
        $this->assertNull($next->get('filter_column'));
    }

    #[Test]
    public function a_new_query_inherits_nothing()
    {
        $next = $this->started()->replace(['dataset' => 'gb_sales', 'metric' => 'record_count'], 2);

        $this->assertSame('record_count', $next->get('metric'));
        $this->assertNull($next->get('group_by'));
        $this->assertNull($next->get('group_value'));
    }

    #[Test]
    public function a_drill_down_adds_a_breakdown_and_changes_nothing_else()
    {
        $next = $this->started()
            ->merge(['group_value' => 'West', 'filter_column' => 'region'], 2)
            ->drillDown('status', 3);

        $this->assertSame('status', $next->get('group_by'));
        $this->assertSame('revenue', $next->get('metric'));
        $this->assertSame('West', $next->get('group_value'));
    }

    // ------------------------------------------------------------ validate

    #[Test]
    public function a_metric_that_does_not_exist_becomes_a_question_not_a_query()
    {
        $result = $this->app->make(StateValidator::class)
            ->validate(new QueryState(['dataset' => 'gb_sales', 'metric' => 'gross_margin'], 1));

        $this->assertFalse($result['valid']);
        $this->assertSame('metric', $result['slot']);
        $this->assertStringContainsString('gross_margin', $this->app->make(StateValidator::class)->question($result));
    }

    #[Test]
    public function a_breakdown_that_is_not_a_dimension_becomes_a_question()
    {
        $result = $this->app->make(StateValidator::class)
            ->validate(new QueryState(['dataset' => 'gb_sales', 'metric' => 'revenue', 'group_by' => 'shoe_size'], 1));

        $this->assertFalse($result['valid']);
        $this->assertSame('group_by', $result['slot']);
    }

    #[Test]
    public function validation_resolves_slots_to_their_schema_names()
    {
        // "sales" is an alias of revenue; resolving it once means the rest of
        // the conversation carries the canonical name.
        $result = $this->app->make(StateValidator::class)
            ->validate(new QueryState(['dataset' => 'gb_sales', 'metric' => 'sales', 'group_by' => 'territory'], 1));

        $this->assertTrue($result['valid']);
        $this->assertSame('revenue', $result['state']->get('metric'));
        $this->assertSame('region', $result['state']->get('group_by'));
    }

    // ------------------------------------------------------------- summary

    #[Test]
    public function the_state_can_be_read_back_to_the_user()
    {
        $state = $this->started()->merge(['group_value' => 'West', 'filter_column' => 'region'], 2);
        $summary = $state->summary($this->app->make(SchemaRegistry::class));

        $this->assertStringContainsString('revenue', $summary);
        $this->assertStringContainsString('by customer name', $summary);
        $this->assertStringContainsString('region is West', $summary);
    }

    #[Test]
    public function state_survives_a_round_trip_through_the_cache()
    {
        $state = $this->started()->merge(['group_value' => 'West'], 2);
        $restored = QueryState::fromArray($state->toArray());

        $this->assertSame($state->toIntent(), $restored->toIntent());
        $this->assertSame(2, $restored->seq);
    }
}
