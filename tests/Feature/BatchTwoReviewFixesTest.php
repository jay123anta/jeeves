<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Jayanta\Jeeves\Cache\TwoTierQueryCache;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Engine\PromptBudget;
use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Support\EnvValue;
use Jayanta\Jeeves\Tests\Support\RecordingProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The second review batch, pinned.
 *
 * Five defects, four of them introduced by the commits that fixed the FIRST
 * review batch. That ratio is the point: every one of these was created while
 * fixing something else, and none of them would have been caught by the suite
 * as it stood.
 */
class BatchTwoReviewFixesTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/fuzzy-cache-isolation-schemas');
        $app['config']->set('jeeves.cache.enabled', true);
    }

    /**
     * A single-character token is the whole difference between two questions.
     *
     * normalizeQuery() dropped every token of length <= 1, so "grade a" and
     * "grade b" normalised identically, hashed identically, and shared one
     * row. The second question got the first's number -  right shape, wrong
     * answer, nothing in the text to reveal it -  and the scope guard cannot
     * help, because both resolve to the same scope. Single-character values
     * are ordinary: grade A, block B, zone 1.
     */
    #[Test]
    public function a_single_character_value_still_reaches_the_cache_key()
    {
        $cache = $this->app->make(TwoTierQueryCache::class);

        // EVERY single character, not a pair.
        //
        // The first version of this test asserted only that "region a" and
        // "region b" differ. They do -  and 'a' was still being stripped as a
        // filler word while 'b' was not, so the assertion passed on the one
        // letter that worked. Two of the thirty-six then collapsed into the
        // un-narrowed question: "total revenue for A" and "total revenue"
        // shared a row, and asking for one region returned the grand total.
        //
        // The guard held on the path it was written for, in the test written
        // to prove the guard held. Enumerating the whole set is the only
        // version of this test worth having.
        $bare = $cache->normalizeQuery('revenue for zone');
        $collapsed = [];

        foreach (array_merge(range('a', 'z'), range('0', '9')) as $token) {
            if ($cache->normalizeQuery("revenue for zone {$token}") === $bare) {
                $collapsed[] = $token;
            }
        }

        $this->assertSame(
            [],
            $collapsed,
            'these one-character values vanish from the cache key, so a question narrowed by one is '
                . 'answered from the un-narrowed question\'s row -  and the reverse: ' . implode(', ', $collapsed)
        );

        // And distinct from each other, not merely present.
        $this->assertNotSame(
            $cache->normalizeQuery('revenue for zone a'),
            $cache->normalizeQuery('revenue for zone i'),
            'two different one-character values share a cache key'
        );
    }

    /**
     * The PUBLISHED config must not reference package code.
     *
     * jeeves:install publishes config/jeeves.php into the app, and
     * a published file outlives the package: `composer remove` it, or roll
     * back to a version where the class does not exist, and Laravel loads that
     * config on every boot. A `use Jayanta\Jeeves\...` in there means the
     * application cannot start -  and cannot run the artisan command that would
     * remove the file either. There is no way out except editing it by hand.
     *
     * The EnvValue helper introduced in this release was referenced from 20
     * lines of it. The helper stays for package code; the config does the same
     * job inline.
     */
    #[Test]
    public function the_published_config_does_not_depend_on_package_classes()
    {
        $source = file_get_contents(__DIR__ . '/../../config/jeeves.php');

        $this->assertDoesNotMatchRegularExpression(
            '/^\s*use\s+Jayanta\\\\Jeeves/m',
            $source,
            'the published config imports a package class, so removing or downgrading the package leaves '
                . 'the application unable to boot and unable to run artisan to fix itself'
        );

        $this->assertStringNotContainsString(
            'Jayanta\\Jeeves',
            $source,
            'the published config names a package class'
        );
    }

    /**
     * Clearing the cache has to clear the cache. Tier 1 is checked first, so a
     * row deleted from the database keeps answering from memory for up to
     * cache.ttl -  24 hours by default -  while the operator has been told
     * "Cleared all N cache entries". The forget loop only ran for --dataset.
     */
    #[Test]
    public function clearing_everything_also_forgets_the_fast_tier()
    {
        $this->artisan('migrate', ['--force' => true])->run();
        config(['jeeves.cache.tier1_store' => 'array']);

        $cache = $this->app->make(TwoTierQueryCache::class);
        $question = 'total revenue for nq_orders';

        $cache->store($question, ['dataset' => 'nq_orders', 'metric' => 'revenue', '_asking_scope' => 'nq_orders']);
        $this->assertNotNull($cache->findForDataset($question, 'nq_orders'), 'precondition: it is cached');

        $cache->clear();

        $this->assertNull(
            $cache->findForDataset($question, 'nq_orders'),
            'the row was deleted from the database and kept answering from Tier 1, so a cleared cache '
                . 'goes on serving the answers it was cleared to remove'
        );
    }

    /**
     * mergeConfigFrom is one level deep. An app that published the config
     * before 2.1.0 has a `prompts` array with no `max_chars` in it, which
     * replaces the package's wholesale -  so the key does not exist and setting
     * the env var did nothing, silently, on exactly the installs large enough
     * to want it.
     */
    #[Test]
    public function the_prompt_bound_is_reachable_when_a_published_config_predates_it()
    {
        $_ENV['JEEVES_PROMPT_MAX_CHARS'] = $_SERVER['JEEVES_PROMPT_MAX_CHARS'] = '4000';

        // A pre-2.1.0 published `prompts` block: no max_chars key at all.
        config(['jeeves.prompts' => ['system_role' => null]]);
        $this->app->forgetInstance(PromptBudget::class);

        $budget = $this->app->make(PromptBudget::class);

        $this->assertNotNull(
            $budget->check(str_repeat('x', 8000), 3),
            'JEEVES_PROMPT_MAX_CHARS was set and ignored, because the published config has no such '
                . 'key and mergeConfigFrom does not reach into it'
        );

        unset($_ENV['JEEVES_PROMPT_MAX_CHARS'], $_SERVER['JEEVES_PROMPT_MAX_CHARS']);
    }

    /** And an explicit config value still wins over the env fallback. */
    #[Test]
    public function a_published_max_chars_value_still_takes_precedence()
    {
        $_ENV['JEEVES_PROMPT_MAX_CHARS'] = $_SERVER['JEEVES_PROMPT_MAX_CHARS'] = '10';

        config(['jeeves.prompts' => ['max_chars' => null]]);
        $this->app->forgetInstance(PromptBudget::class);

        $this->assertNull(
            $this->app->make(PromptBudget::class)->check(str_repeat('x', 8000), 3),
            'an explicit null in the published config was overridden by the env fallback'
        );

        unset($_ENV['JEEVES_PROMPT_MAX_CHARS'], $_SERVER['JEEVES_PROMPT_MAX_CHARS']);
    }

    /**
     * Internal flags never reach a client. The two rate-limit exits added for
     * the planner and the step loop return before query()'s tail, which is
     * where `_rate_limited` is stripped.
     */
    #[Test]
    public function the_new_rate_limit_exits_do_not_leak_internal_flags()
    {
        config(['jeeves.query_mode' => 'sql_generation', 'jeeves.chat.multi_step' => true]);

        $provider = new RecordingProvider;
        $provider->sqlResponse = ['success' => false, 'error' => 'Too Many Requests', 'status' => 429];
        $provider->intentResponse = ['success' => false, 'error' => 'Too Many Requests', 'status' => 429];
        $this->app->instance(LlmProviderInterface::class, $provider);

        $result = $this->app->make(QueryOrchestrator::class)
            ->query('compare revenue for nq_orders with nq_products');

        $this->assertArrayNotHasKey('_rate_limited', $result, json_encode($result));
        $this->assertArrayNotHasKey('_unretriable', $result);
        $this->assertArrayNotHasKey('_fallback_eligible', $result);
    }
}
