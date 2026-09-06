<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Jayanta\Jeeves\Conversation\QueryState;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * A label describing ONE answer must not become an instruction for the next.
 *
 * The period reaches `summary()` as a rendered label — "July 2026" — not as a
 * range. Two labels cannot be intersected the way two dates can, so carrying
 * one forward re-applies a window the next question never asked for.
 *
 * The first attempt made it a slot and excluded it inside `merge()` and
 * `toIntent()`. That was wrong in BOTH directions at once, which is why this
 * file tests both:
 *
 *  - It still carried. `merge()` begins by copying every slot and only
 *    ITERATES the carrying list, and `drillDown()` and `withoutFilters()` copy
 *    the slots too and were never touched. Measured cost: "total revenue in
 *    July" then "only in West" answered 600 — West all-time — under the
 *    heading "July 2026", when West in July is 100.
 *  - And it was destroyed. `StateValidator` rebuilds the state from
 *    `toIntent()`, so on the conversation route the label never survived to
 *    reach `state_summary` at all, and the widget prefers `state_summary`.
 *
 * It is no longer a slot. Nothing that copies slots can move it, and nothing
 * that rebuilds from slots can lose it. These tests pin that property rather
 * than the four call sites that used to have to remember it.
 */
class ThePeriodLabelDoesNotCarryTest extends TestCase
{
    private function labelled(): QueryState
    {
        return QueryState::fromIntent([
            'dataset' => 'sales',
            'metric' => 'revenue',
            'group_by' => 'region',
            'period' => 'July 2026',
        ]);
    }

    /** It is held for the answer it describes. */
    #[Test]
    public function the_answer_it_describes_carries_the_label()
    {
        $state = $this->labelled();

        $this->assertSame('July 2026', $state->period());
        $this->assertStringContainsString('July 2026', $state->summary());
    }

    /** And no derivation of that state carries it — all four, not two. */
    #[Test]
    public function no_derived_state_carries_the_label()
    {
        $state = $this->labelled();

        $derivations = [
            'merge' => $state->merge(['filters' => [['column' => 'region', 'value' => 'West']]], 2, 'only in West'),
            'drillDown' => $state->drillDown('status', 2),
            'withoutFilters' => $state->withoutFilters(2),
            'fromArray(toArray)' => QueryState::fromArray($state->toArray()),
        ];

        foreach ($derivations as $how => $derived) {
            $this->assertNull(
                $derived->period(),
                "[{$how}] the previous answer's period survived into a derived state, so a follow-up "
                    . 'is captioned with — and prompted to re-apply — a window it never asked for'
            );
            $this->assertStringNotContainsString('July 2026', $derived->summary(), "[{$how}]");
        }
    }

    /** It never reaches the prompt, which is where it would change an answer. */
    #[Test]
    public function the_label_is_not_exported_to_the_state_prompt()
    {
        $intent = $this->labelled()->toIntent();

        $this->assertArrayNotHasKey(
            'period',
            $intent,
            'toIntent() feeds the CURRENT QUERY STATE block of the next prompt; a label describing '
                . 'the last answer is not an instruction the model should carry forward'
        );
    }

    /**
     * The other direction, which the first fix broke: rebuilding a state from
     * its slots is what StateValidator does on every turn, and it must not
     * destroy anything that carries.
     */
    #[Test]
    public function the_slots_that_carry_survive_a_rebuild()
    {
        $state = QueryState::fromIntent([
            'dataset' => 'sales',
            'metric' => 'revenue',
            'group_by' => 'region',
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
            'filters' => [['column' => 'region', 'value' => 'West']],
            'period' => 'July 2026',
        ]);

        $rebuilt = new QueryState($state->toIntent(), $state->seq, $state->refinements);

        $this->assertSame('sales', $rebuilt->get('dataset'));
        $this->assertSame('revenue', $rebuilt->get('metric'));
        $this->assertSame('region', $rebuilt->get('group_by'));
        $this->assertSame('2026-07-01', $rebuilt->get('date_from'));
        $this->assertSame('2026-07-31', $rebuilt->get('date_to'));
        $this->assertNotEmpty($rebuilt->get('filters'));
    }

    /** A resolved RANGE is a real slot and does carry — only the label does not. */
    #[Test]
    public function a_resolved_date_range_still_carries()
    {
        $state = QueryState::fromIntent([
            'dataset' => 'sales',
            'metric' => 'revenue',
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
        ]);

        $next = $state->merge([], 2, 'only in West');

        $this->assertSame('2026-07-01', $next->get('date_from'));
        $this->assertArrayHasKey('date_from', $state->toIntent());
    }
}
