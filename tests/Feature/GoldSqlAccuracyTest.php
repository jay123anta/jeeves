<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jayanta\Jeeves\Engine\SqlBuilder;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Execution accuracy against gold SQL.
 *
 * This is how text-to-SQL is graded internationally (Spider, BIRD): you do not
 * compare query strings, because many different queries are correct. You run
 * the generated query and a hand-written reference against the same database
 * and compare the RESULT SETS. A query is right when its answer is right.
 *
 * What is measured here is deliberately narrower than a public benchmark: the
 * intent is supplied rather than parsed, so this grades SQL CONSTRUCTION, not
 * language understanding. That separation is the point. When an answer is
 * wrong, this says whether the builder or the model was at fault -  and the
 * builder is the half that must never be wrong, because no amount of prompt
 * work can compensate for SQL that computes the wrong thing.
 *
 * Every case below is a claim made by the time-filter work. The gold SQL is
 * written by hand from the question, not from the implementation.
 */
class GoldSqlAccuracyTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/time-schemas');
        $app['config']->set('jeeves.cache.enabled', false);
        $app['config']->set('jeeves.feedback.enabled', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tf_sales', function ($t) {
            $t->id();
            $t->string('region');
            $t->date('order_date');
            $t->date('shipped_at')->nullable();
            $t->decimal('revenue', 12, 2);
        });

        DB::table('tf_sales')->insert([
            // June -  outside the July window every dated case asks for.
            ['region' => 'West', 'order_date' => '2026-06-15', 'shipped_at' => '2026-07-02', 'revenue' => 1000],
            ['region' => 'East', 'order_date' => '2026-06-20', 'shipped_at' => '2026-07-05', 'revenue' => 900],
            // July -  inside it.
            ['region' => 'West', 'order_date' => '2026-07-01', 'shipped_at' => '2026-07-10', 'revenue' => 300],
            ['region' => 'West', 'order_date' => '2026-07-31', 'shipped_at' => '2026-08-02', 'revenue' => 200],
            ['region' => 'East', 'order_date' => '2026-07-15', 'shipped_at' => '2026-07-20', 'revenue' => 400],
            // August -  after it.
            ['region' => 'West', 'order_date' => '2026-08-01', 'shipped_at' => '2026-08-03', 'revenue' => 5000],
        ]);
    }

    /**
     * Run the package's SQL and the gold SQL, and compare answers.
     *
     * Compared as multisets of scalars: a result is correct when it contains
     * the right numbers, regardless of column aliases or row order, which is
     * what execution accuracy grades.
     */
    private function assertMatchesGold(array $intent, string $goldSql, string $because): void
    {
        $built = $this->app->make(SqlBuilder::class)->buildQuery($intent + ['dataset' => 'tf_sales']);

        $this->assertTrue($built['success'], 'build failed: ' . ($built['error'] ?? '') . " -  {$because}");

        $actual = DB::select($built['sql'], $built['bindings'] ?? []);
        $gold = DB::select($goldSql);

        $this->assertSame(
            $this->normalize($gold),
            $this->normalize($actual),
            "{$because}\n  gold: {$goldSql}\n  ours: {$built['sql']}\n  bindings: " . json_encode($built['bindings'] ?? [])
        );
    }

    /** Numbers a person would read off the result, in a comparable shape. */
    private function normalize(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $values = [];

            foreach ((array) $row as $value) {
                $values[] = is_numeric($value) ? (string) round((float) $value, 2) : (string) $value;
            }

            sort($values);
            $out[] = implode('|', $values);
        }

        sort($out);

        return $out;
    }

    #[Test]
    public function total_for_a_closed_period()
    {
        // "revenue in July 2026" -  300 + 200 + 400 = 900. June and August must
        // not be counted; the August row alone (5000) would swamp the answer.
        $this->assertMatchesGold(
            ['metric' => 'revenue', 'date_from' => '2026-07-01', 'date_to' => '2026-07-31', 'group_by' => 'region'],
            "SELECT region, SUM(revenue) AS revenue FROM tf_sales
             WHERE order_date >= '2026-07-01' AND order_date <= '2026-07-31'
             GROUP BY region ORDER BY SUM(revenue) DESC LIMIT 10",
            'a closed period must exclude both sides'
        );
    }

    #[Test]
    public function an_open_ended_period_from_a_date()
    {
        // "revenue since July" -  July and August, not June.
        $this->assertMatchesGold(
            ['metric' => 'revenue', 'date_from' => '2026-07-01', 'group_by' => 'region'],
            "SELECT region, SUM(revenue) AS revenue FROM tf_sales
             WHERE order_date >= '2026-07-01'
             GROUP BY region ORDER BY SUM(revenue) DESC LIMIT 10",
            'an open-ended period must include everything after it'
        );
    }

    #[Test]
    public function an_open_ended_period_up_to_a_date()
    {
        $this->assertMatchesGold(
            ['metric' => 'revenue', 'date_to' => '2026-06-30', 'group_by' => 'region'],
            "SELECT region, SUM(revenue) AS revenue FROM tf_sales
             WHERE order_date <= '2026-06-30'
             GROUP BY region ORDER BY SUM(revenue) DESC LIMIT 10",
            'an up-to period must exclude everything after it'
        );
    }

    #[Test]
    public function no_period_covers_everything()
    {
        // The regression guard: adding time must not narrow questions that
        // never asked for a period.
        $this->assertMatchesGold(
            ['metric' => 'revenue', 'group_by' => 'region'],
            'SELECT region, SUM(revenue) AS revenue FROM tf_sales
             GROUP BY region ORDER BY SUM(revenue) DESC LIMIT 10',
            'a question with no period must cover all rows'
        );
    }

    /**
     * The case the parenthesisation bug would have failed.
     *
     * The name match is an OR. AND-ing the period onto it unparenthesised
     * binds the date to only half the condition, so West would come back with
     * its all-time total -  1500 instead of 500 -  and only filtered questions
     * would be affected.
     */
    #[Test]
    public function a_period_and_a_name_filter_both_apply()
    {
        $built = $this->app->make(SqlBuilder::class)->buildQuery([
            'dataset' => 'tf_sales',
            'metric' => 'revenue',
            'group_value' => 'West',
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
        ]);

        $this->assertTrue($built['success'], $built['error'] ?? '');

        $rows = DB::select($built['sql'], $built['bindings']);
        $gold = DB::select(
            "SELECT SUM(revenue) AS revenue FROM tf_sales
             WHERE region = 'West' AND order_date >= '2026-07-01' AND order_date <= '2026-07-31'"
        );

        $this->assertSame(500.0, (float) $gold[0]->revenue, 'gold fixture sanity');
        $this->assertSame(
            500.0,
            (float) $rows[0]->revenue,
            'West in July is 500; 1500 means the period was lost to the OR'
        );
    }

    #[Test]
    public function the_schema_decides_which_date_column_a_period_means()
    {
        // Every row shipped in a different month from when it was ordered. If
        // the period were applied to shipped_at instead of the declared
        // order_date, the total changes -  so this distinguishes them.
        $ours = $this->app->make(SqlBuilder::class)->buildQuery([
            'dataset' => 'tf_sales',
            'metric' => 'revenue',
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
            'group_by' => 'region',
        ]);

        $byOrderDate = (float) DB::select(
            "SELECT SUM(revenue) AS r FROM tf_sales WHERE order_date BETWEEN '2026-07-01' AND '2026-07-31'"
        )[0]->r;

        $byShippedAt = (float) DB::select(
            "SELECT SUM(revenue) AS r FROM tf_sales WHERE shipped_at BETWEEN '2026-07-01' AND '2026-07-31'"
        )[0]->r;

        $this->assertNotSame($byOrderDate, $byShippedAt, 'fixture must distinguish the two columns');

        $total = array_sum(array_map(
            fn ($row) => (float) $row->revenue,
            DB::select($ours['sql'], $ours['bindings'])
        ));

        $this->assertSame($byOrderDate, $total, 'the declared date_column must be the one used');
    }
}
