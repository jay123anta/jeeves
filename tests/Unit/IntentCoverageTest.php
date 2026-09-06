<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Jayanta\Jeeves\Engine\IntentCoverage;
use Jayanta\Jeeves\Schema\SchemaRegistry;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Four wrong-number defects in three days shared one shape: intent mode could
 * not represent part of the question, dropped it, and answered the remainder
 * without saying so. Each was fixed by adding a field -  dimension, metric,
 * clarification target, period.
 *
 * Adding fields one at a time never closes the gap, because there is always
 * another SQL clause. This closes it from the other side: if the wording shows
 * the question needs more than the contract holds, use the mode that can
 * express it. Both modes cost one API call, so escalating is close to free -
 * a false positive loses determinism, a false negative is a confident lie.
 */
class IntentCoverageTest extends TestCase
{
    private function coverage(): IntentCoverage
    {
        return $this->app->make(IntentCoverage::class);
    }

    #[Test]
    public function questions_the_contract_can_express_stay_in_intent_mode()
    {
        // Intent mode is deterministic and cannot hallucinate a column. It
        // should keep every question it can genuinely answer.
        foreach ([
            'top 5 customers by revenue',
            'revenue by region',
            'how many orders by status',
            'total revenue',
            'show me Talukdar & Co',
            'revenue last month',
            'bottom 10 regions by revenue',
            'orders per status',
        ] as $query) {
            $this->assertNull(
                $this->coverage()->exceeds($query),
                "'{$query}' fits the intent contract and should not escalate"
            );
        }
    }

    #[Test]
    public function filtering_groups_by_an_aggregate_escalates()
    {
        // There is no HAVING in the contract. "Customers with more than 10
        // orders" quietly became "customers", ranked.
        $this->assertSame('having', $this->coverage()->exceeds('customers with more than 10 orders'));
        $this->assertSame('having', $this->coverage()->exceeds('regions having at least 50 orders'));
    }

    #[Test]
    public function a_numeric_filter_escalates()
    {
        // The contract filters by name and by period. Nothing else.
        $this->assertSame('numeric_filter', $this->coverage()->exceeds('orders over 5000'));
        $this->assertSame('numeric_filter', $this->coverage()->exceeds('sales above £1000'));
        $this->assertSame('numeric_filter', $this->coverage()->exceeds('orders between 100 and 500'));
    }

    #[Test]
    public function an_exclusion_escalates()
    {
        // No NOT anywhere in the contract, so "excluding cancelled" was simply
        // dropped and cancelled orders were counted in the total.
        $this->assertSame('exclusion', $this->coverage()->exceeds('revenue excluding cancelled orders'));
        $this->assertSame('exclusion', $this->coverage()->exceeds('all regions except West'));
        $this->assertSame('exclusion', $this->coverage()->exceeds('orders not including refunds'));
    }

    #[Test]
    public function distinct_counting_escalates()
    {
        // record_count is COUNT(*). "How many different customers" is not that.
        $this->assertSame('distinct', $this->coverage()->exceeds('how many different customers'));
        $this->assertSame('distinct', $this->coverage()->exceeds('count distinct regions'));
    }

    #[Test]
    public function ratios_and_shares_escalate()
    {
        $this->assertSame('ratio', $this->coverage()->exceeds('what percentage of orders were cancelled'));
        $this->assertSame('ratio', $this->coverage()->exceeds('revenue per customer'));
        $this->assertSame('ratio', $this->coverage()->exceeds('share of revenue by region'));
    }

    #[Test]
    public function a_per_group_superlative_escalates()
    {
        // Needs a window function or a correlated subquery -  the contract has
        // one ORDER BY and one LIMIT for the whole result, not per group.
        $this->assertSame('per_group_top', $this->coverage()->exceeds('top 2 customers in each region'));
        $this->assertSame('per_group_top', $this->coverage()->exceeds('for each region the highest revenue customer'));
    }

    /**
     * Found by running real Spider dev questions rather than questions written
     * here. Every failure in that run was intent mode and every SQL-generation
     * run passed -  the contract carries ONE metric and ONE label, while people
     * routinely ask for several of each.
     */
    #[Test]
    public function asking_for_several_aggregates_at_once_escalates()
    {
        // Answered as a single number, with the other two silently absent.
        $this->assertSame(
            'multi_aggregate',
            $this->coverage()->exceeds('What is the average, minimum, and maximum age of all singers?')
        );
        $this->assertSame(
            'multi_aggregate',
            $this->coverage()->exceeds('What is the average and maximum capacities for all stadiums?')
        );
    }

    #[Test]
    public function asking_for_a_list_of_columns_escalates()
    {
        $this->assertSame(
            'multi_column',
            $this->coverage()->exceeds('Show name, country, age for all singers ordered by age')
        );
        $this->assertSame(
            'multi_column',
            $this->coverage()->exceeds('What are the names, countries, and ages for every singer?')
        );
    }

    #[Test]
    public function a_single_measure_by_a_single_dimension_still_stays_in_intent_mode()
    {
        // The new patterns must not swallow the questions intent mode handles
        // well -  it is cheaper, deterministic, and cannot invent a column.
        foreach ([
            'revenue by region',
            'top 5 customers by revenue',
            'how many singers are from each country',
            'total revenue last month',
            'average order value',
        ] as $query) {
            $this->assertNull(
                $this->coverage()->exceeds($query),
                "'{$query}' should stay in intent mode"
            );
        }
    }

    /**
     * Caught by a Spider question that had been passing by luck: "the model of
     * the car whose weight is below the average" needs
     * WHERE x < (SELECT AVG(x)). The digit-anchored numeric patterns miss it
     * precisely because the sentence contains no number.
     */
    #[Test]
    public function a_comparison_against_an_aggregate_escalates()
    {
        foreach ([
            'find the model of the car whose weight is below the average',
            'customers with revenue above the average',
            'products priced under the median',
        ] as $query) {
            $this->assertSame(
                'numeric_filter',
                $this->coverage()->exceeds($query),
                "'{$query}' needs a subquery"
            );
        }
    }

    #[Test]
    public function escalation_can_be_switched_off()
    {
        config(['jeeves.sql.escalate_beyond_intent' => false]);

        $this->assertNull($this->coverage()->exceeds('customers with more than 10 orders'));
    }

    /**
     * "Average amount" summed.
     *
     * The intent contract names a METRIC and says nothing about what to do
     * with it, and SqlBuilder wraps every aggregatable column in SUM(). On a
     * schema discovered without --ai -  no computed metrics at all -  "average
     * amount" therefore returned 12,100 where the answer was 4,033.33. A
     * plausible number, three times too large, labelled "average". Found by
     * asking questions whose answers could be checked by hand.
     */
    #[Test]
    public function an_aggregate_the_contract_cannot_express_escalates()
    {
        // No computed metrics here, so nothing provides an average.
        config(['jeeves.schema.config_path' => __DIR__ . '/../Stubs/groupby-schemas']);
        $this->app->forgetInstance(SchemaRegistry::class);
        $this->app->forgetInstance(IntentCoverage::class);

        foreach (['average revenue', 'what is the average revenue', 'minimum revenue'] as $query) {
            $this->assertSame(
                'non_sum_aggregate',
                $this->coverage()->exceeds($query),
                '{} would have been summed'
            );
        }
    }

    /**
     * But a schema that DEFINES the average answers it exactly, and escalating
     * would spend a second call to reach the same number while giving up the
     * determinism intent mode exists for. computed_metrics is precisely where
     * a schema says "average order value means ROUND(AVG(amount), 2)".
     */
    #[Test]
    public function an_aggregate_the_schema_defines_stays_in_intent_mode()
    {
        // The default stub declares avg_amount with the alias "average".
        $this->assertNull($this->coverage()->exceeds('average order value'));
        $this->assertNull($this->coverage()->exceeds('what is the average'));
    }

    /**
     * Totals and counts are what the contract is FOR. Escalating them would
     * double the cost of the most common questions there are.
     */
    #[Test]
    public function sums_and_counts_are_never_escalated_as_aggregates()
    {
        config(['jeeves.schema.config_path' => __DIR__ . '/../Stubs/groupby-schemas']);
        $this->app->forgetInstance(SchemaRegistry::class);
        $this->app->forgetInstance(IntentCoverage::class);

        foreach (['total revenue', 'how many orders', 'sum of revenue', 'revenue by region'] as $query) {
            $this->assertNotSame('non_sum_aggregate', $this->coverage()->exceeds($query), $query);
        }
    }

    /**
     * A negated existence question is not expressible in the contract at all.
     *
     * "Which orders have not shipped" needs NOT EXISTS, NOT IN, or a LEFT JOIN
     * with an IS NULL test. The contract holds one measure, one grouping, one
     * name filter, one period, an order and a limit -  there is nowhere to put
     * the negation. So it was dropped and the remainder answered: asked which
     * orders had not shipped, every order came back. "All of them" reads like
     * a real answer to that question, which is what makes it worse than a
     * refusal.
     *
     * The `exclusion` rule did not catch these. It matches excluding, except,
     * apart from and without -  none of which is how anyone phrases this.
     */
    #[Test]
    public function a_negated_existence_question_escalates()
    {
        foreach ([
            'which customers have never opened a support ticket',
            'customers who have never ordered',
            'which orders have not been paid',
            'which orders have not shipped yet',
            'what are the names of all stadiums that did not have a concert in 2014',
            'products that were never sold',
            'customers with no orders',
        ] as $query) {
            $this->assertSame(
                'anti_join',
                $this->coverage()->exceeds($query),
                "'{$query}' needs SQL the contract cannot express, and dropping the negation "
                    . 'answers the opposite question'
            );
        }
    }

    /**
     * THE COUNTERWEIGHT. Escalating swaps one API call for another rather than
     * adding one, but it costs determinism -  so a rule that fires on questions
     * the contract handles is a real regression.
     *
     * Superlatives are the case measured and deliberately LEFT ALONE. Across
     * the 164 questions in the benchmark and Spider sets, a singular-
     * superlative rule newly escalated 7, and the gold SQL for all 7 was plain
     * GROUP BY / ORDER BY / LIMIT that the contract can express. Whatever makes
     * those questions fail, it is not routing, and escalating them would spend
     * determinism for nothing. Do not add one without measuring again.
     */
    #[Test]
    public function questions_the_contract_can_still_express_are_left_alone()
    {
        foreach ([
            'which carrier shipped the most orders',
            'which supplier products generated the most revenue',
            'what is the year that had the most concerts',
            'top 5 customers by revenue',
            'bottom 10 regions by revenue',
            'revenue by region',
        ] as $query) {
            $this->assertNull(
                $this->coverage()->exceeds($query),
                "'{$query}' has gold SQL the intent contract can express, so escalating it "
                    . 'buys nothing and loses determinism'
            );
        }
    }

    /**
     * "Highest" is "maximum", and the contract can express neither.
     *
     * SqlBuilder wraps every aggregatable column in SUM(), so a question the
     * contract keeps is answered by adding the column up. "Maximum unit price"
     * escalated; "highest unit price" did not. Measured on the benchmark it
     * answered 670 -  120 + 250 + 300 -  and called it the highest price. The
     * right answer is 300.
     *
     * Anchored to the SCALAR form on purpose. "The car with the largest
     * acceleration" asks for a row, which ORDER BY ... LIMIT 1 expresses
     * perfectly well; a looser pattern escalates five of those across the
     * benchmark and Spider sets and buys nothing on any of them.
     */
    #[Test]
    public function a_superlative_asking_for_a_value_escalates_like_maximum_does()
    {
        foreach ([
            'what is the highest unit price',
            'what is the lowest unit price',
            'what is the largest order value',
            'what was the smallest payment',
            // Same question, other spellings. Which of these escalated used to
            // depend on how the user happened to type it, and the ones that
            // did not were summed. No question in either benchmark corpus uses
            // these forms, so the corpus cannot show the benefit - but it does
            // show the cost is zero, and the failure they prevent is a total
            // returned as an extreme value.
            "what's the highest unit price",
            'show me the highest unit price',
            'give me the lowest price',
            'what is the max unit price',
            "what's the min order value",
        ] as $query) {
            $this->assertSame(
                'non_sum_aggregate',
                $this->coverage()->exceeds($query),
                "'{$query}' asks for a single extreme value; kept in intent mode it is summed"
            );
        }
    }

    /**
     * THE COUNTERWEIGHT for the rule above: a superlative that asks for a ROW
     * is an ordinary ranking the contract handles, and escalating it spends
     * determinism for nothing.
     */
    #[Test]
    public function a_superlative_asking_for_a_row_stays_in_intent_mode()
    {
        foreach ([
            'what is the car model with the highest mpg',
            'what is the horsepower of the car with the largest acceleration',
            'what is the model of the car with the smallest weight',
        ] as $query) {
            $this->assertNotSame(
                'non_sum_aggregate',
                $this->coverage()->exceeds($query),
                "'{$query}' asks for a row, which ORDER BY ... LIMIT 1 expresses"
            );
        }
    }

    /**
     * The metric bypass must ask WHICH aggregate, not merely whether the
     * schema knows the word.
     *
     * `schemaAlreadyProvidesIt()` exists so that a schema defining
     * "average order value" as ROUND(AVG(amount), 2) is not escalated to pay
     * for a second call reaching the same answer. It used to fire on a bare
     * name match, so any computed-metric alias appearing anywhere in the
     * sentence disarmed the guard.
     *
     * `discover --ai` writes SUM totals aliased with the words people say -
     * "revenue", "total", "sales". On such a schema "what is the highest
     * revenue" named a metric the schema provides, the bypass fired, and
     * SqlBuilder answered with SUM(). The question named the metric; it did
     * not ask for the aggregate the schema computes.
     */
    #[Test]
    public function a_sum_metric_does_not_answer_a_question_about_the_maximum()
    {
        config(['jeeves.schema.config_path' => __DIR__ . '/../Stubs/sum-alias-schemas']);
        $this->app->forgetInstance(SchemaRegistry::class);
        $this->app->forgetInstance(IntentCoverage::class);

        foreach ([
            'what is the highest revenue',
            'what is the lowest revenue',
            'what is the average revenue',
        ] as $query) {
            $this->assertSame(
                'non_sum_aggregate',
                $this->coverage()->exceeds($query),
                "'{$query}' was disarmed by a SUM metric that happens to be aliased 'revenue', "
                    . 'so the total was returned as though it were the extreme value'
            );
        }
    }

    /**
     * THE COUNTERWEIGHT. A schema that genuinely provides the aggregate being
     * asked for must still be honoured, or every curated average costs a
     * second API call to reach the answer the schema already names.
     */
    #[Test]
    public function a_schema_that_provides_the_asked_for_aggregate_is_still_honoured()
    {
        // The default stub declares avg_amount as ROUND(AVG(amount), 2),
        // aliased "average".
        $this->assertNull($this->coverage()->exceeds('average order value'));
        $this->assertNull($this->coverage()->exceeds('what is the average'));
    }

    /** The same question, spelled with a word instead of a digit. */
    #[Test]
    public function a_group_filter_escalates_whichever_way_the_number_is_written()
    {
        $this->assertSame('having', $this->coverage()->exceeds('which customers have opened more than 1 support ticket'));
        $this->assertSame(
            'having',
            $this->coverage()->exceeds('which customers placed more than one order'),
            'whether the rule fired depended on whether the user typed "1" or "one"'
        );
        $this->assertSame(
            'having',
            $this->coverage()->exceeds('which customers placed more than twenty orders'),
            'the word-number list stopped at ten'
        );
        $this->assertSame(
            'having',
            $this->coverage()->exceeds('which customers opened more than a dozen tickets')
        );
    }
}
