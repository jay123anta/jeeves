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
 * The staleness guard, on the route where the dates are invisible.
 *
 * `sql_generation` caches the model's finished SQL, so "revenue last month"
 * bakes that month in as literals. The first guard against this read
 * `time_filter`, which on this route is `$data['period']` - what the model
 * SAYS about the WHERE it wrote, in an optional free-text field.
 *
 * That failed in both directions at once. A model that filtered dates and
 * omitted the field disarmed the guard completely, so the June window was
 * cached and replayed into August - the exact defect the guard exists to
 * prevent, alive on the other route. And a model that answered "none" instead
 * of a null armed it on every question, switching caching off entirely with
 * nothing logged.
 *
 * Neither was covered: deleting the whole branch left the suite green.
 *
 * The guard now reads the SQL. Fixture `ps_orders`: June 2026 = 300, July
 * 2026 = 700, so a stale window cannot pass by coincidence.
 */
class ACachedRecipeIsNotPinnedToAMonthTest extends TestCase
{
    private const JUNE = 'SELECT SUM(revenue) AS revenue FROM ps_orders '
        . "WHERE placed_on >= '2026-06-01' AND placed_on <= '2026-06-30'";

    private const JULY = 'SELECT SUM(revenue) AS revenue FROM ps_orders '
        . "WHERE placed_on >= '2026-07-01' AND placed_on <= '2026-07-31'";

    private const ALL_TIME = 'SELECT SUM(revenue) AS revenue FROM ps_orders';

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/period-carry-schemas');
        $app['config']->set('jeeves.cache.enabled', true);
        $app['config']->set('jeeves.verification.enabled', false);
        $app['config']->set('jeeves.query_mode', 'sql_generation');
    }

    private function seedTwoMonths(): void
    {
        Schema::dropIfExists('ps_orders');
        Schema::create('ps_orders', function ($t) {
            $t->id();
            $t->string('region');
            $t->date('placed_on');
            $t->decimal('revenue', 12, 2);
        });

        DB::table('ps_orders')->insert([
            ['region' => 'West', 'placed_on' => '2026-06-10', 'revenue' => 300],
            ['region' => 'West', 'placed_on' => '2026-07-10', 'revenue' => 700],
        ]);
    }

    /** @return array{0: array, 1: RecordingProvider} */
    private function ask(string $question, string $sql, array $extra = []): array
    {
        $provider = new RecordingProvider;
        $provider->sqlResponse = [
            'success' => true,
            'data' => array_merge([
                'sql' => $sql,
                'dataset' => 'ps_orders',
                'metric' => 'revenue',
                'query_type' => 'aggregation',
                'explanation' => 'Revenue',
            ], $extra),
        ];

        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        return [$this->app->make(QueryOrchestrator::class)->query($question, 'ps_orders'), $provider];
    }

    private function total(array $r): float
    {
        $row = $r['rows'][0] ?? null;

        return (float) (is_array($row) ? ($row['revenue'] ?? 0) : ($row->revenue ?? 0));
    }

    /**
     * THE DEFECT. The model filters dates and says nothing about it.
     *
     * This is the common case on a small model, not the edge case: `period` is
     * an optional field in a shared prompt and omitting it costs the model
     * nothing.
     */
    #[Test]
    public function a_recipe_that_bakes_a_month_into_its_sql_is_not_replayed_next_month()
    {
        $this->artisan('migrate', ['--force' => true])->run();
        $this->seedTwoMonths();

        // Asked in July: "last month" is June. No `period` key at all.
        [$july] = $this->ask('revenue last month', self::JUNE);
        $this->assertSame('success', $july['status'], 'fixture check: the first ask must succeed');
        $this->assertEquals(300.0, $this->total($july), 'fixture check: June holds 300');

        // Asked in August, same words: "last month" is now July.
        [$august, $provider] = $this->ask('revenue last month', self::JULY);

        $this->assertSame('success', $august['status']);
        $this->assertEquals(
            700.0,
            $this->total($august),
            'a recipe with June baked into its WHERE was replayed in August because the model had '
                . 'not declared a period - the guard was reading the claim instead of the SQL'
        );
        $this->assertNotEmpty(
            $provider->methodsCalled(),
            'the second ask was served from cache, so the dated recipe had been stored'
        );
    }

    /**
     * The counterweight. Refusing everything would also pass the test above.
     */
    #[Test]
    public function an_undated_recipe_is_still_cached()
    {
        $this->artisan('migrate', ['--force' => true])->run();
        $this->seedTwoMonths();

        [$first] = $this->ask('total revenue', self::ALL_TIME);
        $this->assertSame('success', $first['status']);
        $this->assertEquals(1000.0, $this->total($first), 'fixture check: 300 + 700');

        [$second, $provider] = $this->ask('total revenue', self::ALL_TIME);

        $this->assertEquals(1000.0, $this->total($second));
        $this->assertSame(
            [],
            $provider->methodsCalled(),
            'an ordinary undated question stopped being cached on this route, the over-refusal '
                . 'that makes a guard cost more than the bug it prevents'
        );
    }

    /**
     * Rule 8, stated as a test: the SQL decides, never the model's self-report.
     *
     * Both halves put the same guard in front of the same question, with the
     * claim and the artifact disagreeing in opposite directions.
     */
    #[Test]
    public function the_guard_reads_the_sql_and_not_what_the_model_said_about_it()
    {
        $this->artisan('migrate', ['--force' => true])->run();
        $this->seedTwoMonths();

        $table = config('jeeves.cache.table_name', 'jeeves_cache');

        // The model denies filtering dates. Its SQL filters dates.
        DB::table($table)->delete();
        [$denied] = $this->ask('revenue last month', self::JUNE, ['period' => 'none']);
        $this->assertSame('success', $denied['status']);
        $this->assertSame(
            0,
            DB::table($table)->count(),
            'a dated recipe was cached because the model wrote "none" in a free-text field'
        );

        // The model claims a period. Its SQL has none, so it is cacheable.
        DB::table($table)->delete();
        [$claimed] = $this->ask('total revenue', self::ALL_TIME, ['period' => 'June 2026']);
        $this->assertSame('success', $claimed['status']);
        $this->assertSame(
            1,
            DB::table($table)->count(),
            'caching was refused on a model self-report while the SQL it wrote covers all time - '
                . 'the over-refusal that switched the cache off entirely on small models'
        );
    }
}
