<?php

namespace Jayanta\Jeeves\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Engine\DatasetSeeder;
use Jayanta\Jeeves\Engine\IntentCoverage;
use Jayanta\Jeeves\Engine\PromptBudget;
use Jayanta\Jeeves\Engine\PromptBuilder;
use Jayanta\Jeeves\Schema\SchemaRegistry;
use Jayanta\Jeeves\Security\ExecutionConnection;

/**
 * Debug Prompt Command
 *
 * Shows developers EXACTLY what prompt the AI receives for a given query.
 * Essential for tuning schema configs and reducing errors.
 *
 * Usage:
 *   php artisan jeeves:debug "your query here"
 *   php artisan jeeves:debug "your query" --dataset=orders
 *   php artisan jeeves:debug "your query" --execute
 */
class DebugPromptCommand extends Command
{
    protected $signature = 'jeeves:debug
                            {query : The natural language query to debug}
                            {--dataset= : Force a specific dataset}
                            {--execute : Actually execute the query and show results}
                            {--raw : Show the full raw AI response}';

    protected $description = 'Show the exact prompt sent to AI for a query -  essential for debugging and tuning';

    public function handle(
        PromptBuilder $promptBuilder,
        SchemaRegistry $registry,
        LlmProviderInterface $llm,
        DatasetSeeder $seeder,
        PromptBudget $budget,
        IntentCoverage $coverage
    ): int {
        $query = $this->argument('query');
        $dataset = $this->option('dataset');

        $this->info('Jeeves Prompt Debugger');
        $this->newLine();

        // Show config
        $this->comment('Configuration:');
        $this->line('  Query mode: ' . config('jeeves.query_mode', 'auto'));
        $this->line('  LLM provider: ' . $llm->getName());
        $this->line('  Default dataset: ' . (config('jeeves.default_dataset') ?: 'none'));
        $this->line('  System instructions: ' . (config('jeeves.system_instructions') ? 'set (' . strlen(config('jeeves.system_instructions')) . ' chars)' : 'none'));
        $this->line('  Schemas loaded: ' . count($registry->all()) . ' (' . implode(', ', $registry->keys()) . ')');
        $this->newLine();

        // Detect dataset
        if (!$dataset) {
            $dataset = config('jeeves.default_dataset');
        }

        // DatasetSeeder, not a second implementation of it. This command's
        // entire value is that it decides what the engine decides; an
        // open-coded str_contains() loop over keys and aliases misses
        // query_routing rules and can land on a different dataset than the
        // real question would, which sends the person debugging off in the
        // wrong direction at exactly the moment they are relying on it.
        if (!$dataset) {
            $dataset = $seeder->detect($query);
        }

        $this->comment('Dataset detection:');
        $this->line('  Detected: ' . ($dataset ?: 'NONE -  will use multi-dataset prompt'));
        $this->newLine();

        // Same condition as QueryOrchestrator::processWithSqlGeneration(). The
        // linked-schemas half used to be missing here, so on any install whose
        // schemas declare relationships this printed the focused prompt while
        // the engine sent the multi-dataset one -  a question can legitimately
        // span linked datasets, and only the multi-dataset prompt permits the
        // join that answers it.
        if ($dataset && $registry->has($dataset) && !$registry->hasLinkedSchemas()) {
            $prompt = $promptBuilder->buildSqlPrompt($dataset, $query);
            $datasetsRendered = 1;
            $this->comment("Prompt type: Single-dataset ({$dataset})");
        } else {
            $prompt = $promptBuilder->buildMultiDatasetPrompt($query);
            $datasetsRendered = count($registry->all());
            $this->comment('Prompt type: Multi-dataset (all datasets)');

            if ($dataset && $registry->has($dataset) && $registry->hasLinkedSchemas()) {
                $this->line('  (schemas are linked, so the engine sends the multi-dataset prompt');
                $this->line("   even though '{$dataset}' was detected -  a question may span them)");
            }
        }

        $this->line('  Length: ' . strlen($prompt) . ' chars (' . str_word_count($prompt) . ' words)');

        // Which route the engine would actually take for this question. In the
        // shipped default 'auto' it answers most questions by intent parsing,
        // whose prompt is built inside the provider -  so the SQL prompt below
        // is not what gets sent, and saying nothing about that is the same
        // mistake as printing the wrong prompt.
        $mode = config('jeeves.query_mode', 'auto');
        $escalates = $mode === 'auto' ? $coverage->exceeds($query) : null;
        $buildsThisPrompt = $mode === 'sql_generation' || ($mode === 'auto' && $escalates !== null);

        $this->newLine();
        if ($mode === 'intent') {
            $this->warn("query_mode is 'intent': the engine does NOT build this prompt.");
            $this->line('  Intent prompts are built per-provider and are not shown here.');
        } elseif ($mode === 'auto' && !$buildsThisPrompt) {
            $this->warn("query_mode is 'auto' and this question stays inside the intent contract,");
            $this->line('  so the engine answers it by intent parsing and never builds the prompt below.');
            $this->line('  It is shown as the prompt that WOULD be sent if the question escalated.');
        } elseif ($mode === 'auto') {
            $this->line("  auto mode escalates this question to SQL generation ({$escalates}).");
        }

        // prompts.max_chars refuses a prompt BEFORE it is sent -  but only on
        // the route that consults it. Announcing a refusal for a question the
        // engine answers happily through intent parsing tells the user their
        // question will fail when it will not.
        $refusal = $buildsThisPrompt ? $budget->check($prompt, $datasetsRendered) : null;

        if ($refusal) {
            $this->newLine();
            $this->error('This prompt would be REFUSED, not sent:');
            $this->line('  ' . $refusal);
        }

        $this->newLine();

        // Show prompt
        $this->comment('=== FULL PROMPT ===');
        $this->line($prompt);
        $this->comment('=== END PROMPT ===');
        $this->newLine();

        // Execute if requested -  but never a prompt just reported as refused.
        // Sending it would make the line above false in the most direct way
        // available, and the refusal exists because an oversized prompt comes
        // back as a confident answer built on a truncated schema.
        if ($this->option('execute') && $refusal) {
            $this->error('Not sending: this prompt is over prompts.max_chars (see above).');

            return self::SUCCESS;
        }

        if ($this->option('execute')) {
            $this->comment('Sending to AI...');
            $response = $llm->generateSql($prompt);

            $this->newLine();
            $this->comment('=== AI RESPONSE ===');

            if ($response['success']) {
                $data = $response['data'];
                if (isset($data['sql'])) {
                    $this->info('SQL: ' . $data['sql']);
                    $this->line('Dataset: ' . ($data['dataset'] ?? '?'));
                    $this->line('Type: ' . ($data['query_type'] ?? '?'));
                    $this->line('Explanation: ' . ($data['explanation'] ?? '?'));
                } elseif (isset($data['error'])) {
                    $this->error('AI returned error: ' . $data['error']);
                } else {
                    $this->warn('Unexpected response format');
                }

                if ($this->option('raw')) {
                    $this->newLine();
                    $this->line(json_encode($data, JSON_PRETTY_PRINT));
                }

                // Try executing the SQL
                if (isset($data['sql'])) {
                    $this->newLine();
                    $this->comment('Executing SQL...');
                    // NQ-001. This runs the MODEL'S sql, by hand, and it is
                    // the path most likely to be pointed at production. Same
                    // rule as the query path: no fallback to the app's own
                    // connection, and the exception is left to surface -  a
                    // developer running this deserves the loud version.
                    $connection = ExecutionConnection::resolve(
                        $dataset ? $registry->getConnection($dataset) : null
                    );

                    try {
                        $rows = DB::connection($connection)->select($data['sql']);

                        $this->info('Results: ' . count($rows) . ' rows');
                        foreach (array_slice($rows, 0, 5) as $row) {
                            $this->line('  ' . json_encode((array) $row));
                        }
                        if (count($rows) > 5) {
                            $this->line('  ... and ' . (count($rows) - 5) . ' more');
                        }
                    } catch (\Exception $e) {
                        $this->error('SQL execution failed: ' . $e->getMessage());
                    }
                }
            } else {
                $this->error('AI call failed: ' . ($response['error'] ?? 'unknown'));
            }
        }

        return self::SUCCESS;
    }
}
