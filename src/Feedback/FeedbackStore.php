<?php

namespace Jayanta\Jeeves\Feedback;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Feedback Store
 *
 * Stores user corrections and feedback on AI-generated queries.
 * Corrections are fed back into future prompts so the AI learns
 * from its mistakes over time.
 *
 * Flow:
 * 1. AI generates SQL → user sees wrong results
 * 2. User submits correction: "the metric should be 'delivered' not 'pending'"
 * 3. FeedbackStore saves: original query, generated SQL, correction
 * 4. On future similar queries, PromptBuilder includes relevant corrections
 * 5. AI sees past mistakes and avoids them
 */
class FeedbackStore
{
    protected string $tableName;

    public function __construct()
    {
        $this->tableName = 'jeeves_feedback';
    }

    /**
     * Record a correction from the user.
     *
     * @param  string  $query  Original natural language query
     * @param  string  $dataset  The dataset that was queried
     * @param  string  $generatedSql  The SQL that was generated (the wrong one)
     * @param  string  $correction  User's correction text (what was wrong / what was expected)
     * @param  string|null  $correctedSql  Optional: the correct SQL if user provides it
     * @param  string|null  $feedbackType  'wrong_metric', 'wrong_table', 'wrong_result', 'wrong_filter', 'other'
     */
    public function recordCorrection(
        string $query,
        string $dataset,
        string $generatedSql,
        string $correction,
        ?string $correctedSql = null,
        ?string $feedbackType = 'other'
    ): bool {
        try {
            DB::table($this->tableName)->insert([
                'query' => $query,
                'dataset' => $dataset,
                'generated_sql' => $generatedSql,
                'correction' => $correction,
                'corrected_sql' => $correctedSql,
                'feedback_type' => $feedbackType,
                'times_applied' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Log::info('[Jeeves:Feedback] Correction recorded', [
                'dataset' => $dataset,
                'type' => $feedbackType,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('[Jeeves:Feedback] Failed to record', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Record a positive signal -  user confirmed the result was correct.
     */
    public function recordPositive(string $query, string $dataset, string $generatedSql): bool
    {
        try {
            DB::table($this->tableName)->insert([
                'query' => $query,
                'dataset' => $dataset,
                'generated_sql' => $generatedSql,
                'correction' => null,
                'corrected_sql' => null,
                'feedback_type' => 'positive',
                'times_applied' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('[Jeeves:Feedback] Failed to record positive', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Get relevant corrections for a dataset to include in the AI prompt.
     *
     * Returns recent corrections that the AI should learn from.
     * Prioritizes: most recent + most frequently needed corrections.
     */
    public function getCorrectionsForPrompt(string $dataset, int $limit = 5): array
    {
        try {
            $corrections = DB::table($this->tableName)
                ->where('dataset', $dataset)
                ->where('feedback_type', '!=', 'positive')
                ->whereNotNull('correction')
                ->orderByDesc('times_applied')
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get(['query', 'correction', 'corrected_sql', 'feedback_type']);

            return $corrections->map(fn ($c) => [
                'query' => $c->query,
                'correction' => $c->correction,
                'corrected_sql' => $c->corrected_sql,
                'type' => $c->feedback_type,
            ])->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get corrections for ALL datasets (used in multi-dataset prompts).
     */
    public function getAllCorrectionsForPrompt(int $limit = 10): array
    {
        try {
            $corrections = DB::table($this->tableName)
                ->where('feedback_type', '!=', 'positive')
                ->whereNotNull('correction')
                ->orderByDesc('times_applied')
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get(['dataset', 'query', 'correction', 'corrected_sql', 'feedback_type']);

            return $corrections->map(fn ($c) => [
                'dataset' => $c->dataset,
                'query' => $c->query,
                'correction' => $c->correction,
                'corrected_sql' => $c->corrected_sql,
                'type' => $c->feedback_type,
            ])->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Mark a correction as applied (increment usage count).
     */
    public function markApplied(int $id): void
    {
        try {
            DB::table($this->tableName)
                ->where('id', $id)
                ->update([
                    'times_applied' => DB::raw('times_applied + 1'),
                    'updated_at' => now(),
                ]);
        } catch (\Exception $e) {
            // Non-critical
        }
    }

    /**
     * Get feedback statistics.
     */
    public function getStatistics(): array
    {
        try {
            $stats = DB::table($this->tableName)
                ->selectRaw("
                    COUNT(*) as total,
                    COUNT(CASE WHEN feedback_type = 'positive' THEN 1 END) as positive,
                    COUNT(CASE WHEN feedback_type != 'positive' THEN 1 END) as corrections,
                    COUNT(DISTINCT dataset) as datasets_with_feedback
                ")
                ->first();

            $topCorrections = DB::table($this->tableName)
                ->where('feedback_type', '!=', 'positive')
                ->whereNotNull('correction')
                ->select('dataset', 'feedback_type', 'query', 'correction')
                ->orderByDesc('times_applied')
                ->limit(5)
                ->get();

            return [
                'total_feedback' => (int) $stats->total,
                'positive' => (int) $stats->positive,
                'corrections' => (int) $stats->corrections,
                'datasets_with_feedback' => (int) $stats->datasets_with_feedback,
                'top_corrections' => $topCorrections,
            ];
        } catch (\Exception $e) {
            return ['total_feedback' => 0, 'error' => $e->getMessage()];
        }
    }
}
