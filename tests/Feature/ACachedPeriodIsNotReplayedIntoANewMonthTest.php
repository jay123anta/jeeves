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
 * "Last month" means a different month next month.
 *
 * The model resolves a relative period into absolute dates, and the intent was
 * cached whole - dates included. So "revenue last month" asked in July cached
 * a June window, and the same words asked in August replayed June: the wrong
 * number, silently, with the provider never consulted again.
 *
 * Nothing recovered from it either. Tier 2 rows carry no expiry, and the fuzzy
 * tier hands the same row to every rewording, so rephrasing the question did
 * not escape it.
 *
 * A resolved date range is only true for the moment it was resolved. The cache
 * key is the question's words, and those words do not change when the answer
 * does - so an intent carrying dates is not cacheable, and is no longer
 * stored. The cost is a provider call on every dated question; the alternative
 * is a wrong number that never heals.
 *
 * Fixture `ps_orders`: June 2026 = 300, July 2026 = 700. Two distinct values,
 * so a stale window cannot pass by coincidence.
 */
class ACachedPeriodIsNotReplayedIntoANewMonthTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/period-carry-schemas');
        $app['config']->set('jeeves.cache.enabled', true);
        $app['config']->set('jeeves.verification.enabled', false);
        $app['config']->set('jeeves.query_mode', 'intent');
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
    private function ask(string $question, array $intent): array
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

        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        return [$this->app->make(QueryOrchestrator::class)->query($question, 'ps_orders'), $provider];
    }

    private function total(array $r): float
    {
        $row = $r['rows'][0] ?? null;

        return (float) (is_array($row) ? ($row['revenue'] ?? 0) : ($row->revenue ?? 0));
    }

    /** THE DEFECT. Same words, a month later, a different correct answer. */
    #[Test]
    public function the_same_relative_question_is_re_resolved_in_a_new_month()
    {
        $this->artisan('migrate', ['--force' => true])->run();
        $this->seedTwoMonths();

        // Asked in July: "last month" is June.
        [$july] = $this->ask('revenue last month', [
            'date_from' => '2026-06-01', 'date_to' => '2026-06-30',
        ]);
        $this->assertEquals(300.0, $this->total($july), 'fixture check: June holds 300');

        // Asked in August, same words: "last month" is now July.
        [$august] = $this->ask('revenue last month', [
            'date_from' => '2026-07-01', 'date_to' => '2026-07-31',
        ]);

        $this->assertEquals(
            700.0,
            $this->total($august),
            'the cached June window was replayed into August, so a question whose correct answer '
                . 'had changed returned the old one with no provider call and no way to escape it'
        );
    }

    /** An intent carrying dates is not stored, so nothing can go stale. */
    #[Test]
    public function an_intent_with_a_resolved_period_is_not_cached()
    {
        $this->artisan('migrate', ['--force' => true])->run();
        $this->seedTwoMonths();

        $table = config('jeeves.cache.table_name', 'jeeves_cache');
        $before = DB::table($table)->count();

        [$result] = $this->ask('revenue last month', [
            'date_from' => '2026-06-01', 'date_to' => '2026-06-30',
        ]);

        // Counting rows that are not there passes just as well when the answer
        // failed and the guard never ran at all - a rate limit made this test
        // green while proving nothing.
        $this->assertSame('success', $result['status'] ?? null, json_encode($result));

        $this->assertSame(
            $before,
            DB::table($table)->count(),
            'an intent holding a resolved date range was written to a store keyed on the question '
                . 'text, which does not change when the right answer does'
        );
    }

    /** A question with no period still caches, or the cache stops earning its keep. */
    #[Test]
    public function a_question_without_a_period_is_still_cached()
    {
        $this->artisan('migrate', ['--force' => true])->run();
        $this->seedTwoMonths();

        $table = config('jeeves.cache.table_name', 'jeeves_cache');
        $before = DB::table($table)->count();

        [$first] = $this->ask('total revenue', []);
        $this->assertEquals(1000.0, $this->total($first), 'fixture check: 300 + 700');

        $this->assertGreaterThan(
            $before,
            DB::table($table)->count(),
            'an ordinary question stopped being cached, which is the whole point of the cache'
        );
    }
}
