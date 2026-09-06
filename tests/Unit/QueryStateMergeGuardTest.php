<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Jayanta\Jeeves\Conversation\QueryState;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * What a follow-up is allowed to change.
 *
 * "Only in Guwahati" narrows. It does not choose a new measure and it does not
 * choose a new breakdown, so an intent that comes back with different ones has
 * re-guessed rather than answered -  and merging those silently swaps the
 * question out from under the user.
 *
 * A weaker model on Groq did exactly that: after "total amount by city", the
 * narrowing turn returned record_count by client, and the answer counted
 * invoices per client while the screen said amounts per city. Stronger models
 * carry state without being told; guarding it here is what makes conversations
 * work on the small open-weight models this package is meant to run on, where
 * that inference is precisely what is missing.
 */
class QueryStateMergeGuardTest extends TestCase
{
    private function established(): QueryState
    {
        return QueryState::fromIntent(
            ['dataset' => 'invoices', 'metric' => 'amount', 'group_by' => 'city'],
            1
        );
    }

    /** What the weak model sent back for "only in Guwahati". */
    private function reguessed(): array
    {
        return [
            'dataset' => 'invoices',
            'metric' => 'record_count',
            'group_by' => 'client',
            'filters' => [['column' => 'city', 'value' => 'Guwahati']],
        ];
    }

    #[Test]
    public function a_bare_narrowing_cannot_change_the_measure()
    {
        $merged = $this->established()->merge($this->reguessed(), 2, 'only in Guwahati')->toIntent();

        $this->assertSame('amount', $merged['metric'], 'the measure was swapped by a narrowing');
    }

    #[Test]
    public function a_bare_narrowing_cannot_change_the_breakdown()
    {
        $merged = $this->established()->merge($this->reguessed(), 2, 'only in Guwahati')->toIntent();

        $this->assertSame('city', $merged['group_by'], 'the breakdown was swapped by a narrowing');
    }

    #[Test]
    public function the_narrowing_itself_still_applies()
    {
        $merged = $this->established()->merge($this->reguessed(), 2, 'only in Guwahati')->toIntent();

        $this->assertSame('Guwahati', $merged['filters'][0]['value'] ?? null);
    }

    /**
     * The guard must not become a cage. A follow-up that DOES name a measure or
     * a breakdown is asking for the change and gets it.
     */
    #[Test]
    public function naming_a_measure_changes_it()
    {
        $merged = $this->established()
            ->merge(['metric' => 'record_count'], 2, 'how many invoices instead')
            ->toIntent();

        $this->assertSame('record_count', $merged['metric']);
    }

    #[Test]
    public function naming_a_breakdown_changes_it()
    {
        $merged = $this->established()
            ->merge(['group_by' => 'client'], 2, 'breakdown by client')
            ->toIntent();

        $this->assertSame('client', $merged['group_by']);
        // …and the measure, which was NOT named, survives.
        $this->assertSame('amount', $merged['metric']);
    }

    /**
     * Called without a question -  as older code and hand-built tests do -  the
     * guard cannot judge and stays out of the way.
     */
    #[Test]
    public function without_the_question_it_behaves_as_before()
    {
        $merged = $this->established()->merge($this->reguessed(), 2)->toIntent();

        $this->assertSame('record_count', $merged['metric']);
        $this->assertSame('client', $merged['group_by']);
    }

    /** Nothing established yet means nothing to protect. */
    #[Test]
    public function a_first_turn_is_not_constrained()
    {
        $merged = (new QueryState)
            ->merge(['dataset' => 'invoices', 'metric' => 'amount', 'group_by' => 'city'], 1, 'only in Guwahati')
            ->toIntent();

        $this->assertSame('amount', $merged['metric']);
        $this->assertSame('city', $merged['group_by']);
    }
}
