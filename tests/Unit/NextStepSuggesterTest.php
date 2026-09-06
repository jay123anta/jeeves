<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Jayanta\Jeeves\Engine\NextStepSuggester;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The hard part of asking your data questions is not phrasing one -  it is
 * knowing what can be asked at all. In a chat box there is nothing on screen
 * to tell you, so every answer carries a few concrete follow-ups.
 *
 * They are built from the schema rather than from the model: free, instant,
 * and incapable of proposing a breakdown the validator would then refuse.
 */
class NextStepSuggesterTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/groupby-schemas');
    }

    private function suggest(array $overrides = [], array $rows = []): array
    {
        return $this->app->make(NextStepSuggester::class)->suggest($overrides + [
            'dataset' => 'gb_sales',
            'metric' => 'revenue',
            'group_column' => 'region',
            'query_type' => 'ranking',
            'order' => 'DESC',
            'limit' => 5,
        ], $rows);
    }

    #[Test]
    public function it_offers_the_other_breakdowns_the_schema_allows()
    {
        $queries = array_column($this->suggest(), 'query');

        $this->assertNotEmpty($queries);

        // Re-offering the question just answered is the one useless suggestion.
        // ("how many by region" is fine -  same slice, different measure.)
        $this->assertNotContains('revenue by region', $queries);

        $joined = implode(' | ', $queries);
        $this->assertTrue(
            str_contains($joined, 'customer_name') || str_contains($joined, 'status'),
            'expected another groupable column to be offered, got: ' . $joined
        );
    }

    #[Test]
    public function it_never_suggests_a_breakdown_the_validator_would_refuse()
    {
        // revenue is a measure, not a dimension. Grouping by it is a question
        // the SqlBuilder is required to reject, so it must never be offered.
        // Only the breakdown position matters -  "... by revenue" as the METRIC
        // is exactly right.
        foreach ($this->suggest() as $s) {
            $this->assertDoesNotMatchRegularExpression(
                '/\bby revenue\b(?!\s*$)/',
                $s['query'],
                'revenue may only appear as the measure, never as the grouping'
            );
        }
    }

    /**
     * RULE 2. No value from the result set may appear in a suggestion.
     *
     * A suggestion is not inert text: the widget sends it to a provider the
     * moment it is clicked, so a value composed into one is a data-derived
     * string on its way out of the building.
     *
     * This class used to offer "Break West down by category", carrying that
     * value in the query it would send, defended on the grounds that clicking
     * makes it the user's own text. On a freshly discovered schema the top
     * row's value can be a `remember_token` -  introspection marks every string
     * column groupable and nothing here knows which ones hold secrets. It was
     * gated by `chat.suggest_drilldown_values`, which is exactly the
     * "rejected by configuration" that Rule 2 forbids.
     *
     * The seeded values are deliberately unmistakable: if one reaches a
     * suggestion by ANY route, this fails and names the field it came out of.
     */
    #[Test]
    public function no_value_from_the_rows_ever_reaches_a_suggestion()
    {
        $secret = 'TOKENabc123SESSIONSECRET';

        $suggestions = $this->suggest([], [
            ['region' => $secret, 'revenue' => 5515736],
            ['region' => 'ALSO-SECRET-VALUE', 'revenue' => 42],
        ]);

        $this->assertNotEmpty(
            $suggestions,
            'suggestions vanished entirely; the schema-derived ones are meant to survive'
        );

        foreach ($suggestions as $s) {
            foreach (['query', 'label'] as $field) {
                $this->assertStringNotContainsString(
                    $secret,
                    (string) $s[$field],
                    "a value from the result rows reached suggestion[{$field}] -  one click sends "
                        . 'that string to the provider, and the privacy wall is the product: '
                        . json_encode($s)
                );
                $this->assertStringNotContainsString('ALSO-SECRET-VALUE', (string) $s[$field]);
                $this->assertStringNotContainsString('5515736', (string) $s[$field]);
            }
        }
    }

    #[Test]
    public function it_offers_counting_after_a_money_question()
    {
        // "How many" is the question people reach for right after seeing money.
        $queries = implode(' | ', array_column($this->suggest(), 'query'));

        $this->assertStringContainsString('how many', $queries);
    }

    #[Test]
    public function it_offers_the_opposite_end_of_a_ranking()
    {
        $labels = implode(' | ', array_column($this->suggest(['order' => 'DESC']), 'label'));

        $this->assertStringContainsString('Bottom', $labels);
    }

    #[Test]
    public function a_detail_view_is_not_drilled_into_again()
    {
        $suggestions = $this->suggest(
            ['group_value' => 'West', 'query_type' => 'group_detail'],
            [['region' => 'West', 'revenue' => 1]]
        );

        foreach ($suggestions as $s) {
            $this->assertStringNotContainsString('Break West', $s['label']);
        }
    }

    #[Test]
    public function suggestions_are_capped_and_never_duplicated()
    {
        config(['jeeves.chat.max_next_steps' => 3]);

        $suggestions = $this->suggest([], [['region' => 'West', 'revenue' => 1]]);
        $queries = array_column($suggestions, 'query');

        $this->assertLessThanOrEqual(3, count($suggestions));
        $this->assertSame($queries, array_values(array_unique($queries)));
    }

    #[Test]
    public function it_stays_silent_when_switched_off_or_for_an_unknown_dataset()
    {
        config(['jeeves.chat.suggest_next_steps' => false]);
        $this->assertSame([], $this->suggest());

        config(['jeeves.chat.suggest_next_steps' => true]);
        $this->assertSame([], $this->suggest(['dataset' => 'no_such_dataset']));
    }
}
