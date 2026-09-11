<?php

namespace Jayanta\Jeeves\Engine;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Jayanta\Jeeves\Cache\TwoTierQueryCache;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Contracts\QueryCacheInterface;
use Jayanta\Jeeves\Contracts\ReportsUsage;
use Jayanta\Jeeves\Contracts\ScopesCacheByDataset;
use Jayanta\Jeeves\Contracts\SemanticMatcherInterface;
use Jayanta\Jeeves\Contracts\SqlValidatorInterface;
use Jayanta\Jeeves\Conversation\QueryState;
use Jayanta\Jeeves\Engine\Semantic\NullSemanticMatcher;
use Jayanta\Jeeves\Events\QuestionAnswered;
use Jayanta\Jeeves\Events\QuestionAsked;
use Jayanta\Jeeves\Events\QuestionFailed;
use Jayanta\Jeeves\Events\UnsafeSqlRejected;
use Jayanta\Jeeves\Exceptions\UnsafeConnectionException;
use Jayanta\Jeeves\Schema\SchemaRegistry;
use Jayanta\Jeeves\Security\ExecutionConnection;
use Jayanta\Jeeves\Security\InputGuard;
use Jayanta\Jeeves\Support\QuestionForLog;
use Jayanta\Jeeves\Support\SqlLiterals;

/**
 * Query Orchestrator - Main Engine
 *
 * Orchestrates the full natural language → SQL → results pipeline.
 *
 * Supports three query modes:
 *
 * 1. INTENT MODE: AI extracts intent → local SqlBuilder constructs SQL.
 *    Safest, but limited to predefined metrics in schema config.
 *
 * 2. SQL GENERATION MODE: AI receives full table structure and generates
 *    SQL directly. More flexible -  works with any query. SQL is validated
 *    before execution.
 *
 * 3. AUTO MODE (default): Tries intent mode first. If intent parsing
 *    fails or needs clarification, falls back to SQL generation mode.
 *    This gives the best of both worlds.
 *
 * This is the primary entry point exposed via the Jeeves facade.
 */
class QueryOrchestrator
{
    /**
     * Shown when the LLM provider answers 429. Kept honest and actionable -
     * a rate limit must never surface as "could not understand the query".
     */
    public const RATE_LIMIT_MESSAGE =
        'The AI service is receiving too many requests right now (rate limit). '
        . 'Please wait a minute and try again.';

    protected LlmProviderInterface $llmProvider;

    protected QueryCacheInterface $cache;

    protected SqlValidatorInterface $validator;

    protected SchemaRegistry $registry;

    protected SqlBuilder $sqlBuilder;

    protected PromptBuilder $promptBuilder;

    protected ResponseFormatter $formatter;

    protected InputGuard $inputGuard;

    protected QueryVerifier $verifier;

    protected ?QueryPlanner $planner;

    protected ?StepSynthesizer $synthesizer;

    protected ?NextStepSuggester $suggester;

    protected ?IntentCoverage $coverage;

    // NQ-001-REDUCE: DatasetSeeder is load-bearing on its own for NQ-003 (see
    // resolveAskingDataset() below) as well as dataset detection in
    // processWithSqlGeneration(); PromptBudget is the bare `prompts.max_chars`
    // size bound the G1-round-3 ruling kept. Both optional and nullable, same
    // pattern as the four above -  a hand-built orchestrator (existing unit
    // tests construct one directly) keeps working exactly as today, with the
    // bound simply never engaging.
    protected ?DatasetSeeder $seeder;

    protected ?PromptBudget $budget;

    // Opt-in semantic dataset matching. Nullable and last, same reason as the
    // rest: an orchestrator built by hand against the previous constructor
    // keeps working, with this stage simply never consulted. The container
    // always binds SOMETHING (NullSemanticMatcher when the feature is off), so
    // null here means "constructed without it", not "disabled".
    protected ?SemanticMatcherInterface $semanticMatcher;

    /**
     * True while the steps of a decomposed question are being answered.
     *
     * Each step re-enters query(), and a step must never be decomposed again:
     * "compare A and B" would plan into "A" and "B", and if "A" were planned in
     * turn the recursion has no floor.
     */
    protected bool $inStepExecution = false;

    /** Memoised reflection: see cacheOverridesFindOnly(). Null until first asked. */
    private ?bool $cacheFindIsOverridden = null;

    /** SQL from the most recent execution, for the QuestionAnswered event. */
    protected ?string $lastSql = null;

    public function __construct(
        LlmProviderInterface $llmProvider,
        QueryCacheInterface $cache,
        SqlValidatorInterface $validator,
        SchemaRegistry $registry,
        SqlBuilder $sqlBuilder,
        PromptBuilder $promptBuilder,
        ResponseFormatter $formatter,
        InputGuard $inputGuard,
        QueryVerifier $verifier,
        ?QueryPlanner $planner = null,
        ?StepSynthesizer $synthesizer = null,
        ?NextStepSuggester $suggester = null,
        ?IntentCoverage $coverage = null,
        ?DatasetSeeder $seeder = null,
        ?PromptBudget $budget = null,
        ?SemanticMatcherInterface $semanticMatcher = null
    ) {
        $this->llmProvider = $llmProvider;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->registry = $registry;
        $this->sqlBuilder = $sqlBuilder;
        $this->promptBuilder = $promptBuilder;
        $this->formatter = $formatter;
        $this->inputGuard = $inputGuard;
        $this->verifier = $verifier;

        // Optional so an orchestrator built by hand -  in a test, or in code
        // written against the previous constructor -  keeps working, simply
        // without chat features.
        $this->planner = $planner;
        $this->synthesizer = $synthesizer;
        $this->suggester = $suggester;
        $this->coverage = $coverage;

        $this->seeder = $seeder;
        $this->budget = $budget;
        $this->semanticMatcher = $semanticMatcher;
    }

    /**
     * Process a natural language query end-to-end.
     *
     * @param  string  $naturalLanguageQuery  The user's question
     * @param  string|null  $datasetHint  Optional dataset key hint
     * @return array Complete response with data, or clarification/error
     */
    public function query(string $naturalLanguageQuery, ?string $datasetHint = null, array $context = []): array
    {
        $startTime = microtime(true);
        $queryMode = config('jeeves.query_mode', 'auto');

        // The question travels with the metadata from here.
        //
        // It was read at the execution point as `$metadata['original_query']`,
        // which the HTTP controller writes AFTER this method returns - so at
        // the moment it was needed it was always absent, and the
        // UnsafeSqlRejected event dispatched with the empty string for the
        // field its own docblock calls "usually the more telling half". A
        // listener watching for a burst of refusals from one user got the SQL
        // and no idea what had been asked to produce it.
        $metadata = [
            'processing_mode' => $this->llmProvider->getName(),
            'query_mode' => $queryMode,
            'original_query' => $naturalLanguageQuery,
        ];

        // Token counts are per-question, so the running total starts here. The
        // provider is a singleton for the request; without this, the second
        // question of a conversation reports the first one's tokens too.
        //
        // Not reset for the steps of a decomposed question -  those are one
        // question to the user and should be billed as one.
        if (!$this->inStepExecution) {
            $this->lastSql = null;

            if ($this->llmProvider instanceof ReportsUsage) {
                $this->llmProvider->resetUsage();
            }
        }

        try {
            // Security: Validate and sanitize input BEFORE it reaches the AI
            $guardResult = $this->inputGuard->validate($naturalLanguageQuery);
            if (!$guardResult['safe']) {
                Log::warning('[Jeeves] Input blocked by guard', [
                    'reason' => $guardResult['blocked_reason'],
                    'query' => QuestionForLog::text($naturalLanguageQuery),
                ]);

                return $this->formatter->formatError(
                    $guardResult['blocked_reason'] ?? 'Query blocked for security reasons.',
                    $metadata,
                    ErrorCode::BLOCKED
                );
            }
            $naturalLanguageQuery = $guardResult['query']; // Use sanitized version

            // Announced after the guard and before any spending, so a listener
            // can attribute cost, enforce a quota the package knows nothing
            // about, or record who asked what. Steps of a decomposed question
            // stay silent -  the user asked one question.
            if (!$this->inStepExecution) {
                Event::dispatch(new QuestionAsked(
                    $naturalLanguageQuery,
                    $datasetHint,
                    $queryMode,
                    $context['session_id'] ?? null
                ));
            }

            // Apply default dataset if configured and no hint provided
            if (!$datasetHint) {
                $defaultDataset = config('jeeves.default_dataset');
                if ($defaultDataset && $this->registry->has($defaultDataset)) {
                    $datasetHint = $defaultDataset;
                    $metadata['default_dataset_applied'] = true;
                }
            }

            // A pinned query: an exact question an install has tied to reviewed
            // SQL. Before planning, so a pinned question is never decomposed,
            // and before the cache, because it is already free. Its SQL goes
            // through validateAndExecute() like every other statement - the
            // whitelist, SELECT-only, the LIMIT rule and required_filter all
            // apply. Pinned means "this is our SQL", not "this is safe".
            if ($pinned = $this->pinnedQueryFor($naturalLanguageQuery)) {
                $metadata['pinned_query'] = true;
                $metadata['query_mode_used'] = 'pinned';

                $result = $this->validateAndExecute([
                    'sql' => $pinned['sql'],
                    'bindings' => [],
                    'dataset' => $pinned['dataset'],
                    'metric' => $pinned['metric'],
                    'query_type' => $pinned['query_type'],
                    'question' => $naturalLanguageQuery,
                ], $pinned['dataset'], $metadata);

                return $this->finishQuestion($naturalLanguageQuery, $result, false, $startTime);
            }

            // A question about two things needs two queries. Gated by a local
            // pattern check, so an ordinary question costs exactly what it
            // costs today and takes exactly the path it takes today.
            if (!$this->inStepExecution
                && $this->planner
                && $this->synthesizer
                && $this->planner->looksMultiStep($naturalLanguageQuery)) {
                $plan = $this->planner->plan($naturalLanguageQuery);

                if ($plan['success']) {
                    return $this->runSteps($naturalLanguageQuery, $plan, $datasetHint, $metadata, $startTime, $context);
                }

                // A plan that could not be built is not a reason to stop -  the
                // question is still answerable the ordinary way, and falling
                // through is the whole point of planning being an enhancement.
                //
                // Unless the provider asked us to stop. On a 429 the fall-through
                // makes a second call to a service that has just reported it is
                // over quota, which extends the very window it complained about
                // and is the one thing docs/TROUBLESHOOTING.md promises the
                // package does not do. The failure was reported and then
                // discarded unread, so nothing here could tell the difference.
                if (($plan['refused_before_sending'] ?? false)) {
                    // Same treatment as the 429 branch below it, which this
                    // was added one line away from and did not copy: strip the
                    // internal flag providerFailure() sets for the retry logic,
                    // and announce, because this exit skips query()'s tail
                    // where both normally happen.
                    $refused = $this->providerFailure($plan, $metadata);
                    unset($refused['_unretriable'], $refused['_rate_limited'], $refused['_fallback_eligible']);

                    // Unconditional: the planner branch this sits in already
                    // required !inStepExecution, so re-checking only looks
                    // like it is guarding something.
                    $this->announceOutcome($naturalLanguageQuery, $refused, false, $startTime);

                    return $refused;
                }

                if (($plan['status'] ?? null) === 429) {
                    // No `_rate_limited` flag. It exists to stop the refined
                    // retry, and this returns from above that block, so here it
                    // would only leak an internal name into the JSON body -
                    // which query()'s tail strips and this exit skips.
                    $limited = $this->formatter->formatError(
                        self::RATE_LIMIT_MESSAGE,
                        $metadata,
                        ErrorCode::RATE_LIMITED
                    );

                    // Unconditional: the planner branch this sits in already
                    // required !inStepExecution, so re-checking only looks
                    // like it is guarding something.
                    $this->announceOutcome($naturalLanguageQuery, $limited, false, $startTime);

                    return $limited;
                }

                if (!empty($plan['error'])) {
                    Log::info('[Jeeves] Planning failed; answering as a single query', [
                        'error' => $plan['error'],
                    ]);
                }
            }

            // Check cache first (works for all modes).
            //
            // The dataset THIS question resolves to, at zero API cost, so the
            // cache knows what a candidate row would have to match (NQ-003) -
            // see resolveAskingDataset() and TwoTierQueryCache::findForDataset().
            $cacheHit = false;
            $askingDataset = $this->resolveAskingDataset($naturalLanguageQuery, $datasetHint, $context);

            // Not looked up at all mid-conversation. Both readers refuse a row
            // when conversation state is present, so the lookup was pure cost
            // -  and worse than free: TwoTierQueryCache counts a hit the moment
            // it returns a row, so every follow-up incremented hit_count on a
            // row it was never going to use, and jeeves:cache-stats
            // reported reuse that had not happened.
            $cachedResult = empty($context['state'])
                ? $this->findInCache($naturalLanguageQuery, $askingDataset)
                : null;

            // An entry belonging to a different dataset is not a hit, and it is
            // discarded HERE -  at the one place a cached result enters -  rather
            // than downstream where the mismatch is finally acted on.
            //
            // Discovering it downstream was too late. `cache_hit` is set the
            // moment the cache returns anything, so a question that went on to
            // call the provider still reported itself as cached; and
            // verification.skip_on_cache_hit, which defaults to true, reads that
            // flag and skipped QueryVerifier on the SQL that had just been
            // generated. The one path where the self-check matters most was the
            // one path that turned it off.
            //
            // Null counts as a mismatch on EITHER side. The first version of
            // this guard read `$askingDataset !== null && ...`, which skipped
            // the check entirely whenever the asking dataset could not be
            // placed -  and resolveAskingDataset() returns null routinely, any
            // time more than one dataset is registered and neither a hint, the
            // conversation state, nor a keyword names one. So a row cached from
            // a dataset-scoped page was served verbatim to the same wording
            // asked from the general one. That is the widget's ordinary shape,
            // and it is the exact failure this guard was added to close.
            //
            // Both null is the one case that still serves: neither ask carried
            // dataset context, so there is nothing to disagree about.
            // Compared against the scope the CACHED question was asked under,
            // not against the dataset its answer turned out to be about. Those
            // are different values, and the first version of this guard used
            // the second one -  which is the only value the row had.
            //
            // The consequence was that a question naming no dataset never
            // matched its own row: the asking side resolved to null, the row
            // carried whatever dataset the model had chosen, and they could
            // never agree. On a multi-dataset install that is most questions,
            // so the cache was off for precisely the ones people repeat.
            // Comparing scope to scope keeps the cross-dataset hit closed -
            // an explicitly scoped row is still refused to an unscoped ask -
            // without charging for it on every ordinary repeat.
            // FAILS CLOSED. A missing scope is UNKNOWN, not "unscoped".
            //
            // `?? null` read both the same way, and the difference matters
            // because `_asking_scope` rides inside an opaque blob that a
            // third-party QueryCacheInterface was never asked to round-trip.
            // An implementation that drops keys it does not recognise -  which
            // the 2.0.0 contract permitted -  handed back rows with no scope,
            // every one of which then looked eligible for any unscoped
            // question. The cross-dataset hit this release exists to close,
            // reopened for exactly the adopters who wrote their own cache.
            //
            // The same test also catches a blob that is not an array at all.
            // Those used to reach normalizeIntent(array $intent) and throw; the
            // \Throwable catch added earlier turned the crash into an error
            // response, which was still wrong -  Tier 2 rows never expire, so
            // that question stayed permanently unanswerable with no remedy in
            // the message. An unreadable row is a MISS. The question is
            // answered, the row is rewritten on the way past, and it costs one
            // API call.
            $blob = $cachedResult['intent'] ?? null;
            $scopeIsKnown = is_array($blob) && array_key_exists('_asking_scope', $blob);
            $cachedScope = $scopeIsKnown ? $blob['_asking_scope'] : null;

            if ($cachedResult && (!$scopeIsKnown || $cachedScope !== $askingDataset)) {
                Log::debug('[Jeeves:Cache] Discarded: unusable or asked under another scope', [
                    'asking' => $askingDataset,
                    'cached' => $scopeIsKnown ? $cachedScope : '(no scope recorded)',
                    'answers' => $cachedResult['dataset'] ?? null,
                ]);
                $cachedResult = null;
            }

            // Deliberately NOT flagging a hit here.
            //
            // Finding a row is not using one. Every reader below has its own
            // reasons to refuse the row it was handed -  mid-conversation,
            // wrong dataset, wrong shape -  and a flag set at the entry cannot
            // know about any of them. It used to be set here, and so a
            // conversation turn that both readers declined still reported
            // itself as cached: the provider generated the SQL, the audit log
            // recorded a cached answer, the QuestionAnswered event carried the
            // wrong figure, and verification.skip_on_cache_hit read the flag
            // and skipped QueryVerifier on brand-new SQL.
            //
            // markCacheHit() is now the only writer, and every caller of it is
            // a line that has just committed to using the row.

            // Route to appropriate mode
            if ($queryMode === 'sql_generation') {
                $result = $this->processWithSqlGeneration($naturalLanguageQuery, $datasetHint, $cachedResult, $metadata, $context);
            } elseif ($queryMode === 'intent') {
                $result = $this->processWithIntent($naturalLanguageQuery, $datasetHint, $cachedResult, $metadata, $context);
            } else {
                // AUTO mode: intent first -  unless the question plainly needs
                // SQL the intent contract cannot express.
                //
                // Falling back on ERROR is not enough, and that was the whole
                // problem: intent mode did not fail on these questions, it
                // succeeded at a narrower one. "Customers with more than 10
                // orders" quietly became "customers", ranked. Deciding up front
                // costs nothing -  both modes are a single API call.
                // A cached SQL recipe settles it before the coverage check
                // does. Only processWithSqlGeneration can replay one; intent
                // mode declines it, pays for a parseIntent, and -  because both
                // readers store under the same key -  then OVERWRITES the recipe
                // with an intent row. The next repeat finds an intent row,
                // clarifies, falls back, regenerates, and overwrites the intent
                // row with a recipe again. The cache oscillated between one and
                // three provider calls per repeat of the same question, forever,
                // where zero is available.
                //
                // The row's own shape says which reader wrote it, so use it.
                $beyond = isset($cachedResult['intent']['_sql_result'])
                    ? 'a cached SQL result'
                    : ($this->coverage ? $this->coverage->exceeds($naturalLanguageQuery) : null);

                if ($beyond) {
                    Log::info('[Jeeves] Question needs SQL beyond the intent contract', [
                        'component' => $beyond,
                    ]);
                    $metadata['query_mode'] = 'auto→sql_generation';
                    $metadata['escalated_for'] = $beyond;
                    $result = $this->processWithSqlGeneration($naturalLanguageQuery, $datasetHint, $cachedResult, $metadata, $context);
                } else {
                    $result = $this->processWithIntent($naturalLanguageQuery, $datasetHint, $cachedResult, $metadata, $context);

                    // Fall back when intent mode could not answer -  whether it
                    // failed outright, or asked a question it should not have
                    // needed to ask. A clarification is only offered here when
                    // the dataset chosen cannot express the breakdown but a
                    // related table can, so trying is strictly better than
                    // handing the user a menu they cannot usefully answer.
                    //
                    // A name that matched nothing and may belong to a related
                    // table is an answer intent mode could NOT give, even though
                    // it comes back as "success" - a count of zero, or the
                    // unfiltered total with a note. See
                    // retryWithoutUnmatchedNameFilter().
                    $couldNotAnswer = in_array($result['status'] ?? '', ['error', 'clarification_needed'], true)
                        || ($result['_name_unmatched'] ?? false);

                    if ($couldNotAnswer && ($result['_fallback_eligible'] ?? false)) {
                        Log::info('[Jeeves] Auto mode: falling back to sql_generation', [
                            'after' => $result['status'] ?? '?',
                        ]);
                        $metadata['query_mode'] = 'auto→sql_generation';

                        if ($result['_name_unmatched'] ?? false) {
                            $metadata['escalated_for'] = 'a name not found in the table it was asked of';
                        }
                        // Intent mode may have used the cached row and then
                        // failed downstream. Whatever it marked describes an
                        // answer that is being thrown away.
                        $metadata['cache_hit'] = false;
                        $generated = $this->processWithSqlGeneration($naturalLanguageQuery, $datasetHint, $cachedResult, $metadata, $context);

                        // Keep the clarification if generation did no better -
                        // a usable question beats a bare failure.
                        if (($generated['status'] ?? '') === 'success'
                            || ($result['status'] ?? '') === 'error') {
                            $result = $generated;
                        }
                    }
                }
            }

            // Retry with refined prompt on failure (if enabled).
            // Never retry a rate-limited request -  it would only add load.
            // Never retry a request the provider refused before sending
            // anything (NQ-002): Strategy 1 below retries with a SMALLER,
            // single-dataset prompt, which is exactly the wrong move for a
            // refusal caused by prompt SIZE -  the smaller prompt can clear
            // the same guard and be answered for real, but as a narrower
            // question than the one that was refused. rewordingCannotHelp()
            // already recognises this class of result; it just used to be
            // consulted too late, after Strategy 1 had already fired.
            if (($result['status'] ?? '') === 'error'
                && config('jeeves.errors.retry_on_failure', true)
                && !($result['_retried'] ?? false)
                && !($result['_rate_limited'] ?? false)
                && !($result['_unretriable'] ?? false)
            ) {
                // The failure so far is passed in, so the retry can decline to
                // overwrite a provider fault with a claim about the question.
                $metadata['cache_hit'] = false;
                $result = $this->retryWithRefinedPrompt($naturalLanguageQuery, $datasetHint, $metadata, $result);
            }

            // Read AFTER the readers have run, so it reflects what was used
            // rather than what was available. This is the value the audit log,
            // the QuestionAnswered event and verification.skip_on_cache_hit
            // all consume.
            return $this->finishQuestion($naturalLanguageQuery, $result, (bool) ($metadata['cache_hit'] ?? false), $startTime);

            // \Throwable, not \Exception. A TypeError extends \Error and so slipped
            // straight past this, taking the whole error envelope with it: the
            // caller got a framework 500 and a stack trace instead of a
            // Jeeves error response, and the HTTP layer's error_code,
            // retryable and Retry-After never happened.
            //
            // Reachable from ordinary data rather than from a bug in the caller -
            // a cache row whose `intent` column is present but not an array
            // reaches normalizeIntent(array $intent) and throws. Tier 2 rows have
            // no expiry, so once one exists that question is a hard 500 forever.
        } catch (\Throwable $e) {
            Log::error('[Jeeves] Orchestrator error', [
                'error' => $e->getMessage(),
                'type' => get_class($e),
            ]);

            return $this->formatter->formatError('An error occurred processing your query.', $metadata, ErrorCode::INTERNAL);
        }
    }

    // =========================================================================
    // INTENT MODE
    // =========================================================================

    /**
     * Process query using intent parsing → local SQL builder.
     *
     * Flow: AI extracts (dataset, metric, order, limit, group_value) → SqlBuilder constructs SQL
     */
    protected function processWithIntent(string $query, ?string $datasetHint, ?array $cached, array &$metadata, array $context = []): array
    {
        $metadata['query_mode_used'] = 'intent';

        // A follow-up is meaningless on its own: "only in West" means one thing
        // after a revenue question and another after an order count. So a turn
        // carrying conversation state is never answered from cache and never
        // written to it -  the words are the same and the question is not.
        $inConversation = !empty($context['state']);

        $intent = null;
        // A SQL-generation recipe is not an intent, whatever the column it
        // shares. processWithSqlGeneration() caches those rows with the
        // finished SQL in `_sql_result`, and it replays that SQL verbatim.
        // Handed the same row, this reader instead passes it to normalizeIntent()
        // and SqlBuilder -  and the intent contract has no slot for a WHERE
        // predicate, so a cached "revenue for pending orders" came back as the
        // revenue for every row: right shape, wrong number, status success,
        // no provider call, and no TTL on tier-2 rows to make it stop.
        //
        // The row exists BECAUSE the question needed something this contract
        // cannot express. Rebuilding it through this contract must lose exactly
        // that. Leaving $intent null makes it an honest miss and costs one call.
        //
        // Eligibility itself is not re-checked here. query() already discarded
        // any row asked under a different scope, and the check that used to sit
        // on this line compared the asking scope against the row's ANSWER
        // dataset -  the same conflation, one level down, rejecting a row that
        // had just been cleared on the correct grounds. NQ-003's original
        // version of it was worse still: it reconciled the mismatch by
        // overwriting $intent['dataset'], so an identical repeat could be
        // answered from a table the model never chose. One authoritative guard,
        // at the entry.
        if ($cached && !$inConversation && !isset($cached['intent']['_sql_result'])) {
            $intent = $this->normalizeIntent($cached['intent']);
            $this->markCacheHit($metadata, $cached);
        }

        if ($intent === null) {
            $datasetList = $this->registry->getDatasetListForLlm();
            $intent = $this->normalizeIntent(
                $this->llmProvider->parseIntent($this->withState($query, $context), $datasetList)
            );

            // Never overwrite a SQL recipe with an intent. Both readers store
            // under the same key, and the recipe is the row that can actually
            // answer this question -  it exists because the intent contract
            // could not. Clobbering it throws away the better answer and
            // guarantees the next repeat pays to rebuild it.
            $wouldClobberARecipe = isset($cached['intent']['_sql_result']);

            if (!$inConversation
                && !$wouldClobberARecipe
                && ($intent['success'] ?? false)
                && !($intent['needs_clarification'] ?? false)
                && $this->intentIsCacheable($intent)
            ) {
                $this->rememberIntent(
                    $query,
                    $intent,
                    $this->resolveAskingDataset($query, $datasetHint, $context)
                );
            }
        }

        // A total is one number. Applied after the cache too, since a stored
        // intent can carry the same invented breakdown.
        $intent = $this->dropUnaskedBreakdown($intent, $query);

        // Apply dataset hint
        if ($datasetHint && empty($intent['dataset'])) {
            if ($this->registry->has($datasetHint)) {
                $intent['dataset'] = $datasetHint;
            }
        }

        // Fold the conversation's state into what actually runs.
        //
        // The state was previously used only as prompt context: the SQL was
        // built from THIS turn's intent alone, so "and what about Electronics?"
        // executed with the Electronics filter and without the West one, while
        // the state -  and the line shown to the user -  claimed both. A summary
        // that promises a narrowing the query does not apply is worse than no
        // summary at all.
        //
        // The same QueryState::merge the conversation uses, so what runs and
        // what is displayed cannot disagree.
        if (!empty($context['state'])) {
            $intent = QueryState::fromArray(['slots' => $context['state']])
                ->merge($intent, 0, $query)
                ->toIntent() + $intent;

            $intent = $this->narrowingRatherThanDetail($intent);
        }

        // Handle parse failure.
        //
        // success:false from a provider never means "the question was unclear"
        // -  an unclear question comes back as a successful call carrying a
        // clarification. It means the call itself failed: no route to the host,
        // a rejected key, a reply that was not JSON. Reporting that as
        // not_understood sends the user off rewording a perfectly good question
        // while the real fault goes unmentioned.
        if (!($intent['success'] ?? true)) {
            // Rate limiting especially: falling back to sql_generation or
            // retrying would fire MORE calls at a provider already refusing
            // them.
            if (($intent['status'] ?? null) === 429) {
                return array_merge(
                    $this->formatter->formatError(self::RATE_LIMIT_MESSAGE, $metadata, ErrorCode::RATE_LIMITED),
                    ['_rate_limited' => true]
                );
            }

            // A refusal that never reached the wire is NOT fallback-eligible.
            //
            // Falling back means trying SQL generation, and on a schema with
            // no linked datasets that builds the single-dataset prompt -  which
            // is SMALLER, clears the very guard that just refused, and gets
            // answered. The user asked a question the context could not hold
            // and receives a confident number computed from one table.
            //
            // That is the same conversion NQ-002 stopped in retryWithRefinedPrompt,
            // reappearing on the auto-mode fallback because the flag was read
            // in providerFailure() and this path builds its error inline.
            // Marked unretriable too, so handle() does not then retry it.
            if ($intent['refused_before_sending'] ?? false) {
                return array_merge(
                    $this->formatter->formatError($intent['error'] ?? 'The AI service could not be reached.', $metadata, ErrorCode::PROVIDER_ERROR),
                    ['_unretriable' => true]
                );
            }

            // Still fallback-eligible: in auto mode a garbled intent response
            // is worth one attempt at SQL generation, which asks for something
            // simpler. If that fails too, its error is the one reported.
            return array_merge(
                $this->formatter->formatError($intent['error'] ?? 'The AI service could not be reached.', $metadata, ErrorCode::PROVIDER_ERROR),
                ['_fallback_eligible' => true]
            );
        }

        // Handle clarification
        $availableDatasets = $this->registry->getAvailableDatasets();
        $hasGroupValue = !empty($intent['group_value']);

        // With exactly one dataset there is nothing to choose between, so a
        // model that says "which dataset?" is really saying "I could not tell
        // what you meant" -  about the metric, usually.
        if (empty($intent['dataset']) && count($availableDatasets) === 1) {
            $intent['dataset'] = $availableDatasets[0]['key'];
        }

        $hasDataset = !empty($intent['dataset']);

        // Asking which dataset is only meaningful when the dataset is genuinely
        // unresolved AND there is more than one to pick from. Asking it once
        // the dataset is known produced a card whose only button re-sent the
        // same question and redrew the same card -  indistinguishable, from the
        // outside, from the widget being broken.
        if (!$hasDataset && count($availableDatasets) > 1) {
            return $this->formatter->formatClarification($intent, $availableDatasets);
        }

        if ($hasDataset && $hasGroupValue && empty($intent['metric'])) {
            // One record's detail -  SqlBuilder handles this
        } elseif (($intent['needs_clarification'] ?? false) || !$hasDataset) {
            // Why the model asked, before it is rewritten below. The prompt
            // tells it to answer 'ambiguous' when the requested breakdown is
            // not available on the dataset it chose -  which, across related
            // tables, usually means the answer needs a JOIN rather than a
            // question. "Revenue by region" is line_total in one table and
            // region in another: perfectly answerable, just not by a builder
            // that works within a single dataset.
            $askedBecause = $intent['clarification_type'] ?? null;

            // The dataset is settled; whatever is still unclear is a metric.
            $intent['clarification_type'] = 'metric';

            $clarification = $this->formatter->formatClarification(
                $intent,
                $availableDatasets,
                $hasDataset ? $this->registry->getDatasetMetrics($intent['dataset']) : []
            );

            // Asking the user to choose a metric they already named is a dead
            // end. Let auto mode try SQL generation, which can join across the
            // related tables and answer it outright.
            //
            // 'ambiguous' means the breakdown does not exist on the chosen
            // dataset. But a question that states its own measure -  "how many
            // continents are there", "average horsepower" -  is not ambiguous
            // whatever the model labelled it, and on a schema of mostly
            // dimension tables it labelled almost everything 'metric' and
            // asked. A whole Spider database scored zero that way.
            //
            // Retrying is safe: the clarification is kept unless generation
            // actually succeeds, so a genuinely open question ("which is the
            // best?") still gets asked rather than guessed at.
            $selfEvident = $this->statesItsOwnMeasure($query);

            if (($askedBecause === 'ambiguous' || $selfEvident) && $this->registry->hasLinkedSchemas()) {
                $clarification['_fallback_eligible'] = true;
            }

            return $clarification;
        }

        // Build SQL locally
        $queryResult = $this->sqlBuilder->buildQuery($intent);
        if (!$queryResult['success']) {
            return array_merge(
                $this->formatter->formatError($queryResult['error'] ?? 'Failed to build query', $metadata, ErrorCode::CANNOT_ANSWER),
                ['_fallback_eligible' => true]
            );
        }

        // Validate and execute
        $response = $this->validateAndExecute($queryResult, $intent['dataset'], $metadata);

        return $this->retryWithoutUnmatchedNameFilter($response, $intent, $metadata);
    }

    /**
     * Answer each step of a decomposed question, then combine them.
     *
     * Every step goes back through query() unchanged, so each one is intent
     * parsed, validated against the table whitelist and executed exactly like
     * a question typed on its own. Decomposition adds a planning call; it does
     * not add a second way into the database.
     *
     * A step that fails does not fail the whole answer -  three of four numbers
     * is more useful than none, provided the response says so, which it does.
     */
    protected function runSteps(
        string $originalQuery,
        array $plan,
        ?string $datasetHint,
        array $metadata,
        float $startTime,
        array $context = []
    ): array {
        $steps = [];
        $rateLimited = false;

        $this->inStepExecution = true;

        try {
            foreach ($plan['steps'] as $i => $question) {
                // $context, not nothing. Re-entering without it dropped the
                // conversation at the door: the steps could not see the metric,
                // period or filters established in earlier turns, and -  because
                // the cache guard is keyed on !empty($context['state']) -  each
                // step looked like a standalone question and WROTE itself to
                // the shared, session-less cache. Another session asking those
                // words then read this conversation's rows back, which is
                // exactly what docs/CONVERSATIONS.md promises cannot happen.
                $result = $this->query($question, $datasetHint, $context);
                $succeeded = ($result['status'] ?? '') === 'success';

                $steps[] = [
                    'n' => $i + 1,
                    'question' => $question,
                    'status' => $succeeded ? 'success' : 'error',
                    'answer' => $result['answer'] ?? ($result['error'] ?? null),
                    'rows' => $result['rows'] ?? [],
                    'metric' => $result['parsed_query']['metric'] ?? null,
                    'group_by' => $result['parsed_query']['group_by'] ?? null,
                    // The period this step actually used. "Last year" is read
                    // as calendar 2025 by some models and as a trailing twelve
                    // months by others; the two differ by millions and the
                    // number alone shows nothing. A step that states its own
                    // period lets the reader see which was meant.
                    'period' => $result['parsed_query']['period'] ?? null,
                    'insights' => $result['insights'] ?? null,
                    'next_steps' => $result['next_steps'] ?? [],
                ];

                // A rate limit ends the run. Each step is a full query() and
                // guards its own 429 correctly, but the loop then carried on
                // and asked again -  so one rate limit authorised N more calls
                // against a provider that had just said stop, which is the
                // one response that makes a quota problem worse.
                //
                // error_code survives query()'s tail (only the underscore
                // flags are stripped), so the loop can see it; it simply never
                // looked.
                if (($result['error_code'] ?? null) === ErrorCode::RATE_LIMITED) {
                    $rateLimited = true;
                    break;
                }
            }
        } finally {
            // Restored even if a step throws, or the next ordinary question
            // silently loses the ability to be decomposed.
            $this->inStepExecution = false;
        }

        $synthesis = $this->synthesizer->synthesize($originalQuery, $steps, $plan['comparison'] ?? false);
        $successful = array_values(array_filter($steps, fn ($s) => $s['status'] === 'success'));

        // A rate limit is reported as a rate limit, decomposed question or not.
        //
        // This envelope carried no error_code at all, and the controller reads
        // `$result['error_code'] ?? null` -  ErrorCode::httpStatus(null) is 500
        // and isRetryable(null) is false. So the identical fault that returns
        // 429 + Retry-After + retryable:true for a one-part question returned
        // 500 + retryable:false for a two-part one: an explicit instruction to
        // every SDK and queue worker NOT to back off, on the one fault where
        // backing off is the entire remedy. docs/API.md tells clients to branch
        // on error_code, and there was nothing to branch on.
        //
        // The bundled widget makes it worse still: it renders `data.error`,
        // which this envelope does not set either, so the user saw "The query
        // could not be processed" and no mention of a rate limit anywhere.
        if ($rateLimited) {
            // Steps kept so the caller can see how far it got. No
            // `_rate_limited` flag: this result is returned straight out of
            // query() without passing the tail that strips internal names.
            $limited = array_merge(
                $this->formatter->formatError(self::RATE_LIMIT_MESSAGE, $metadata, ErrorCode::RATE_LIMITED),
                ['steps' => $steps]
            );

            $this->announceOutcome($originalQuery, $limited, false, $startTime);

            return $limited;
        }

        $response = [
            'status' => empty($successful) ? 'error' : 'success',
            'type' => 'multi_step',
            'answer' => $synthesis['answer'],
            'steps' => $steps,
            'comparison' => $synthesis['comparison'],
            // Kept for clients that render a single result table: the last
            // step's rows are the ones a follow-up would build on.
            'rows' => empty($successful) ? [] : end($successful)['rows'],
            'visualization' => 'steps',
            'parsed_query' => [
                'dataset' => $datasetHint,
                'multi_step' => true,
                'step_count' => count($steps),
            ],
            'metadata' => array_merge($metadata, [
                'multi_step' => true,
                'steps_planned' => count($plan['steps']),
                'steps_succeeded' => count($successful),
                'processing_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
            ]),
        ];

        // The conversation continues from where it ended, so the follow-ups
        // offered are the last successful step's.
        if (!empty($successful)) {
            $last = end($successful);

            if (!empty($last['next_steps'])) {
                $response['next_steps'] = $last['next_steps'];
            }
        }

        if (config('jeeves.response.include_speech_text', true)) {
            $response['speech_text'] = $synthesis['answer'];
        }

        // The fields query()'s tail adds to every other answer, which this
        // path returns straight past. A decomposed answer was arriving with no
        // `provider`, no `cache_hit` and no `usage` -  and docs/API.md documents
        // usage as accumulating specifically across "the steps of a decomposed
        // question", which is the one shape where it was absent.
        //
        // cache_hit is false by construction: each step consults the cache on
        // its own, and this envelope is assembled fresh every time.
        $response['metadata'] = array_merge($response['metadata'], [
            'cache_hit' => false,
            'provider' => $this->llmProvider->getName(),
        ]);

        if ($usage = $this->usageForThisQuestion()) {
            $response['metadata']['usage'] = $usage;
        }

        // And the event. A listener counting cost or logging answers saw every
        // single-part question and no decomposed one.
        $this->announceOutcome($originalQuery, $response, false, $startTime);

        return $response;
    }

    /**
     * Put the conversation's state in front of the utterance.
     *
     * A structured summary, not a transcript. The model is asked to resolve ONE
     * instruction against a handful of named slots, rather than to re-read four
     * turns of dialogue and work out for itself what still applies -  which is
     * both more to get wrong and more that changes when any earlier turn is
     * worded differently.
     */
    protected function withState(string $query, array $context): string
    {
        if (empty($context['state'])) {
            return $query;
        }

        $slots = [];

        foreach ($context['state'] as $slot => $value) {
            if ($value !== null && $value !== '' && $slot !== 'query_type') {
                $slots[] = $slot . '=' . (is_scalar($value) ? $value : json_encode($value));
            }
        }

        if (!$slots) {
            return $query;
        }

        // The narrowing rule is spelled out because leaving it implicit lost
        // it. "Only in Springfield" after "total amount by city" came back from
        // one provider with no filter at all -  every city returned, the
        // instruction silently discarded -  and from two others as a request for
        // one record's detail rows. Naming the slot removes the guess.
        return "CURRENT QUERY STATE (carry these forward unless the instruction changes them):\n"
            . '  ' . implode('; ', $slots) . "\n"
            . "NEW INSTRUCTION: \"{$query}\"\n"
            . 'A narrowing -  "only in X", "just for X", "in X" -  goes in filters as '
            . '{"column":"<the column X belongs to>","value":"X"}, and the existing '
            . 'group_by STAYS. Do not put it in group_value: that means one named record '
            . "and returns its detail rows instead of the narrowed answer.\n"
            . 'Return the FULL intent after applying the instruction to that state.';
    }

    /**
     * Does the question already say what to measure?
     *
     * "How many continents are there" names its own measure -  every dataset
     * can be counted -  so a request to choose a metric is not a real question,
     * it is a dead end. "Which is the best?" names nothing and deserves to be
     * asked about.
     */
    protected function statesItsOwnMeasure(string $query): bool
    {
        if (preg_match(
            '/\b(?:how\s+many|how\s+much|number\s+of|count\s+of|total|sum|average|mean|median|minimum|maximum|min|max|highest|lowest|largest|smallest)\b/i',
            $query
        )) {
            return true;
        }

        // "Top 3 genres by revenue", "best artists by sales": a ranking BY a
        // named measure says what to measure. Found on a real database, where
        // the chosen dataset held no money and the question came back as "What
        // metric would you like?" - with the measure two joins away, in a table
        // SQL generation could reach. "Which is the best?" names nothing and
        // is still asked.
        return (bool) preg_match(
            '/\b(?:top|bottom|best|worst|most|least|rank(?:ed|ing)?)\b[^.?!]*\bby\s+[a-z]/i',
            $query
        );
    }

    /**
     * "How many invoices are pending" is one number, not a league table.
     *
     * Both Gemini and DeepSeek answered that question with
     * query_type=ranking and group_by=client, producing "Rekha Stores: 1
     * records" where the answer is "1". Two providers agreeing means the
     * prompt is not carrying it, so this is decided locally instead -  the same
     * reasoning as every other guard here: a rule that must hold is cheaper to
     * enforce than to ask for.
     *
     * The count was right, which is what makes it worth fixing. A wrong number
     * gets questioned; a right number wearing the wrong shape gets read as
     * "only Rekha Stores has pending invoices", which is a different claim and
     * one nobody checked.
     *
     * Only fires when the sentence asks for a total AND names no breakdown. A
     * breakdown that was asked for is never touched, and neither is a question
     * that did not ask for a total -  "top clients by amount" has no total
     * wording and keeps its grouping.
     *
     * @param  array<string, mixed>  $intent
     * @return array<string, mixed>
     */
    protected function dropUnaskedBreakdown(array $intent, string $query): array
    {
        // Deliberately NOT skipped when group_by is empty. The breakdown comes
        // from two places: the model can name one, and SqlBuilder falls back to
        // the schema's default group column when it does not. An early return
        // on an empty group_by fixed DeepSeek, which names one, and left Gemini
        // exactly as it was, because Gemini names none and the default supplies
        // it downstream. Saying "aggregation" is what stops that fallback.

        // "by region", "per customer", "for each status", "breakdown by" -  any
        // of these and the grouping was requested.
        if (preg_match('/\b(?:by|per|each|breakdown|split|grouped)\b/i', $query)) {
            return $intent;
        }

        // Asks for a single figure over the whole set.
        $wantsOneNumber = preg_match(
            '/\b(?:how\s+many|how\s+much|total|sum|count|average|mean|number\s+of)\b/i',
            $query
        );

        if (!$wantsOneNumber) {
            return $intent;
        }

        if (($intent['query_type'] ?? null) === 'aggregation' && empty($intent['group_by'])) {
            return $intent; // already right; nothing to say
        }

        Log::info('[Jeeves] Answering as a total, not a breakdown', [
            'group_by' => $intent['group_by'] ?? '(schema default)',
            'query' => QuestionForLog::text($query),
        ]);

        $intent['group_by'] = null;
        $intent['query_type'] = 'aggregation';

        return $intent;
    }

    /**
     * "Only in Springfield" narrows the answer; it does not ask for a file card.
     *
     * A bare `group_value` means "one named record" and routes to the detail
     * view -  every column of the matching rows. That is a reasonable reading of
     * "revenue for Springfield" asked cold. It is the wrong reading of "only in
     * Springfield" said straight after "total amount by city", where the user is
     * plainly narrowing the answer they are looking at.
     *
     * Three providers demonstrated three different wrong answers to exactly
     * that pair. Claude and DeepSeek set group_value and got a raw dump of
     * invoice rows with the breakdown silently changed; Gemini set nothing at
     * all and returned every city, the narrowing quietly discarded.
     *
     * So during a conversation a bare group_value is re-read as a filter on the
     * column already being grouped by, which is what "only in X" means. The
     * grouping survives, so the answer is the one row asked for rather than a
     * table with the question changed underneath it.
     *
     * Only inside a conversation. A one-shot "revenue for Springfield" has no
     * established breakdown to narrow and keeps the detail reading.
     *
     * @param  array<string, mixed>  $intent
     * @return array<string, mixed>
     */
    protected function narrowingRatherThanDetail(array $intent): array
    {
        $value = $intent['group_value'] ?? null;
        $groupBy = $intent['group_by'] ?? null;

        if ($value === null || $value === '' || empty($groupBy)) {
            return $intent;
        }

        // Already expressed as a filter, on any column: leave it alone.
        foreach (($intent['filters'] ?? []) as $filter) {
            if (is_array($filter) && isset($filter['value'])
                && strcasecmp(trim((string) $filter['value']), trim((string) $value)) === 0) {
                $intent['group_value'] = null;

                return $intent;
            }
        }

        Log::info('[Jeeves] Reading a follow-up as a narrowing, not a detail view', [
            'value' => $value,
            'column' => $groupBy,
        ]);

        $intent['filters'] = array_merge(
            is_array($intent['filters'] ?? null) ? $intent['filters'] : [],
            [['column' => $groupBy, 'value' => $value]]
        );
        $intent['group_value'] = null;

        return $intent;
    }

    protected function normalizeIntent(array $intent): array
    {
        $intent['query_type'] = $this->normalizeQueryType($intent['query_type'] ?? null);

        return $this->dropDuplicatedGroupValue($intent);
    }

    /**
     * One of 'aggregation', 'ranking' or 'group_detail', or null.
     *
     * `query_type` decides whether a question is answered as ONE number or as
     * a list, and every consumer compares it with `===` (SqlBuilder,
     * ResponseFormatter, dropUnaskedBreakdown). Nothing normalised it, so a
     * model answering "Aggregation" instead of "aggregation" fell through to
     * the ranking default: "what is the overall figure" came back as a league
     * table, and parsed_summary then announced a breakdown nobody asked for.
     *
     * HERE rather than in AbstractProvider, which is where the identical fix
     * for `order` lives. A provider is not required to extend AbstractProvider
     * — implementing LlmProviderInterface is the documented way to add one, and
     * the package's own test double does exactly that. Normalising per provider
     * would leave every third-party implementation on the broken path, which is
     * this project's oldest mistake: attaching a guard to the callers instead
     * of to the thing guarded. Every intent reaches the engine through here.
     *
     * CASE AND WHITESPACE ONLY. A synonym table was tried and removed.
     *
     * Mapping `total`, `sum` and `aggregate` onto `aggregation` looks like the
     * same repair and is not: those words describe a MEASURE as often as a
     * shape. "Top 3 customers by revenue", answered by a model that omits
     * `group_by` and says `query_type: "total"`, became a single number where
     * it had correctly been a two-row ranking — a different question answered,
     * with a number, at `status: success`. `dropUnaskedBreakdown()` cannot
     * catch it either: it returns early the moment the question contains "by".
     *
     * The measured benefit was near zero, because the wording that would gain
     * from the map ("total", "sum") is already matched by that method's own
     * regex. So the map cost real rankings and bought nothing.
     *
     * Anything unrecognised is left to fall back to `ranking` exactly as before
     * this method existed: every `===` comparison already treated an unknown
     * value as neither aggregation nor group_detail.
     *
     * `is_scalar` because the parameter is genuinely `mixed`: providers pass
     * `$parsed['query_type']` through raw, and a small model answering
     * `["aggregation"]` made `(string)` raise "Array to string conversion",
     * which Laravel promotes to an ErrorException and the engine reported as
     * `internal_error` — a 500 on a question the package used to answer.
     */
    protected function normalizeQueryType(mixed $queryType): ?string
    {
        if (!is_scalar($queryType)) {
            return null;
        }

        $normalized = strtolower(trim((string) $queryType));

        if ($normalized === '') {
            return null;
        }

        return in_array($normalized, ['aggregation', 'ranking', 'group_detail'], true)
            ? $normalized
            : 'ranking';
    }

    /**
     * One constraint, expressed twice.
     *
     * Models sometimes put the same value in `group_value` AND in `filters`.
     * "How many invoices are pending" came back with filters=[status:pending]
     * and group_value="pending" -  the same narrowing said two ways.
     *
     * That is not harmless. `group_value` matches against the GROUP column, so
     * the copy asks for a *client* named "pending"; and its mere presence
     * disqualifies the query from being a total, which is how a question with
     * a plain numeric answer came back as a one-row league table.
     *
     * `filters` is the better of the two -  it names the column -  so the bare
     * copy goes. Compared case-insensitively, since the two rarely agree on
     * capitalisation.
     *
     * @param  array<string, mixed>  $intent
     * @return array<string, mixed>
     */
    protected function dropDuplicatedGroupValue(array $intent): array
    {
        $value = $intent['group_value'] ?? null;

        if ($value === null || $value === '' || empty($intent['filters']) || !is_array($intent['filters'])) {
            return $intent;
        }

        foreach ($intent['filters'] as $filter) {
            if (!is_array($filter) || !isset($filter['value'])) {
                continue;
            }

            if (strcasecmp(trim((string) $filter['value']), trim((string) $value)) === 0) {
                Log::info('[Jeeves] Same filter given twice; keeping the one that names its column', [
                    'group_value' => $value,
                    'column' => $filter['column'] ?? null,
                ]);

                $intent['group_value'] = null;
                break;
            }
        }

        return $intent;
    }

    /**
     * Recover when a name filter matched nothing.
     *
     * The intent contract lets the model name a single record to filter by.
     * It sometimes fills that in with a word that is really the grouping
     * dimension -  "top 5 customers by revenue" occasionally comes back with
     * the filter set to "customers" -  and the resulting WHERE clause matches
     * no rows. The user then gets "No data found for customers", which is a
     * dead end and simply wrong: drop the filter and the question answers
     * perfectly.
     *
     * So when a filtered query finds nothing, run it again without the filter.
     * This costs one local query and no API call, and the answer says plainly
     * that the name did not match rather than quietly pretending it was never
     * asked for.
     */
    protected function retryWithoutUnmatchedNameFilter(array $response, array $intent, array $metadata): array
    {
        $filter = $intent['group_value'] ?? null;

        if (empty($filter) || !$this->matchedNothing($response)) {
            return $response;
        }

        // A name that IS in its column matched nothing only because of the
        // other conditions - a period, a second filter - and that empty
        // answer is true. Checked locally; nothing leaves the server.
        if ($this->nameIsStored($intent, (string) $filter)) {
            return $response;
        }

        // A real name - not a schema word misread into the name slot - that
        // is not in this table may belong to a RELATED one: "albums by Iron
        // Maiden" filtered on the album's title. Only SQL generation can join
        // to where it lives, so on a linked schema auto mode is told to try
        // that (see query()). Pure intent mode, and a schema with no links,
        // keep the answer below, exactly as before.
        $mayBelongElsewhere = $this->registry->hasLinkedSchemas() && !$this->isSchemaWord((string) $filter);

        $unfiltered = $intent;
        $unfiltered['group_value'] = null;

        $rebuilt = $this->sqlBuilder->buildQuery($unfiltered);
        if (!($rebuilt['success'] ?? false)) {
            return $mayBelongElsewhere ? $this->markNameUnmatched($response) : $response;
        }

        $fallback = $this->validateAndExecute($rebuilt, $unfiltered['dataset'] ?? null, $metadata);

        // Only prefer the fallback if it actually found something.
        if (($fallback['status'] ?? '') !== 'success' || ($fallback['type'] ?? null) === 'no_data') {
            return $mayBelongElsewhere ? $this->markNameUnmatched($response) : $response;
        }

        Log::info('[Jeeves] Name filter matched nothing; answered without it', [
            'unmatched_filter' => $filter,
            'dataset' => $unfiltered['dataset'] ?? null,
        ]);

        $fallback['answer'] = "No match for \"{$filter}\", so this covers everything. "
            . ($fallback['answer'] ?? '');
        $fallback['metadata'] = array_merge($fallback['metadata'] ?? [], [
            'unmatched_filter' => $filter,
            'filter_dropped' => true,
        ]);

        return $mayBelongElsewhere ? $this->markNameUnmatched($fallback) : $fallback;
    }

    /**
     * Whether an answer matched nothing: no rows, or the one row of NULL or 0
     * an ungrouped COUNT or SUM returns when its filter excluded everything.
     * The first version read only the `no_data` type, so "how many albums
     * does Iron Maidan have" answered a confident 0.
     */
    protected function matchedNothing(array $response): bool
    {
        if (($response['type'] ?? null) === 'no_data') {
            return true;
        }

        return ($response['status'] ?? '') === 'success'
            && $this->answerCarriesNoData($response['rows'] ?? []);
    }

    /**
     * Whether the name is present in the column it was filtered on - the same
     * column SqlBuilder used, matched the same way (exact, or contained). A
     * local existence check on the read-only connection; no value leaves the
     * server. Anything that stops it answering reads as "not stored", which
     * costs at most one extra call and never a wrong number.
     *
     * @param  array<string, mixed>  $intent
     */
    protected function nameIsStored(array $intent, string $name): bool
    {
        $dataset = $intent['dataset'] ?? null;

        if (!is_string($dataset) || !$this->registry->has($dataset)) {
            return false;
        }

        // Through a required join the name lives in the joined table, and a
        // probe of the base table would ask the wrong one.
        if (!empty($this->registry->get($dataset)['tables']['primary']['required_join'])) {
            return false;
        }

        $requested = $intent['group_by'] ?? null;
        $column = $requested
            ? $this->registry->resolveGroupColumn($dataset, (string) $requested)
            : $this->registry->getGroupColumn($dataset);
        $table = $this->registry->getTableName($dataset);

        if (!$column || !$table) {
            return false;
        }

        try {
            $db = DB::connection(ExecutionConnection::resolve($this->registry->getConnection($dataset)));
            $wrapped = $db->getQueryGrammar()->wrap($column);
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $name) . '%';

            return $db->table($table)
                ->whereRaw("LOWER({$wrapped}) = LOWER(?)", [$name])
                ->orWhereRaw("LOWER({$wrapped}) LIKE LOWER(?) ESCAPE '!'", [$like])
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Whether a value is a word from the schema itself - a dataset or column
     * name or alias. "Top 5 customers by revenue" sometimes arrives with
     * "customers" in the name slot: a misread breakdown, not a name, and
     * dropping it is the whole answer. Only a real name can belong to a
     * different table.
     */
    protected function isSchemaWord(string $value): bool
    {
        $word = strtolower(trim($value));

        if ($word === '') {
            return false;
        }

        $forms = array_unique([$word, rtrim($word, 's'), $word . 's']);

        foreach ($this->registry->all() as $key => $schema) {
            $terms = array_merge([(string) $key, (string) ($schema['name'] ?? '')], (array) ($schema['aliases'] ?? []));

            foreach ($schema['tables']['primary']['columns'] ?? [] as $column => $definition) {
                $terms[] = (string) $column;

                foreach ((array) ($definition['aliases'] ?? []) as $alias) {
                    $terms[] = $alias;
                }
            }

            foreach ($terms as $term) {
                if (is_string($term) && in_array(strtolower(trim(str_replace('_', ' ', $term))), $forms, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Mark an answer as one intent mode could not really give, so auto mode
     * offers the question to SQL generation. The flags are internal and are
     * stripped before the response leaves.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    protected function markNameUnmatched(array $response): array
    {
        $response['_fallback_eligible'] = true;
        $response['_name_unmatched'] = true;

        return $response;
    }

    // =========================================================================
    // SQL GENERATION MODE
    // =========================================================================

    /**
     * Process query using AI-generated SQL.
     *
     * Flow: AI receives full schema → generates SQL → validate → execute
     * The AI sees every table, column, type, description, alias, and JOIN.
     */
    protected function processWithSqlGeneration(string $query, ?string $datasetHint, ?array $cached, array &$metadata, array $context = []): array
    {
        $metadata['query_mode_used'] = 'sql_generation';

        // The conversation, carried into the SQL prompt.
        //
        // This method did not take $context at all, so every follow-up that
        // escalated here lost the whole accumulated state. "Total amount by
        // city" → "only in Springfield" → "breakdown by client" came back with
        // all three clients: the Springfield filter, established two turns
        // earlier and displayed in the state summary the user was reading,
        // simply gone from the SQL. A complete answer to a question nobody
        // asked, which is the failure this package exists to prevent.
        //
        // Intent mode had carried state since conversations were built. This
        // path never did, and only showed it when a follow-up happened to
        // escalate -  which depends on the provider, so it hid behind whichever
        // one was being tested.
        $stated = $this->withState($query, $context);

        // Check if we have a cached SQL result.
        //
        // NQ-003-FIX: the cached SQL names whatever dataset the ORIGINAL
        // question resolved to, which is not necessarily this one -  replaying
        // it verbatim would silently answer the wrong table. NQ-003 handled a
        // disagreement by retargeting the cached recipe (metric, query_type,
        // group_value, limit, order) through SqlBuilder for the dataset THIS
        // question resolves to, at zero API cost. That recipe is exactly the
        // fields the INTENT contract can express -  this cached result exists
        // in the first place because the question needed SQL generation, i.e.
        // something beyond that contract, most often a WHERE predicate with
        // no slot to carry it. Retargeting silently dropped it and reported
        // success on the unfiltered query. So a mismatch is a cache MISS, not
        // a retarget: a fresh generation below costs one API call and cannot
        // drop anything, because it starts from the question, not the recipe.
        // A conversation turn is never served from this cache, matching what
        // processWithIntent has always done and what docs/CONVERSATIONS.md
        // promises in as many words. The key is the question's TEXT and carries
        // no session, so "and the total there" is the same key for every
        // conversation in the application -  a follow-up only means anything
        // relative to the turns before it, and those are not in the key.
        $inConversation = !empty($context['state']);

        // Eligibility was settled in query(), against the scope the cached
        // question was asked under. The comparison that used to sit here
        // measured the asking scope against the row's ANSWER dataset instead,
        // and threw away rows that had just been cleared on the correct
        // grounds -  the same conflation as in processWithIntent, in the other
        // reader. What is left is a shape check: this reader replays finished
        // SQL, so it wants the rows that carry some.
        if ($cached && !$inConversation && isset($cached['intent']['_sql_result'])) {
            $sqlResult = $cached['intent']['_sql_result'];
            $recipeDataset = $sqlResult['dataset'] ?? null;

            // A recipe the CURRENT rules would refuse is a stale row, not an
            // answer. Rows carry no expiry, so one cached before the adopter
            // wrote a required_filter kept being replayed into a refusal:
            // permanently, because the provider was never consulted again, and
            // for every rewording too, because the fuzzy tier matched the same
            // dead row. The advice in the refusal - ask again - could not work.
            //
            // Falling through regenerates once and the row heals itself:
            // store() overwrites on the unique question hash as soon as a
            // fresh answer succeeds. One provider call instead of a question
            // that could never be answered again.
            $staleRule = $this->requiredFilterMissing(
                $sqlResult['sql'] ?? '',
                $recipeDataset ? $this->registry->get($recipeDataset) : null
            );

            if ($staleRule === null) {
                $this->markCacheHit($metadata, $cached);

                return $this->validateAndExecute($sqlResult, $recipeDataset, $metadata);
            }

            Log::info('[Jeeves] Discarding a cached recipe that a schema rule now forbids', [
                'dataset' => $recipeDataset,
            ]);
        }

        // Step 1: Identify the dataset
        // (priority: hint → routing → keywords → fuzzy → semantic → LLM intent)
        $dataset = $datasetHint;
        if (!$dataset || !$this->registry->has($dataset)) {
            // Try keyword/routing detection first (fast, no API call)
            $dataset = $this->seeder?->detect($query);
        }

        // Typo-tolerant routing, when an install has opted into it. Ahead of
        // semantic matching because it is local and deterministic - a
        // misspelled alias should not cost a round trip to learn what an edit
        // distance already knows - and behind exact detection so it can never
        // re-decide a question the aliases already place.
        if ((!$dataset || !$this->registry->has($dataset))
            && config('jeeves.fuzzy_dataset_matching.enabled', false)
            && ($fuzzy = $this->seeder?->detectFuzzy(
                $query,
                (int) config('jeeves.fuzzy_dataset_matching.max_distance', 2)
            ))
        ) {
            $dataset = $fuzzy;
            $metadata['_dataset_via'] = 'fuzzy';
        }

        // Semantic matching, when an install has opted into it. It sits HERE
        // and nowhere earlier because exact routing must always win: a
        // question the aliases already place correctly must not be re-decided
        // by a similarity score.
        //
        // It sits here and nowhere LATER because the call below is the one it
        // exists to save. A confident match answers "which dataset" without
        // spending a provider call on the question.
        //
        // It is deliberately absent from DatasetSeeder::detect(). That method
        // also feeds resolveAskingDataset(), which decides whether a cached
        // answer belongs to THIS question's dataset - a guard against replaying
        // one dataset's numbers for another. Wiring a similarity score into a
        // guard against confidently-wrong answers would be the exact failure
        // §0 names as the worst one. Keyword detection is exact and can be
        // trusted there; this cannot, so it stays on the generation path.
        if (!$dataset || !$this->registry->has($dataset)) {
            if ($semantic = $this->matchDatasetSemantically($query, $metadata)) {
                $dataset = $semantic;
            } elseif ($this->semanticStageIsLive() && $this->semanticFallback() === 'clarification') {
                // Configured never to guess. The LLM below would place this
                // question on its own, so this genuinely gives something up -
                // which is why 'llm' is the default and this is opt-in.
                return $this->formatter->formatClarification(
                    ['clarification_type' => 'dataset'],
                    $this->registry->getAvailableDatasets()
                );
            }
        }

        if (!$dataset || !$this->registry->has($dataset)) {
            // Fall back to LLM intent parsing (slower, requires API call)
            $datasetList = $this->registry->getDatasetListForLlm();
            $intent = $this->llmProvider->parseIntent($query, $datasetList);

            // The response was read only for ['dataset'], and a failure has no
            // dataset -  so a 429 here read as "could not place the question"
            // and generateSql was called a line later, against a provider that
            // had just reported it was over quota. Same fall-through as the
            // planner's, on the call beside it.
            //
            // Only the failures that must not be followed by another call.
            // Widening this to every failure broke the fallback that makes
            // this method worth reaching: a model that could not place the
            // question is still perfectly able to answer the multi-dataset
            // prompt below, and RateLimitHandlingTest exists to say so.
            $mustStop = ($intent['status'] ?? null) === 429
                || ($intent['refused_before_sending'] ?? false);

            if (!($intent['success'] ?? true) && $mustStop) {
                return $this->providerFailure($intent, $metadata);
            }

            $dataset = $intent['dataset'] ?? null;

            if (!$dataset) {
                $dataset = $this->registry->findByAlias($query);
            }
        }

        // Step 2: Build the prompt.
        //
        // A single-table prompt is sharper when it is the right table. But on a
        // normalised schema the routing above matches the table NAMED in the
        // question, which is often a dimension table rather than the one
        // holding the numbers: "top customers by revenue" routes to
        // `customers`, whose prompt has no revenue in it, and the model
        // correctly replies that it does not know which metric is meant.
        //
        // So when the tables are linked by foreign keys, any question may
        // legitimately span them and the multi-table prompt -  which lists every
        // table, their relationships, and permission to join -  is the only one
        // that can answer. Fall back to the focused prompt when there is one
        // dataset, or when nothing is related and a join is impossible anyway.
        // $stated, not $query: the SQL must reflect the conversation, not just
        // the last sentence of it. Dataset detection above deliberately still
        // uses the bare question -  the state block would match every dataset
        // name it mentions.
        if ($dataset && $this->registry->has($dataset) && !$this->registry->hasLinkedSchemas()) {
            $prompt = $this->promptBuilder->buildSqlPrompt($dataset, $stated);
            $datasetsRendered = 1;
        } else {
            $prompt = $this->promptBuilder->buildMultiDatasetPrompt($stated);
            $datasetsRendered = count($this->registry->all());
        }

        // R4: over budget refuses BEFORE any provider call -  never a smaller
        // prompt answering a narrower question. _unretriable per R5: the only
        // retry strategy this package has (retryWithRefinedPrompt) sends a
        // SMALLER, single-dataset prompt, which is exactly the wrong move for
        // a refusal caused by size.
        $refusal = $this->budget?->check($prompt, $datasetsRendered);
        if ($refusal !== null) {
            return array_merge(
                $this->formatter->formatError($refusal, $metadata, ErrorCode::CANNOT_ANSWER),
                ['_unretriable' => true]
            );
        }

        // Ask AI to generate SQL
        $response = $this->llmProvider->generateSql($prompt);

        if (!$response['success']) {
            return $this->providerFailure($response, $metadata);
        }

        $data = $response['data'];

        // AI returned an error / needs clarification
        if (isset($data['error'])) {
            $intent = [
                'dataset' => null,
                'metric' => null,
                'group_value' => null,
                'confidence' => 0,
                'needs_clarification' => $data['needs_clarification'] ?? true,
                'clarification_type' => $data['clarification_type'] ?? 'ambiguous',
            ];

            return $this->formatter->formatClarification($intent, $this->registry->getAvailableDatasets());
        }

        // AI generated SQL
        $sql = $data['sql'] ?? null;
        $dataset = $data['dataset'] ?? $datasetHint;

        if (!$sql) {
            return $this->formatter->formatError('AI did not generate a SQL query', $metadata, ErrorCode::PROVIDER_ERROR);
        }

        // Replace computed metric names if AI used them as column names
        if ($dataset && $this->registry->has($dataset)) {
            $sql = $this->replaceComputedMetrics($sql, $dataset);
        }

        // A required_filter is a RULE, not a hint, and this route was treating
        // it as a hint: PromptBuilder writes "REQUIRED FILTER (always include
        // in WHERE)" into the prompt and hopes, while SqlBuilder appends it to
        // the SQL on the intent route so the model cannot omit it.
        //
        // The check no longer lives here. It sits in validateAndExecute, the
        // single place SQL executes, because a guard on the generation sites
        // missed the verifier's rewrite, the cached recipe replayed from that
        // rewrite, and the steps of a decomposed question.
        $schemaData = $dataset ? $this->registry->get($dataset) : null;

        // Build query result
        $queryResult = [
            'success' => true,
            'sql' => $sql,
            'dataset' => $dataset,
            'dataset_name' => $schemaData['name'] ?? $dataset,
            'metric' => $data['metric'] ?? null,
            // Not the model's `explanation`: the prompt asks for it as a
            // sentence about the query, and it was set into the answer as the
            // measure's name. ResponseFormatter names the column that ran.
            'metric_description' => null,
            'metric_unit' => '',
            'metric_type' => 'neutral',
            'group_value' => $data['group_value'] ?? null,
            'limit' => $data['limit'] ?? config('jeeves.sql.default_limit', 100),
            'order' => $data['order'] ?? 'DESC',
            'query_type' => $data['query_type'] ?? 'ranking',
            // Reported so a generated query says which dates it covered.
            // Intent mode derives this from date_from/date_to; SQL
            // generation writes the WHERE itself, so it had nothing to
            // report and every step of a decomposed question came back
            // with a blank period -  while the README promises each one
            // states the range it used. The model knows; it just was not
            // being asked.
            'time_filter' => $data['period'] ?? null,
            'group_column' => $dataset ? $this->registry->getGroupColumn($dataset) : 'name',
        ];

        // Resolve metric unit/type from schema if possible
        if ($dataset && $queryResult['metric']) {
            $metricData = $this->resolveMetricData($dataset, $queryResult['metric']);
            if ($metricData) {
                $queryResult['metric_description'] = $metricData['description'] ?? $queryResult['metric_description'];
                $queryResult['metric_unit'] = $metricData['unit'] ?? '';
                $queryResult['metric_type'] = $metricData['type'] ?? $metricData['metric_type'] ?? 'neutral';
            }
        }

        // Self-verification: AI checks its own SQL before execution
        if ($this->shouldVerify($metadata)) {
            $verification = $this->verifier->verify($query, $queryResult['sql'], $dataset);

            $metadata['verification'] = [
                'confidence' => $verification['confidence'],
                'passed' => $verification['passed'],
                'attempts' => $verification['attempt'],
            ];

            // If verification provided a fixed SQL, use it
            if ($verification['fixed_sql']) {
                $queryResult['sql'] = $verification['fixed_sql'];
                $metadata['verification']['sql_corrected'] = true;
                Log::info('[Jeeves:Verifier] SQL corrected', [
                    'issue' => $verification['issues'],
                ]);
            }
        }

        // Cache the VERIFIED SQL result for future identical queries -  but
        // never a conversation turn. Writing one poisons the shared, text-keyed
        // store for every other session that asks the same follow-up words,
        // and it is the write, not the read, that does the damage: the row
        // outlives the conversation that created it.
        if (!$inConversation) {
            $recipe = [
                'dataset' => $dataset,
                'metric' => $queryResult['metric'],
                'group_value' => $queryResult['group_value'],
                'limit' => $queryResult['limit'],
                'order' => $queryResult['order'],
                'query_type' => $queryResult['query_type'],
                '_sql_result' => $queryResult,
            ];

            $rememberIfItWorks = fn () => $this->intentIsCacheable($recipe)
                ? $this->rememberIntent($query, $recipe, $this->resolveAskingDataset($query, $datasetHint, $context))
                : null;
        }

        // Validate and execute -  THEN cache, and only what worked.
        //
        // The store used to run first, so SQL that SqlValidator rejected, or
        // that the database refused, was written to a cache with no expiry and
        // replayed on every later ask of that wording. The provider was never
        // consulted again, so the one bad generation became permanent: the
        // user was told their question could not be understood, forever, and
        // rewording it slightly was the only escape.
        //
        // Identical in shape to the recipe defect this release opened with -
        // a row cached in a state nothing downstream re-checks.
        $result = $this->validateAndExecute($queryResult, $dataset, $metadata);

        if (isset($rememberIfItWorks) && ($result['status'] ?? '') === 'success') {
            $rememberIfItWorks();
        }

        return $result;
    }

    /**
     * The single dataset THIS question resolves to, at zero API cost
     * (NQ-003): an explicit hint, conversation state, then keyword/alias
     * detection on the question's own text via DatasetSeeder -  the same
     * priority `processWithSqlGeneration()`'s own dataset-identification
     * step already uses, minus its final LLM fallback, which costs a call
     * and is not needed just to sanity-check a cache hit.
     *
     * A cache row is written for whatever dataset the ORIGINAL question
     * resolved to. Replaying it for a DIFFERENT dataset than the one THIS
     * question resolves to answers the wrong table with no error, no
     * latency, and no log entry to suggest anything happened -  the
     * confidently-wrong-number failure mode §0 exists to rule out. Both
     * cached-result branches (processWithIntent(), processWithSqlGeneration())
     * compare this against the cached row's own dataset before reusing it.
     *
     * Null when none of the free signals resolve one AND more than one
     * dataset is registered -  the caller then trusts the cached row's own
     * dataset as-is: an exact-hash hit is the identical question asked
     * before, and a fuzzy hit already refused to reach this point without
     * one of these same signals (TwoTierQueryCache::find()). With exactly
     * one dataset registered there is nothing for a cached row to cross
     * INTO, so that one is resolved unconditionally -  the same reasoning
     * `processWithIntent()` already applies when a parsed intent names no
     * dataset and there is only one to choose from.
     */
    /**
     * Look a question up in the cache.
     *
     * Which method gets called is about capability, not about safety. A cache
     * implementing ScopesCacheByDataset is told what the asking question
     * resolves to and can narrow its own fuzzy search; one that does not is
     * called through find(). Either way the row comes back to query(), which
     * decides eligibility against the scope recorded on it.
     */
    protected function findInCache(string $query, ?string $askingDataset): ?array
    {
        // findForDataset() is an OPTIMISATION, not the safety mechanism. It
        // lets the bundled cache filter the fuzzy tier in SQL; eligibility
        // itself is decided in query() against `_asking_scope`, which rides
        // inside the intent blob that every implementation stores and returns.
        // So a cache that cannot scope is safe to read, and the bypass that
        // used to sit here -  returning null whenever a dataset was known -
        // was not protecting anything.
        //
        // It was, however, silently disabling every custom cache on the most
        // common install shape: resolveAskingDataset() returns the sole key
        // unconditionally when one dataset is registered, so the bypass fired
        // on every question. store() still ran, so the adopter's table filled
        // up and never returned a row.
        //
        // A subclass that overrides find() is the other half. Overriding
        // find() is the obvious way to bolt a tenant or permission gate onto
        // the bundled cache, and it inherits ScopesCacheByDataset, so calling
        // findForDataset() would route around the gate without a word -  a
        // cross-tenant read that looks like a cache hit. Where the override
        // exists and the scoped method has not been overridden with it, the
        // override wins: one fuzzy tier is worth one API call, and a gate that
        // does not run is worth considerably more.
        if ($this->cache instanceof ScopesCacheByDataset && !$this->cacheOverridesFindOnly()) {
            return $this->cache->findForDataset($query, $askingDataset);
        }

        return $this->cache->find($query);
    }

    /**
     * Whether the injected cache overrides find() but inherits findForDataset().
     *
     * Reflection once per instance, memoised -  the answer cannot change for a
     * given object, and this runs on every question.
     */
    private function cacheOverridesFindOnly(): bool
    {
        if ($this->cacheFindIsOverridden !== null) {
            return $this->cacheFindIsOverridden;
        }

        // Only meaningful for subclasses of the bundled cache. The question
        // this answers -  "did the author gate find() and not realise
        // findForDataset() bypasses it?" -  presupposes inheriting both from
        // TwoTierQueryCache.
        //
        // Applied to any implementation, the comparison misfires: a cache that
        // declares ScopesCacheByDataset on a concrete class while inheriting
        // find() from its OWN abstract base has find() declared somewhere that
        // is neither TwoTierQueryCache nor the class declaring findForDataset,
        // so it was read as a find()-only gate and its findForDataset() was
        // never called. It had implemented the capability interface precisely
        // to be asked.
        if (!$this->cache instanceof TwoTierQueryCache) {
            return $this->cacheFindIsOverridden = false;
        }

        $declaring = fn (string $method) => (new \ReflectionMethod($this->cache, $method))
            ->getDeclaringClass()
            ->getName();

        $find = $declaring('find');
        $scoped = $declaring('findForDataset');

        // Both redeclared together means the author knew about both, so their
        // scoped version is the one to call.
        $overridesFindOnly = $find !== TwoTierQueryCache::class && $find !== $scoped;

        if ($overridesFindOnly) {
            // Warning, not debug. This choice keeps the adopter's gate running
            // and costs them EVERY cache hit, not just the fuzzy tier: find()
            // looks up with no scope, while rows are stored under the scope
            // their question was asked with, so the exact tier cannot match
            // either. That is the right way round -  a 0% hit rate is a
            // performance loss and a skipped tenant gate is a cross-tenant
            // read -  but it is far too expensive to discover from a debug log.
            Log::warning(
                '[Jeeves:Cache] ' . get_class($this->cache) . ' overrides find() but not '
                . 'findForDataset(). find() is being called so the override still runs, which means NO '
                . 'cache hits at all: lookups carry no dataset scope while stored rows do. Override '
                . 'findForDataset() as well (see docs/CACHING.md) to keep both the gate and the cache.',
                ['cache' => get_class($this->cache), 'declares_find' => $find]
            );
        }

        return $this->cacheFindIsOverridden = $overridesFindOnly;
    }

    /**
     * Record that this answer came from a cached row.
     *
     * Call it where a row is USED, never where one is found. Four things read
     * this flag -  the response metadata, auditLog(), the QuestionAnswered
     * event, and verification.skip_on_cache_hit -  and the last of those turns
     * QueryVerifier off, so a false positive disables the self-check on exactly
     * the SQL that was generated a moment earlier.
     *
     * It was previously set in query() the moment the cache returned anything,
     * which is the fifth guard in this class to have been attached to the entry
     * rather than to the thing it describes. Both readers refuse a row
     * mid-conversation and neither told the caller, so the flag was true for
     * answers the provider had just generated and billed for.
     */
    /**
     * Write an intent to the cache, stamped with the scope it was asked under.
     *
     * The scope is a required parameter rather than something read from state,
     * so a new store site cannot be added without deciding what it is. Every
     * previous guard in this class was optional at the call site, and every
     * one of them was then missed at least once.
     *
     * `_asking_scope` rides inside the intent blob deliberately. Passing it as
     * an argument would mean widening QueryCacheInterface::store(), and adding
     * a parameter to an interface method -  even an optional one -  is a fatal
     * error at class load for every third-party implementation that already
     * exists. That mistake was made once already on find(); the capability
     * became ScopesCacheByDataset instead. A reserved key inside a payload the
     * interface already carries costs nothing and breaks nobody, and
     * normalizeIntent() drops unknown keys, so it never reaches SqlBuilder.
     */
    /**
     * Why this SQL cannot be trusted, or null.
     *
     * Keyed on the tables the SQL NAMES, not on the dataset the model reported
     * about itself. A self-reported label is not a trust boundary: it made the
     * rule both too weak (a mislabelled response disarmed it) and too strong
     * (a correct query against a neighbouring table was refused for omitting a
     * filter that does not apply to it).
     *
     * REFUSES rather than injects, deliberately. SqlBuilder can splice the
     * filter into its own SQL because it wrote that SQL and knows its shape.
     * Model-generated SQL is arbitrary -  a derived table, a CTE, a subquery in
     * the FROM clause -  and the splice is a regex on the first WHERE, which on
     * `SELECT … FROM (SELECT … WHERE x) sub` lands inside the subquery and
     * narrows the wrong thing. Silently. That is a worse failure than the one
     * being fixed, and "reconcile rather than refuse" is the exact habit this
     * release spent four review rounds removing.
     *
     * The comparison is deliberately literal -  whitespace collapsed and `<>`
     * folded to `!=`, nothing more. A model writing an EQUIVALENT filter in
     * different words (`status NOT IN ('cancelled')`) is refused even though
     * its SQL was correct. That is a false refusal, it costs an error message
     * on a good answer, and it is still the right trade: the alternative is
     * accepting text that merely mentions the column, which passes
     * `GROUP BY status` and hands back the unfiltered total.
     *
     * AND ITS LIMIT, stated plainly because an overstated guard is worse than
     * a modest one: this tests that the filter is PRESENT, not that it
     * COVERS. The same arbitrariness that makes splicing unsafe makes a
     * substring check incomplete -  a filter sitting in a subquery while the
     * outer aggregate runs unfiltered, or defeated by an `OR` beside it, reads
     * as present here. Detecting that needs a real parser, not a bigger
     * regex. So this raises the floor from "the model was asked nicely" to
     * "the text must be there", and `query_mode` "intent" remains the only
     * route where the filter is applied rather than checked. docs/SCHEMA.md
     * says so.
     *
     * Armed by the dataset being answered, so a model that reports the wrong
     * one is not caught here — see the note in docs/SCHEMA.md rather than
     * assuming otherwise.
     */
    /**
     * Does the needle appear somewhere that is actually part of the query?
     *
     * An occurrence inside a string literal is data, not a predicate:
     * `WHERE label = 'status != ''cancelled'''` contains the rule's text and
     * enforces nothing. Parity of unescaped quotes before the match says which
     * side of a literal it fell on.
     *
     * Deliberately no attempt to understand backslash escapes, which are MySQL
     * and not standard. A miscount there makes an occurrence look like it is
     * inside a literal, and an occurrence inside a literal does not satisfy the
     * rule - so the error lands on refusing a query, never on serving a
     * forbidden total.
     */
    private function containsOutsideStringLiterals(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        $offset = 0;

        while (($position = strpos($haystack, $needle, $offset)) !== false) {
            if (substr_count(substr($haystack, 0, $position), "'") % 2 === 0) {
                return true;
            }

            $offset = $position + 1;
        }

        return false;
    }

    private function requiredFilterMissing(string $sql, ?array $schemaData): ?string
    {
        $required = $schemaData['tables']['primary']['required_filter'] ?? null;

        if (!$required) {
            return null;
        }

        $normalise = static fn (string $s) => strtolower(preg_replace(
            ["/\s+/", '/<>/'],
            [' ', '!='],
            trim($s)
        ) ?? '');

        // NQ-008. The rule is satisfied by TEXT, so text that does nothing used
        // to satisfy it. A comment is part of the string and not part of the
        // query:
        //
        //     SELECT /* status != 'cancelled' */ SUM(revenue) FROM nq_orders
        //
        // passed this check, constrained nothing, and returned 800 - every row,
        // including the 500 the adopter's rule says must never be counted -
        // captioned "Total Revenue: 800". A confident wrong number, which
        // Rule 0 names as the worst failure this package has.
        //
        // This repository has met the bug before from the other side:
        // `pg_sleep/**​/(10)` defeated a validator rule added hours earlier
        // because `\s*` does not match a comment. There a comment hid something
        // forbidden; here it supplied something required.
        $withoutComments = preg_replace(['#/\*.*?\*/#s', '/--[^\r\n]*/'], ' ', $sql) ?? $sql;

        if ($this->containsOutsideStringLiterals($normalise($withoutComments), $normalise($required))) {
            return null;
        }

        Log::warning('[Jeeves] SQL omitted a required filter', [
            'dataset' => $schemaData['name'] ?? null,
            'required_filter' => $required,
        ]);

        return sprintf(
            'This dataset has a required filter (%s) that the query did not apply, so the '
            . 'answer would have counted rows your schema says must never be counted. Rephrase the '
            . 'question, or ask your administrator to set query_mode "intent" for this dataset - '
            . 'that route applies the filter itself instead of asking the model to.',
            $required
        );
    }

    /**
     * A resolved date range is only true for the moment it was resolved.
     *
     * The model turns "last month" into absolute dates, and the whole intent
     * was cached, dates included. So the question asked in July stored a June
     * window and the same words asked in August replayed June - the wrong
     * number, with no provider call and no escape: Tier 2 rows carry no expiry,
     * and until 2.3.0 the fuzzy tier handed the same row to every rewording of
     * the question too.
     *
     * The cache is keyed on the question's WORDS, and those do not change when
     * the correct answer does. That makes any intent carrying dates
     * uncacheable, whether the user said "last month" or "July 2026" - the
     * package cannot tell which from the intent, and guessing wrong costs a
     * wrong number rather than a slow one.
     *
     * The price is a provider call on every dated question. Worth it: a stale
     * period is the failure Rule 0 names, and one that never heals.
     */
    private function intentIsCacheable(array $intent): bool
    {
        if (($intent['date_from'] ?? null) !== null || ($intent['date_to'] ?? null) !== null) {
            return false;
        }

        // The same staleness reaches the other route by a different door. A
        // `_sql_result` recipe stores the model's finished SQL, and a question
        // about "last month" bakes that month's dates into it as literals, so
        // replaying the recipe next month is stale in exactly the same way -
        // and worse, because nothing downstream can even see the dates.
        //
        // Keyed on the SQL, never on `time_filter`. On this route that field
        // is `$data['period']` - what the model SAYS about the WHERE it wrote,
        // an optional free-text field. A model that filtered dates and omitted
        // it disarmed this guard entirely, so the defect this method exists to
        // prevent survived on the other route; a model that writes the string
        // "none" instead of a null armed it on every question and switched
        // caching off completely, with nothing logged. Rule 8: a guard keys on
        // what the SQL does, never on what the model says about itself.
        $recipe = $intent['_sql_result'] ?? null;

        if (is_array($recipe)) {
            if ($this->sqlPinsAMoment((string) ($recipe['sql'] ?? ''))) {
                Log::debug('[Jeeves] Not caching a recipe that pins a date', [
                    'dataset' => $recipe['dataset'] ?? null,
                ]);

                return false;
            }

            return true;
        }

        return true;
    }

    /**
     * Does this SQL bake a moment in time into itself?
     *
     * Only LITERALS go stale. `NOW()`, `CURRENT_DATE` and friends re-evaluate
     * on every execution, so a recipe built on them is still correct next
     * month and stays cacheable. It is `'2026-06-01'`, and the bare year in
     * `YEAR(placed_on) = 2026`, that pin an answer to a month already gone.
     *
     * Deliberately broad in the safe direction. This decides only whether to
     * CACHE: a false positive costs one provider call, while a false negative
     * serves a wrong number for ever - Tier 2 rows carry no expiry. So a
     * four-digit year anywhere in the statement is enough, even inside a
     * table name.
     */
    private function sqlPinsAMoment(string $sql): bool
    {
        // One pattern covers every shape that goes stale: the ISO literal
        // '2026-06-01', the quoted month '2026-06', and the bare comparison
        // in YEAR(placed_on) = 2026 or EXTRACT(YEAR FROM placed_on) = 2026.
        return (bool) preg_match('/\b(?:19|20)\d{2}\b/', $sql);
    }

    private function rememberIntent(string $query, array $intent, ?string $askingScope): void
    {
        $intent['_asking_scope'] = $askingScope;
        $this->cache->store($query, $intent);
    }

    private function markCacheHit(array &$metadata, array $cached): void
    {
        $metadata['cache_hit'] = true;
        $metadata['cache_match_type'] = $cached['cache_match_type'] ?? null;
    }

    /**
     * Whether the semantic stage is actually configured to run.
     *
     * The container binds NullSemanticMatcher when the feature is off, so the
     * null object IS the off state and asking about it is the honest question.
     * This matters for the fallback policy: 'clarification' must never fire on
     * an install that has not enabled semantic matching at all, or every
     * question the LLM used to place would come back as a prompt.
     */
    protected function semanticStageIsLive(): bool
    {
        return $this->semanticMatcher !== null
            && !$this->semanticMatcher instanceof NullSemanticMatcher;
    }

    /** Below-threshold / service-down policy: 'llm' (default) or 'clarification'. */
    protected function semanticFallback(): string
    {
        $fallback = config('jeeves.semantic_matching.fallback', 'llm');

        // Anything unrecognised reads as the additive default. A typo in this
        // setting must not be a way to accidentally truncate the cascade.
        return $fallback === 'clarification' ? 'clarification' : 'llm';
    }

    /**
     * The dataset an embedding service places this question in, or null.
     *
     * Null covers every outcome that is not a confident, registered match:
     * the stage is off, nothing cleared the threshold, the service was
     * unreachable, or it named a dataset this install does not have. The
     * caller treats all of them the same way, which is the point - there is no
     * outcome here that can break a question, only one that can save a call.
     *
     * Rule 8. The metadata records the score that came BACK, and is written
     * only on the branch that actually routed on it. A question placed by the
     * LLM carries nothing from here, so `_dataset_via` never claims a route
     * the answer did not take.
     */
    protected function matchDatasetSemantically(string $query, array &$metadata): ?string
    {
        if (!$this->semanticStageIsLive()) {
            return null;
        }

        $result = $this->semanticMatcher->match($query, array_keys($this->registry->all()));

        if (!$result->confident || $result->dataset === null) {
            Log::debug('[Jeeves] Semantic matching did not place the question', [
                'question' => QuestionForLog::text($query),
                'best_score' => $result->score,
                'error' => $result->error,
            ]);

            return null;
        }

        // The matcher filters against the allowed set already. This is the
        // second check, and it is not redundant: `allowed` is a list of keys
        // and has() is the registry's own answer about whether that key can be
        // loaded. A guard on the thing guarded, not on the argument passed.
        if (!$this->registry->has($result->dataset)) {
            return null;
        }

        Log::info('[Jeeves] Semantic matching placed the question', [
            'dataset' => $result->dataset,
            'score' => $result->score,
        ]);

        $metadata['_dataset_via'] = 'semantic';
        $metadata['_dataset_score'] = $result->score;

        return $result->dataset;
    }

    protected function resolveAskingDataset(string $query, ?string $datasetHint, array $context = []): ?string
    {
        if ($datasetHint && $this->registry->has($datasetHint)) {
            return $datasetHint;
        }

        $stateDataset = $context['state']['dataset'] ?? null;
        if (is_string($stateDataset) && $stateDataset !== '' && $this->registry->has($stateDataset)) {
            return $stateDataset;
        }

        $detected = $this->seeder?->detect($query);
        if ($detected && $this->registry->has($detected)) {
            return $detected;
        }

        $keys = $this->registry->keys();

        return count($keys) === 1 ? $keys[0] : null;
    }

    // =========================================================================
    // RETRY LOGIC
    // =========================================================================

    /**
     * Retry a failed query with a refined prompt.
     *
     * Strategy:
     * 1. Try to detect dataset from query keywords/aliases
     * 2. If found, retry with single-dataset SQL prompt (much more accurate)
     * 3. If still no dataset, retry with explicit instruction to generate SQL
     */
    /**
     * Say what actually went wrong with a provider call.
     *
     * A failed request is not a failure to understand, and reporting it as one
     * sends the user off rewording a perfectly good question while the real
     * fault -  an expired key, a rate limit, no route to the host -  goes
     * unmentioned. The benchmark suite lost a CA bundle and every single case
     * came back "Could not understand the query. Try mentioning a dataset
     * name", which is a lie that costs an afternoon.
     *
     * @param  array<string, mixed>  $response  Provider response with success:false
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    protected function providerFailure(array $response, array $metadata): array
    {
        if (($response['status'] ?? null) === 429) {
            return array_merge(
                $this->formatter->formatError(self::RATE_LIMIT_MESSAGE, $metadata, ErrorCode::RATE_LIMITED),
                ['_rate_limited' => true]
            );
        }

        $error = $this->formatter->formatError(
            $response['error'] ?? 'AI failed to generate SQL',
            $metadata,
            ErrorCode::PROVIDER_ERROR
        );

        // A provider can refuse BEFORE sending anything -  OllamaProvider's
        // context-window guard does, because Ollama does not reject an
        // oversized prompt, it silently truncates the schema and answers
        // anyway. That refusal is tied to prompt SIZE, not to what the model
        // thinks of the question, so it is not the "model answered badly"
        // case retryWithRefinedPrompt() exists for. Flagged here, at the one
        // place every such response passes through, so the caller can decide
        // not to retry at all rather than send a smaller prompt that clears
        // the guard and answers a narrower question than was asked.
        if ($response['refused_before_sending'] ?? false) {
            $error['_unretriable'] = true;
        }

        return $error;
    }

    protected function retryWithRefinedPrompt(string $query, ?string $datasetHint, array $metadata, array $previous = []): array
    {
        $metadata['_retried'] = true;
        $metadata['retry'] = true;
        Log::info('[Jeeves] Retrying with refined prompt', ['query' => QuestionForLog::text($query)]);

        // Strategy 1: Try keyword-based dataset detection from all aliases
        $dataset = $datasetHint;
        if (!$dataset) {
            $dataset = $this->seeder?->detect($query);
        }

        if ($dataset && $this->registry->has($dataset)) {
            Log::info('[Jeeves] Retry: detected dataset from keywords', ['dataset' => $dataset]);
            // Use single-dataset SQL prompt -  much more reliable
            $prompt = $this->promptBuilder->buildSqlPrompt($dataset, $query);

            // Measured like any other. This is the same builder the bounded
            // path uses, and it was the one prompt in the package that went
            // out unchecked -  so an install that set prompts.max_chars
            // precisely to stop oversized prompts being sent still sent one
            // here, on the retry, where nobody was looking.
            if ($refusal = $this->budget?->check($prompt, 1)) {
                return array_merge(
                    $this->formatter->formatError($refusal, $metadata, ErrorCode::CANNOT_ANSWER),
                    ['_unretriable' => true]
                );
            }

            $response = $this->llmProvider->generateSql($prompt);

            // The retry is the last thing that runs before the "could not
            // understand" message, so a provider fault swallowed here is a
            // provider fault reported as a bad question.
            if (!($response['success'] ?? false)) {
                return $this->providerFailure($response, $metadata);
            }

            if (!isset($response['data']['sql'])) {
                // The dataset was identified and the provider answered without
                // SQL. Telling the user to name a dataset would send them off
                // supplying the one thing that was never missing.
                $name = $this->registry->get($dataset)['name'] ?? $dataset;

                return $this->formatter->formatError(
                    "That could not be answered from {$name}. Try naming the measure you want, or rephrasing the breakdown.",
                    $metadata,
                    ErrorCode::CANNOT_ANSWER
                );
            }

            $data = $response['data'];
            $schemaData = $this->registry->get($dataset);

            // The required_filter check that used to sit here has moved to
            // validateAndExecute, which this path reaches too. Counting the
            // sites where SQL is generated is how the rule ended up enforced on
            // some of them; there is one place it is executed.

            $queryResult = [
                'success' => true,
                'sql' => $data['sql'],
                'dataset' => $dataset,
                'dataset_name' => $schemaData['name'] ?? $dataset,
                'metric' => $data['metric'] ?? null,
                // Not the model's `explanation` - see processWithSqlGeneration.
                'metric_description' => null,
                'metric_unit' => '',
                'metric_type' => 'neutral',
                'group_value' => $data['group_value'] ?? null,
                'limit' => $data['limit'] ?? config('jeeves.sql.default_limit', 100),
                'order' => $data['order'] ?? 'DESC',
                'query_type' => $data['query_type'] ?? 'ranking',
                // Reported so a generated query says which dates it covered.
                // Intent mode derives this from date_from/date_to; SQL
                // generation writes the WHERE itself, so it had nothing to
                // report and every step of a decomposed question came back
                // with a blank period -  while the README promises each one
                // states the range it used. The model knows; it just was not
                // being asked.
                'time_filter' => $data['period'] ?? null,
                'group_column' => $this->registry->getGroupColumn($dataset),
            ];

            // Model-written SQL runs next, so say so. The label was whatever
            // the failed attempt had set, and after an intent failure that
            // reported `intent` over the model's statement.
            $metadata['query_mode_used'] = 'sql_generation';

            $result = $this->validateAndExecute($queryResult, $dataset, $metadata);
            $result['_retried'] = true;

            return $result;
        }

        // Strategy 2: say we could not read the question -  but only when that
        // is what happened.
        //
        // Everything upstream funnels here, so this message was the last word
        // on failures that had nothing to do with the wording: an expired key,
        // no route to the host, a provider returning nonsense. Phase 9 fixed
        // the two places that mislabelled such failures, and they still ended
        // up overwritten here whenever no dataset could be guessed from the
        // words -  which is exactly what happens when the provider never
        // answered and there is no intent to guess from.
        //
        // Caught by pointing a real install at a provider with no API key: the
        // transcription was perfect, and the answer was "Try mentioning a
        // dataset name."
        if ($this->rewordingCannotHelp($previous)) {
            return array_merge($previous, ['_retried' => true]);
        }

        $datasets = array_map(fn ($s) => $s['name'] . ' (' . $s['key'] . ')', $this->registry->getAvailableDatasets());
        $datasetList = implode(', ', array_slice($datasets, 0, 10));

        return $this->formatter->formatError(
            "Could not understand the query. Try mentioning a dataset name. Available: {$datasetList}",
            $metadata,
            ErrorCode::NOT_UNDERSTOOD
        );
    }

    /**
     * Did this failure come from somewhere the question cannot fix?
     *
     * Rewording is the remedy this path offers, so replacing an error with
     * "Could not understand the query. Try mentioning a dataset name" is only
     * honest when the words were actually the problem.
     *
     * DATABASE_ERROR is on the list because it is the case where the advice is
     * not merely unhelpful but a loop: a schema naming a table that does not
     * exist produced "Could not understand the query … Available: Orders
     * (orders)" while the log recorded `no such table: shop_ordrs`. The dataset
     * IS named, and it is the broken thing, so following the advice can never
     * work. `jeeves:doctor` diagnoses this exactly; the query path was
     * discarding what it already knew.
     *
     * UNSAFE_SQL is on it for the same reason. A recipe cached before a table
     * was renamed still names the old one, the validator refuses it against
     * the schema-derived whitelist -  correctly, and with a message saying so -
     * and that message was then replaced by "Could not understand the query.
     * Try mentioning a dataset name. Available: Orders (nq_orders)". The
     * dataset is named, the question was read perfectly, and the advice cannot
     * work. This is consulted only after the regeneration strategy above has
     * declined, so the recovery that strategy provides is untouched.
     *
     * Renamed from `isProviderFailure()` when DATABASE_ERROR joined the list.
     * A database fault is not a provider fault, and the old name invited an
     * obvious refactor — hoisting this check into the retry gate above — that
     * would have killed a recovery which works today: model SQL naming a
     * column that does not exist is regenerated by the refined prompt and
     * answers correctly on the second call. This is consulted only after that
     * strategy has already declined.
     *
     * @param  array<string, mixed>  $result
     */
    protected function rewordingCannotHelp(array $result): bool
    {
        return in_array(
            $result['error_code'] ?? null,
            [
                ErrorCode::PROVIDER_ERROR,
                ErrorCode::RATE_LIMITED,
                ErrorCode::DATABASE_ERROR,
                ErrorCode::UNSAFE_SQL,
            ],
            true
        );
    }

    // =========================================================================
    // SHARED EXECUTION
    // =========================================================================

    /**
     * Validate SQL, execute it, and format the response.
     */
    /**
     * One more attempt when the answer's shape contradicts the question.
     *
     * Returns the better answer, or null to keep the one already produced.
     *
     * Three properties this must hold, and each has a test:
     *
     *  - The success path costs nothing. Nothing here runs unless the shape is
     *    already wrong, so a correct answer never pays for a second call.
     *  - At most one extra generation, ever. `_shape_retried` in the metadata
     *    is set before the second attempt, so the recursion through
     *    validateAndExecute cannot go round again.
     *  - The retry is adopted only if it fixed the thing it was fired for. A
     *    second answer that is still the wrong shape is discarded and the
     *    first is returned, so this can never make an answer worse.
     *
     * @param  array<int, mixed>  $rows
     */
    protected function retryForShape(array $queryResult, array $rows, ?string $dataset, array $metadata): ?array
    {
        if (($metadata['_shape_retried'] ?? false) || $dataset === null) {
            return null;
        }

        $question = (string) ($metadata['original_query'] ?? '');

        if ($question === '' || !$this->asksForASingleRow($question) || count($rows) <= 1) {
            return null;
        }

        Log::info('[Jeeves] Answer shape contradicts the question, regenerating once', [
            'expected' => 'one row',
            'rows' => count($rows),
        ]);

        // The hint has to say what to KEEP as well as what to change.
        //
        // Its first version said only "return exactly one row", and the model
        // obliged by dropping the measure: "which carrier shipped the most
        // orders" went from `2|royal mail ; 1|dpd` to `royal mail`. One row,
        // the right row, and the number the question was about gone. Measured
        // over three runs before this sentence existed.
        $prompt = $this->promptBuilder->buildSqlPrompt($dataset, $question)
            . "\n\n--- RETRY ---\n"
            . 'The previous attempt returned several rows for a question that asks which '
            . "single one is the most or the least.\n"
            . "Change ONLY the number of rows: add ORDER BY the measure DESC and LIMIT 1.\n"
            . 'Keep exactly the same SELECT columns as a ranked answer would have - the '
            . 'label AND the measure. Returning the label alone drops the number the '
            . "question was asking for.\n"
            . 'Use only the columns listed in the schema above.';

        $response = $this->llmProvider->generateSql($prompt);

        if (!($response['success'] ?? false) || empty($response['data']['sql'])) {
            return null;
        }

        // The hint above asks for the measure, and a model still drops it
        // sometimes. It is usually still in the ORDER BY, so it goes back into
        // the SELECT here - locally, no second call, and validated below like
        // any other statement.
        $restored = $this->restoreDroppedMeasure($response['data']['sql']);

        $second = $this->validateAndExecute(
            array_merge($queryResult, ['sql' => $restored ?? $response['data']['sql']]),
            $dataset,
            array_merge($metadata, ['_shape_retried' => true])
        );

        // Adopted only if it is the shape we retried for. Anything else -
        // an error, a refusal, another list - leaves the first answer standing.
        if (($second['status'] ?? null) === 'success' && count($second['rows'] ?? []) === 1) {
            // The model wrote this SQL, whichever route asked for it. On the
            // intent route the label still said `intent` over a join the
            // intent contract cannot express - Rule 8, found live on Chinook.
            $second['metadata'] = array_merge($second['metadata'] ?? [], [
                'shape_retry' => true,
                'query_mode_used' => 'sql_generation',
            ], $restored !== null ? ['measure_restored' => true] : []);

            return $second;
        }

        return null;
    }

    /**
     * Put back a measure that a statement orders by but does not select.
     *
     * "Which artist has the most albums", retried for one row, came back live
     * as `SELECT T1.Name ... GROUP BY T1.Name ORDER BY COUNT(T2.AlbumId) DESC
     * LIMIT 1`: the right artist, and the number the question was about only
     * in the ORDER BY. That aggregate is added to the SELECT list under a
     * plain alias. Nothing else in the statement changes.
     *
     * Returns null - run the statement as written - unless every condition
     * holds: one SELECT with a GROUP BY, no comments, every literal closed,
     * and a first ORDER BY item that is an aggregate not already selected.
     * A statement this cannot read is never rewritten.
     */
    protected function restoreDroppedMeasure(string $sql): ?string
    {
        $flat = $this->topLevelOnly($sql);

        if ($flat === null
            || !preg_match('/^\s*SELECT\b/i', $flat)
            || preg_match('/\b(?:UNION|INTERSECT|EXCEPT)\b/i', $flat)
            || !preg_match('/\bGROUP\s+BY\b/i', $flat)
            || !preg_match('/\bFROM\b/i', $flat, $from, PREG_OFFSET_CAPTURE)
            || !preg_match('/\bORDER\s+BY\b/i', $flat, $order, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $start = $order[0][1] + strlen($order[0][0]);
        $end = preg_match('/\b(?:LIMIT|OFFSET|FETCH)\b|;/i', $flat, $stop, PREG_OFFSET_CAPTURE, $start)
            ? $stop[0][1]
            : strlen($flat);

        // The first ordering item: up to the first comma at the top level.
        $comma = strpos(substr($flat, $start, $end - $start), ',');
        $item = trim(substr($sql, $start, $comma === false ? $end - $start : $comma));
        $item = (string) preg_replace('/\s+(?:ASC|DESC)(?:\s+NULLS\s+(?:FIRST|LAST))?$/i', '', $item);

        if (!preg_match('/^(COUNT|SUM|AVG|MIN|MAX)\s*\(/i', $item, $aggregate)) {
            return null;
        }

        $normal = fn (string $s) => strtolower((string) preg_replace('/\s+/', '', $s));
        $selectList = substr($sql, 0, $from[0][1]);

        if (str_contains($normal($selectList), $normal($item))) {
            return null;
        }

        $alias = ['count' => 'count', 'sum' => 'total', 'avg' => 'average', 'min' => 'minimum', 'max' => 'maximum'][strtolower($aggregate[1])];

        if (preg_match('/\bAS\s+["`]?' . $alias . '\b/i', $selectList)) {
            $alias = 'measure';
        }

        return rtrim($selectList) . ", {$item} AS {$alias} " . substr($sql, $from[0][1]);
    }

    /**
     * The statement with literals, quoted identifiers and everything inside
     * parentheses blanked to spaces, so keywords found in it are the outer
     * statement's own and their offsets are offsets into the original.
     * Null for a statement with a comment or an unclosed quote.
     */
    private function topLevelOnly(string $sql): ?string
    {
        $out = '';
        $depth = 0;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if (($char === '-' && $next === '-') || ($char === '/' && $next === '*')) {
                return null;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $j = $i + 1;

                while ($j < $length) {
                    if ($sql[$j] === $char) {
                        if (($sql[$j + 1] ?? '') === $char) {
                            $j += 2;

                            continue;
                        }

                        break;
                    }

                    $j++;
                }

                if ($j >= $length) {
                    return null;
                }

                $out .= str_repeat(' ', $j - $i + 1);
                $i = $j;

                continue;
            }

            if ($char === '(') {
                $depth++;
            }

            $out .= ($depth > 0 || $char === ')') ? ' ' : $char;

            if ($char === ')') {
                $depth = max(0, $depth - 1);
            }
        }

        return $out;
    }

    /**
     * Does the question ask which ONE thing, rather than for a ranked list?
     *
     * Deliberately narrow. "top 5 carriers" and "the 3 best clients" are
     * rankings whose correct answer has many rows, and firing on those would
     * buy a provider call on every list anyone asks for - which is why an
     * explicit count anywhere in the question disqualifies it outright.
     */
    protected function asksForASingleRow(string $question): bool
    {
        $q = strtolower($question);

        // "top 5", "3 best", "first 10" - a ranking, however it is phrased.
        if (preg_match('/\b(?:top|bottom|first|last|best|worst)\s+\d+\b/', $q)
            || preg_match('/\b\d+\s+(?:best|worst|top|highest|lowest)\b/', $q)) {
            return false;
        }

        // Plurals asking for several: "which carriers", "the products that".
        if (preg_match('/\bwhich\s+\w+s\b/', $q) && !preg_match('/\bwhich\s+\w+s\s+(?:has|is|was)\b/', $q)) {
            return false;
        }

        return (bool) preg_match(
            '/\bthe\s+(?:most|least|highest|lowest|largest|smallest|biggest|greatest)\b/',
            $q
        );
    }

    /**
     * Swap a value the user typed for the one the database stores, where a
     * schema declares the pair in a column's `value_aliases`.
     *
     * A value is swapped only when it is COMPARED WITH that column - `status
     * = 'canceled'`, `LOWER(o.status) LIKE '%canceled%'`, an item of `IN
     * (...)` - in a table the statement reads. The first version checked only
     * that the column appeared somewhere, so selecting `status` licensed a
     * rewrite of a value compared to a free-text column in the same query.
     *
     * Values only. The structure of the statement is never touched, and it
     * still goes through SqlValidator on the next line.
     *
     * @return array{0: array<string, mixed>, 1: array<int, array{from: string, to: string, column: string}>}
     */
    protected function applyValueAliases(array $queryResult): array
    {
        $sql = (string) ($queryResult['sql'] ?? '');

        $reachable = $this->reachableColumns(
            $sql,
            fn (array $definition) => is_array($definition['value_aliases'] ?? null) && $definition['value_aliases'] !== []
        );

        if ($reachable === []) {
            return [$queryResult, []];
        }

        $tables = SqlLiterals::tableAliases($sql);
        $applied = [];

        $queryResult = $this->rewriteQueryValues(
            $queryResult,
            function (string $plain, ?string $reference) use ($reachable, $tables, &$applied): ?string {
                $target = $this->resolveComparedColumn($reference, $reachable, $tables);

                if ($target === null) {
                    return null;
                }

                $to = $this->valueAliasFor($plain, $target['definition']['value_aliases']);

                if ($to === null) {
                    return null;
                }

                $applied[$plain . "\0" . $to . "\0" . $target['column']] = [
                    'from' => $plain,
                    'to' => $to,
                    'column' => $target['column'],
                ];

                return $to;
            }
        );

        return [$queryResult, array_values($applied)];
    }

    /**
     * The stored value a column declares $plain to be another name for, or
     * null. A variant listed under two different stored values is ambiguous
     * and returns null: which one was meant is not knowable from here.
     *
     * @param  array<mixed>  $aliases  canonical => variant or list of variants
     */
    protected function valueAliasFor(string $plain, array $aliases): ?string
    {
        $key = mb_strtolower(trim($plain));

        if ($key === '') {
            return null;
        }

        $found = null;

        foreach ($aliases as $canonical => $variants) {
            foreach ((array) $variants as $variant) {
                if (!is_string($variant) || mb_strtolower(trim($variant)) !== $key) {
                    continue;
                }

                if ($found !== null && $found !== (string) $canonical) {
                    return null;
                }

                $found = (string) $canonical;
            }
        }

        return $found;
    }

    /**
     * Apply a decision to every VALUE in a statement - the model's inline
     * literals and intent mode's positional bindings alike - telling the
     * decision which column each value is compared with.
     *
     * `%` wildcards around a value are kept. Intent mode escapes LIKE
     * metacharacters with `!` inside a binding, so a binding that is a pattern
     * is unescaped before the decision sees it and escaped again after. One
     * place for that, shared by every feature that changes a value, because
     * two copies of escaping rules drift apart.
     *
     * @param  callable(string, ?string): ?string  $decide  plain value and compared column in, replacement or null out
     * @return array<string, mixed>
     */
    protected function rewriteQueryValues(array $queryResult, callable $decide): array
    {
        $sql = (string) ($queryResult['sql'] ?? '');

        $swap = function (string $value, bool $bangEscaped, ?string $column) use ($decide): ?string {
            if (!preg_match('/^(%*)(.*?)(%*)$/s', $value, $m)) {
                return null;
            }

            [, $pre, $core, $post] = $m;
            $isPattern = $bangEscaped && ($pre !== '' || $post !== '');
            $plain = $isPattern ? (string) preg_replace('/!(.)/s', '$1', $core) : $core;
            $to = $decide($plain, $column);

            if ($to === null) {
                return null;
            }

            if ($isPattern) {
                $to = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $to);
            }

            return $pre . $to . $post;
        };

        // Read from the ORIGINAL statement: rewriting literals changes no
        // placeholder, so the positions line up with the bindings either way.
        $placeholderColumns = SqlLiterals::placeholderColumns($sql);

        $queryResult['sql'] = SqlLiterals::mapCompared(
            $sql,
            fn (string $v, ?string $column) => $swap($v, false, $column)
        );

        if (isset($queryResult['bindings']) && is_array($queryResult['bindings'])) {
            $position = 0;

            foreach ($queryResult['bindings'] as $i => $binding) {
                $column = $placeholderColumns[$position++] ?? null;

                if (is_string($binding) && ($swapped = $swap($binding, true, $column)) !== null) {
                    $queryResult['bindings'][$i] = $swapped;
                }
            }
        }

        return $queryResult;
    }

    /**
     * Columns a statement can reach: belonging to a table the statement names,
     * and themselves named in it. The one scoping rule every value feature
     * uses, so none of them can drift into touching a table the query never
     * read.
     *
     * @param  callable(array<string, mixed>): bool  $wants
     * @return array<int, array{table: string, column: string, definition: array<string, mixed>}>
     */
    protected function reachableColumns(string $sql, callable $wants): array
    {
        $out = [];

        foreach ($this->registry->all() as $schema) {
            $table = (string) ($schema['tables']['primary']['name'] ?? '');
            $short = $this->shortTableName($table);

            if ($short === '' || !SqlLiterals::mentions($sql, $short)) {
                continue;
            }

            foreach ($schema['tables']['primary']['columns'] ?? [] as $column => $definition) {
                if (!is_array($definition) || !$wants($definition) || !SqlLiterals::mentions($sql, (string) $column)) {
                    continue;
                }

                $out[] = ['table' => $table, 'column' => (string) $column, 'definition' => $definition];
            }
        }

        return $out;
    }

    /**
     * The reachable column a compared reference points at, or null.
     *
     * `o.status` is resolved through the statement's own FROM/JOIN aliases,
     * so it reaches the table `o` stands for and no other. A bare `status`
     * resolves only when exactly one reachable table has that column - two is
     * ambiguous, and an ambiguous reference is never rewritten.
     *
     * @param  array<int, array{table: string, column: string, definition: array<string, mixed>}>  $reachable
     * @param  array<string, string>  $tables  from SqlLiterals::tableAliases()
     * @return array{table: string, column: string, definition: array<string, mixed>}|null
     */
    protected function resolveComparedColumn(?string $reference, array $reachable, array $tables): ?array
    {
        if ($reference === null || $reference === '') {
            return null;
        }

        $parts = explode('.', $reference);
        $column = strtolower((string) array_pop($parts));
        $qualifier = $parts === [] ? null : strtolower((string) end($parts));

        $matches = [];

        foreach ($reachable as $reach) {
            if (strtolower($reach['column']) !== $column) {
                continue;
            }

            if ($qualifier !== null) {
                $table = $tables[$qualifier] ?? null;

                if ($table === null || $this->shortTableName($table) !== $this->shortTableName($reach['table'])) {
                    continue;
                }
            }

            $matches[$reach['table']] = $reach;
        }

        return count($matches) === 1 ? array_values($matches)[0] : null;
    }

    /** `schema.table` and `table` alike become `table`, lower-cased. */
    protected function shortTableName(string $table): string
    {
        return strtolower(str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table);
    }

    /**
     * Whether rows carry no answer: none at all, or a single row whose every
     * cell is NULL or zero - what an ungrouped SUM or COUNT returns when its
     * filter matched nothing.
     *
     * Only ever the trigger for an ATTEMPT at correction. A zero that is true
     * - the filtered value really is stored - is left alone by the correction
     * itself, which never touches a stored value.
     *
     * @param  array<int, mixed>  $rows
     */
    protected function answerCarriesNoData(array $rows): bool
    {
        if ($rows === []) {
            return true;
        }

        if (count($rows) !== 1) {
            return false;
        }

        foreach ((array) $rows[0] as $cell) {
            if ($cell !== null && !(is_numeric($cell) && (float) $cell === 0.0)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A filter value that matched nothing because it was misspelled, corrected
     * to the closest value ITS column actually holds - for columns whose
     * schema sets `correct_typos`.
     *
     * Without this, "stock of Keybord" answers "no data", which is not true:
     * the data is there and the spelling is not.
     *
     * Each value is checked only against the column it is compared with. The
     * first version compared every value with every opted-in column, so a
     * value filtered on `category` could be "corrected" into a product name.
     *
     * PRIVACY. The stored values are read HERE, through the connection the
     * answer itself ran on, and compared here. They are never sent to the
     * model and no provider call is made - the corrected statement is the old
     * one with a value changed, not a new generation.
     *
     * Bounded to one extra run: the corrected statement goes back through
     * validateAndExecute() - validated, required_filter checked - carrying a
     * flag that stops it correcting again.
     */
    protected function correctMisspelledValue(array $queryResult, ?string $dataset, array $metadata, ?string $connection): ?array
    {
        if (($metadata['_value_corrected'] ?? false) || !config('jeeves.value_correction.enabled', true)) {
            return null;
        }

        $sql = (string) ($queryResult['sql'] ?? '');

        $reachable = $this->reachableColumns(
            $sql,
            fn (array $definition) => ($definition['correct_typos'] ?? false) === true
        );

        if ($reachable === []) {
            return null;
        }

        $tables = SqlLiterals::tableAliases($sql);

        // Each value the statement filters on, grouped by the column it is
        // compared with - read through the same walk that will rewrite them,
        // so what is checked is exactly what gets changed.
        $targets = [];

        $this->rewriteQueryValues(
            $queryResult,
            function (string $plain, ?string $reference) use ($reachable, $tables, &$targets): ?string {
                $target = $this->resolveComparedColumn($reference, $reachable, $tables);

                if ($target !== null && trim($plain) !== '') {
                    $key = $target['table'] . "\0" . $target['column'];
                    $targets[$key]['reach'] = $target;
                    $targets[$key]['values'][trim($plain)] = true;
                }

                return null;
            }
        );

        if ($targets === []) {
            return null;
        }

        $maxDistinct = max(1, (int) config('jeeves.value_correction.max_distinct', 1000));
        $maxDistance = max(0, (int) config('jeeves.value_correction.max_distance', 2));

        $corrections = [];

        foreach ($targets as $key => $target) {
            $stored = $this->storedValues($connection, $target['reach'], $maxDistinct);

            if ($stored === null) {
                continue;
            }

            foreach (array_keys($target['values']) as $value) {
                $to = $this->closestStoredValue((string) $value, $stored, $maxDistance);

                if ($to !== null) {
                    $corrections[$key][(string) $value] = $to;
                }
            }
        }

        if ($corrections === []) {
            return null;
        }

        $applied = [];

        $corrected = $this->rewriteQueryValues(
            $queryResult,
            function (string $plain, ?string $reference) use ($reachable, $tables, $corrections, &$applied): ?string {
                $target = $this->resolveComparedColumn($reference, $reachable, $tables);

                if ($target === null) {
                    return null;
                }

                $to = $corrections[$target['table'] . "\0" . $target['column']][trim($plain)] ?? null;

                if ($to === null) {
                    return null;
                }

                $applied[$plain . "\0" . $to . "\0" . $target['column']] = [
                    'from' => $plain,
                    'to' => $to,
                    'column' => $target['column'],
                ];

                return $to;
            }
        );

        Log::info('[Jeeves] Corrected a filter value that matched nothing', [
            'corrections' => count($applied),
        ]);

        $metadata['_value_corrected'] = true;
        $metadata['value_corrections'] = array_values($applied);

        return $this->validateAndExecute($corrected, $dataset, $metadata);
    }

    /**
     * The distinct values one column holds, read through the connection the
     * answer ran on - or null when they cannot be read, or there are more of
     * them than max_distinct, in which case the column is not a list of names
     * and a nearest match in it means nothing.
     *
     * @param  array{table: string, column: string, definition: array<string, mixed>}  $reach
     * @return array<int, string>|null
     */
    protected function storedValues(?string $connection, array $reach, int $maxDistinct): ?array
    {
        try {
            $list = DB::connection($connection)
                ->table($reach['table'])
                ->select($reach['column'])
                ->distinct()
                ->whereNotNull($reach['column'])
                ->limit($maxDistinct + 1)
                ->pluck($reach['column'])
                ->all();
        } catch (\Throwable $e) {
            Log::info('[Jeeves] Value correction could not read a column', [
                'column' => $reach['column'],
                'error' => $this->sanitizeDbError($e->getMessage()),
            ]);

            return null;
        }

        if (count($list) > $maxDistinct) {
            Log::info('[Jeeves] Value correction skipped a column with too many values', [
                'column' => $reach['column'],
                'max_distinct' => $maxDistinct,
            ]);

            return null;
        }

        return array_values(array_filter(
            array_map(fn ($v) => is_scalar($v) ? (string) $v : null, $list),
            fn ($v) => $v !== null && $v !== ''
        ));
    }

    /**
     * The one stored value a typed value most plausibly meant, or null.
     *
     * In order: present exactly - nothing to correct, the empty answer is
     * real. The same value in a different case - the commonest empty result on
     * a case-sensitive database, and safe at any length. Otherwise an edit or
     * two away, for values of five letters or more only, because short values
     * are where a near-miss stops being a typo and starts being a different
     * value. Anything two stored values match equally well returns null.
     *
     * @param  array<int, string>  $stored  one column's values
     */
    protected function closestStoredValue(string $value, array $stored, int $maxDistance): ?string
    {
        if (in_array($value, $stored, true)) {
            return null;
        }

        $lower = mb_strtolower($value);
        $caseHits = array_values(array_unique(array_filter(
            $stored,
            fn (string $candidate) => mb_strtolower($candidate) === $lower
        )));

        if (count($caseHits) === 1) {
            return $caseHits[0];
        }

        if ($caseHits !== []) {
            return null;
        }

        $length = mb_strlen($lower);

        if ($length < 5 || $maxDistance < 1) {
            return null;
        }

        $allowed = min($maxDistance, $length >= 9 ? 2 : 1);
        $best = null;
        $bestDistance = PHP_INT_MAX;
        $tied = false;

        foreach ($stored as $candidate) {
            $distance = levenshtein($lower, mb_strtolower($candidate));

            if ($distance > $allowed) {
                continue;
            }

            if ($distance < $bestDistance) {
                $best = $candidate;
                $bestDistance = $distance;
                $tied = false;
            } elseif ($distance === $bestDistance && $candidate !== $best) {
                $tied = true;
            }
        }

        return $tied ? null : $best;
    }

    protected function validateAndExecute(array $queryResult, ?string $dataset, array $metadata): array
    {
        $sql = $queryResult['sql'];

        // `query_type` decides how the answer is WORDED, and every route ends
        // up here, so this is where it is made canonical for presentation.
        //
        // normalizeIntent() covers the intent route, but only that route: it
        // is reached from processWithIntent alone, so sql_generation, the
        // refinement retry and the cached `_sql_result` replay all handed
        // ResponseFormatter whatever the model said. A model answering
        // "Aggregation" on the SQL route produced a row labelled with the
        // no-label sentinel — "- : 800 (revenue)" — and the recipe was then
        // cached, so that wording survived every later ask of the question.
        //
        // The package's own SQL prompts asked for `overview`, which is not a
        // value anything here supports; that is fixed in PromptBuilder, and
        // this catches whatever a model invents regardless.
        $queryResult['query_type'] = $this->normalizeQueryType($queryResult['query_type'] ?? null);

        // Value aliases (a column's `value_aliases` in its schema file): a
        // renamed or variant-spelled value becomes the one the database
        // stores. HERE and nowhere else, for the reason required_filter is
        // checked below: this is the single place SQL runs, so the model's
        // literals, intent mode's bindings, the verifier's rewrite, a cached
        // recipe and each step of a decomposed question all pass through it.
        //
        // Before validation, so the statement validated is the statement run,
        // and before lastSql, so the event describes the query that RAN.
        // Pinned SQL runs exactly as it was reviewed: no value rewritten, no
        // correction, no shape retry. It is still validated below.
        $pinned = (bool) ($metadata['pinned_query'] ?? false);
        [$queryResult, $aliased] = $pinned ? [$queryResult, []] : $this->applyValueAliases($queryResult);
        $sql = $queryResult['sql'];

        if ($aliased !== []) {
            $metadata['value_aliases_applied'] = $aliased;
        }

        // Remembered for the QuestionAnswered event, which is server-side.
        // The SQL is deliberately kept OUT of the HTTP response -  a browser has
        // no use for it and it describes the shape of the database -  but it is
        // the single most useful field a listener can log.
        $this->lastSql = $sql;

        // Validate SQL security
        $allowedTables = $this->registry->getAllowedTables();
        $maxLimit = $dataset ? $this->registry->getMaxLimit($dataset) : config('jeeves.sql.max_limit');
        $requireLimit = $maxLimit !== null;

        $validation = $this->validator->validate($sql, $allowedTables, [
            'max_limit' => $maxLimit,
            'require_limit' => $requireLimit,
        ]);

        if (!$validation['valid']) {
            Log::warning('[Jeeves] SQL validation failed', ['sql' => $sql, 'reason' => $validation['reason']]);

            // A security signal, so it gets its own event rather than being
            // one flavour of failure. Under normal use it should almost never
            // fire; a burst from one user is worth looking at.
            Event::dispatch(new UnsafeSqlRejected(
                question: (string) ($metadata['original_query'] ?? $queryResult['question'] ?? ''),
                sql: $sql,
                reason: (string) $validation['reason'],
                provider: $this->llmProvider->getName(),
            ));

            return $this->formatter->formatError('Query validation failed: ' . $validation['reason'], $metadata, ErrorCode::UNSAFE_SQL);
        }

        // A required_filter is a RULE, and it is checked HERE because this is
        // the only place SQL is executed.
        //
        // It was checked at the two sites where SQL is generated, which left
        // three ways past it. QueryVerifier rewrites `sql` AFTER generation and
        // its fix is trusted unchecked, so a rewrite could drop the filter; the
        // rewritten SQL is then stored as the `_sql_result` recipe, and the
        // replay branch hands that straight to this method with no guard on the
        // way; and a step of a decomposed question re-enters by yet another
        // path. Every one of them arrives here.
        //
        // Placed after validation so genuinely unsafe SQL is still reported as
        // unsafe, and before execution because the whole point of the setting
        // is that the answer is wrong without it. _unretriable, because the
        // retry regenerates from the same prompt carrying the same REQUIRED
        // FILTER line the model has already ignored -  the first version of this
        // fix bounced into retryWithRefinedPrompt and returned the unfiltered
        // total as a success.
        if ($refusal = $this->requiredFilterMissing($sql, $dataset ? $this->registry->get($dataset) : null)) {
            return array_merge(
                $this->formatter->formatError($refusal, $metadata, ErrorCode::CANNOT_ANSWER),
                ['_unretriable' => true]
            );
        }

        // NQ-001. There is no DB::select() fallback here, deliberately: the
        // bare call runs on the application's own connection, which is the
        // finding. Resolution and refusal happen together in
        // ExecutionConnection, so no caller can obtain a name without passing
        // the check, and a future execution site cannot reintroduce the hole
        // by forgetting a guard.
        $bindings = $queryResult['bindings'] ?? [];

        try {
            $connection = ExecutionConnection::resolve(
                $dataset ? $this->registry->getConnection($dataset) : null
            );
        } catch (UnsafeConnectionException $e) {
            Log::error('[Jeeves] Refusing to execute generated SQL', ['reason' => $e->getMessage()]);

            return $this->formatter->formatError($e->getMessage(), $metadata, ErrorCode::INTERNAL);
        }

        try {
            $rows = DB::connection($connection)->select($sql, $bindings);
        } catch (\Exception $e) {
            // NQ-006. Neither the statement nor the driver's message may go to
            // the log as-is: on the sql_generation route the model writes every
            // literal inline, and Laravel appends the statement AGAIN to a
            // QueryException message with the bindings already interpolated.
            // The log is a different audience with a different retention
            // policy from the person who asked the question.
            Log::error('[Jeeves] SQL execution failed', [
                'sql' => config('jeeves.sql.log_failing_statement', false)
                    ? $sql
                    : $this->maskLiterals($sql),
                'error' => $this->sanitizeDbError($e->getMessage()),
            ]);

            return $this->formatter->formatError('Database query failed: ' . $this->sanitizeDbError($e->getMessage()), $metadata, ErrorCode::DATABASE_ERROR);
        }

        // A filter value that matched nothing because it was misspelled.
        // Opt-in per column (`correct_typos`), bounded to one extra run, and
        // entirely local - see correctMisspelledValue(). Tested before the
        // empty-result branch, because matching nothing is not only zero
        // rows: an ungrouped SUM over no rows is one row of NULL, and a COUNT
        // is one row of 0. The first version waited for zero rows and missed
        // the question the feature was written for.
        if (!$pinned
            && $this->answerCarriesNoData($rows)
            && ($corrected = $this->correctMisspelledValue($queryResult, $dataset, $metadata, $connection))) {
            return $corrected;
        }

        // Empty results
        if (empty($rows)) {
            $response = $this->formatter->formatNoData($queryResult);
            $response['metadata'] = $metadata;

            return $response;
        }

        // Execution-guided retry. The SHAPE of what came back can be wrong
        // when nothing about the SQL was, and that is invisible until now:
        // "which carrier shipped the most orders" is ORDER BY ... LIMIT 1, and
        // without the LIMIT the top row is right while every row under it is
        // an answer to a question nobody asked.
        //
        // It lives here because this is the only place SQL executes. Attached
        // to the generation sites instead it would miss the verifier's
        // rewrite, the cached recipe replayed later, and each step of a
        // decomposed question - the guard-at-the-call-site mistake that
        // produced four of the findings in the last audit.
        //
        // PRIVACY. The row count is read on this server and never leaves it.
        // The regenerated prompt carries the question and the schema, exactly
        // as the first one did, plus one sentence saying the shape was wrong.
        // No value, no row, no count, and no driver message.
        if (!$pinned && ($retried = $this->retryForShape($queryResult, $rows, $dataset, $metadata))) {
            return $retried;
        }

        // Format response
        $response = $this->formatter->format($queryResult, $rows);
        $response['metadata'] = array_merge($metadata, [
            'dataset_name' => $queryResult['dataset_name'] ?? null,
            'metric_unit' => $queryResult['metric_unit'] ?? null,
        ]);

        // How the question was understood, in one readable line:
        //   "Orders · revenue · by region · status is pending"
        //
        // `parsed_query` has carried all of this since 1.0.0, as structure a
        // client has to assemble itself -  and the bundled widget did not, so
        // the one thing that makes a misreading visible was visible only to
        // people writing their own front end. A conversation turn got a
        // summary from ConversationManager; an ordinary question, which is the
        // first thing anyone asks, got nothing.
        //
        // Roughly one question in five is misread on an uncurated schema.
        // Rendering the interpretation next to the number is the cheapest
        // defence there is against the failure §0 names as the worst: not that
        // the package is sometimes wrong, but that it is wrong without saying
        // so.
        //
        // The slots are mapped EXPLICITLY rather than by handing the result
        // array straight to fromIntent(). The two shapes look alike and are
        // not: fromIntent() reads `group_by`, `date_from` and `date_to`, and a
        // result array carries `group_column` and `time_filter` instead. It
        // was handed over whole, so the breakdown and the period were not
        // merely sometimes missing -  they could never appear, and a month's
        // total printed the same sentence as an all-time one.
        // The breakdown and the period come from the BUILDER, which wrote the
        // SQL and therefore knows what it did. Both are absent on the
        // sql_generation route, where the statement is the model's.
        //
        // An earlier version recovered them by pattern-matching the finished
        // SQL, and that cannot be done safely: a GROUP BY inside a CTE was
        // read as the answer's dimension and printed "by region" over a single
        // ungrouped total, and any mention of the date column after WHERE —
        // including an ORDER BY — was read as a period, so an all-time total
        // was captioned with a month the model had merely claimed. Under
        // Rule 8 the honest move is to report what is known and stay silent
        // otherwise; a caption that might be false is worse than none, because
        // this line exists to be trusted.
        $dimension = $queryResult['grouped_by'] ?? null;

        // `time_filter` is only trustworthy when the builder resolved the
        // period itself, and `time_column` is set on exactly that path.
        $period = isset($queryResult['time_column'])
            ? ($queryResult['time_filter'] ?? null)
            : null;

        // Both surfaces get the same two values. docs/API.md describes
        // `group_by` as what the rows ARE and `period` as the dates actually
        // applied; they were the schema default and the model's claim.
        if (isset($response['parsed_query'])) {
            $response['parsed_query']['group_by'] = $dimension;
            $response['parsed_query']['period'] = $period;
        }

        $summary = QueryState::fromIntent([
            'dataset' => $queryResult['dataset'] ?? null,
            'metric' => $queryResult['metric'] ?? null,
            'group_by' => $dimension,
            'filter_column' => $queryResult['filter_column'] ?? null,
            'filters' => $queryResult['filters'] ?? [],
            'group_value' => $queryResult['group_value'] ?? null,
            'period' => $period,
            'order' => $queryResult['order'] ?? null,
            'limit' => $queryResult['limit'] ?? null,
            'query_type' => $queryResult['query_type'] ?? null,
        ])->summary($this->registry);

        if ($summary !== '') {
            $response['parsed_summary'] = $summary;
        }

        // What to ask next. Derived from the schema, so it costs no API call.
        if ($this->suggester) {
            $nextSteps = $this->suggester->suggest($queryResult, $rows);

            if ($nextSteps) {
                $response['next_steps'] = $nextSteps;
            }
        }

        return $response;
    }

    /**
     * Replace computed metric names with their SQL expressions.
     * Safety net if AI uses a computed metric name as a column name.
     */
    protected function replaceComputedMetrics(string $sql, string $dataset): string
    {
        $computed = $this->registry->getComputedMetrics($dataset);
        if (empty($computed)) {
            return $sql;
        }

        foreach ($computed as $metricName => $metricData) {
            $expression = $metricData['expression'];

            // Replace in ORDER BY
            $sql = preg_replace(
                '/\bORDER\s+BY\s+' . preg_quote($metricName, '/') . '\b/i',
                'ORDER BY ' . $expression,
                $sql
            );

            // Replace in SELECT (if used as column name without AS)
            if (preg_match('/\bSELECT\b.*\b' . preg_quote($metricName, '/') . '\b.*\bFROM\b/is', $sql)) {
                if (!preg_match('/AS\s+' . preg_quote($metricName, '/') . '\b/i', $sql)) {
                    $sql = preg_replace(
                        '/(\bSELECT\s+(?:.*?,\s*)?)' . preg_quote($metricName, '/') . '(\s*(?:,|\s+FROM\b))/i',
                        '$1' . $expression . ' AS ' . $metricName . '$2',
                        $sql
                    );
                }
            }
        }

        return $sql;
    }

    /**
     * Resolve metric metadata from schema config.
     */
    protected function resolveMetricData(string $dataset, string $metric): ?array
    {
        $metrics = $this->registry->getMetrics($dataset);
        if (isset($metrics[$metric])) {
            return $metrics[$metric];
        }

        $computed = $this->registry->getComputedMetrics($dataset);
        if (isset($computed[$metric])) {
            return $computed[$metric];
        }

        // Try alias resolution
        $resolved = $this->registry->resolveMetric($dataset, $metric);
        if ($resolved) {
            return $metrics[$resolved] ?? $computed[$resolved] ?? null;
        }

        return null;
    }

    /**
     * Should we verify the AI-generated SQL?
     */
    protected function shouldVerify(array $metadata): bool
    {
        if (!config('jeeves.verification.enabled', true)) {
            return false;
        }
        if (config('jeeves.verification.skip_on_cache_hit', true) && ($metadata['cache_hit'] ?? false)) {
            return false;
        }
        if ($metadata['_retried'] ?? false) {
            return false;
        }

        return true;
    }

    /**
     * Replace every literal in a statement with `?`.
     *
     * Keeps the shape that makes an error diagnosable - which table, which
     * column, which clause - and drops the part that makes it sensitive. The
     * string pattern understands doubled quotes, so `'it''s'` is one literal
     * rather than two fragments with a stray tail.
     *
     * Numbers go too. `WHERE account_id = 4471` identifies a person as surely
     * as their name does, and `LIMIT ?` costs a reader nothing.
     */
    protected function maskLiterals(string $sql): string
    {
        $masked = preg_replace("/'(?:[^']|'')*'/", '?', $sql);
        $masked = preg_replace('/\b\d+(?:\.\d+)?\b/', '?', $masked ?? $sql);

        // A failed pattern returns null, and a null here would log nothing at
        // all. Reporting the statement shape is the point, so fall back to
        // saying it could not be masked rather than to the raw statement.
        return $masked ?? '(statement withheld: could not be masked)';
    }

    /**
     * Sanitize database error messages for user display.
     */
    protected function sanitizeDbError(string $message): string
    {
        // Keep the human-readable part, drop the SQL and the bindings.
        //
        // Three dialects say it three ways, and only one of them used to come
        // through. PostgreSQL writes `ERROR:  relation "x" does not exist` but
        // follows it with a newline and a LINE marker, so a pattern without
        // /s stopped at the right place by luck. MySQL writes
        // `SQLSTATE[42S02]: Base table or view not found: 1146 Table 'a.b'
        // doesn't exist` and carries no ERROR: token at all, so every MySQL
        // fault reduced to the generic sentence - on the stack this package is
        // most often installed on.
        //
        // That mattered more once a database error stopped being reworded into
        // "could not understand the query": the message a user now sees is
        // this one, so it has to say something.
        // Never echo the statement back - and strip it BEFORE matching, not
        // out of the captured group afterwards. Laravel appends "(Connection:
        // mysql, SQL: select ...)" with the bindings already interpolated, so
        // matching first ran the patterns over the user's own query text: a
        // question filtering on a value containing "error:" put that value
        // where the cause belongs, and the user was shown a fragment of their
        // own question instead of what went wrong.
        $message = preg_replace('/\s*\((?:Connection:|SQL:).*$/is', '', $message) ?? $message;

        $patterns = [
            // PostgreSQL. The lookbehind is what stops it claiming the other
            // dialects' messages: SQLite says "General error: 1 no such table"
            // and MySQL "Grouping error: 7 ...", and a bare /ERROR:/i matched
            // the token inside those words, so the SQLSTATE branch below never
            // ran for them. PostgreSQL's own marker follows a number ("...: 7
            // ERROR:  relation"), never a word.
            //
            // No "(" terminator either. It truncated the cause at the first
            // parenthesis, so "function sum(character varying) does not exist"
            // reached the user as "function sum" - which reads like a column
            // name rather than an error. The statement is already gone by here.
            '/(?<![a-z] )ERROR:\s*(.+?)(?:\r?\n|$)/i',
            '/SQLSTATE\[[^\]]+\]:\s*(?:[^:]+:\s*)?(?:\d+\s+)?(.+?)(?:\r?\n|$)/i', // MySQL, SQLite
        ];

        foreach ($patterns as $pattern) {
            if (!preg_match($pattern, $message, $m) || trim($m[1]) === '') {
                continue;
            }

            // Drivers prefix their own error number; it means nothing here.
            $clean = preg_replace('/^\d+\s+/', '', trim($m[1])) ?? '';

            if ($clean !== '' && $this->isSafeToShow($clean)) {
                return $clean;
            }
        }

        return 'An error occurred while querying the database.';
    }

    /**
     * Is this driver message about the SCHEMA, or about a row?
     *
     * Extracting the cause made the message useful and also made it a way for
     * a data value to reach a user and a log. MySQL says
     * `Truncated incorrect DOUBLE value: 'Rekha Stores'` and PostgreSQL says
     * `invalid input syntax for type numeric: "N/A"`, and both are reachable
     * from a SELECT with an implicit cast. The value is the customer's, and
     * it lands in QuestionFailed, which most apps forward to a log drain.
     *
     * Listing the leaky shapes was the obvious fix and it is the wrong one.
     * Three drivers, an open-ended vocabulary, and the first message nobody
     * anticipated is a leak with no test to catch it - the same trap the SQL
     * function denylist fell into three times.
     *
     * So this is an allowlist of shapes that talk about STRUCTURE: a missing
     * table, an unknown column, a bad function signature, a GROUP BY mistake,
     * a syntax error. Anything else falls back to the generic sentence. The
     * cost is a less specific message for an error nobody anticipated; the
     * alternative is a value nobody anticipated, forwarded to a log.
     *
     * The raw message is still logged server-side, where it belongs.
     */
    private function isSafeToShow(string $cause): bool
    {
        foreach ([
            '/\bno such (table|column|function)\b/i',
            '/\bTable\b.*\bdoesn\x27t exist\b/i',
            '/\brelation\b.*\bdoes not exist\b/i',
            '/\bUnknown (column|table)\b/i',
            '/\bcolumn\b.*\bdoes not exist\b/i',
            '/\bambiguous\b/i',
            '/\bmust appear in the GROUP BY clause\b/i',
            '/\bfunction\b.*\bdoes not exist\b/i',
            '/\bwrong number of arguments\b/i',
            '/\bsyntax error\b/i',
            '/\bmisuse of aggregate\b/i',
            '/\baggregate functions are not allowed\b/i',
            '/\bNOT NULL constraint failed\b/i',
            '/\bdivision by zero\b/i',
        ] as $shape) {
            if (preg_match($shape, $cause)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Audit log for successful queries.
     */
    /**
     * Everything an answer gets on the way out, whichever route produced it:
     * internal flags removed, timing and provider added, usage when known, the
     * audit log, and the QuestionAnswered / QuestionFailed announcement.
     *
     * One method because a second way out of query() - pinned queries are the
     * first - that copied these lines would be one more place to keep in step.
     * The early exits above show how that goes: each one had to be taught to
     * announce itself separately, and one of them was not.
     */
    protected function finishQuestion(string $question, array $result, bool $cacheHit, float $startTime): array
    {
        // Remove internal flags
        unset($result['_fallback_eligible'], $result['_rate_limited'], $result['_unretriable'], $result['_name_unmatched']);

        $result['metadata'] = array_merge($result['metadata'] ?? [], [
            'processing_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
            'cache_hit' => $cacheHit,
            'provider' => $this->llmProvider->getName(),
        ]);

        // What this answer cost. Absent for a cache hit, which is the point of
        // the cache, and absent for providers that report no usage - an
        // omitted figure is honest, a zero is not.
        if ($usage = $this->usageForThisQuestion()) {
            $result['metadata']['usage'] = $usage;
        }

        if (config('jeeves.privacy.audit_queries', true) && ($result['status'] ?? '') === 'success') {
            $this->auditLog($result, $cacheHit, $startTime);
        }

        if (!$this->inStepExecution) {
            $this->announceOutcome($question, $result, $cacheHit, $startTime);
        }

        return $result;
    }

    /**
     * The pinned query an install declared for this exact question, or null.
     *
     * "Exact" ignores case, punctuation and spacing - "Revenue by status?" and
     * "revenue  by status" are one question - and nothing else. No fuzziness:
     * a pinned query exists to be predictable, and a near-miss that silently
     * ran reviewed SQL for a different question would be the opposite.
     *
     * @return array{sql: string, dataset: ?string, metric: ?string, query_type: ?string}|null
     */
    protected function pinnedQueryFor(string $question): ?array
    {
        $pinned = config('jeeves.pinned_queries', []);

        if (!is_array($pinned) || $pinned === []) {
            return null;
        }

        $asked = $this->normalisePinnedQuestion($question);

        if ($asked === '') {
            return null;
        }

        foreach ($pinned as $entry) {
            if (!is_array($entry) || !is_string($entry['sql'] ?? null) || trim($entry['sql']) === '') {
                continue;
            }

            foreach ((array) ($entry['question'] ?? []) as $phrasing) {
                if (!is_string($phrasing) || $this->normalisePinnedQuestion($phrasing) !== $asked) {
                    continue;
                }

                $dataset = $entry['dataset'] ?? null;

                if (!is_string($dataset) || !$this->registry->has($dataset)) {
                    $dataset = $this->datasetNamedBySql($entry['sql']);
                }

                return [
                    'sql' => $entry['sql'],
                    'dataset' => $dataset,
                    'metric' => isset($entry['metric']) ? (string) $entry['metric'] : null,
                    'query_type' => isset($entry['query_type']) ? (string) $entry['query_type'] : null,
                ];
            }
        }

        return null;
    }

    /** Lower-cased words joined by single spaces: case, punctuation and spacing ignored. */
    protected function normalisePinnedQuestion(string $text): string
    {
        return implode(' ', preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * The one registered dataset whose table a statement names, or null when
     * it names none or several. Used only when a pinned entry does not say -
     * it decides which connection the SQL runs on, so a guess between two is
     * not made.
     */
    protected function datasetNamedBySql(string $sql): ?string
    {
        $found = [];

        foreach ($this->registry->all() as $key => $schema) {
            $table = (string) ($schema['tables']['primary']['name'] ?? '');
            $short = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;

            if ($short !== '' && SqlLiterals::mentions($sql, $short)) {
                $found[] = (string) $key;
            }
        }

        return count($found) === 1 ? $found[0] : null;
    }

    /**
     * Tell the application how the question ended.
     *
     * A clarification is neither: being asked which measure you meant is the
     * system working, and firing a failure event for it would fill an alerting
     * channel with successes.
     *
     * @param  array<string, mixed>  $result
     */
    protected function announceOutcome(string $question, array $result, bool $cacheHit, float $startTime): void
    {
        $status = $result['status'] ?? null;
        $durationMs = round((microtime(true) - $startTime) * 1000, 2);
        $metadata = $result['metadata'] ?? [];

        if ($status === 'success') {
            Event::dispatch(new QuestionAnswered(
                question: $question,
                parsedQuery: $result['parsed_query'] ?? [],
                sql: $result['sql'] ?? $this->lastSql,
                rowCount: count($result['rows'] ?? []),
                modeUsed: $metadata['query_mode_used'] ?? null,
                cacheHit: $cacheHit,
                durationMs: $durationMs,
                provider: $this->llmProvider->getName(),
                usage: $metadata['usage'] ?? [],
            ));

            return;
        }

        if ($status === 'error') {
            Event::dispatch(new QuestionFailed(
                question: $question,
                errorCode: $result['error_code'] ?? ErrorCode::INTERNAL,
                message: (string) ($result['error'] ?? ''),
                provider: $this->llmProvider->getName(),
                durationMs: $durationMs,
            ));
        }
    }

    /**
     * Tokens spent answering the question in flight, if the provider says.
     *
     * @return array<string, int>
     */
    protected function usageForThisQuestion(): array
    {
        return $this->llmProvider instanceof ReportsUsage
            ? $this->llmProvider->lastUsage()
            : [];
    }

    protected function auditLog(array $result, bool $cacheHit, float $startTime): void
    {
        $channel = config('jeeves.privacy.audit_channel');
        $logger = $channel ? Log::channel($channel) : Log::getFacadeRoot();
        $logger->info('[Jeeves] Query executed', [
            'dataset' => $result['parsed_query']['dataset'] ?? null,
            'query_type' => $result['parsed_query']['query_type'] ?? null,
            'metric' => $result['parsed_query']['metric'] ?? null,
            'rows' => count($result['rows'] ?? []),
            'cache_hit' => $cacheHit,
            'mode' => $result['metadata']['query_mode_used'] ?? 'unknown',
            'processing_ms' => round((microtime(true) - $startTime) * 1000, 2),
            // In the audit line as well as the response, so cost is answerable
            // from the logs without every caller having to store it.
            'usage' => $result['metadata']['usage'] ?? null,
        ]);
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    public function getDatasets(): array
    {
        return $this->registry->getAvailableDatasets();
    }

    /**
     * The schema registry this orchestrator resolves against.
     *
     * Exposed so callers describing what can be asked -  the /datasets endpoint,
     * a custom front end -  read the same registry the engine answers from,
     * rather than a copy that can disagree with it.
     */
    public function registry(): SchemaRegistry
    {
        return $this->registry;
    }

    public function getDatasetMetrics(string $datasetKey): array
    {
        return $this->registry->getDatasetMetrics($datasetKey);
    }

    public function healthCheck(): array
    {
        $providerHealth = $this->llmProvider->healthCheck();
        $cacheStats = $this->cache->getStatistics();

        return [
            'status' => $providerHealth['status'] === 'ok' ? 'healthy' : 'degraded',
            'provider' => [
                'name' => $this->llmProvider->getName(),
                'status' => $providerHealth['status'],
                'model' => $providerHealth['model'] ?? null,
            ],
            'cache' => [
                'enabled' => ($cacheStats['enabled'] ?? false),
                'total_entries' => $cacheStats['total_entries'] ?? 0,
                'total_hits' => $cacheStats['total_hits'] ?? 0,
            ],
            'schemas' => [
                'loaded' => count($this->registry->all()),
                'keys' => $this->registry->keys(),
            ],
            'query_mode' => config('jeeves.query_mode', 'auto'),
            'security' => [
                'input_guard' => true,
                'sql_validator' => true,
                'ai_guard' => $this->inputGuard->hasAiGuard(),
                'rate_limiting' => str_contains(implode(',', config('jeeves.routes.middleware', [])), 'throttle'),
            ],
            'verification' => [
                'enabled' => config('jeeves.verification.enabled', true),
                'confidence_threshold' => config('jeeves.verification.confidence_threshold', 0.7),
            ],
        ];
    }

    public function getCacheStats(): array
    {
        return $this->cache->getStatistics();
    }

    public function clearCache(?string $dataset = null, int $olderThanDays = 0, int $minHits = 0): int
    {
        return $this->cache->clear($dataset, $olderThanDays, $minHits);
    }
}
