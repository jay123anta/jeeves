<?php

namespace Jayanta\Jeeves\Cache;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Jayanta\Jeeves\Contracts\CacheKeyScopeInterface;
use Jayanta\Jeeves\Contracts\QueryCacheInterface;
use Jayanta\Jeeves\Contracts\ScopesCacheByDataset;
use Jayanta\Jeeves\Support\QuestionForLog;

/**
 * Two-Tier Query Cache
 *
 * Tier 1 (Redis/Memory): Fast exact-match lookups via Laravel Cache (~1ms)
 * Tier 2 (Database): Persistent storage + analytics (~5-20ms)
 *
 * Flow:
 * 1. Check Tier 1 for exact hash match (fastest)
 * 2. If miss, check Tier 2 for exact hash match
 * 3. If miss, the model answers it. There is no third tier.
 * 4. On hit, update hit count
 * 5. Store new entries in both tiers
 */
class TwoTierQueryCache implements QueryCacheInterface, ScopesCacheByDataset
{
    /**
     * Words that carry no meaning for matching, so two phrasings of the same
     * question share a row.
     *
     * 'a' and 'i' are NOT here, and their absence is deliberate. Both are
     * ordinary English filler, and both are also ordinary VALUES -  region A,
     * grade A, zone I, block I. Stripping them made "total revenue for A" and
     * "total revenue" the same key, so asking about one region returned the
     * grand total, from cache, with no provider call and nothing in the answer
     * to show it. The reverse held too.
     *
     * Keeping them costs a cache miss when two phrasings differ only by an
     * article -  "give me a total" against "give me total". That is one API
     * call. Dropping them costs a confidently wrong number, and §0 does not
     * rank those anywhere near each other.
     */
    protected array $fillerWords = [
        'show', 'me', 'the', 'please', 'can', 'you', 'what', 'is', 'are',
        'give', 'tell', 'find', 'get', 'display', 'list', 'want',
        'to', 'see', 'know', 'about', 'for', 'of', 'in', 'an',
        'how', 'many', 'much', 'which', 'could', 'would', 'like',
    ];

    protected array $synonymMappings = [
        'top' => 'best', 'bottom' => 'worst', 'highest' => 'best',
        'lowest' => 'worst', 'maximum' => 'best', 'minimum' => 'worst',
        'most' => 'best', 'least' => 'worst', 'pending' => 'pending',
        'backlog' => 'pending', 'waiting' => 'pending', 'approved' => 'approved',
        'sanctioned' => 'approved', 'delivered' => 'delivered',
        'completed' => 'completed', 'done' => 'completed',
        'rejected' => 'rejected', 'denied' => 'rejected', 'cancelled' => 'rejected',
    ];

    protected int $cacheTtlSeconds;

    protected bool $useTier1;

    protected string $tier1Prefix;

    protected ?string $tier1Store;

    protected string $tableName;

    /**
     * A numeric setting, or the documented default when it is unusable.
     *
     * Deliberately not Support\EnvValue: this reads through config(), so it
     * also covers a published config that hard-codes a bad value, and it
     * cannot be shadowed by a stale published file the way the config's own
     * guards can.
     */
    private function numeric(string $key, int|float $default): int|float
    {
        $raw = config($key, $default);

        return is_numeric($raw) ? $raw + 0 : $default;
    }

    public function __construct()
    {
        // Guarded HERE, not only in the shipped config file.
        //
        // The config's own `is_numeric(env(...))` expressions protect a fresh
        // install, and Laravel's one-level merge means an app that published
        // config/jeeves.php before 2.1.0 never evaluates them -  its
        // older `cache` block replaces the package's wholesale. A blank
        // `JEEVES_CACHE_TTL=` then arrives here as the empty string and
        // `(int) ''` is 0 -  a cache that expires everything the instant it is
        // written, which looks like a cache that is simply never hit.
        $this->cacheTtlSeconds = $this->numeric('jeeves.cache.ttl', 86400);
        $this->tier1Prefix = config('jeeves.cache.tier1_prefix', 'jeeves:');
        $this->tableName = config('jeeves.cache.table_name', 'jeeves_cache');

        // Determine Tier 1 cache store
        $configuredStore = config('jeeves.cache.tier1_store');
        $defaultDriver = config('cache.default');

        if ($configuredStore) {
            $this->useTier1 = true;
            $this->tier1Store = $configuredStore;
        } else {
            $this->useTier1 = in_array($defaultDriver, ['redis', 'memcached', 'array', 'file', 'database']);
            $this->tier1Store = $defaultDriver;
        }
    }

    /**
     * Find a cached result for a query.
     *
     * $datasetHint (NQ-003) is the dataset the ASKING question resolves to. It
     * is folded into the hash by scopedKey(), so two pages scoped to different
     * datasets never share a row for the same wording -  "what is the total?"
     * means one thing on an orders page and another on a visits page, and the
     * question text cannot tell them apart.
     *
     * It used to have a second job: gating the fuzzy tier, which matched on
     * TEXT alone and so could return a row belonging to any dataset. That tier
     * is gone as of 2.3.0 and this parameter now does one thing.
     */
    public function find(string $query): ?array
    {
        return $this->findForDataset($query, null);
    }

    public function findForDataset(string $query, ?string $datasetHint = null): ?array
    {
        // A cache that cannot be read is a cache miss, not a failed question.
        //
        // cache.enabled defaults to true, so an install that has not run
        // `php artisan migrate` yet queries a table that does not exist. The
        // QueryException travelled up to QueryOrchestrator's catch and every
        // single question came back "An error occurred processing your query."
        // -  no mention of a cache, a table, or a migration. That is the first
        // thing a new adopter sees, on the stacks Rule 0 names by name.
        //
        // Degrading to a miss costs one API call and keeps the package working;
        // the log says exactly which command fixes it.
        try {
            return $this->lookup($query, $datasetHint);
        } catch (QueryException $e) {
            Log::warning(
                '[Jeeves:Cache] Cache table unreadable, continuing without it. '
                . "Run `php artisan migrate` to create `{$this->tableName}`.",
                ['error' => $e->getMessage()]
            );

            return null;
        }
    }

    private function lookup(string $query, ?string $datasetHint): ?array
    {
        $normalized = $this->normalizeQuery($query);
        $hash = $this->generateHash($this->scopedKey($normalized, $datasetHint));

        // TIER 1: Fast cache lookup
        if ($this->useTier1) {
            $tier1Result = $this->findInTier1($hash);
            if ($tier1Result) {
                Log::debug('[Jeeves:Cache] Tier 1 hit', ['hash' => substr($hash, 0, 16)]);
                $this->incrementHitCountByHash($hash);

                return $tier1Result;
            }
        }

        // TIER 2: Database exact match
        $cached = $this->findExactMatch($hash);
        if ($cached) {
            $this->incrementHitCount($cached->id);
            $result = $this->formatCacheResult($cached, 'exact');
            if ($this->useTier1) {
                $this->storeInTier1($hash, $result);
            }
            Log::debug('[Jeeves:Cache] Tier 2 exact hit');

            return $result;
        }

        // There is no third tier. A question that does not normalise onto a row
        // already here is a MISS, and the model answers it.
        //
        // Tier 2 used to fall through to a fuzzy match on wording. Removed in
        // 2.3.0, because normalizeQuery() has already folded away everything
        // two questions can innocently differ by - case, filler words,
        // synonyms, duplication, word order. Whatever is still different after
        // that differs in MEANING, and scoring how much of it two questions
        // share was measured reusing "grade a" for "grade b" while refusing the
        // typos the tier existed for. See CHANGELOG.md.
        Log::debug('[Jeeves:Cache] Cache miss', ['query' => QuestionForLog::text($query)]);

        return null;
    }

    /**
     * Store a query result in cache.
     */
    public function store(string $query, array $intent): bool
    {
        try {
            $normalized = $this->normalizeQuery($query);
            // The scope this question was asked under, put there by
            // QueryOrchestrator::rememberIntent(). Part of the row's identity,
            // so a second scope adds a row instead of replacing the first.
            $hash = $this->generateHash($this->scopedKey($normalized, $intent['_asking_scope'] ?? null));

            $existing = DB::table($this->tableName)
                ->where('query_hash', $hash)
                ->first();

            if ($existing) {
                // Every derived column, not just the blob. They are projections
                // OF the intent, and rewriting the intent while leaving them
                // behind makes them describe an answer that is no longer
                // stored. `dataset` is the one that matters: it is what
                // jeeves:cache-cleanup --dataset and the stats command
                // read, so once the same wording was asked about a second
                // dataset the documented remedy for a stale answer quietly
                // deleted nothing.
                DB::table($this->tableName)
                    ->where('id', $existing->id)
                    ->update([
                        'contract_version' => static::INTENT_CONTRACT_VERSION,
                        'intent' => json_encode($intent),
                        'dataset' => $intent['dataset'] ?? null,
                        'metric' => $intent['metric'] ?? null,
                        'group_value' => $intent['group_value'] ?? null,
                        'limit_value' => $intent['limit'] ?? null,
                        'order_direction' => $intent['order'] ?? null,
                        'query_type' => $intent['query_type'] ?? null,
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table($this->tableName)->insert([
                    'query_hash' => $hash,
                    'contract_version' => static::INTENT_CONTRACT_VERSION,
                    'original_query' => $query,
                    'normalized_query' => $normalized,
                    'dataset' => $intent['dataset'] ?? null,
                    'metric' => $intent['metric'] ?? null,
                    'group_value' => $intent['group_value'] ?? null,
                    'intent' => json_encode($intent),
                    'limit_value' => $intent['limit'] ?? null,
                    'order_direction' => $intent['order'] ?? null,
                    'query_type' => $intent['query_type'] ?? null,
                    'hit_count' => 1,
                    'last_hit_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Store in Tier 1
            if ($this->useTier1) {
                $cacheData = [
                    'cached' => true,
                    'cache_match_type' => 'exact',
                    'intent' => $intent,
                    'dataset' => $intent['dataset'] ?? null,
                    'metric' => $intent['metric'] ?? null,
                    'group_value' => $intent['group_value'] ?? null,
                    'limit' => $intent['limit'] ?? null,
                    'order' => $intent['order'] ?? null,
                    'query_type' => $intent['query_type'] ?? null,
                ];
                $this->storeInTier1($hash, $cacheData);
            }

            return true;
        } catch (\Exception $e) {
            Log::error('[Jeeves:Cache] Store failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Get cache statistics.
     */
    public function getStatistics(): array
    {
        try {
            $stats = DB::table($this->tableName)
                ->selectRaw('
                    COUNT(*) as total_entries,
                    COALESCE(SUM(hit_count), 0) as total_hits,
                    COALESCE(AVG(hit_count), 0) as avg_hits_per_entry,
                    COALESCE(MAX(hit_count), 0) as max_hits,
                    COUNT(DISTINCT dataset) as unique_datasets,
                    MIN(created_at) as oldest_entry,
                    MAX(last_hit_at) as most_recent_hit
                ')
                ->first();

            $topDatasets = DB::table($this->tableName)
                ->selectRaw('dataset, COUNT(*) as entries, SUM(hit_count) as total_hits')
                ->whereNotNull('dataset')
                ->groupBy('dataset')
                ->orderByDesc('total_hits')
                ->limit(10)
                ->get();

            $topQueries = DB::table($this->tableName)
                ->select('original_query', 'dataset', 'hit_count', 'last_hit_at')
                ->orderByDesc('hit_count')
                ->limit(10)
                ->get();

            return [
                'enabled' => true,
                'total_entries' => (int) ($stats->total_entries ?? 0),
                'total_hits' => (int) ($stats->total_hits ?? 0),
                'avg_hits_per_entry' => round($stats->avg_hits_per_entry ?? 0, 2),
                'max_hits' => (int) ($stats->max_hits ?? 0),
                'unique_datasets' => (int) ($stats->unique_datasets ?? 0),
                'oldest_entry' => $stats->oldest_entry,
                'most_recent_hit' => $stats->most_recent_hit,
                'tier1_enabled' => $this->useTier1,
                'tier1_store' => $this->tier1Store,
                'top_datasets' => $topDatasets,
                'top_queries' => $topQueries,
            ];
        } catch (\Exception $e) {
            Log::error('[Jeeves:Cache] Statistics failed', ['error' => $e->getMessage()]);

            return ['enabled' => true, 'total_entries' => 0, 'total_hits' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Clear cache entries.
     */
    public function clear(?string $dataset = null, int $olderThanDays = 0, int $minHits = 0): int
    {
        try {
            $query = DB::table($this->tableName);

            if ($dataset) {
                $query->where('dataset', $dataset);
            }

            if ($olderThanDays > 0) {
                $query->where('last_hit_at', '<', now()->subDays($olderThanDays));
            }

            if ($minHits > 0) {
                $query->where('hit_count', '<', $minHits);
            }

            // Tier 1 first, and for EVERY filter, not just --dataset.
            //
            // Tier 1 is checked before Tier 2, so a row deleted from the
            // database keeps answering from memory for up to cache.ttl -
            // 24 hours by default. The forget loop used to live inside
            // `if ($dataset)`, so `--all`, a bare run, `--days` and
            // `--min-hits` all deleted the durable copy and left the fast one
            // serving: the operator saw "Cleared all N cache entries" and the
            // stale answers carried on. docs/CACHING.md says --all empties the
            // cache, and it has to mean both tiers.
            //
            // Collected before the delete, because afterwards there is nothing
            // left to read the hashes from.
            if ($this->useTier1) {
                foreach ((clone $query)->pluck('query_hash') as $hash) {
                    $this->getCacheStore()->forget($this->tier1Prefix . $hash);
                }
            }

            $deleted = $query->delete();

            Log::info('[Jeeves:Cache] Cleared entries', [
                'deleted' => $deleted,
                'dataset' => $dataset,
                'older_than_days' => $olderThanDays,
            ]);

            return $deleted;
        } catch (\Exception $e) {
            Log::error('[Jeeves:Cache] Clear failed', ['error' => $e->getMessage()]);

            return 0;
        }
    }

    // =========================================================================
    // Internal Methods
    // =========================================================================

    /**
     * Normalize query for consistent caching.
     */
    public function normalizeQuery(string $query): string
    {
        $query = strtolower(trim($query));
        $query = preg_replace('/[^\w\s\-]/', '', $query);
        $words = preg_split('/\s+/', $query);

        // Filler words go; single characters stay.
        //
        // Dropping tokens of length 1 deleted the only thing distinguishing
        // some questions from each other. "revenue for region a" and "revenue
        // for region b" normalised identically, hashed identically, and shared
        // one row -  so the second returned the first's number, with the right
        // shape and nothing in the answer text to reveal it. The scope guard
        // cannot help: both questions resolve to the same scope.
        //
        // Single-character values are ordinary in real schemas -  grade A,
        // block B, class C, zone 1 -  and a token that changes the answer
        // belongs in the key.
        $words = array_filter($words, function ($word) {
            return $word !== '' && !in_array($word, $this->fillerWords);
        });

        $words = array_map(function ($word) {
            return $this->synonymMappings[$word] ?? $word;
        }, $words);

        $words = array_unique($words);
        sort($words);

        return implode(' ', $words);
    }

    /**
     * Version of the parsed-intent contract these entries were built against.
     *
     * Bump whenever a field is added to or reinterpreted in the intent, so an
     * upgrade misses the old rows instead of serving them. Intents cached
     * before `group_by` existed answer "revenue by region" with the default
     * grouping; without this they would keep doing so until the TTL expired,
     * and the upgrade would look like it had changed nothing.
     *
     * 3 -  2.0.0 renamed the `scheme` slot to `dataset`. The stored intent is a
     * JSON blob, so the column rename does not touch what is inside it: a row
     * written by 1.0.0 still says "scheme". Served to 2.0.0 the dataset reads
     * null, and Tier 2 has no expiry, so every question asked before the
     * upgrade would have stayed broken until someone ran cache-cleanup.
     *
     * 4 -  2.1.0 added `_asking_scope`: the dataset the ASKING question was
     * scoped to, which is not the same thing as the dataset the answer turned
     * out to be about. A row written before this carries no scope at all, and
     * reading a missing scope as "unscoped" would make every pre-upgrade row
     * eligible for unscoped questions -  which is exactly the cross-dataset
     * hit this release exists to close. Missing it must mean unknown, and
     * unknown must miss.
     *
     * 5 -  2.2.1 stopped caching questions that resolve to a date. Rows
     * written by 2.2.0 and earlier carry those dates, and gating only the
     * WRITE left every one of them eligible on read: an install that already
     * had the stale-period bug kept serving it for ever, and upgrading did
     * not fix the installs that actually had it. Rows carry no expiry, so
     * retiring the contract is the only thing that reaches them.
     */
    protected const INTENT_CONTRACT_VERSION = 5;

    /**
     * The words a row is keyed by, with the asking scope folded in.
     *
     * The scope has to reach the key because `query_hash` is UNIQUE and
     * store() updates the row it finds. Keyed on the text alone there is
     * exactly one row per wording, so two dataset-scoped pages asking the same
     * words took turns overwriting each other -  miss, regenerate, overwrite,
     * miss -  and neither saw a hit again. Refusing to SHARE a row across
     * scopes is the point; refusing to STORE both was an accident of the key.
     *
     * It goes in HERE, in the string, rather than as a parameter on
     * generateHash(). generateHash() is `protected` on a class docs/CACHING.md
     * tells adopters to subclass, so adding even an optional parameter to it
     * is a fatal error at class load for anyone who overrode it -  the same
     * break this release already made once on QueryCacheInterface::find(),
     * recorded as an anti-pattern, and then made twice more here. A subclass
     * that overrides generateHash() still receives a string and still works.
     */
    protected function scopedKey(string $normalizedQuery, ?string $askingScope): string
    {
        $caller = $this->callerScope();

        // Prefixed only when there IS one, so the default key is byte-identical
        // to every version before this. Adding an empty segment would have
        // changed the hash of every row in every install that never asked for
        // this feature - a silent, total cache invalidation on upgrade, which
        // is not a thing a security fix should charge people for. Caught by
        // DatasetRenameUpgradeTest, whose fixture writes the key format out by
        // hand.
        return ($caller === '' ? '' : $caller . '|')
            . ($askingScope ?? '') . '|' . $normalizedQuery;
    }

    /**
     * NQ-007. The caller's own scope, when the adopter has defined one.
     *
     * A cached row holds an intent or a SQL recipe, never result rows, and the
     * SQL re-executes per request - so two people asking the same words sharing
     * a row is normally right, and is the whole value of the cache. It stops
     * being right when the recipe was built under a rule that is not the same
     * for everyone: vary `required_filter` per tenant and the statement carries
     * one tenant's predicate under a key that says only "total revenue last
     * month".
     *
     * There was no way to express that. Now there is, and it is OFF by default
     * on purpose: scoping per caller on an install that does not need it turns
     * one shared row into one row per caller and multiplies the API bill, and a
     * default that costs money is a default people switch off - including on
     * the installs that needed it.
     *
     * A class name rather than a closure because Laravel's `config:cache`
     * cannot serialise closures, and a privacy control that stops working when
     * someone caches their config is worse than none.
     */
    protected function callerScope(): string
    {
        $resolver = config('jeeves.cache.key_scope');

        if ($resolver === null || $resolver === '') {
            return '';
        }

        $instance = is_string($resolver) && class_exists($resolver)
            ? app($resolver)
            : $resolver;

        // Loud, not silent. A key_scope that does not resolve would otherwise
        // sit in the config file looking like it was doing something while one
        // tenant's recipe was served to the next.
        if (!$instance instanceof CacheKeyScopeInterface) {
            throw new \InvalidArgumentException(sprintf(
                'jeeves.cache.key_scope must name a class implementing %s, got %s. '
                . 'Until it does, cached recipes are shared across every caller.',
                CacheKeyScopeInterface::class,
                is_object($resolver) ? get_class($resolver) : var_export($resolver, true)
            ));
        }

        return (string) $instance->resolve();
    }

    /**
     * The row's identity: contract version and the (scoped) words.
     *
     * SIGNATURE FROZEN. Overridden by adopter subclasses; see scopedKey().
     */
    protected function generateHash(string $normalizedQuery): string
    {
        return hash('sha256', static::INTENT_CONTRACT_VERSION . '|' . $normalizedQuery);
    }

    protected function getCacheStore()
    {
        return $this->tier1Store ? Cache::store($this->tier1Store) : Cache::store();
    }

    protected function findInTier1(string $hash): ?array
    {
        try {
            $cached = $this->getCacheStore()->get($this->tier1Prefix . $hash);
            if ($cached && is_array($cached)) {
                $cached['cache_match_type'] = 'tier1';

                return $cached;
            }
        } catch (\Exception $e) {
            Log::warning('[Jeeves:Cache] Tier 1 lookup failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    protected function storeInTier1(string $hash, array $data): void
    {
        try {
            $this->getCacheStore()->put($this->tier1Prefix . $hash, $data, $this->cacheTtlSeconds);
        } catch (\Exception $e) {
            Log::warning('[Jeeves:Cache] Tier 1 store failed', ['error' => $e->getMessage()]);
        }
    }

    protected function findExactMatch(string $hash): ?object
    {
        return DB::table($this->tableName)
            ->where('query_hash', $hash)
            ->where('contract_version', static::INTENT_CONTRACT_VERSION)
            ->first();
    }

    protected function incrementHitCount(int $id): void
    {
        try {
            DB::table($this->tableName)
                ->where('id', $id)
                ->update(['hit_count' => DB::raw('hit_count + 1'), 'last_hit_at' => now()]);
        } catch (\Exception $e) {
            // Non-critical
        }
    }

    protected function incrementHitCountByHash(string $hash): void
    {
        try {
            DB::table($this->tableName)
                ->where('query_hash', $hash)
                ->update(['hit_count' => DB::raw('hit_count + 1'), 'last_hit_at' => now()]);
        } catch (\Exception $e) {
            // Non-critical
        }
    }

    protected function formatCacheResult(object $cached, string $matchType): array
    {
        $intent = json_decode($cached->intent, true);

        return [
            'cached' => true,
            'cache_match_type' => $matchType,
            'cache_id' => $cached->id,
            'hit_count' => $cached->hit_count,
            'original_cached_query' => $cached->original_query,
            'intent' => $intent,
            'dataset' => $intent['dataset'] ?? $cached->dataset,
            'metric' => $intent['metric'] ?? $cached->metric,
            'group_value' => $intent['group_value'] ?? $cached->group_value,
            'limit' => $intent['limit'] ?? $cached->limit_value,
            'order' => $intent['order'] ?? $cached->order_direction,
            'query_type' => $intent['query_type'] ?? $cached->query_type,
        ];
    }
}
