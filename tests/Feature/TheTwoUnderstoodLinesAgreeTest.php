<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Conversation\ConversationManager;
use Jayanta\Jeeves\Tests\Support\RecordingProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * `state_summary` and `parsed_summary` describe the same answer, so they agree.
 *
 * The widget renders `data.state_summary || data.parsed_summary`, so whichever
 * is present is THE explanation of the number on screen — and inside a
 * conversation that is always `state_summary`. They are built from different
 * objects, and every time they have drifted the user has been shown the
 * weaker one:
 *
 *  - First, one named a breakdown and the other did not, because `parsed_query`
 *    reported the schema's default dimension while the summary reported none.
 *  - Then the period appeared on `parsed_summary` and never on `state_summary`,
 *    because the label was made a state slot and `StateValidator` rebuilds the
 *    state from `toIntent()`, destroying it on every turn. A month's total and
 *    an all-time total once again read identically, with the correct line
 *    present in the same payload and unused.
 *
 * A shared builder was never what prevented drift. Feeding it the same values
 * is, and asserting both lines is how that stays true.
 *
 * Fixture `ul_sales`: schema default group column `customer_name`, which is
 * deliberately NOT what these queries group by. Acme/West/pending/100 and
 * Beta/East/pending/200, both dated July 2026.
 */
class TheTwoUnderstoodLinesAgreeTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/understood-line-schemas');
        $app['config']->set('jeeves.cache.enabled', false);
        $app['config']->set('jeeves.verification.enabled', false);
        // The intent route, because that is where the engine knows enough to
        // describe the query it built. On the sql_generation route both lines
        // correctly stay silent about the breakdown and the period, so there
        // is nothing to agree about and no drift to catch.
        $app['config']->set('jeeves.query_mode', 'intent');
    }

    private function seedSales(): void
    {
        Schema::dropIfExists('ul_sales');
        Schema::create('ul_sales', function ($t) {
            $t->id();
            $t->string('customer_name');
            $t->string('region');
            $t->string('status');
            $t->date('placed_on');
            $t->decimal('revenue', 12, 2);
        });

        foreach ([
            ['Acme', 'West', 'pending', '2026-07-05', 100],
            ['Beta', 'East', 'pending', '2026-07-06', 200],
        ] as [$c, $r, $s, $d, $v]) {
            DB::table('ul_sales')->insert([
                'customer_name' => $c, 'region' => $r, 'status' => $s,
                'placed_on' => $d, 'revenue' => $v,
            ]);
        }
    }

    /** @return array{0: array, 1: array<string, string>} the response, and the two lines */
    private function ask(string $session, array $intent): array
    {
        $provider = new RecordingProvider;
        $provider->intentResponse = array_merge([
            'success' => true,
            'dataset' => 'ul_sales',
            'metric' => 'revenue',
            'query_type' => 'ranking',
            'limit' => 10,
            'order' => 'desc',
            'group_value' => null,
            'confidence' => 0.95,
            'needs_clarification' => false,
        ], $intent);
        $this->app->instance(LlmProviderInterface::class, $provider);

        // (sessionId, question, datasetHint) — passed the other way round at
        // first, which made the session id the question text. It still passed,
        // because an empty state classifies as NEW_QUERY, so the classifier and
        // merge()'s question-based guards were never exercised at all.
        $result = $this->app->make(ConversationManager::class)
            ->query($session, 'revenue by region', 'ul_sales');

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));

        // Both must be PRESENT. Skipping an absent line, which is what this
        // test did originally, means a vanished summary passes silently — and
        // a vanished summary is exactly one of the bugs being guarded here.
        $lines = [];

        foreach (['state_summary', 'parsed_summary'] as $key) {
            $this->assertNotNull($result[$key] ?? null, "[{$key}] is missing from the response");
            $lines[$key] = $result[$key];
        }

        return [$result, $lines];
    }

    /** A breakdown the query really performed appears in whichever line is shown. */
    #[Test]
    public function both_lines_name_the_breakdown_that_happened()
    {
        $this->seedSales();

        [, $lines] = $this->ask('conv-agree-breakdown', ['group_by' => 'region']);

        foreach ($lines as $key => $line) {
            $this->assertStringContainsString(
                'by region',
                $line,
                "[{$key}] the rows are broken down by region and the line a user actually reads "
                    . 'does not say so'
            );
        }
    }

    /** A period the query really applied appears in whichever line is shown. */
    #[Test]
    public function both_lines_name_the_period_that_was_applied()
    {
        $this->seedSales();

        [, $lines] = $this->ask('conv-agree-period', [
            'query_type' => 'aggregation',
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
        ]);

        foreach ($lines as $key => $line) {
            $this->assertStringContainsString(
                '2026-07-01 to 2026-07-31',
                $line,
                "[{$key}] the answer covered one month and the line does not say so, so it reads "
                    . 'identically to an all-time total'
            );
        }
    }

    /**
     * And the inverse, which is the one that manufactures false confidence: a
     * plain total must not have a breakdown announced by either line.
     */
    #[Test]
    public function neither_line_invents_a_breakdown_over_a_total()
    {
        $this->seedSales();

        [$result, $lines] = $this->ask('conv-agree-total', ['query_type' => 'aggregation']);

        foreach ($lines as $key => $line) {
            $this->assertStringNotContainsString(
                'by customer name',
                $line,
                "[{$key}] a single ungrouped total was described as broken down by the schema's "
                    . 'default dimension, which no part of the query used'
            );
        }

        $this->assertNull(
            $result['parsed_query']['group_by'] ?? null,
            'parsed_query announced a grouping the SQL never performed, and ConversationManager '
                . 'merges that phantom into the conversation state for every later turn'
        );
    }
}
