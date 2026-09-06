<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Jayanta\Jeeves\Cache\TwoTierQueryCache;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Contracts\QueryCacheInterface;
use Jayanta\Jeeves\Contracts\ReportsUsage;
use Jayanta\Jeeves\Contracts\SchemaIntrospectorInterface;
use Jayanta\Jeeves\Contracts\ScopesCacheByDataset;
use Jayanta\Jeeves\Contracts\SqlValidatorInterface;
use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Third-party implementations of every published contract must keep loading.
 *
 * WHY THIS FILE EXISTS
 *
 * The package's contracts are an invitation: implement LlmProviderInterface
 * and any model works, implement QueryCacheInterface and any store works.
 * That invitation is only worth something if accepting it does not break on
 * the next upgrade.
 *
 * PHP enforces interface signatures at CLASS LOAD time. Adding a parameter to
 * an interface method -  even an OPTIONAL one -  is a fatal error for every
 * class already implementing the old shape. Not a deprecation, not degraded
 * behaviour: the application does not boot.
 *
 * And the package's own suite is structurally blind to it, because the
 * bundled implementations get updated in the same change that moves the
 * signature. That is exactly what happened: `QueryCacheInterface::find()`
 * grew a `?string $datasetHint` parameter, both bundled caches were updated
 * alongside it, 577 tests stayed green, and every custom cache in the world
 * would have fataled on upgrade. It was caught by reading, not by running.
 *
 * The classes below are deliberately minimal and deliberately NOT updated
 * when a contract changes. They stand in for an adopter's code, frozen at the
 * signature this package published. If one stops loading, an upgrade breaks
 * somebody, and the test says so in the same run that broke it.
 *
 * WHEN THIS TEST FAILS, the fix is usually NOT to update the class here -
 * that just re-hides the break. It is to keep the contract stable and put the
 * new capability in a separate optional interface, the way `ReportsUsage` and
 * `ScopesCacheByDataset` both do.
 */
class PublicContractCanaryTest extends TestCase
{
    public static function contracts(): array
    {
        return [
            'QueryCacheInterface' => [QueryCacheInterface::class, LegacyThirdPartyCache::class],
            'SqlValidatorInterface' => [SqlValidatorInterface::class, LegacyThirdPartyValidator::class],
            'SchemaIntrospectorInterface' => [SchemaIntrospectorInterface::class, LegacyThirdPartyIntrospector::class],
        ];
    }

    /**
     * Loading the class is the assertion. If the signature drifted, PHP raises
     * a fatal before any expectation runs.
     */
    #[DataProvider('contracts')]
    #[Test]
    public function a_third_party_implementation_still_loads(string $contract, string $implementation)
    {
        $this->assertTrue(
            class_exists($implementation),
            "{$implementation} no longer loads -  {$contract} changed shape and every adopter implementing it breaks on upgrade"
        );

        $this->assertInstanceOf($contract, new $implementation);
    }

    /**
     * The provider contract is the package's headline promise -  "any model,
     * hosted or your own" -  so it gets its own case rather than riding along
     * in the data provider.
     */
    #[Test]
    public function a_third_party_provider_still_loads()
    {
        $provider = new LegacyThirdPartyProvider;

        $this->assertInstanceOf(LlmProviderInterface::class, $provider);
        $this->assertSame('legacy-third-party', $provider->getName());
    }

    /**
     * Optional capabilities must stay OPTIONAL. A contract that everyone is
     * forced to implement is not a capability interface, it is a breaking
     * change wearing one.
     */
    #[Test]
    public function capability_interfaces_are_not_required_of_anyone()
    {
        $this->assertNotInstanceOf(ReportsUsage::class, new LegacyThirdPartyProvider);
        $this->assertNotInstanceOf(ScopesCacheByDataset::class, new LegacyThirdPartyCache);
    }

    /**
     * The other half of a capability interface: the bundled implementation
     * must actually BE recognised as capable.
     *
     * This is not redundant with the test above. An `instanceof` check against
     * a class the calling file forgot to import resolves to the current
     * namespace, finds nothing, and returns FALSE -  silently, with no error.
     * The capability then degrades permanently to its fallback and nothing
     * says so. That happened here during this very change, and it presented as
     * an unrelated logic regression.
     */
    /**
     * The PROTECTED extension points, which this canary was not watching.
     *
     * docs/CACHING.md tells adopters to subclass TwoTierQueryCache, so its
     * protected methods are published surface. 2.1.0 widened two of them and
     * fatally broke every existing subclass that had overridden either.
     * Instantiating a subclass frozen at the old signatures is the assertion -
     * a signature mismatch fails at class load, before any test body runs.
     */
    #[Test]
    public function a_subclass_frozen_at_the_previous_protected_signatures_still_loads()
    {
        $this->assertInstanceOf(
            TwoTierQueryCache::class,
            new LegacySubclassedCache,
            'a cache subclass written against the previous release no longer loads, so any application '
                . 'that added a tenant or permission gate this way is dead on upgrade'
        );
    }

    #[Test]
    public function the_bundled_cache_is_recognised_as_scope_capable()
    {
        // Both bundled implementations, not just whichever the environment
        // happens to bind -  NullQueryCache is what a test or a cache-disabled
        // install gets, and it degrading to the bypass path would be silent.
        $this->assertInstanceOf(
            ScopesCacheByDataset::class,
            new TwoTierQueryCache,
            'the real cache is no longer seen as scope-capable, so every lookup silently takes the degraded path'
        );

        $this->assertInstanceOf(
            ScopesCacheByDataset::class,
            $this->app->make(QueryCacheInterface::class),
            'the bound cache is not scope-capable; lookups will bypass it whenever a dataset is known'
        );
    }

    /**
     * And the capability check as the engine performs it, from the engine's
     * own file -  the arrangement that failed. A green assertion here means the
     * import is present and the branch is reachable.
     */
    #[Test]
    public function the_engine_resolves_the_capability_interface_it_checks_against()
    {
        $orchestrator = new \ReflectionClass(QueryOrchestrator::class);
        $source = file_get_contents($orchestrator->getFileName());

        preg_match_all('/instanceof\s+([A-Z][A-Za-z0-9_]*)/', $source, $matches);

        foreach (array_unique($matches[1]) as $shortName) {
            $this->assertTrue(
                $this->resolvesInFile($source, $shortName),
                "QueryOrchestrator does `instanceof {$shortName}` but never imports it -  "
                . 'PHP resolves that to the current namespace, finds nothing, and returns false silently'
            );
        }
    }

    private function resolvesInFile(string $source, string $shortName): bool
    {
        if (preg_match('/^use\s+[^;]*\\\\' . preg_quote($shortName, '/') . ';/m', $source)) {
            return true;
        }

        // Declared in the same namespace, or referenced fully qualified.
        return (bool) preg_match('/\\\\' . preg_quote($shortName, '/') . '\b/', $source);
    }
}

// ---------------------------------------------------------------------------
// Stand-ins for adopter code. Frozen at the published signatures ON PURPOSE.
// Do not "fix" these to match a changed contract -  that hides the break this
// file exists to reveal. See the class docblock.
// ---------------------------------------------------------------------------

class LegacyThirdPartyCache implements QueryCacheInterface
{
    public function find(string $query): ?array
    {
        return null;
    }

    public function store(string $query, array $intent): bool
    {
        return true;
    }

    public function getStatistics(): array
    {
        return [];
    }

    public function clear(?string $dataset = null, int $olderThanDays = 0, int $minHits = 0): int
    {
        return 0;
    }
}

class LegacyThirdPartyValidator implements SqlValidatorInterface
{
    public function validate(string $sql, array $allowedTables, array $options = []): array
    {
        return ['valid' => true, 'sql' => $sql];
    }
}

class LegacyThirdPartyIntrospector implements SchemaIntrospectorInterface
{
    public function listTables(?string $connection = null, array $schemas = []): array
    {
        return [];
    }

    public function getColumns(string $tableName, ?string $connection = null): array
    {
        return [];
    }

    public function getRelationships(string $tableName, ?string $connection = null): array
    {
        return [];
    }

    public function getIndexes(string $tableName, ?string $connection = null): array
    {
        return [];
    }

    public function getDriver(?string $connection = null): string
    {
        return 'legacy';
    }

    public function getSchemas(?string $connection = null): array
    {
        return [];
    }

    public function getDialect(?string $connection = null): string
    {
        return 'legacy';
    }
}

class LegacyThirdPartyProvider implements LlmProviderInterface
{
    public function generateSql(string $prompt): array
    {
        return ['success' => false, 'error' => 'not implemented'];
    }

    public function parseIntent(string $text, array $datasetList): array
    {
        return ['success' => false, 'error' => 'not implemented'];
    }

    public function healthCheck(): array
    {
        return ['status' => 'ok'];
    }

    public function getName(): string
    {
        return 'legacy-third-party';
    }
}

/**
 * A cache subclass frozen at 2.0.0's PROTECTED signatures.
 *
 * The canary above watches the interfaces in src/Contracts/. That was not
 * enough: docs/CACHING.md tells adopters to subclass TwoTierQueryCache, which
 * makes its protected methods a published extension point too -  and 2.1.0
 * widened two of them, generateHash() and findFuzzyMatch(), while adding the
 * asking scope. PHP requires an override to stay signature-compatible, so
 * every subclass in the wild that had overridden either was a fatal error at
 * class load. The application does not boot.
 *
 * Exactly the break this file exists to prevent, one visibility level below
 * where it was looking, made twice in the same commit, by the same author who
 * had already made it once on QueryCacheInterface::find() and written it down
 * as an anti-pattern.
 *
 * Loading this class is the assertion.
 */
class LegacySubclassedCache extends TwoTierQueryCache
{
    protected function generateHash(string $normalizedQuery): string
    {
        return parent::generateHash($normalizedQuery);
    }

    protected function findFuzzyMatch(string $normalizedQuery): ?object
    {
        return parent::findFuzzyMatch($normalizedQuery);
    }
}
