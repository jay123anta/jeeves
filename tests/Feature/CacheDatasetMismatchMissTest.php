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
 * NQ-003-FIX, RED for G2.
 *
 * NQ-003 stopped the cache replaying one dataset's cached answer for a
 * question about another, but the mechanism it used to do that was
 * RECONCILIATION, not refusal: on a dataset mismatch,
 * `QueryOrchestrator::processWithSqlGeneration()`'s `_sql_result` branch
 * retargets the cached recipe through `SqlBuilder::buildQuery()` for the
 * newly-asked dataset, and `processWithIntent()` silently overwrites
 * `$intent['dataset']` with whatever `resolveAskingDataset()` says. Both
 * are zero-API-call "fixes" that can produce a confidently wrong answer:
 *
 *   - BLOCKER A: `SqlBuilder::buildQuery()` rebuilds from the cached
 *     recipe's `metric`/`group_value`/`limit`/`order`/`query_type` alone.
 *     A predicate the intent contract cannot express -  a HAVING, a numeric
 *     comparison, anything that only exists in the raw SQL text the model
 *     originally wrote -  has nowhere to ride along, so it is silently
 *     dropped and `buildQuery()` reports success anyway: a filtered
 *     question is answered UNFILTERED, on a different table.
 *   - BLOCKER B: `resolveAskingDataset()` falls back to
 *     `DatasetSeeder::detect()`, a bare keyword guess, whenever no
 *     `$datasetHint` and no conversation state are available. On a cache
 *     hit that guess OVERWRITES the dataset the model itself parsed the
 *     first time, so an identical repeat of a question can be answered
 *     from a different table than the first time purely because the
 *     keyword guess disagrees with what the model said.
 *
 * NQ-003-FIX deletes both reconciliation paths. A dataset mismatch is now
 * a cache MISS: one more provider call, never a silent retarget or
 * override. This file is written BEFORE that deletion and must fail
 * against the code as it stands today -  see each test's docblock for the
 * specific mechanism it exercises and why it is red right now.
 *
 * `tests/Feature/FuzzyCacheDatasetIsolationTest::
 * an_exact_tier1_hit_must_not_ignore_a_changed_dataset_hint` (now renamed
 * `an_exact_tier1_candidate_with_a_changed_dataset_hint_is_a_cache_miss`)
 * covers the same mechanism as this file's first test, in the other
 * direction (nq_orders asked first, nq_products second) -  deliberately
 * kept in that file since it already owned the Tier 1 route; this file
 * covers everything else NQ-003-FIX's own defect report calls out by name.
 */
class CacheDatasetMismatchMissTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/fuzzy-cache-isolation-schemas');
        $app['config']->set('jeeves.query_mode', 'sql_generation');
        $app['config']->set('jeeves.cache.enabled', false);
        $app['config']->set('jeeves.query_routing', []);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('nq_orders');
        Schema::create('nq_orders', function ($t) {
            $t->id();
            $t->decimal('revenue', 12, 2);
        });
        DB::table('nq_orders')->insert([
            ['revenue' => 100],
            ['revenue' => 200],
            ['revenue' => 50],
        ]);
        // Hand-checked: 100 + 200 + 50 = 350. Only 200 exceeds 150.

        Schema::dropIfExists('nq_products');
        Schema::create('nq_products', function ($t) {
            $t->id();
            $t->decimal('revenue', 12, 2);
        });
        DB::table('nq_products')->insert([
            ['revenue' => 30],
            ['revenue' => 45],
            ['revenue' => 15],
        ]);
        // Hand-checked: 30 + 45 + 15 = 90. Only 45 exceeds 40.
    }

    private function total(array $result): float
    {
        return (float) array_values((array) $result['rows'][0])[0];
    }

    /** Same shape as FuzzyCacheDatasetIsolationTest::wiredProvider(), reused here
     * so this file does not depend on that file's private helper. */
    private function wiredProvider(): RecordingProvider
    {
        $provider = new RecordingProvider;

        $provider->sqlResponse = function (string $prompt) {
            $sawProducts = str_contains(strtolower($prompt), 'nq_products');

            return [
                'success' => true,
                'data' => [
                    'sql' => $sawProducts
                        ? 'SELECT SUM(revenue) AS total FROM nq_products'
                        : 'SELECT SUM(revenue) AS total FROM nq_orders',
                    'dataset' => $sawProducts ? 'nq_products' : 'nq_orders',
                    'metric' => 'revenue',
                    'query_type' => 'aggregation',
                    'group_value' => null,
                    'order' => 'DESC',
                    'limit' => 100,
                    'period' => null,
                    'explanation' => 'Total revenue',
                ],
            ];
        };

        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        return $provider;
    }

    /**
     * WHAT THIS TEST CATCHES: the other direction of the Tier 1 exact-hash
     * mismatch (nq_products asked first, nq_orders second -  the sibling of
     * FuzzyCacheDatasetIsolationTest's rewritten test, which goes
     * nq_orders-then-nq_products). "One direction passing by luck is not a
     * fix": a retarget/override bug that only shows on one ordering would
     * pass a test that checked only the other.
     *
     * In THIS predicate-free fixture, `SqlBuilder::buildQuery()`'s retarget
     * happens to recompute a numerically correct total against the live
     * table (it is a fresh `SUM(revenue)` over whichever dataset it is
     * pointed at, not a replay of stored numbers) -  so the NUMBER alone
     * does not distinguish today's unsafe retarget from tomorrow's honest
     * miss. The call-count assertion is what is actually red right now:
     * the retarget answers with ZERO extra provider calls, and the fix
     * requires exactly ONE.
     */
    #[Test]
    public function a_mismatch_from_products_to_orders_misses_and_returns_the_orders_total()
    {
        config(['jeeves.cache.enabled' => true]);
        $this->artisan('migrate', ['--force' => true])->run();

        $provider = $this->wiredProvider();

        $question = 'what is the total';

        $first = $this->app->make(QueryOrchestrator::class)->query($question, 'nq_products');
        $this->assertSame('success', $first['status'] ?? null, json_encode($first));
        $this->assertEquals(90.0, $this->total($first), 'precondition: nq_products total is 90 (30+45+15)');
        $callsAfterFirst = count($provider->methodsCalled());

        $second = $this->app->make(QueryOrchestrator::class)->query($question, 'nq_orders');

        // THE RED ASSERTION. Today this is $callsAfterFirst (zero extra
        // calls) because the mismatch is retargeted through SqlBuilder
        // instead of missing.
        $this->assertSame(
            $callsAfterFirst + 1,
            count($provider->methodsCalled()),
            'a dataset mismatch must MISS and cost exactly one fresh provider call, not be reconciled for free: '
                . json_encode($provider->calls)
        );

        $this->assertSame('success', $second['status'] ?? null, json_encode($second));
        $this->assertEquals(
            350.0,
            $this->total($second),
            'a mismatch must answer from the dataset actually asked about (nq_orders: 100+200+50). Got: ' . json_encode($second)
        );
    }

    /**
     * WHAT THIS TEST CATCHES (guard, not a defect): a fix that makes every
     * mismatch miss must not turn the cache off altogether. Two calls with
     * the IDENTICAL text and the IDENTICAL dataset hint must still cost
     * only one provider call in total -  the second one served from Tier 1.
     * A cache that never hits fails silently and slowly: nothing is wrong with
     * any answer, every question just costs a provider call it should not.
     *
     * This is expected to PASS today: the same-dataset path does not go
     * through either reconciliation branch NQ-003-FIX removes, so nothing
     * here is red before the fix. It must stay green after it.
     */
    #[Test]
    public function an_identical_repeat_with_the_same_dataset_hint_still_hits_the_cache()
    {
        config(['jeeves.cache.enabled' => true]);
        $this->artisan('migrate', ['--force' => true])->run();

        $provider = $this->wiredProvider();

        $question = 'what is the total';

        $first = $this->app->make(QueryOrchestrator::class)->query($question, 'nq_orders');
        $this->assertSame('success', $first['status'] ?? null, json_encode($first));
        $this->assertEquals(350.0, $this->total($first));
        $callsAfterFirst = count($provider->methodsCalled());

        $second = $this->app->make(QueryOrchestrator::class)->query($question, 'nq_orders');

        $this->assertSame(
            $callsAfterFirst,
            count($provider->methodsCalled()),
            'a genuine repeat (same words, same dataset) must still be served from cache, not cost a fresh call: '
                . json_encode($provider->calls)
        );
        $this->assertSame('success', $second['status'] ?? null, json_encode($second));
        $this->assertEquals(350.0, $this->total($second), 'a genuine repeat must still answer 350 from cache');
    }

    /**
     * WHAT THIS TEST CATCHES: BLOCKER A specifically. The cached recipe
     * `QueryOrchestrator::processWithSqlGeneration()` stores alongside
     * `_sql_result` is `{dataset, metric, group_value, limit, order,
     * query_type}` -  it has NOWHERE to carry a WHERE-clause predicate the
     * intent contract cannot express. First question: "total revenue over
     * 150" against nq_orders, where the AI writes
     * `SELECT SUM(revenue) FROM nq_orders WHERE revenue > 150` -  only the
     * 200 row qualifies (100 and 50 do not), so the true filtered answer is
     * 200, not the unfiltered 350.
     *
     * Second question: the SAME words, asked with a dataset hint for
     * nq_products. Today's retarget calls
     * `SqlBuilder::buildQuery(['dataset' => 'nq_products'] + $cached['intent'])`
     * -  metric=revenue, no filter fields at all -  which rebuilds
     * `SELECT SUM(revenue) FROM nq_products` UNFILTERED (30+45+15=90) and
     * reports success. That is a filtered question answered unfiltered, on
     * a different table - a confident wrong number, which is the worst
     * output this package can produce.
     *
     * After the fix, the mismatch misses and a fresh provider call
     * generates (and, per this stub, correctly filters) SQL for
     * nq_products: `WHERE revenue > 40` -  only the 45 row qualifies (30
     * and 15 do not) -  so the honestly-obtained answer is 45, neither the
     * wrong-dataset 350/200 nor the retargeted-but-unfiltered 90.
     */
    #[Test]
    public function a_predicate_the_intent_contract_cannot_express_is_not_silently_dropped_on_a_mismatch()
    {
        config(['jeeves.cache.enabled' => true]);
        $this->artisan('migrate', ['--force' => true])->run();

        $provider = new RecordingProvider;
        $provider->sqlResponse = function (string $prompt) {
            $sawProducts = str_contains(strtolower($prompt), 'nq_products');

            return [
                'success' => true,
                'data' => $sawProducts ? [
                    'sql' => 'SELECT SUM(revenue) AS total FROM nq_products WHERE revenue > 40',
                    'dataset' => 'nq_products',
                    'metric' => 'revenue',
                    'query_type' => 'aggregation',
                    'group_value' => null,
                    'order' => 'DESC',
                    'limit' => 100,
                    'period' => null,
                    'explanation' => 'High-value product revenue (over 40)',
                ] : [
                    'sql' => 'SELECT SUM(revenue) AS total FROM nq_orders WHERE revenue > 150',
                    'dataset' => 'nq_orders',
                    'metric' => 'revenue',
                    'query_type' => 'aggregation',
                    'group_value' => null,
                    'order' => 'DESC',
                    'limit' => 100,
                    'period' => null,
                    'explanation' => 'High-value order revenue (over 150)',
                ],
            ];
        };
        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        $question = 'high value revenue total';

        $first = $this->app->make(QueryOrchestrator::class)->query($question, 'nq_orders');
        $this->assertSame('success', $first['status'] ?? null, json_encode($first));
        $this->assertEquals(200.0, $this->total($first), 'precondition: filtered nq_orders total (revenue>150) is 200 (only the 200 row qualifies)');
        $callsAfterFirst = count($provider->methodsCalled());

        $second = $this->app->make(QueryOrchestrator::class)->query($question, 'nq_products');

        // THE RED ASSERTION (miss cost). Today this is $callsAfterFirst
        // (zero extra calls): the mismatch is retargeted, not missed.
        $this->assertSame(
            $callsAfterFirst + 1,
            count($provider->methodsCalled()),
            'BLOCKER A: a mismatch on a cached SQL result carrying a predicate the intent contract cannot express must MISS, not be retargeted through SqlBuilder: '
                . json_encode($provider->calls)
        );
        $this->assertSame('success', $second['status'] ?? null, json_encode($second));

        // THE RED ASSERTION (the number). Today this is 90.0 -  the
        // retarget's UNFILTERED nq_products total, because the WHERE
        // clause had nowhere to ride along in the cached recipe.
        $this->assertNotEquals(
            90.0,
            $this->total($second),
            'BLOCKER A reproduced: the filtered question was answered with the UNFILTERED total after retargeting through SqlBuilder. Got: '
                . json_encode($second)
        );
        $this->assertEquals(
            45.0,
            $this->total($second),
            'expected a fresh, correctly-filtered answer for nq_products (only the 45 row exceeds 40). Got: ' . json_encode($second)
        );
    }

    /**
     * WHAT THIS TEST CATCHES: BLOCKER B specifically, in intent mode.
     * `DatasetSeeder::detect()` guesses a dataset from bare keywords -
     * "orders total please" contains the `nq_orders` schema's alias
     * ("orders"), so `resolveAskingDataset()` guesses nq_orders for BOTH
     * calls below, deterministically, since neither call passes a
     * `$datasetHint` or carries conversation state. The model itself (the
     * RecordingProvider stub standing in for it) says nq_products instead
     * -  a real model routinely disagrees with a bare keyword match, which
     * is the entire reason `parseIntent()` exists rather than the package
     * just calling `DatasetSeeder::detect()` alone.
     *
     * Today, the SECOND identical call hits the cached intent (dataset =
     * nq_products, from the model) and `processWithIntent()`'s override
     * (`if ($askingDataset && $askingDataset !== ($intent['dataset'] ??
     * null)) { $intent['dataset'] = $askingDataset; }`) silently replaces
     * it with the keyword guess (nq_orders) -  zero extra provider calls,
     * and the SAME question answered from a DIFFERENT table than moments
     * earlier: 350 instead of 90.
     */
    #[Test]
    public function a_cached_intents_dataset_is_not_silently_replaced_by_a_keyword_guess()
    {
        config([
            'jeeves.query_mode' => 'intent',
            'jeeves.cache.enabled' => true,
        ]);
        $this->artisan('migrate', ['--force' => true])->run();

        $provider = new RecordingProvider;
        // The model's own determination -  deliberately NOT what
        // DatasetSeeder::detect() would guess from this wording (see the
        // $question comment below).
        $provider->intentResponse = [
            'success' => true,
            'dataset' => 'nq_products',
            'metric' => 'revenue',
            'query_type' => 'aggregation',
            'limit' => 10,
            'order' => 'desc',
            'group_value' => null,
            'confidence' => 0.95,
            'needs_clarification' => false,
        ];
        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        // Contains the nq_orders schema's alias ("orders"), so
        // DatasetSeeder::detect() guesses nq_orders every time this exact
        // text is resolved -  deterministically, since no $datasetHint and
        // no conversation state are ever supplied below.
        $question = 'orders total please';

        $first = $this->app->make(QueryOrchestrator::class)->query($question);
        $this->assertSame('success', $first['status'] ?? null, json_encode($first));
        // Hand-checked: nq_products total is 30+45+15=90 -  the FIRST call
        // already used the model's nq_products answer, not the keyword
        // guess of nq_orders (which would read 350).
        $this->assertEquals(90.0, $this->total($first), "precondition: the model's own dataset (nq_products) answers 90, not the keyword guess's 350");
        $callsAfterFirst = count($provider->methodsCalled());

        $second = $this->app->make(QueryOrchestrator::class)->query($question);

        // A HIT IS NOW THE RIGHT OUTCOME, and it is worth saying why this
        // assertion inverted.
        //
        // The defect was never the hit. It was the OVERWRITE: the reader
        // reconciled the keyword guess against the cached intent by replacing
        // the model's dataset with the guess, so the same words returned 350
        // where they had returned 90. Missing was one way to stop that, and it
        // was the one taken first.
        //
        // It cost far too much. Missing on a keyword-guess disagreement means
        // missing whenever the guess and the model disagree -  which is the
        // normal case, and the reason parseIntent() exists at all instead of
        // DatasetSeeder::detect() alone. Every repeat of an ordinary question
        // paid for another API call.
        //
        // Eligibility now compares the SCOPE each question was asked under.
        // Both asks here are the same words with no hint, so both carry the
        // same scope and the row is eligible. The overwrite is gone, so the
        // hit returns the model's own answer rather than the guess's. The
        // guarantee below -  same question, same number -  is what this test
        // was always really defending, and it holds now at zero cost instead
        // of one API call per repeat.
        $this->assertLessThanOrEqual(
            $callsAfterFirst,
            count($provider->methodsCalled()),
            'an identical unscoped repeat should be served from cache: ' . json_encode($provider->calls)
        );
        $this->assertSame('success', $second['status'] ?? null, json_encode($second));

        // THE RED ASSERTION (the number). Today this is 350.0 -  the
        // keyword guess's nq_orders total, silently substituted for the
        // model's own nq_products answer.
        $this->assertEquals(
            90.0,
            $this->total($second),
            "the model's own parsed dataset must not be silently replaced by DatasetSeeder's keyword guess. Got: " . json_encode($second)
        );
    }

    /**
     * WHAT THIS TEST CATCHES (guard, not a defect -  unchanged from NQ-003,
     * must not regress): `TwoTierQueryCache::findForDataset()` refuses to
     * even ATTEMPT a fuzzy match when `resolveAskingDataset()` cannot place
     * the asking question on any dataset at all -  with two datasets
     * registered and no hint, no state, and no alias word in the text,
     * `count($keys) === 1 ? $keys[0] : null` returns null, and
     * `findForDataset()`'s `if ($datasetHint === null) { return null; }`
     * skips `findFuzzyMatch()` outright.
     *
     * This is not a mechanism NQ-003-FIX touches (it lives entirely in
     * `TwoTierQueryCache`, not in the retarget/override code being
     * deleted), so it is expected to PASS today and must keep passing
     * after the fix. The phrase pair below is chosen to score ABOVE the
     * configured similarity threshold by the shipped
     * `calculateSimilarity()` algorithm -  measured directly against that
     * function, not approximated: normalized to "overall revenue summary"
     * / "overall revenue total", Jaccard = 2/4 = 0.5, Levenshtein-similarity
     * = 1 - 6/23 = 0.73913, so 0.6*0.5 + 0.4*0.73913 = 0.59565, above the
     * 0.55 threshold configured below -  so if this guard ever regressed,
     * the second call would fuzzy-match the first
     * one's cached nq_orders answer instead of resolving its own dataset
     * fresh.
     */
    #[Test]
    public function the_fuzzy_tier_still_refuses_when_no_dataset_can_be_established()
    {
        config([
            'jeeves.cache.enabled' => true,
            'jeeves.cache.similarity_threshold' => 0.55,
        ]);
        $this->artisan('migrate', ['--force' => true])->run();

        $provider = new RecordingProvider;
        $provider->intentResponse = [
            'success' => true,
            'dataset' => 'nq_orders',
            'metric' => 'revenue',
            'query_type' => 'aggregation',
            'limit' => 10,
            'order' => 'desc',
            'group_value' => null,
            'confidence' => 0.9,
            'needs_clarification' => false,
        ];
        $provider->sqlResponse = [
            'success' => true,
            'data' => [
                'sql' => 'SELECT SUM(revenue) AS total FROM nq_orders',
                'dataset' => 'nq_orders',
                'metric' => 'revenue',
                'query_type' => 'aggregation',
                'group_value' => null,
                'order' => 'DESC',
                'limit' => 100,
                'period' => null,
                'explanation' => 'Total revenue',
            ],
        ];
        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        // Neither phrase contains "orders", "products", "nq_orders" or
        // "nq_products" -  DatasetSeeder::detect() cannot place either one,
        // and with two datasets registered resolveAskingDataset() cannot
        // fall back to "the only dataset" either.
        $q1 = 'overall revenue summary';
        $q2 = 'overall revenue total';

        $first = $this->app->make(QueryOrchestrator::class)->query($q1, 'nq_orders');
        $this->assertSame('success', $first['status'] ?? null, json_encode($first));
        $this->assertEquals(350.0, $this->total($first));

        $second = $this->app->make(QueryOrchestrator::class)->query($q2);

        // No fuzzy hit: cache_hit must not be true and, if the key is
        // present at all, cache_match_type must not be 'fuzzy'.
        $this->assertFalse(
            $second['metadata']['cache_hit'] ?? false,
            'the fuzzy tier must refuse to guess a dataset when none can be established, not serve a similarity-matched row: '
                . json_encode($second)
        );
        $this->assertNotSame(
            'fuzzy',
            $second['metadata']['cache_match_type'] ?? null,
            json_encode($second)
        );
        $this->assertSame('success', $second['status'] ?? null, json_encode($second));
        $this->assertEquals(350.0, $this->total($second), 'freshly resolved (via the stubbed intent fallback) to nq_orders, not fuzzy-matched');
    }
}
