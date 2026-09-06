<?php

namespace Jayanta\Jeeves\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Jayanta\Jeeves\Contracts\SqlValidatorInterface;
use Jayanta\Jeeves\Conversation\ConversationManager;
use Jayanta\Jeeves\Engine\ErrorCode;
use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Feedback\FeedbackStore;
use Jayanta\Jeeves\Schema\SchemaRegistry;
use Jayanta\Jeeves\Security\InputGuard;

/**
 * Jeeves HTTP Controller
 *
 * Provides API endpoints for the Jeeves package:
 * - Text query processing
 * - Voice query processing
 * - Health check
 * - Dataset listing
 * - Cache management
 */
class JeevesController extends Controller
{
    protected QueryOrchestrator $orchestrator;

    protected ConversationManager $conversation;

    protected FeedbackStore $feedback;

    public function __construct(QueryOrchestrator $orchestrator, ConversationManager $conversation, FeedbackStore $feedback)
    {
        $this->orchestrator = $orchestrator;
        $this->conversation = $conversation;
        $this->feedback = $feedback;
    }

    /**
     * One rule for a question, wherever it arrives.
     *
     * /text demanded three characters while /conversation accepted one, so the
     * same follow-up -  "no", "up", a bare year -  was answered by one endpoint
     * and rejected by the other with a validation error the user could do
     * nothing about. Short questions are real questions.
     */
    protected function questionRules(): string
    {
        return 'required|string|min:1|max:' . config('jeeves.privacy.max_query_length', 1000);
    }

    /**
     * Send a result with an HTTP status that matches what went wrong.
     *
     * The status comes from the error code, in one place, so the two cannot
     * drift apart -  a rate limit arriving as 400 told every client its request
     * was malformed.
     */
    protected function respond(array $result)
    {
        $status = match ($result['status'] ?? 'error') {
            'success', 'clarification_needed' => 200,
            default => ErrorCode::httpStatus($result['error_code'] ?? null),
        };

        // Tells a client it is worth trying again, without it having to know
        // which codes are transient.
        if (($result['status'] ?? '') === 'error') {
            $result['retryable'] = ErrorCode::isRetryable($result['error_code'] ?? null);
        }

        $response = response()->json($result, $status);

        // Standard back-off signal, so generic HTTP clients and queue workers
        // behave sensibly without reading the body at all.
        if ($status === 429) {
            $response->header('Retry-After', (string) config('jeeves.retry.retry_after_seconds', 60));
        }

        return $response;
    }

    /**
     * Package info endpoint (replaces dashboard view).
     */
    public function index()
    {
        return response()->json([
            'package' => 'jeeves',
            'health' => $this->orchestrator->healthCheck(),
            'endpoints' => [
                'POST /text' => 'Natural language query (speech is transcribed in the browser)',
                'POST /conversation' => 'Multi-turn conversation',
                'GET /health' => 'Health check',
                'GET /datasets' => 'Available datasets',
                'POST /feedback' => 'Submit correction',
            ],
        ]);
    }

    /**
     * Process a text query.
     */
    public function textQuery(Request $request)
    {
        $data = $request->validate([
            'text' => $this->questionRules(),
            'dataset' => 'nullable|string|max:100',
            'disable_tts' => 'boolean',
        ]);

        $requestId = (string) Str::uuid();
        $startTime = microtime(true);

        $result = $this->orchestrator->query(
            $data['text'],
            $data['dataset'] ?? null
        );

        $result['metadata'] = array_merge($result['metadata'] ?? [], [
            'request_id' => $requestId,
            'processing_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
            'original_query' => $data['text'],
        ]);

        return $this->respond($result);
    }

    /**
     * Health check endpoint.
     */
    public function health()
    {
        $health = $this->orchestrator->healthCheck();

        return response()->json(array_merge($health, [
            'timestamp' => now()->toISOString(),
        ]), $health['status'] === 'healthy' ? 200 : 503);
    }

    /**
     * Everything a front end needs to show what can be asked.
     *
     * This used to return a key and a name, with metrics behind a second call
     * and dimensions not exposed at all -  so a "what can I ask?" panel, the
     * first thing anyone builds, could not be built. The hardest part of
     * querying your own data in words is knowing which questions the data can
     * answer, and the schema already knows.
     *
     * One call, everything: measures, breakdowns, the date a period applies to,
     * and the example questions from the schema files.
     */
    public function datasets(Request $request)
    {
        $registry = $this->orchestrator->registry();
        $only = $request->query('dataset');
        $datasets = [];

        foreach ($registry->getAvailableDatasets() as $dataset) {
            if ($only && $dataset['key'] !== $only) {
                continue;
            }

            $key = $dataset['key'];

            $datasets[] = $dataset + [
                'metrics' => $registry->getDatasetMetrics($key),
                // What the rows can be broken down by. Without this a client
                // cannot offer "by region" without guessing at column names.
                'dimensions' => $registry->getGroupableColumns($key),
                'default_dimension' => $registry->getGroupColumn($key),
                'date_column' => $registry->getDateColumn($key),
                'examples' => array_values(array_filter(array_map(
                    fn ($e) => is_array($e) ? ($e['natural'] ?? null) : (is_string($e) ? $e : null),
                    $registry->getExampleQueries($key)
                ))),
            ];
        }

        if ($only) {
            if (!$datasets) {
                return response()->json([
                    'status' => 'error',
                    'error_code' => ErrorCode::CANNOT_ANSWER,
                    'error' => "No dataset named '{$only}'.",
                    'available' => array_column($registry->getAvailableDatasets(), 'key'),
                ], 404);
            }

            return response()->json($datasets[0]);
        }

        return response()->json([
            'datasets' => $datasets,
            'total' => count($datasets),
        ]);
    }

    /**
     * Cache statistics.
     */
    public function cacheStats()
    {
        $stats = $this->orchestrator->getCacheStats();

        return response()->json(array_merge($stats, [
            'timestamp' => now()->toISOString(),
        ]));
    }

    /**
     * Clear cache entries.
     */
    public function clearCache(Request $request)
    {
        $data = $request->validate([
            'dataset' => 'nullable|string|max:100',
            'older_than_days' => 'nullable|integer|min:0',
            'min_hits' => 'nullable|integer|min:0',
        ]);

        $deleted = $this->orchestrator->clearCache(
            $data['dataset'] ?? null,
            $data['older_than_days'] ?? 0,
            $data['min_hits'] ?? 0
        );

        return response()->json([
            'status' => 'success',
            'deleted_entries' => $deleted,
            'timestamp' => now()->toISOString(),
        ]);
    }

    /**
     * Multi-turn conversation query.
     */
    public function conversationQuery(Request $request)
    {
        $data = $request->validate([
            'text' => $this->questionRules(),
            'session_id' => 'required|string|max:100',
            'dataset' => 'nullable|string|max:100',
        ]);

        $result = $this->conversation->query(
            $data['session_id'],
            $data['text'],
            $data['dataset'] ?? null
        );

        return $this->respond($result);
    }

    /**
     * Step back to how the query stood before the last turn.
     *
     * "No, go back to revenue" is a state restore rather than another
     * interpretation -  every turn's state is kept, so returning to one is
     * exact instead of being re-derived from the conversation.
     */
    public function rewindConversation(Request $request, string $sessionId)
    {
        $steps = max(1, min(10, (int) $request->input('steps', 1)));

        return response()->json($this->conversation->rewind($sessionId, $steps));
    }

    /**
     * The conversation as it currently stands.
     *
     * A front end that reloads has lost what it was showing, and the state
     * lives on the server -  without this it cannot restore the filters in
     * force, and the next follow-up resolves against context the user can no
     * longer see.
     */
    public function conversationState(string $sessionId)
    {
        return response()->json($this->conversation->state($sessionId));
    }

    public function clearConversation(string $sessionId)
    {
        $this->conversation->clearContext($sessionId);

        return response()->json(['status' => 'cleared', 'session_id' => $sessionId]);
    }

    /**
     * Submit feedback (correction or positive signal).
     */
    public function submitFeedback(Request $request)
    {
        $data = $request->validate([
            'query' => 'required|string|max:1000',
            'dataset' => 'required|string|max:100',
            'generated_sql' => 'nullable|string',
            'correction' => 'nullable|string|max:2000',
            'corrected_sql' => 'nullable|string',
            'feedback_type' => 'nullable|string|in:wrong_metric,wrong_table,wrong_result,wrong_filter,positive,other',
            'is_positive' => 'nullable|boolean',
        ]);

        // Security: screen everything that will be replayed into a future
        // prompt, not one field of it.
        //
        // NQ-012. This screened `correction` alone. `query` is supplied in the
        // same request body, is never compared against what was actually
        // asked, and `PromptBuilder::buildCorrections()` interpolates it into
        // the prompt of every LATER question on that dataset:
        //
        //     - When user asked: "{$c['query']}"
        //       Problem: {$c['correction']}
        //
        // So the payload went in `query` and something innocuous went in
        // `correction`, the row qualified for the prompt, and the injected
        // text was served to every subsequent user of that dataset. Guarding
        // one of two fields that reach the same place is the call-site mistake
        // this project keeps making; the rule belongs to the destination.
        $inputGuard = app(InputGuard::class);

        $correction = $data['correction'] ?? '';
        $correctedSql = $data['corrected_sql'] ?? null;

        $reachesAFuturePrompt = [
            'Question text' => $data['query'],
            'Correction text' => $correction,
        ];

        foreach ($reachesAFuturePrompt as $label => $text) {
            if ($text === '' || $text === null) {
                continue;
            }

            if (!$inputGuard->validate((string) $text)['safe']) {
                return response()->json([
                    'status' => 'rejected',
                    'reason' => $label . ' contains disallowed patterns.',
                ], 422);
            }
        }

        // Validate corrected SQL if provided
        if ($correctedSql) {
            $sqlValidator = app(SqlValidatorInterface::class);
            $allowedTables = app(SchemaRegistry::class)->getAllowedTables();
            $validation = $sqlValidator->validate($correctedSql, $allowedTables);
            if (!$validation['valid']) {
                return response()->json(['status' => 'rejected', 'reason' => 'Corrected SQL failed validation: ' . $validation['reason']], 422);
            }
        }

        if ($data['is_positive'] ?? false) {
            $success = $this->feedback->recordPositive(
                $data['query'],
                $data['dataset'],
                $data['generated_sql'] ?? ''
            );
        } else {
            $success = $this->feedback->recordCorrection(
                $data['query'],
                $data['dataset'],
                $data['generated_sql'] ?? '',
                $correction,
                $correctedSql,
                $data['feedback_type'] ?? 'other'
            );
        }

        return response()->json([
            'status' => $success ? 'recorded' : 'failed',
            'timestamp' => now()->toISOString(),
        ]);
    }

    /**
     * Feedback statistics.
     */
    public function feedbackStats()
    {
        return response()->json($this->feedback->getStatistics());
    }
}
