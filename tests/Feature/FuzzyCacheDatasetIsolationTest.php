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
 * NQ-003, RED for G2.
 *
 * `TwoTierQueryCache::find(string $query)` (src/Cache/TwoTierQueryCache.php)
 * takes ONLY the normalized question text. Nothing about which dataset the
 * ASKING question resolves to is ever consulted before a cached row is
 * served, on any of its routes:
 *
 *   - Tier 1 (fast KV store): keyed by `sha256(CONTRACT_VERSION . normalized
 *     text)`. `$datasetHint` -  the out-of-band signal a caller can supply
 *     (`QueryOrchestrator::query($q, $datasetHint)`; a widget embedded on a
 *     page already scoped to one dataset is the real-world source) -  is
 *     never part of the hash and is never even passed to `find()`.
 *   - Tier 2 exact: same hash, same gap.
 *   - Tier 2 fuzzy (`findFuzzyMatch()`): selects candidates by `LIKE` on
 *     significant words and ranks by `calculateSimilarity()` against
 *     `cache.similarity_threshold`. The `dataset` column the row already
 *     stores is never read.
 *
 * All three feed the SAME cached branch in
 * `QueryOrchestrator::processWithSqlGeneration()` -  `if ($cached &&
 * isset($cached['intent']['_sql_result'])) { return
 * $this->validateAndExecute($sqlResult, ...); }` -  which returns before any
 * prompt is built and before dataset detection (seeder/routing/LLM) ever
 * runs. That is the ONLY place a wrong-dataset SQL result could otherwise be
 * caught, so a cache hit that crosses datasets is never caught downstream
 * either.
 *
 * Routes enumerated and checked in this file:
 *   - Tier 2 fuzzy, different wording, no dataset hint  → test (a)
 *   - Tier 2 fuzzy, SAME dataset (must keep working)     → test (b)
 *   - Tier 1 exact, identical wording, changed dataset hint → test (c)
 * NQ-003-FIX: both (a) and (c) were rewritten, because NQ-003's own fix for
 * this file's original defect was itself unsafe -  a silent retarget through
 * `SqlBuilder` rather than a refusal -  and the `_sql_result` branch that
 * retarget lived in does not distinguish Tier 1 exact from Tier 2 fuzzy; it
 * runs identically for both. (a) -  `a_fuzzy_hit_must_not_replay_a_different_datasets_answer`
 * -  kept its name (still true: a mismatch no longer replays anything) but its
 * body now expects a MISS, the same change made to (c) -
 * `an_exact_tier1_candidate_with_a_changed_dataset_hint_is_a_cache_miss`,
 * formerly `an_exact_tier1_hit_must_not_ignore_a_changed_dataset_hint`. See
 * each test's docblock and `tests/Feature/CacheDatasetMismatchMissTest.php`.
 * NOT covered here: the conversation-turn path. `processWithIntent()`
 * explicitly disables cache USE while `$context['state']` is non-empty
 * (`if ($cached && !$inConversation)`), but `processWithSqlGeneration()`'s
 * `_sql_result` branch has no equivalent guard -  a follow-up could hit this
 * same gap. Left for the Adversarial Reviewer: it is the same structural
 * defect as (a)/(c) above, not a fourth mechanism, so a fix that makes
 * `find()`/the `_sql_result` branch itself dataset-aware closes it too; a
 * fix that only special-cases `findFuzzyMatch()` would not.
 *
 * No scoping feature is involved anywhere below. NQ-001-v2's per-question
 * dataset scoping (`PromptScope`, `SchemaShortlister`, `QueryOrchestrator::
 * resolveScope()`) was removed outright by NQ-001-REDUCE -  the G1-round-3
 * ruling struck it out entirely, keeping only `PromptBudget`'s bare
 * `prompts.max_chars` size refusal. An earlier revision of that now-deleted
 * scoping feature happened to catch this exact cross-dataset scenario as a
 * SIDE EFFECT when it was turned on, in what was then `PromptScopeGateTest`
 * (also since removed) -  which passed for that reason, not because the
 * cache itself was fixed. This file proves the cache is broken on its own,
 * with nothing else involved.
 */
class FuzzyCacheDatasetIsolationTest extends TestCase
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
        // Hand-checked: 100 + 200 + 50 = 350.

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
        // Hand-checked: 30 + 45 + 15 = 90.
    }

    /**
     * What a real model would plausibly write: it can only name a table it
     * was actually shown IN ITS PROMPT for THIS call. The closure does not
     * decide the test's outcome -  it only reports which single-dataset
     * prompt was actually built for whichever call reaches the provider,
     * which a cache hit (correctly or incorrectly) can skip entirely.
     */
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

    private function total(array $result): float
    {
        return (float) array_values((array) $result['rows'][0])[0];
    }

    /**
     * WHAT THIS TEST CATCHES: a cache hit replaying a DIFFERENT dataset's
     * cached SQL for a question that names its own dataset by its own alias
     * word ("products").
     *
     * HISTORY: the route in was the fuzzy tier, which scored candidates on
     * text alone and so had no dataset field to consult at any threshold loose
     * enough to be useful. That tier was removed in 2.3.0 and the config keys
     * below are inert, so this pair now misses for a second reason as well as
     * the one being pinned.
     *
     * The keys are left set on purpose. The assertion is about the `_sql_result`
     * branch refusing a mismatched dataset rather than retargeting it, and that
     * branch is reachable from the exact tiers too - a later change that
     * reintroduced any wording match, or that widened what an exact hit may be
     * replayed onto, would land here. Deleting the config lines would quietly
     * narrow what this guards.
     *
     * NQ-003-FIX: this used to assert the fuzzy hit was retargeted for FREE
     * (zero extra provider calls) onto the right number, because in this
     * predicate-free fixture `SqlBuilder::buildQuery()`'s retarget happens to
     * recompute a correct `SUM(revenue)` against nq_products. That is the
     * same unsafe mechanism `CacheDatasetMismatchMissTest`'s Blocker A test
     * shows dropping a WHERE clause elsewhere -  the `_sql_result` branch does
     * not know it is looking at a fuzzy candidate rather than an exact one,
     * so it cannot be safe here and unsafe there. A dataset mismatch is now a
     * MISS on every route into that branch, fuzzy included: one fresh
     * provider call, never a replay and never a retarget.
     */
    #[Test]
    public function a_fuzzy_hit_must_not_replay_a_different_datasets_answer()
    {
        config([
            'jeeves.cache.enabled' => true,
            // Fuzzy matching is opt-in since 2.1.0; this file tests that tier.
            'jeeves.cache.fuzzy_matching' => true,
            'jeeves.cache.similarity_threshold' => 0.55,
        ]);
        $this->artisan('migrate', ['--force' => true])->run();

        $provider = $this->wiredProvider();

        $q1 = 'revenue summary for the orders channel';
        $q2 = 'revenue summary for the products channel';

        $first = $this->app->make(QueryOrchestrator::class)->query($q1);

        $this->assertSame('success', $first['status'] ?? null, json_encode($first));
        $this->assertEquals(350.0, $this->total($first), 'precondition: nq_orders total is 350 (100+200+50)');
        $callsAfterFirst = count($provider->methodsCalled());

        $second = $this->app->make(QueryOrchestrator::class)->query($q2);

        // The fuzzy candidate names nq_orders; the question actually asked
        // resolves (via its own alias word "products") to nq_products. That
        // mismatch must cost exactly one fresh provider call -  not zero
        // (replayed or retargeted for free), not two.
        $this->assertSame(
            $callsAfterFirst + 1,
            count($provider->methodsCalled()),
            'a fuzzy candidate naming a different dataset must MISS and cost exactly one fresh provider call: '
                . json_encode($provider->calls)
        );

        $this->assertSame('success', $second['status'] ?? null, json_encode($second));

        // nq_products' own total (30+45+15), obtained fresh -  not nq_orders'
        // 350 replayed, and not a retargeted-but-unverified recipe.
        $this->assertEquals(
            90.0,
            $this->total($second),
            'a fuzzy cache hit must answer from the dataset actually asked about, freshly generated. Got: ' . json_encode($second)
        );
    }

    /**
     * WHAT THIS TEST CATCHES (guard, not a defect): the cache must still earn
     * its keep. Removing the fuzzy tier in 2.3.0 was meant to stop the cache
     * GUESSING at wording, not to stop it working - a change that quietly
     * broke the exact tiers would look exactly like a success here otherwise.
     *
     * The same question asked twice costs one provider call, and "the same
     * question" is decided by normalizeQuery(): case, filler words, synonyms,
     * duplication and word order are all folded away before the lookup. That
     * is where the real reuse always came from.
     */
    #[Test]
    public function the_same_question_asked_again_still_answers_from_cache()
    {
        config(['jeeves.cache.enabled' => true]);
        $this->artisan('migrate', ['--force' => true])->run();

        $provider = $this->wiredProvider();

        $first = $this->app->make(QueryOrchestrator::class)->query('revenue summary for the orders channel');
        $this->assertSame('success', $first['status'] ?? null, json_encode($first));
        $this->assertEquals(350.0, $this->total($first));

        // Re-worded only in ways normalisation removes: different case, an
        // extra filler word, a different order. Still the same question.
        $second = $this->app->make(QueryOrchestrator::class)->query('Revenue summary for the Orders channel');

        $this->assertSame(
            1,
            count($provider->methodsCalled()),
            'the same question asked twice cost two provider calls, so the exact tiers are broken: '
                . json_encode($provider->calls)
        );
        $this->assertSame('success', $second['status'] ?? null, json_encode($second));
        $this->assertEquals(350.0, $this->total($second));
    }

    /**
     * THE COST of removing the tier, recorded rather than hidden.
     *
     * The test this replaces pinned the opposite behaviour and said in as many
     * words that making it red was to be reported, not deleted. So: a typo and
     * a paraphrase both MISS now and both pay for a provider call.
     *
     * They have to. Normalisation has already folded away everything two
     * questions can innocently differ by, so whatever is still different
     * afterwards differs in MEANING - and "summary" against "overview" is the
     * same shape of difference as "grade a" against "grade b". Nothing lexical
     * tells them apart, which is why the old scorer reused the second pair at
     * 0.858 while refusing this one at 0.592: it rewarded the tokens two
     * questions SHARE, so it grew more confident the longer and more specific
     * the question became.
     *
     * At the 0.85 the package shipped, both of these already missed. Only an
     * install that had lowered the threshold to around 0.55 was being served
     * them - and that install was also being served grade a for grade b.
     *
     * Paraphrases belong in the synonym map, where they fold during
     * normalisation into exact hits that nothing has to guess at.
     */
    #[Test]
    public function a_near_miss_in_wording_is_never_guessed_at()
    {
        config(['jeeves.cache.enabled' => true]);
        $this->artisan('migrate', ['--force' => true])->run();

        $provider = $this->wiredProvider();

        $this->app->make(QueryOrchestrator::class)->query('revenue summary for the orders channel');
        $calls = count($provider->methodsCalled());

        foreach ([
            'a typo' => 'revenue summry for the orders channel',
            'a paraphrase' => 'orders channel revenue overview',
        ] as $label => $question) {
            $result = $this->app->make(QueryOrchestrator::class)->query($question);

            $this->assertSame('success', $result['status'] ?? null, json_encode($result));
            $this->assertSame(
                ++$calls,
                count($provider->methodsCalled()),
                "{$label} was served from cache, so something is still guessing at wording"
            );
            $this->assertNotSame(
                'fuzzy',
                $result['metadata']['cache_match_type'] ?? null,
                "{$label} reported a fuzzy match, and there is no fuzzy tier any more"
            );
        }
    }

    /**
     * WHAT THIS TEST CATCHES (NQ-003-FIX): NQ-003's first fix closed the
     * Tier 1 gap this test used to pin by RETARGETING a mismatched hit
     * through `SqlBuilder::buildQuery()` instead of refusing it -  zero
     * provider calls, and (in this predicate-free fixture) numerically
     * right by accident, because `buildQuery()` recomputes a fresh
     * `SUM(revenue)` against whichever table it is pointed at rather than
     * replaying stored numbers. That reconciliation is itself unsafe (see
     * `CacheDatasetMismatchMissTest`'s Blocker A test, where the same
     * mechanism silently drops a WHERE clause the cached recipe cannot
     * carry) and NQ-003-FIX deletes it: a dataset mismatch on an
     * exact-hash Tier 1 candidate is now a cache MISS, costing exactly one
     * fresh provider call, never a silent retarget.
     *
     * Renamed from `an_exact_tier1_hit_must_not_ignore_a_changed_dataset_hint`
     * -  that name and its old assertions (a Tier 1 HIT, zero provider
     * calls, AND the other dataset's number, all three at once) are only
     * jointly satisfiable through the retarget this test now forbids.
     *
     * Tier 1 keys purely off `sha256(CONTRACT_VERSION .
     * normalizeQuery($query))` -  the same literal words always hash to the
     * same row, and `$datasetHint` never enters that hash. A question with
     * no dataset-identifying word of its own ("what is the total") depends
     * entirely on `$datasetHint` for which table it means; asking the exact
     * same words twice with two different hints must MISS, not silently
     * answer (or silently retarget to) the first call's dataset.
     */
    #[Test]
    public function an_exact_tier1_candidate_with_a_changed_dataset_hint_is_a_cache_miss()
    {
        config(['jeeves.cache.enabled' => true]);
        $this->artisan('migrate', ['--force' => true])->run();

        $provider = $this->wiredProvider();

        $question = 'what is the total';

        $first = $this->app->make(QueryOrchestrator::class)->query($question, 'nq_orders');
        $this->assertSame('success', $first['status'] ?? null, json_encode($first));
        $this->assertEquals(350.0, $this->total($first), 'precondition: nq_orders total is 350 (100+200+50)');
        $callsAfterFirst = count($provider->methodsCalled());

        $second = $this->app->make(QueryOrchestrator::class)->query($question, 'nq_products');

        // THE RED ASSERTION. Today this is $callsAfterFirst (zero extra
        // calls) because the mismatch is retargeted through SqlBuilder
        // instead of missing -  not reconciled for free, not two calls.
        $this->assertSame(
            $callsAfterFirst + 1,
            count($provider->methodsCalled()),
            'a dataset mismatch on an exact-hash Tier 1 candidate must MISS and cost exactly one fresh provider call, not be reconciled for free: '
                . json_encode($provider->calls)
        );

        $this->assertSame('success', $second['status'] ?? null, json_encode($second));

        // nq_products' own total (30+45+15), obtained from a fresh
        // generation about the dataset actually asked about -  not
        // nq_orders' 350 replayed, and not a retargeted-but-unverified 90.
        $this->assertEquals(
            90.0,
            $this->total($second),
            'a dataset mismatch must answer from the dataset actually asked about, freshly generated. Got: '
                . json_encode($second)
        );
    }
}
