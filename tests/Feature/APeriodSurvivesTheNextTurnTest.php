<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Conversation\ConversationManager;
use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Tests\Support\RecordingProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * A period established in one turn still applies to the next.
 *
 * "Revenue in July" then "only in West" must answer West IN JULY. It answered
 * West all-time, and nothing in the response said the period had gone.
 *
 * The slots exist and `merge()` carries them: `date_from` and `date_to` are in
 * QueryState::SLOTS and always have been. The break is the FEED.
 * `ConversationManager::advance()` builds the next state from
 * `$result['parsed_query']`, and `parsed_query` carries only a rendered
 * `period` label - "2026-07-01 to 2026-07-31" - never the two dates. So
 * `fromIntent()` reads null for both on every turn, and no conversation has
 * ever held a date range.
 *
 * This is why a unit test on QueryState could not see it, and one existed:
 * it built the state by hand with dates the engine never supplies. The class
 * was never the problem.
 *
 * Fixture `ps_orders`, hand-checked:
 *   West/2026-07-05/100, East/2026-07-06/200, West/2025-01-06/500
 *   July 2026 = 300 · West all-time = 600 · West in July = 100
 * Three distinct numbers, so a dropped period cannot pass by coincidence.
 */
class APeriodSurvivesTheNextTurnTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/period-carry-schemas');
        $app['config']->set('jeeves.cache.enabled', false);
        $app['config']->set('jeeves.verification.enabled', false);
        $app['config']->set('jeeves.query_mode', 'intent');
    }

    private function seedOrders(): void
    {
        Schema::dropIfExists('ps_orders');
        Schema::create('ps_orders', function ($t) {
            $t->id();
            $t->string('region');
            $t->date('placed_on');
            $t->decimal('revenue', 12, 2);
        });

        foreach ([
            ['West', '2026-07-05', 100],
            ['East', '2026-07-06', 200],
            ['West', '2025-01-06', 500],
        ] as [$r, $d, $v]) {
            DB::table('ps_orders')->insert(['region' => $r, 'placed_on' => $d, 'revenue' => $v]);
        }
    }

    private function turn(string $session, string $question, array $intent): array
    {
        $provider = new RecordingProvider;
        $provider->intentResponse = array_merge([
            'success' => true,
            'dataset' => 'ps_orders',
            'metric' => 'revenue',
            'query_type' => 'aggregation',
            'limit' => 10,
            'order' => 'desc',
            'group_value' => null,
            'confidence' => 0.95,
            'needs_clarification' => false,
        ], $intent);

        // BOTH singletons. The orchestrator holds the provider it was built
        // with, so forgetting only the manager leaves turn 2 answering with
        // turn 1's canned intent - which looks exactly like a passing or
        // failing conversation depending on the fixture, and is neither.
        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);
        $this->app->forgetInstance(ConversationManager::class);

        return $this->app->make(ConversationManager::class)->query($session, $question, 'ps_orders');
    }

    private function total(array $r): float
    {
        $row = $r['rows'][0] ?? null;

        return (float) (is_array($row) ? ($row['revenue'] ?? 0) : ($row->revenue ?? 0));
    }

    /**
     * THE DEFECT. The narrowing turn sends no dates - it is a refinement, and
     * the period is supposed to be inherited.
     */
    #[Test]
    public function a_narrowing_turn_keeps_the_period_from_the_turn_before()
    {
        $this->seedOrders();

        $july = $this->turn('ps-1', 'revenue in July 2026', [
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
        ]);

        $this->assertSame('success', $july['status'] ?? null, json_encode($july));
        $this->assertEquals(300.0, $this->total($july), 'fixture check: July is 100 + 200');

        $west = $this->turn('ps-1', 'only in West', [
            'filters' => [['column' => 'region', 'value' => 'West']],
        ]);

        $this->assertSame('success', $west['status'] ?? null, json_encode($west));
        $this->assertEquals(
            100.0,
            $this->total($west),
            'the period from the previous turn was dropped: 600 is West all-time and 300 is July '
                . 'across all regions, so any answer but 100 means the narrowing lost the month'
        );
    }

    /** And the state a client reads must say the period is still in force. */
    #[Test]
    public function the_carried_period_is_visible_in_the_state()
    {
        $this->seedOrders();

        $this->turn('ps-2', 'revenue in July 2026', [
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
        ]);

        $west = $this->turn('ps-2', 'only in West', [
            'filters' => [['column' => 'region', 'value' => 'West']],
        ]);

        // A failed turn merges an empty parsed_query, so the state below
        // survives untouched from turn 1 and both assertions pass while the
        // thing they describe never happened. Without this line the test is
        // green on a rate limit.
        $this->assertSame('success', $west['status'] ?? null, json_encode($west));

        $this->assertSame(
            '2026-07-01',
            $west['state']['date_from'] ?? null,
            'the conversation state does not hold the period, so nothing downstream can carry it: '
                . json_encode($west['state'] ?? [])
        );
        $this->assertSame('2026-07-31', $west['state']['date_to'] ?? null);
    }

    /** A turn that names a NEW period replaces the old one rather than adding to it. */
    #[Test]
    public function a_new_period_replaces_the_carried_one()
    {
        $this->seedOrders();

        $this->turn('ps-3', 'revenue in July 2026', [
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
        ]);

        $jan = $this->turn('ps-3', 'what about January 2025', [
            'date_from' => '2025-01-01',
            'date_to' => '2025-01-31',
        ]);

        $this->assertSame('success', $jan['status'] ?? null, json_encode($jan));
        $this->assertEquals(
            500.0,
            $this->total($jan),
            'January 2025 holds one row of 500; anything else means the periods were combined '
                . 'or the new one ignored'
        );
    }

    /**
     * A period that carries must also be removable.
     *
     * merge() cannot see the difference between a slot the turn did not fill
     * and one it deliberately cleared: both arrive as null. So once a window
     * was established every widening instruction was ignored and the answer
     * stayed pinned to the month, with New topic or rewind the only way out.
     *
     * Before the period carried between turns this could not happen - making
     * it carry is what turned it into a wrong answer, which is why it ships
     * with the carry rather than after it.
     */
    #[Test]
    public function an_instruction_to_widen_removes_the_carried_period()
    {
        $this->seedOrders();

        $july = $this->turn('ps-4', 'revenue in July 2026', [
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
        ]);
        $this->assertSame('success', $july['status'] ?? null, json_encode($july));
        $this->assertEquals(300.0, $this->total($july), 'fixture check: July is 100 + 200');

        $west = $this->turn('ps-4', 'only in West', [
            'filters' => [['column' => 'region', 'value' => 'West']],
        ]);
        $this->assertEquals(100.0, $this->total($west), 'fixture check: West in July is 100');

        // The model answers honestly: this turn names no dates.
        $all = $this->turn('ps-4', 'and across all time', [
            'date_from' => null,
            'date_to' => null,
            'filters' => [['column' => 'region', 'value' => 'West']],
        ]);

        $this->assertSame('success', $all['status'] ?? null, json_encode($all));
        $this->assertEquals(
            600.0,
            $this->total($all),
            'the July window survived an instruction to drop it: 100 is West still inside July, '
                . 'and the user has no way to widen the answer they are looking at'
        );

        $this->assertNull(
            $all['state']['date_from'] ?? null,
            'the state still carries the period the turn asked to remove, so the next turn '
                . 'narrows again: ' . json_encode($all['state'] ?? [])
        );
    }

    /** Widening drops the period. It must not also drop the filters. */
    #[Test]
    public function widening_the_period_keeps_the_filters()
    {
        $this->seedOrders();

        $this->turn('ps-5', 'revenue in July 2026', [
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
        ]);
        $this->turn('ps-5', 'only in West', [
            'filters' => [['column' => 'region', 'value' => 'West']],
        ]);

        $all = $this->turn('ps-5', 'and across all time', [
            'date_from' => null,
            'date_to' => null,
            'filters' => [['column' => 'region', 'value' => 'West']],
        ]);

        $this->assertSame('success', $all['status'] ?? null, json_encode($all));
        $this->assertEquals(
            600.0,
            $this->total($all),
            '800 is every region across all time, so the widening turn threw away the West '
                . 'filter as well as the period'
        );
    }
}
