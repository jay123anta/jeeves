<?php

namespace Jayanta\Jeeves\Engine;

use Illuminate\Support\Facades\Log;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Schema\SchemaRegistry;

/**
 * Decide whether a question needs more than one query, and if so, what they are.
 *
 * "Compare revenue this year with last year" is two queries and a subtraction.
 * Answered as one, it returns whichever half the model happened to write SQL
 * for, and the other half is silently missing -  the same failure mode as a
 * dropped GROUP BY, with the same confident presentation.
 *
 * Planning costs an API call, so it is not run on every question. A local
 * pattern check gates it: questions with no sign of comparison or conjunction
 * go straight down the existing single-query path at exactly the cost they do
 * today. This keeps the common case free and, more importantly, keeps it
 * unchanged -  a planner that fires on "top 5 customers" could only make that
 * worse.
 *
 * PRIVACY: the planning prompt contains the question and the dataset list.
 * No data values, in keeping with the rest of the package.
 */
class QueryPlanner
{
    protected LlmProviderInterface $provider;

    protected SchemaRegistry $registry;

    /**
     * Wording that suggests more than one thing is being asked about.
     *
     * Deliberately conservative. A missed decomposition degrades to today's
     * behaviour; a false positive spends an API call and risks turning a
     * working single query into two worse ones.
     */
    protected const MULTI_STEP_HINTS = [
        '/\bvs\.?\b/i',
        '/\bversus\b/i',
        '/\bcompared?\s+(?:to|with)\b/i',
        '/\bcompare\b/i',
        '/\bdifference\s+between\b/i',
        '/\bagainst\s+last\b/i',
        '/\b(?:year|month|quarter|week)\s+(?:on|over)\s+(?:year|month|quarter|week)\b/i',
        '/\bboth\b.*\band\b/i',
        '/\bas\s+well\s+as\b/i',
        '/\balong\s+with\b/i',
        '/\band\s+also\b/i',
        '/\bthen\s+(?:show|break|split)\b/i',
    ];

    public function __construct(LlmProviderInterface $provider, SchemaRegistry $registry)
    {
        $this->provider = $provider;
        $this->registry = $registry;
    }

    /**
     * Might this question need several queries? Cheap, local, no API call.
     */
    public function looksMultiStep(string $query): bool
    {
        if (!config('jeeves.chat.multi_step', true)) {
            return false;
        }

        foreach (self::MULTI_STEP_HINTS as $pattern) {
            if (preg_match($pattern, $query)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Split a question into standalone sub-questions.
     *
     * Each returned question must stand on its own, because each is answered by
     * the ordinary single-query path -  the same intent parsing, the same
     * validation, the same table whitelist. Nothing here bypasses a guard.
     *
     * @return array{success: bool, steps: array<int, string>, comparison: bool, error?: string, status?: int|null, refused_before_sending?: bool}
     */
    public function plan(string $query): array
    {
        $maxSteps = max(2, (int) config('jeeves.chat.max_steps', 4));

        $response = $this->provider->generateSql($this->buildPlanPrompt($query, $maxSteps));

        if (!($response['success'] ?? false)) {
            // Planning is an enhancement, never a gate. A provider failure here
            // must leave the question answerable the ordinary way.
            //
            // With one exception, and the status is carried out so the caller
            // can tell: a 429 means the provider has asked for fewer calls, and
            // answering it by making another is the one response that makes
            // things worse. The caller decides -  this class just stops throwing
            // the evidence away.
            return [
                'success' => false,
                'steps' => [],
                'comparison' => false,
                'error' => $response['error'] ?? 'Planning failed',
                'status' => $response['status'] ?? null,
                // Carried for the same reason as the status, and missed for
                // the same reason it was: a provider that refused before
                // sending anything did not fail to answer, it declined to be
                // asked. Falling through then answers a two-part question as
                // a one-part one, using a smaller prompt that clears the very
                // guard that refused.
                'refused_before_sending' => $response['refused_before_sending'] ?? false,
            ];
        }

        $data = $response['data'] ?? [];
        $steps = [];

        foreach ($data['steps'] ?? [] as $step) {
            $text = is_array($step) ? ($step['question'] ?? null) : $step;

            if (is_string($text) && trim($text) !== '') {
                $steps[] = trim($text);
            }
        }

        $steps = array_slice(array_values(array_unique($steps)), 0, $maxSteps);

        // One step is not a plan -  it is the question we already had.
        if (count($steps) < 2) {
            return ['success' => false, 'steps' => [], 'comparison' => false];
        }

        Log::debug('[Jeeves:Planner] Decomposed into steps', ['count' => count($steps)]);

        return [
            'success' => true,
            'steps' => $steps,
            'comparison' => (bool) ($data['comparison'] ?? false),
        ];
    }

    protected function buildPlanPrompt(string $query, int $maxSteps): string
    {
        $datasets = [];

        foreach ($this->registry->getDatasetListForLlm() as $dataset) {
            $dimensions = implode(', ', $dataset['dimensions'] ?? []);
            $metrics = implode(', ', array_keys($dataset['metrics'] ?? []));
            $datasets[] = "- {$dataset['key']} ({$dataset['name']}): metrics=[{$metrics}], group_by=[{$dimensions}]";
        }

        $datasetList = implode("\n", $datasets);

        return <<<PROMPT
A user asked a question about their data. Decide whether answering it honestly
requires MORE THAN ONE database query.

USER QUESTION: "{$query}"

AVAILABLE DATASETS:
{$datasetList}

It needs several queries when it asks about two or more separate things -  two
time periods, two named records, two datasets, or a total plus a breakdown.
It needs only ONE query when a single SELECT with GROUP BY, WHERE or ORDER BY
would answer it. "Revenue by region" is ONE query. "Revenue this year vs last
year" is TWO.

Rules:
- Each step must be a COMPLETE question that stands alone, with its own subject
  and its own filter. Never write "and the same for last year" -  write
  "total revenue in 2025".
- Use the vocabulary of the datasets above.
- At most {$maxSteps} steps. Prefer fewer.
- If one query answers it, return an empty steps array.

Return JSON only (no markdown):
{
    "steps": ["first complete question", "second complete question"],
    "comparison": true,
    "explanation": "why it was split, briefly"
}

Set "comparison" to true only when the steps measure the SAME thing under
different conditions, so the numbers are meaningfully comparable.

JSON ONLY:
PROMPT;
    }
}
