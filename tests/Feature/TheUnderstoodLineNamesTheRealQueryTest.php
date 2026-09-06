<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Tests\Support\RecordingProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The line that says how a question was read never says more than is known.
 *
 * `parsed_summary` exists to make a misreading visible, so a caption that
 * might be false is worse than no caption at all: it manufactures exactly the
 * confidence it was built to puncture.
 *
 * Two attempts to fill the breakdown and period slots were wrong before this
 * one, both in the direction of saying too much.
 *
 *  1. Reading `group_column` printed the schema's DEFAULT dimension over every
 *     answer, ungrouped totals included.
 *  2. Inferring from the finished SQL, first from a groupable column appearing
 *     in the rows and then by regex over `GROUP BY` and `WHERE`, announced "by
 *     region" over a single total whenever a CTE contained a grouping, and read
 *     `ORDER BY placed_on` as a date filter, captioning an all-time total with
 *     a month.
 *
 * So both slots now come from `SqlBuilder`, which wrote the SQL and knows
 * which branch it took. On the sql_generation route the statement is the
 * model's, nothing here can honestly describe it, and the line says only what
 * is certain. That is a deliberate reduction: `parsed_query` still carries
 * everything the engine knows, and silence is the correct output when the
 * artifact cannot be read: describe the query that RAN, never the one that
 * was planned, and say nothing rather than repeat a plan.
 *
 * Fixture `ul_sales`, hand-checked:
 *   West/pending/2026-07-05/100, East/pending/2026-07-06/200,
 *   West/cancelled/2025-01-06/500
 *   all time 800, July 2026 300, West all-time 600, West in July 100
 */
class TheUnderstoodLineNamesTheRealQueryTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/understood-line-schemas');
        $app['config']->set('jeeves.cache.enabled', false);
        $app['config']->set('jeeves.verification.enabled', false);
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
            ['Acme', 'West', 'cancelled', '2025-01-06', 500],
        ] as [$c, $r, $s, $d, $v]) {
            DB::table('ul_sales')->insert([
                'customer_name' => $c, 'region' => $r, 'status' => $s,
                'placed_on' => $d, 'revenue' => $v,
            ]);
        }
    }

    /** An answer the package itself built, so it knows what it did. */
    private function viaIntent(array $intent): array
    {
        config(['jeeves.query_mode' => 'intent']);

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
        $this->app->forgetInstance(QueryOrchestrator::class);

        return $this->app->make(QueryOrchestrator::class)->query('revenue', 'ul_sales');
    }

    /** An answer whose SQL the MODEL wrote, so the package cannot describe it. */
    private function viaSql(array $data): array
    {
        config(['jeeves.query_mode' => 'sql_generation']);

        $provider = new RecordingProvider;
        $provider->sqlResponse = ['success' => true, 'data' => $data + [
            'dataset' => 'ul_sales',
            'metric' => 'revenue',
        ]];
        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        return $this->app->make(QueryOrchestrator::class)->query('revenue', 'ul_sales');
    }

    private function total(array $r): float
    {
        $row = $r['rows'][0] ?? null;

        return (float) (is_array($row) ? ($row['revenue'] ?? 0) : ($row->revenue ?? 0));
    }

    // ---------------------------------------------------------------------
    // What the package built, it can describe.
    // ---------------------------------------------------------------------

    /** A ranking names its dimension: `region`, not the default `customer_name`. */
    #[Test]
    public function a_built_breakdown_is_named()
    {
        $this->seedSales();

        $result = $this->viaIntent(['group_by' => 'region']);

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        $this->assertStringContainsString('by region', $result['parsed_summary'] ?? '');
        $this->assertSame('region', $result['parsed_query']['group_by'] ?? null);
    }

    /**
     * THE TRAP THE FIRST FIX FELL INTO. A plain total claims no breakdown,
     * even though `group_column` is set to the schema default on every result.
     */
    #[Test]
    public function a_built_total_claims_no_breakdown()
    {
        $this->seedSales();

        $result = $this->viaIntent(['query_type' => 'aggregation']);

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        $this->assertStringNotContainsString('by customer name', $result['parsed_summary'] ?? '');
        $this->assertNull($result['parsed_query']['group_by'] ?? null);
    }

    /** A period the builder resolved is named, and it is a real narrowing. */
    #[Test]
    public function a_built_period_is_named()
    {
        $this->seedSales();

        $result = $this->viaIntent([
            'query_type' => 'aggregation',
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
        ]);

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        $this->assertEquals(300.0, $this->total($result), 'fixture check: July is 100 + 200');
        $this->assertStringContainsString('2026-07-01 to 2026-07-31', $result['parsed_summary'] ?? '');
    }

    /** And the point of it: two different numbers never share one line. */
    #[Test]
    public function a_period_answer_and_an_all_time_answer_do_not_share_a_line()
    {
        $this->seedSales();

        $allTime = $this->viaIntent(['query_type' => 'aggregation']);
        $july = $this->viaIntent([
            'query_type' => 'aggregation',
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
        ]);

        $this->assertEquals(800.0, $this->total($allTime), 'fixture check');
        $this->assertEquals(300.0, $this->total($july), 'fixture check');
        $this->assertNotSame($allTime['parsed_summary'] ?? '', $july['parsed_summary'] ?? '');
    }

    // ---------------------------------------------------------------------
    // What the model wrote, the package does not describe. Silence, never a
    // guess. Each of these produced a FALSE caption before.
    // ---------------------------------------------------------------------

    /** A grouping inside a CTE is not the answer's dimension; the answer is one number. */
    #[Test]
    public function a_grouping_inside_a_cte_is_not_claimed_as_the_breakdown()
    {
        $this->seedSales();

        $result = $this->viaSql([
            'sql' => 'WITH x AS (SELECT region, SUM(revenue) AS r FROM ul_sales GROUP BY region) '
                . 'SELECT SUM(r) AS revenue FROM x LIMIT 1',
            'query_type' => 'aggregation',
        ]);

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        $this->assertEquals(800.0, $this->total($result), 'fixture check: one row, the grand total');
        $this->assertStringNotContainsString(
            'by region',
            $result['parsed_summary'] ?? '',
            'one row holding 800 was captioned as a per-region breakdown; the regions are 600 and '
                . '200, and 800 is neither'
        );
        $this->assertNull($result['parsed_query']['group_by'] ?? null);
    }

    /** A flat listing groups by nothing, whatever columns it happens to select. */
    #[Test]
    public function a_flat_listing_claims_no_breakdown()
    {
        $this->seedSales();

        $result = $this->viaSql([
            'sql' => 'SELECT customer_name, region, status, revenue FROM ul_sales LIMIT 10',
            'query_type' => 'ranking',
        ]);

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        $this->assertStringNotContainsString(' by ', $result['parsed_summary'] ?? '');
    }

    /** A total narrowed TO one status is not a breakdown BY status. */
    #[Test]
    public function a_filtered_total_is_not_called_a_breakdown()
    {
        $this->seedSales();

        $result = $this->viaSql([
            'sql' => "SELECT status, SUM(revenue) AS revenue FROM ul_sales WHERE status = 'pending' LIMIT 10",
            'query_type' => 'aggregation',
        ]);

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        $this->assertStringNotContainsString('by status', $result['parsed_summary'] ?? '');
    }

    /**
     * THE WORST ONE. The period on this route is the model's own claim about
     * SQL it wrote, and here the claim is false: no date filter at all.
     */
    #[Test]
    public function a_period_the_model_merely_claimed_is_not_printed()
    {
        $this->seedSales();

        $result = $this->viaSql([
            'sql' => 'SELECT SUM(revenue) AS revenue FROM ul_sales',
            'query_type' => 'aggregation',
            'period' => 'July 2026',
        ]);

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        $this->assertEquals(800.0, $this->total($result), 'fixture check: all time');
        $this->assertStringNotContainsString(
            'July 2026',
            $result['parsed_summary'] ?? '',
            'the all-time total 800 was captioned July 2026, which is 300'
        );
        $this->assertNull($result['parsed_query']['period'] ?? null);
    }

    /** Sorting by a date is not filtering by one: the shape that defeated the regex. */
    #[Test]
    public function ordering_by_the_date_column_is_not_a_period()
    {
        $this->seedSales();

        $result = $this->viaSql([
            'sql' => "SELECT SUM(revenue) AS revenue FROM ul_sales WHERE region = 'West' ORDER BY placed_on DESC",
            'query_type' => 'aggregation',
            'period' => 'July 2026',
        ]);

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        $this->assertEquals(600.0, $this->total($result), 'fixture check: West all time');
        $this->assertStringNotContainsString(
            'July 2026',
            $result['parsed_summary'] ?? '',
            "West's all-time 600 was captioned July 2026; West in July is 100"
        );
    }

    /** Silence is not emptiness: the line still says what IS known. */
    #[Test]
    public function the_line_still_reports_what_is_certain_on_the_sql_route()
    {
        $this->seedSales();

        $result = $this->viaSql([
            'sql' => 'SELECT SUM(revenue) AS revenue FROM ul_sales LIMIT 10',
            'query_type' => 'aggregation',
        ]);

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        $this->assertStringContainsString('Sales', $result['parsed_summary'] ?? '');
        $this->assertStringContainsString('revenue', $result['parsed_summary'] ?? '');
    }
}
