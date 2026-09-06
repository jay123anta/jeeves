<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Jayanta\Jeeves\Engine\SqlBuilder;
use Jayanta\Jeeves\Schema\SchemaRegistry;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class SqlBuilderTest extends TestCase
{
    private SqlBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $registry = new SchemaRegistry(__DIR__ . '/../Stubs/schemas');
        $this->builder = new SqlBuilder($registry);
    }

    #[Test]
    public function it_builds_ranking_query()
    {
        $result = $this->builder->buildQuery([
            'dataset' => 'test_orders',
            'metric' => 'amount',
            'limit' => 10,
            'order' => 'desc',
            'group_value' => null,
        ]);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('SELECT', $result['sql']);
        $this->assertStringContainsString('public.orders', $result['sql']);
        $this->assertStringContainsString('amount', $result['sql']);
        $this->assertStringContainsString('ORDER BY', $result['sql']);
        $this->assertStringContainsString('DESC', $result['sql']);
        $this->assertStringContainsString('LIMIT 10', $result['sql']);
        $this->assertEquals('ranking', $result['query_type']);
        $this->assertEmpty($result['bindings']);
    }

    #[Test]
    public function it_aggregates_ranking_on_transactional_tables()
    {
        // Regression: 'amount' is aggregatable → one row per order must be
        // summed per customer, not ranked as raw order lines (which produced
        // duplicate customers in the top-N).
        $result = $this->builder->buildQuery([
            'dataset' => 'test_orders',
            'metric' => 'amount',
            'limit' => 10,
            'order' => 'desc',
            'group_value' => null,
        ]);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('SUM(amount)', $result['sql']);
        $this->assertStringContainsString('GROUP BY customer_name', $result['sql']);
    }

    #[Test]
    public function it_groups_aggregate_computed_metrics_without_nesting()
    {
        $result = $this->builder->buildQuery([
            'dataset' => 'test_orders',
            'metric' => 'avg_amount',
            'limit' => 5,
            'order' => 'desc',
            'group_value' => null,
        ]);

        $this->assertTrue($result['success']);
        // Expression already aggregates -  must be grouped, never SUM-wrapped
        $this->assertStringContainsString('GROUP BY customer_name', $result['sql']);
        $this->assertStringContainsString('ROUND(AVG(amount), 2)', $result['sql']);
        $this->assertStringNotContainsString('SUM(ROUND', $result['sql']);
    }

    #[Test]
    public function it_does_not_aggregate_preaggregated_tables()
    {
        // Backward compatibility: no aggregatable columns (one row per
        // district) → read rows as-is, no GROUP BY, no SUM
        $result = $this->builder->buildQuery([
            'dataset' => 'test_districts',
            'metric' => 'total_households',
            'limit' => 10,
            'order' => 'desc',
            'group_value' => null,
        ]);

        $this->assertTrue($result['success']);
        $this->assertStringNotContainsString('GROUP BY', $result['sql']);
        $this->assertStringNotContainsString('SUM(', $result['sql']);
    }

    #[Test]
    public function it_aggregates_group_value_detail_on_transactional_tables()
    {
        $result = $this->builder->buildQuery([
            'dataset' => 'test_orders',
            'metric' => 'amount',
            'limit' => 1,
            'order' => 'desc',
            'group_value' => 'Sharma Traders',
        ]);

        $this->assertTrue($result['success']);
        // Detail view must sum the customer's orders, not pick one row
        $this->assertStringContainsString('SUM(amount) AS amount', $result['sql']);
        $this->assertStringContainsString('GROUP BY customer_name', $result['sql']);
    }

    #[Test]
    public function aggregation_query_does_not_nest_aggregate_expressions()
    {
        $plain = $this->builder->buildAggregationQuery([
            'dataset' => 'test_orders',
            'metric' => 'amount',
        ]);
        $this->assertTrue($plain['success']);
        $this->assertStringContainsString('SUM(amount)', $plain['sql']);

        $computed = $this->builder->buildAggregationQuery([
            'dataset' => 'test_orders',
            'metric' => 'avg_amount',
        ]);
        $this->assertTrue($computed['success']);
        $this->assertStringNotContainsString('SUM(ROUND', $computed['sql']);
        $this->assertStringContainsString('ROUND(AVG(amount), 2)', $computed['sql']);
    }

    #[Test]
    public function it_builds_a_group_detail_query_with_parameterized_bindings()
    {
        $result = $this->builder->buildQuery([
            'dataset' => 'test_orders',
            'metric' => 'amount',
            'limit' => 1,
            'order' => 'desc',
            'group_value' => 'Kamrup',
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('group_detail', $result['query_type']);
        // Should use parameterized queries (? placeholders)
        $this->assertStringContainsString('?', $result['sql']);
        $this->assertNotEmpty($result['bindings']);
        $this->assertCount(3, $result['bindings']); // exact, LIKE, CASE
        $this->assertEquals('Kamrup', $result['bindings'][0]);
    }

    /**
     * `district` was the pre-1.0 name for this field. A cached intent, a
     * custom prompt override or a third-party provider can still send it, and
     * dropping it silently would turn a filtered question into an unfiltered
     * one -  the wrong answer, confidently.
     */
    #[Test]
    public function the_legacy_district_key_is_still_honoured()
    {
        $result = $this->builder->buildQuery([
            'dataset' => 'test_orders',
            'metric' => 'amount',
            'limit' => 1,
            'order' => 'desc',
            'district' => 'Kamrup',
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('group_detail', $result['query_type']);
        $this->assertEquals('Kamrup', $result['bindings'][0]);
    }

    #[Test]
    public function it_resolves_metric_aliases()
    {
        $result = $this->builder->buildQuery([
            'dataset' => 'test_orders',
            'metric' => 'revenue', // alias for 'amount'
            'limit' => 5,
            'order' => 'desc',
            'group_value' => null,
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('amount', $result['metric']);
    }

    #[Test]
    public function it_uses_default_metric_when_none_specified()
    {
        $result = $this->builder->buildQuery([
            'dataset' => 'test_orders',
            'metric' => null,
            'limit' => 5,
            'order' => 'desc',
            'group_value' => null,
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('amount', $result['metric']); // default_metric
    }

    #[Test]
    public function it_fails_for_unknown_dataset()
    {
        $result = $this->builder->buildQuery([
            'dataset' => 'nonexistent',
            'metric' => 'amount',
            'limit' => 5,
            'order' => 'desc',
            'group_value' => null,
        ]);

        $this->assertFalse($result['success']);
    }

    #[Test]
    public function it_fails_for_null_dataset()
    {
        $result = $this->builder->buildQuery([
            'dataset' => null,
            'metric' => 'amount',
        ]);

        $this->assertFalse($result['success']);
    }

    #[Test]
    public function it_caps_limit_to_max()
    {
        $result = $this->builder->buildQuery([
            'dataset' => 'test_orders',
            'metric' => 'amount',
            'limit' => 500, // max is 100
            'order' => 'desc',
            'group_value' => null,
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals(100, $result['limit']);
    }

    #[Test]
    public function it_keeps_a_dangerous_group_value_out_of_the_sql_string()
    {
        $result = $this->builder->buildQuery([
            'dataset' => 'test_orders',
            'metric' => 'amount',
            'limit' => 1,
            'order' => 'desc',
            'group_value' => "Kamrup'; DROP TABLE--",
        ]);

        $this->assertTrue($result['success']);

        // The payload must never reach the SQL text; it goes to the driver as
        // a bound parameter. This test used to require the quote be stripped
        // from the binding as well, which sounds safer but is not: mangling
        // the value is what made legitimate identifiers unusable, and a bound
        // parameter is not executable SQL no matter what it contains.
        $this->assertStringNotContainsStringIgnoringCase('DROP', $result['sql']);
        $this->assertStringNotContainsString('Kamrup', $result['sql']);
        $this->assertContains("Kamrup'; DROP TABLE--", $result['bindings']);
    }

    #[Test]
    public function it_normalizes_order_direction()
    {
        $result = $this->builder->buildQuery([
            'dataset' => 'test_orders',
            'metric' => 'amount',
            'limit' => 5,
            'order' => 'invalid',
            'group_value' => null,
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('DESC', $result['order']); // falls back to DESC
    }

    /**
     * ILIKE is PostgreSQL-only. The named-record lookup used it unconditionally,
     * so on MySQL and MariaDB -  both officially supported -  every "details for
     * X" query died with a syntax error. Nothing caught it because the builder
     * never consulted the dialect and MySQL had no integration coverage.
     */
    #[Test]
    public function the_named_record_lookup_uses_portable_sql_not_postgres_only_ilike()
    {
        $result = $this->builder->buildQuery([
            'dataset' => 'test_orders',
            'metric' => 'amount',
            'limit' => 5,
            'order' => 'desc',
            'group_value' => 'Acme',
        ]);

        $this->assertTrue($result['success']);
        $this->assertStringNotContainsStringIgnoringCase(
            'ILIKE',
            $result['sql'],
            'ILIKE is a hard syntax error on MySQL/MariaDB'
        );
        $this->assertStringContainsString('LOWER(', $result['sql']);
        $this->assertStringContainsString('LIKE', $result['sql']);
    }

    /** The value must stay a binding -  never interpolated into the SQL. */
    #[Test]
    public function the_named_record_lookup_parameterises_the_value()
    {
        $result = $this->builder->buildQuery([
            'dataset' => 'test_orders',
            'metric' => 'amount',
            'limit' => 5,
            'order' => 'desc',
            'group_value' => 'Acme Traders',
        ]);

        $this->assertTrue($result['success']);
        $this->assertStringNotContainsString('Acme Traders', $result['sql']);
        $this->assertContains('Acme Traders', $result['bindings']);
        $this->assertContains('%Acme Traders%', $result['bindings']);
    }

    /**
     * Injection is prevented by binding the value, not by mangling it: the
     * payload never reaches the SQL string, it goes to the driver as data.
     */
    #[Test]
    public function an_injection_payload_stays_out_of_the_sql_string()
    {
        $result = $this->builder->buildQuery([
            'dataset' => 'test_orders',
            'metric' => 'amount',
            'limit' => 5,
            'order' => 'desc',
            'group_value' => "Acme'; DROP TABLE orders; --",
        ]);

        $this->assertTrue($result['success']);
        $this->assertStringNotContainsStringIgnoringCase('DROP', $result['sql']);
        $this->assertStringContainsString('?', $result['sql']);
    }

    /**
     * The worst bug this package can have: a question about ONE record
     * answered with the top-ranked row instead.
     *
     * The sanitiser rejected anything that was not ASCII letters, spaces,
     * hyphens or dots. A rejected value became null, null meant no filter, and
     * no filter meant the builder quietly produced a ranking query -  so "units
     * in bin A-01" returned whichever bin had the most units, presented as the
     * answer, with no warning. Digits alone were enough to trigger it, which
     * covers most real identifiers.
     */
    #[Test]
    public function realistic_identifiers_are_not_silently_dropped()
    {
        $identifiers = [
            'A-01',            // bin / seat / ward code
            'Bin 7',           // digits in a plain name
            '3M',              // company starting with a digit
            'H&M',             // ampersand
            'INV-2024-88',     // invoice or order number
            'Zürich',          // non-ASCII letter
            "O'Brien",         // apostrophe
            'ACME_CORP',       // underscore
        ];

        foreach ($identifiers as $identifier) {
            $result = $this->builder->buildQuery([
                'dataset' => 'test_orders',
                'metric' => 'amount',
                'limit' => 1,
                'order' => 'desc',
                'group_value' => $identifier,
            ]);

            $this->assertTrue($result['success']);
            $this->assertSame(
                'group_detail',
                $result['query_type'],
                "'{$identifier}' was dropped, so this became a ranking query answering a different question"
            );
            $this->assertSame($identifier, $result['bindings'][0]);
        }
    }

    /** An underscore is a LIKE wildcard; it must match literally. */
    #[Test]
    public function like_wildcards_inside_the_value_are_escaped_not_removed()
    {
        $result = $this->builder->buildQuery([
            'dataset' => 'test_orders',
            'metric' => 'amount',
            'limit' => 1,
            'order' => 'desc',
            'group_value' => 'ACME_CORP',
        ]);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString("ESCAPE '!'", $result['sql']);
        // Exact-match binding keeps the value; the LIKE binding escapes it.
        $this->assertSame('ACME_CORP', $result['bindings'][0]);
        $this->assertSame('%ACME!_CORP%', $result['bindings'][1]);
    }

    /** Control characters are still removed, and an empty value means no filter. */
    #[Test]
    public function control_characters_are_stripped_and_blank_values_mean_no_filter()
    {
        $result = $this->builder->buildQuery([
            'dataset' => 'test_orders',
            'metric' => 'amount',
            'limit' => 5,
            'order' => 'desc',
            'group_value' => "  \x00\x07  ",
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('ranking', $result['query_type']);
    }
}
