<?php

namespace Jayanta\Jeeves\Tests\Security;

use Jayanta\Jeeves\Cache\TwoTierQueryCache;
use Jayanta\Jeeves\Contracts\CacheKeyScopeInterface;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * NQ-007 - the cache key had no place for the caller's identity.
 *
 * `scopedKey()` combined the normalised wording with the asking dataset and
 * nothing else, so two people asking the same words shared a row. That is
 * correct for the common case and this finding does not claim otherwise:
 * rows hold an intent or a SQL recipe, never result rows, and the SQL is
 * re-executed per request against whatever connection the caller resolves.
 * Sharing "total revenue last month" between two users of one dataset is the
 * cache doing its job.
 *
 * It stops being correct the moment a recipe is built under a rule that is not
 * the same for everyone. An adopter who swaps `required_filter` per tenant, or
 * rewrites schema config per request, stores a statement carrying one tenant's
 * predicate under a key that says only "total revenue last month" - and the
 * next tenant asking those words gets it back.
 *
 * There was no way to express that, which is the finding. There is now: a
 * class implementing `CacheKeyScopeInterface`, named in
 * `cache.key_scope`, whose return value is folded into the key.
 *
 * Left OFF by default deliberately. Scoping the cache per user on an install
 * that does not need it turns a shared cache into a per-user one and quietly
 * multiplies the API bill - and a default that costs money is a default people
 * turn off, including the installs that needed it.
 */
class NQ007TheCacheCanBeScopedToTheCallerTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.cache.enabled', true);
    }

    /** The hash a given wording resolves to, read through the real key path. */
    private function hashFor(string $query): string
    {
        $cache = new class extends TwoTierQueryCache
        {
            public function hashOf(string $query): string
            {
                return $this->generateHash($this->scopedKey($this->normalizeQuery($query), null));
            }
        };

        return $cache->hashOf($query);
    }

    #[Test]
    public function two_callers_in_different_scopes_do_not_share_a_row(): void
    {
        config(['jeeves.cache.key_scope' => TenantAlpha::class]);
        $alpha = $this->hashFor('total revenue last month');

        config(['jeeves.cache.key_scope' => TenantBeta::class]);
        $beta = $this->hashFor('total revenue last month');

        $this->assertNotSame(
            $alpha,
            $beta,
            'NQ-007: the same wording resolved to the same cache row for two different '
            . 'tenants, so a recipe built under one tenant\'s rules is served to the other.'
        );
    }

    /**
     * Counterweight, and the reason the test above is not satisfied by a hash
     * that simply varies. Within ONE scope the wording must still land on the
     * same row, or the cache never hits and the finding has been "fixed" by
     * breaking the feature.
     */
    #[Test]
    public function the_same_caller_asking_the_same_words_still_hits_one_row(): void
    {
        config(['jeeves.cache.key_scope' => TenantAlpha::class]);

        $this->assertSame(
            $this->hashFor('total revenue last month'),
            $this->hashFor('total revenue last month'),
            'the same caller and the same wording produced two different rows'
        );
    }

    /**
     * With nothing configured the key is BYTE-IDENTICAL to what it was before
     * this feature existed - not merely stable within a run.
     *
     * Asserted against the format written out by hand, because the first
     * version of this change prefixed an empty segment and silently changed
     * the hash of every row in every install that never asked for the feature.
     * That is a total cache invalidation on upgrade, charged to people who
     * gained nothing from the change. `DatasetRenameUpgradeTest` caught it; the
     * assertion belongs here too, next to the feature that can break it.
     */
    #[Test]
    public function no_scope_configured_leaves_the_key_byte_identical(): void
    {
        config(['jeeves.cache.key_scope' => null]);

        $version = (new \ReflectionClass(TwoTierQueryCache::class))
            ->getConstant('INTENT_CONTRACT_VERSION');

        $cache = new TwoTierQueryCache;
        $normalised = $cache->normalizeQuery('total revenue last month');

        $this->assertSame(
            hash('sha256', $version . '|' . '' . '|' . $normalised),
            $this->hashFor('total revenue last month'),
            'the default cache key changed shape, which invalidates every stored row '
            . 'on upgrade for installs that do not use key_scope'
        );
    }

    /**
     * A misconfigured privacy control must be loud. Silently ignoring a
     * `key_scope` that does not resolve would serve one tenant's recipe to
     * another while the setting sat in the config file looking like it was
     * doing something.
     */
    #[Test]
    public function a_key_scope_that_is_not_a_resolver_is_refused(): void
    {
        config(['jeeves.cache.key_scope' => \stdClass::class]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/cache\.key_scope/');

        $this->hashFor('total revenue last month');
    }
}

class TenantAlpha implements CacheKeyScopeInterface
{
    public function resolve(): ?string
    {
        return 'tenant-alpha';
    }
}

class TenantBeta implements CacheKeyScopeInterface
{
    public function resolve(): ?string
    {
        return 'tenant-beta';
    }
}
