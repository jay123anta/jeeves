<?php

namespace Jayanta\Jeeves\Conversation;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Schema\SchemaRegistry;

/**
 * Conversation Manager
 *
 * Enables multi-turn conversations where follow-up queries
 * inherit context from previous queries.
 *
 * Example, using whatever datasets the application actually registers:
 *   Turn 1: "top customers by revenue in orders"  → dataset=orders, metric=revenue
 *   Turn 2: "now filter by North"                 → keeps dataset and metric, adds the filter
 *   Turn 3: "compare with South"                  → keeps dataset and metric, compares the two
 *   Turn 4: "what about inventory"                → switches dataset, keeps metric where it applies
 *
 * Nothing here knows any domain vocabulary: dataset names and aliases come
 * from the schema registry, so this works the same on any application.
 *
 * Context is stored per-session in cache with a configurable TTL.
 */
class ConversationManager
{
    protected QueryOrchestrator $orchestrator;

    protected SchemaRegistry $registry;

    protected TurnClassifier $classifier;

    protected StateValidator $validator;

    protected int $contextTtl;

    /**
     * Versioned because the stored state is a slot object, and 2.0.0 renamed
     * the `scheme` slot to `dataset`. Without the suffix, a conversation in
     * flight when the deploy lands is read back with a slot the validator no
     * longer knows, and the next follow-up answers "which dataset?" instead of
     * narrowing. Changing the prefix abandons those states, which is the right
     * outcome: a new topic works immediately.
     */
    protected string $cachePrefix = 'nq_conv:v2:';

    public function __construct(
        QueryOrchestrator $orchestrator,
        SchemaRegistry $registry,
        ?TurnClassifier $classifier = null,
        ?StateValidator $validator = null
    ) {
        $this->orchestrator = $orchestrator;
        $this->registry = $registry;
        $this->classifier = $classifier ?? new TurnClassifier($registry);
        $this->validator = $validator ?? new StateValidator($registry);
        $this->contextTtl = config('jeeves.conversation.ttl', 1800); // 30 minutes
    }

    /**
     * Process a query within a conversation context.
     *
     * @param  string  $sessionId  Unique session identifier (e.g., user ID, session token)
     * @param  string  $query  The user's natural language query
     * @param  string|null  $datasetHint  Optional explicit dataset
     * @return array Response with conversation metadata
     */
    public function query(string $sessionId, string $query, ?string $datasetHint = null): array
    {
        $state = $this->getState($sessionId);
        $classification = $this->classifier->classify($query, $state);
        $seq = $state->seq + 1;

        // Ambiguity compounds. Past a handful of consecutive refinements nobody
        // remembers which filters are live, and resolution degrades faster than
        // the user notices -  so say so rather than resolving into nonsense.
        $cap = (int) config('jeeves.conversation.max_refinements', 6);

        if ($cap > 0 && $classification !== TurnClassifier::NEW_QUERY && $state->refinements >= $cap) {
            return $this->refinementCapReached($sessionId, $state, $seq, $classification);
        }

        // A new question inherits nothing; anything else resolves against the
        // state, which the model sees as a compact object rather than as a
        // transcript it has to re-read.
        $carried = $classification === TurnClassifier::NEW_QUERY ? new QueryState : $state;
        $result = $this->orchestrator->query(
            $query,
            $datasetHint ?? $carried->get('dataset'),
            $carried->isEmpty() ? [] : ['state' => $carried->toIntent(), 'summary' => $carried->summary($this->registry)]
        );

        $next = $this->advance($carried, $result, $classification, $seq, $query);

        // Nothing is asked of the database until every slot is a real one.
        $checked = $this->validator->validate($next);

        if (!$checked['valid'] && ($result['status'] ?? '') === 'success') {
            return $this->slotQuestion($sessionId, $state, $checked, $seq, $classification);
        }

        if ($checked['valid']) {
            $next = $checked['state'];
        }

        if (($result['status'] ?? '') === 'success') {
            $this->putState($sessionId, $next);
        }

        // The state goes to the log next to the SQL. When an answer is wrong,
        // the first question is whether the turn was misread or the state was
        // -  two different bugs with two different fixes, and no way to tell
        // them apart afterwards without both recorded together.
        Log::info('[Jeeves:Conversation] Turn resolved', [
            'session' => $this->scopeSession($sessionId),
            'seq' => $seq,
            'classification' => $classification,
            'state' => $next->toIntent(),
            'status' => $result['status'] ?? null,
        ]);

        $result['conversation'] = [
            'session_id' => $sessionId,
            'turn' => $seq,
            'classification' => $classification,
            'context_active' => !$carried->isEmpty(),
            'refinements' => $next->refinements,
            'can_rewind' => $seq > 1,
        ];

        // Shown above the answer, so a misread is caught rather than trusted.
        //
        // The period is labelled onto a COPY, for this render only. It
        // describes the answer just produced, not the conversation, so the
        // state that was persisted a few lines up must not carry it into the
        // next turn. Without this the widget — which prefers `state_summary`
        // whenever it is present — showed a strictly less informative line
        // than `parsed_summary` sitting in the same payload, and a month's
        // total and an all-time total again read identically.
        $result['state'] = $next->toIntent();
        $result['state_summary'] = $next
            ->withPeriod($result['parsed_query']['period'] ?? null)
            ->summary($this->registry);

        return $result;
    }

    /**
     * Step back to how things stood before the last turn.
     *
     * "No, go back to revenue" is a restore, not another interpretation -
     * every turn's state is kept, so returning to one is exact.
     */
    public function rewind(string $sessionId, int $steps = 1): array
    {
        $history = Cache::get($this->historyKey($sessionId), []);

        if (count($history) <= 1) {
            return ['status' => 'error', 'error' => 'There is nothing to go back to.'];
        }

        for ($i = 0; $i < max(1, $steps) && count($history) > 1; $i++) {
            array_pop($history);
        }

        $state = QueryState::fromArray(end($history) ?: null);

        Cache::put($this->historyKey($sessionId), $history, $this->contextTtl);
        Cache::put($this->cachePrefix . $this->scopeSession($sessionId), $state->toArray(), $this->contextTtl);

        // The same conversation block state() returns, plus the fact that this
        // one was a rewind. It used to report only the turn number, so a client
        // that had just gone back could not tell whether it could go back
        // again without asking a second time -  and the natural thing to do
        // with that missing answer is to leave the control enabled and let the
        // user find out by pressing it.
        return [
            'status' => 'success',
            'state' => $state->toIntent(),
            'state_summary' => $state->summary($this->registry),
            'conversation' => [
                'session_id' => $sessionId,
                'turn' => $state->seq,
                'rewound' => true,
                'refinements' => $state->refinements,
                'context_active' => !$state->isEmpty(),
                'can_rewind' => count($history) > 1,
                'max_refinements' => (int) config('jeeves.conversation.max_refinements', 6),
            ],
        ];
    }

    /** Build the state this turn should carry forward. */
    protected function advance(QueryState $carried, array $result, string $classification, int $seq, string $question = ''): QueryState
    {
        $parsed = $result['parsed_query'] ?? [];

        return match ($classification) {
            TurnClassifier::NEW_QUERY => QueryState::fromIntent($parsed, $seq),
            TurnClassifier::DRILL_DOWN => $carried->drillDown($parsed['group_by'] ?? null, $seq),
            // A reference asks about what is already on screen and changes
            // nothing about the query itself.
            TurnClassifier::REFERENCE => new QueryState($carried->toIntent(), $seq, $carried->refinements),
            default => $carried->merge($parsed, $seq, $question),
        };
    }

    protected function slotQuestion(string $sessionId, QueryState $state, array $failure, int $seq, string $classification): array
    {
        return [
            'status' => 'clarification_needed',
            'type' => 'slot_clarification',
            'message' => $this->validator->question($failure),
            'alternatives' => [],
            'available_metrics' => ($failure['slot'] ?? '') === 'metric'
                ? $this->registry->getDatasetMetrics((string) $state->get('dataset'))
                : [],
            'state' => $state->toIntent(),
            'state_summary' => $state->summary($this->registry),
            'conversation' => [
                'session_id' => $sessionId,
                'turn' => $seq,
                'classification' => $classification,
                'unresolved_slot' => $failure['slot'] ?? null,
            ],
        ];
    }

    protected function refinementCapReached(string $sessionId, QueryState $state, int $seq, string $classification): array
    {
        return [
            'status' => 'clarification_needed',
            'type' => 'refinement_cap',
            'message' => 'That is a lot of refinements on one question, and I am no longer confident '
                . 'I am carrying them all correctly. Ask it fresh, or start a new topic.',
            'alternatives' => [],
            'available_metrics' => [],
            'state' => $state->toIntent(),
            'state_summary' => $state->summary($this->registry),
            'conversation' => [
                'session_id' => $sessionId,
                'turn' => $seq,
                'classification' => $classification,
                'refinements' => $state->refinements,
                'cap_reached' => true,
            ],
        ];
    }

    protected function getState(string $sessionId): QueryState
    {
        return QueryState::fromArray(
            Cache::get($this->cachePrefix . $this->scopeSession($sessionId))
        );
    }

    protected function putState(string $sessionId, QueryState $state): void
    {
        Cache::put($this->cachePrefix . $this->scopeSession($sessionId), $state->toArray(), $this->contextTtl);

        $history = Cache::get($this->historyKey($sessionId), []);
        $history[] = $state->toArray();

        // Bounded: rewinding further than this is a new question anyway.
        Cache::put($this->historyKey($sessionId), array_slice($history, -12), $this->contextTtl);
    }

    protected function historyKey(string $sessionId): string
    {
        return $this->cachePrefix . 'hist:' . $this->scopeSession($sessionId);
    }

    /**
     * Scope session_id to the authenticated user.
     * Prevents one user from accessing another user's conversation.
     */
    protected function scopeSession(string $sessionId): string
    {
        $userId = auth()->id() ?? 'anon';

        return $userId . ':' . $sessionId;
    }

    /**
     * Clear conversation context (start fresh).
     */
    public function clearContext(string $sessionId): void
    {
        Cache::forget($this->cachePrefix . $this->scopeSession($sessionId));
    }

    /**
     * Get current context (for debugging / UI display).
     */
    /**
     * The conversation as it currently stands.
     *
     * A front end that reloads the page has lost everything it was showing, and
     * the state is server-side -  so without this it cannot restore the filters
     * in force or the "reading this as" line, and the next follow-up resolves
     * against context the user can no longer see. Shaped like the payload a
     * query returns, so the same rendering code handles both.
     */
    public function state(string $sessionId): array
    {
        $state = $this->getState($sessionId);
        $history = Cache::get($this->historyKey($sessionId), []);

        return [
            'status' => 'success',
            'state' => $state->toIntent(),
            'state_summary' => $state->summary($this->registry),
            'conversation' => [
                'session_id' => $sessionId,
                'turn' => $state->seq,
                'refinements' => $state->refinements,
                'context_active' => !$state->isEmpty(),
                'can_rewind' => count($history) > 1,
                'max_refinements' => (int) config('jeeves.conversation.max_refinements', 6),
            ],
        ];
    }

    /** @deprecated Use state(). Returns the raw cache shape. */
    public function peekContext(string $sessionId): array
    {
        return Cache::get($this->cachePrefix . $this->scopeSession($sessionId), []);
    }
}
