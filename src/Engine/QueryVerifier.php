<?php

namespace Jayanta\Jeeves\Engine;

use Illuminate\Support\Facades\Log;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Schema\SchemaRegistry;

/**
 * Query Verifier -  The X Factor
 *
 * AI verifies its own SQL before execution. Catches wrong columns,
 * wrong ORDER, wrong JOINs, wrong aggregations -  BEFORE the user
 * sees bad data.
 *
 * Flow:
 * 1. AI generates SQL (existing step)
 * 2. Verifier sends a SHORT prompt: "Does this SQL answer this question?"
 * 3. AI responds: pass/fail + confidence + optional fixed SQL
 * 4. If fail: use the fixed SQL (validated by SqlValidator before execution)
 *
 * Cost: ~200 tokens per verification (fraction of the generation cost).
 * Skipped for cache hits and intent mode (no AI-generated SQL to verify).
 */
class QueryVerifier
{
    protected LlmProviderInterface $llm;

    protected SchemaRegistry $registry;

    public function __construct(LlmProviderInterface $llm, SchemaRegistry $registry)
    {
        $this->llm = $llm;
        $this->registry = $registry;
    }

    /**
     * Verify that generated SQL correctly answers the user's question.
     *
     * @return array {passed: bool, confidence: float, issues: ?string, fixed_sql: ?string, attempt: int}
     */
    public function verify(string $question, string $sql, ?string $datasetKey): array
    {
        $threshold = config('jeeves.verification.confidence_threshold', 0.7);
        $maxFixes = config('jeeves.verification.max_fix_attempts', 1);

        // First verification
        $result = $this->runVerification($question, $sql, $datasetKey);

        // If passed with sufficient confidence, return immediately
        if ($result['passed'] && $result['confidence'] >= $threshold) {
            return $result;
        }

        // If failed and we have a fix, try it
        if (!$result['passed'] && $result['fixed_sql'] && $maxFixes > 0) {
            $fixedSql = $result['fixed_sql'];

            // Optionally re-verify the fix
            if (config('jeeves.verification.reverify_fixes', false)) {
                $recheck = $this->runVerification($question, $fixedSql, $datasetKey);
                $recheck['attempt'] = 2;

                if ($recheck['passed'] && $recheck['confidence'] >= $threshold) {
                    return $recheck;
                }

                // Re-verify failed -  return fix anyway with its confidence
                return [
                    'passed' => false,
                    'confidence' => $recheck['confidence'],
                    'issues' => $recheck['issues'],
                    'fixed_sql' => $fixedSql,
                    'attempt' => 2,
                ];
            }

            // No re-verify -  trust the fix
            return [
                'passed' => true,
                'confidence' => max($result['confidence'], 0.6),
                'issues' => $result['issues'],
                'fixed_sql' => $fixedSql,
                'attempt' => 1,
            ];
        }

        return $result;
    }

    /**
     * Run a single verification call.
     */
    protected function runVerification(string $question, string $sql, ?string $datasetKey): array
    {
        try {
            $prompt = $this->buildVerificationPrompt($question, $sql, $datasetKey);
            $response = $this->llm->generateSql($prompt);

            if (!$response['success']) {
                // LLM call failed -  graceful degradation, treat as pass
                Log::debug('[Jeeves:Verifier] LLM call failed, passing through');

                return $this->defaultPass();
            }

            $data = $response['data'];

            // Parse response
            $passed = $data['pass'] ?? $data['passed'] ?? true;
            $confidence = floatval($data['confidence'] ?? 0.5);
            $confidence = max(0.0, min(1.0, $confidence));

            return [
                'passed' => (bool) $passed,
                'confidence' => $confidence,
                'issues' => $data['issue'] ?? $data['issues'] ?? null,
                'fixed_sql' => (!$passed && isset($data['fixed_sql'])) ? $data['fixed_sql'] : null,
                'attempt' => 1,
            ];
        } catch (\Exception $e) {
            // Any failure -  graceful degradation
            Log::warning('[Jeeves:Verifier] Exception, passing through', ['error' => $e->getMessage()]);

            return $this->defaultPass();
        }
    }

    /**
     * Build the compact verification prompt (~150 tokens input).
     */
    protected function buildVerificationPrompt(string $question, string $sql, ?string $datasetKey): string
    {
        $compactSchema = $this->buildCompactSchema($datasetKey);

        return <<<PROMPT
Verify this SQL answers the user's question correctly.

Question: "{$question}"
SQL: {$sql}
Available columns: {$compactSchema}

Check these 5 things:
1. Correct table used?
2. Correct columns in SELECT (not invented column names)?
3. Correct WHERE filter (matches what user asked)?
4. Correct ORDER BY direction (ASC for worst/lowest, DESC for top/highest)?
5. Correct LIMIT (matches "top 5", "top 10", etc)?

Respond JSON only:
{"pass":true,"confidence":0.95}

Or if wrong:
{"pass":false,"confidence":0.3,"issue":"used wrong column X instead of Y","fixed_sql":"corrected SELECT ..."}
PROMPT;
    }

    /**
     * Build compact schema -  one line per table with column names only.
     * Example: "orders(id, customer, revenue, status) JOIN customers(id, name)"
     */
    protected function buildCompactSchema(?string $datasetKey): string
    {
        if (!$datasetKey || !$this->registry->has($datasetKey)) {
            return 'schema not available';
        }

        $schema = $this->registry->get($datasetKey);
        $parts = [];

        // Primary table columns
        $primary = $schema['tables']['primary'] ?? [];
        $tableName = $primary['name'] ?? 'unknown';
        $columns = array_keys($primary['columns'] ?? []);
        $parts[] = "{$tableName}(" . implode(', ', $columns) . ')';

        // Computed metrics
        $computed = $schema['computed_metrics'] ?? [];
        if (!empty($computed)) {
            $compNames = [];
            foreach ($computed as $key => $meta) {
                $compNames[] = "{$key}={$meta['expression']}";
            }
            $parts[] = 'Computed: ' . implode(', ', $compNames);
        }

        // JOIN info
        $join = $primary['required_join'] ?? null;
        if ($join) {
            $parts[] = "JOIN: {$join}";
        }

        return implode(' | ', $parts);
    }

    /**
     * Default pass result for graceful degradation.
     */
    protected function defaultPass(): array
    {
        return [
            'passed' => true,
            'confidence' => 0.5,
            'issues' => null,
            'fixed_sql' => null,
            'attempt' => 1,
        ];
    }
}
