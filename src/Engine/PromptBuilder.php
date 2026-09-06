<?php

namespace Jayanta\Jeeves\Engine;

use Illuminate\Support\Facades\Log;
use Jayanta\Jeeves\Contracts\SchemaIntrospectorInterface;
use Jayanta\Jeeves\Feedback\FeedbackStore;
use Jayanta\Jeeves\Schema\SchemaRegistry;
use Jayanta\Jeeves\Security\InputGuard;

/**
 * Prompt Builder
 *
 * Builds schema-aware prompts for LLM providers.
 * The AI receives FULL table structure -  column names, types, descriptions,
 * aliases, JOINs, computed metrics, example queries, AND past corrections.
 *
 * This is how the package enables ANY project to work: the AI understands
 * the database structure from the schema config and generates correct SQL.
 *
 * PRIVACY: Only schema structure is included - never actual data values.
 */
class PromptBuilder
{
    protected SchemaRegistry $registry;

    protected SchemaIntrospectorInterface $introspector;

    protected FeedbackStore $feedback;

    public function __construct(SchemaRegistry $registry, SchemaIntrospectorInterface $introspector, FeedbackStore $feedback)
    {
        $this->registry = $registry;
        $this->introspector = $introspector;
        $this->feedback = $feedback;
    }

    /**
     * Build a complete SQL generation prompt for a specific dataset.
     *
     * Used in sql_generation mode -  AI generates the full SQL query.
     */
    public function buildSqlPrompt(string $datasetKey, string $userQuery): string
    {
        $schema = $this->registry->get($datasetKey);
        if (!$schema) {
            return "Error: Unknown dataset '{$datasetKey}'";
        }

        $dialect = $this->introspector->getDialect();
        $schemaInfo = $this->buildFullSchemaInfo($datasetKey, $schema);
        $examples = $this->buildExamples($datasetKey);
        $llmInstructions = $this->registry->getLlmInstructions($datasetKey);
        $maxLimit = $this->registry->getMaxLimit($datasetKey) ?? config('jeeves.sql.max_limit');
        $defaultLimit = $schema['defaults']['limit'] ?? config('jeeves.sql.default_limit', 100);

        $limitRule = $maxLimit
            ? "Always include LIMIT (default {$defaultLimit}, max {$maxLimit})"
            : "Include LIMIT when appropriate (default {$defaultLimit})";

        // Project-level system instructions (custom per project)
        $systemInstructions = config('jeeves.system_instructions', '');

        // Custom system role (if project overrides it)
        $systemRole = config('jeeves.prompts.system_role')
            ?? "You are a SQL query generator for a {$dialect} database. Generate a SELECT query based on the user's natural language request.";

        $prompt = "{$systemRole}\n\n";

        if ($systemInstructions) {
            $prompt .= "PROJECT CONTEXT:\n{$systemInstructions}\n\n";
        }

        $prompt .= "DATABASE SCHEMA (these are the ONLY tables you can query):\n\n{$schemaInfo}\n";

        if ($llmInstructions) {
            $prompt .= "\nDATA CONTEXT:\n{$llmInstructions}\n";
        }

        $prompt .= <<<PROMPT

IMPORTANT RULES:
1. ONLY generate SELECT queries -  no INSERT, UPDATE, DELETE, DROP, etc.
2. ONLY use tables listed above with their exact schema-qualified names.
3. ONLY use columns listed under each table -  do NOT invent column names.
4. COMPUTED METRICS are NOT database columns! Use the SQL expression provided.
5. Use proper {$dialect} syntax.
6. {$limitRule}
7. For ranking queries, use ORDER BY with the appropriate column or expression.
8. For specific record lookup (e.g., a name), use smart matching:
   WHERE LOWER(column) = LOWER('value') OR LOWER(column) LIKE LOWER('%value%')
   ORDER BY CASE WHEN LOWER(column) = LOWER('value') THEN 0 ELSE 1 END
   LIMIT 1
   Never use ILIKE -  it is PostgreSQL-only and a syntax error on MySQL.
9. If a JOIN is specified in the schema, you MUST include it in your query.
10. Column aliases tell you what users might call a column -  match user words to the correct column.
11. SELECT only what the question asks for. A question that names a measure
    ("revenue by region") wants the label AND the measure. A question that asks
    WHICH things, or to list them, wants the identifying column ALONE:
    "which products cost more than 200" wants the product name -  the price is
    what you filter on, not what you return. Adding a count or the filtered
    column to such an answer makes it a different answer.
12. A singular superlative wants exactly ONE row. "Which carrier shipped the
    most orders" is ORDER BY ... DESC LIMIT 1, not a ranked list to read the
    top off. "Top 5 carriers" is the ranked list.
PROMPT;

        if ($examples) {
            $prompt .= "\n\nEXAMPLE QUERIES (follow this pattern):\n{$examples}\n";
        }

        // Include past corrections so the AI learns from mistakes
        $corrections = $this->buildCorrections($datasetKey);
        if ($corrections) {
            $prompt .= "\n\nPAST CORRECTIONS (avoid these mistakes):\n{$corrections}\n";
        }

        $prompt .= <<<PROMPT

USER QUERY: "{$userQuery}"

Respond with ONLY a JSON object (no markdown):
{
    "sql": "SELECT ... FROM ... ORDER BY ... LIMIT ...",
    "dataset": "{$datasetKey}",
    "query_type": "ranking|group_detail|aggregation",
    "metric": "main metric column name or null",
    "group_value": "a specific record name to filter to, if the user named one, else null -  never a category or column name",
    "order": "DESC or ASC",
    "limit": {$defaultLimit},
    "period": "the date range your WHERE clause applies, as YYYY-MM-DD to YYYY-MM-DD, or null if you filtered no dates",
    "explanation": "Brief explanation of what the query does"
}

If the query cannot be processed, respond with:
{
    "error": "Reason why",
    "needs_clarification": true,
    "clarification_type": "dataset|metric|ambiguous",
    "suggestions": ["suggestion 1", "suggestion 2"]
}

RESPOND WITH JSON ONLY:
PROMPT;

        return $prompt;
    }

    /**
     * Build a multi-dataset SQL generation prompt (dataset not yet identified).
     *
     * AI must first identify which dataset to query, then generate SQL.
     */
    public function buildMultiDatasetPrompt(string $userQuery): string
    {
        $dialect = $this->introspector->getDialect();
        $allSchemaInfo = $this->buildAllSchemasFullInfo();
        $defaultLimit = config('jeeves.sql.default_limit', 100);
        $maxLimit = config('jeeves.sql.max_limit');
        $systemInstructions = config('jeeves.system_instructions', '');
        $systemRole = config('jeeves.prompts.system_role')
            ?? "You are a SQL query generator for a {$dialect} database. Generate a SELECT query based on the user's natural language request.";

        $limitRule = $maxLimit
            ? "Always include LIMIT (default {$defaultLimit}, max {$maxLimit})"
            : "Include LIMIT when appropriate (default {$defaultLimit})";

        $prompt = "{$systemRole}\n\n";

        if ($systemInstructions) {
            $prompt .= "PROJECT CONTEXT:\n{$systemInstructions}\n\n";
        }

        // Query routing hints
        $routingHints = $this->buildRoutingHints();
        if ($routingHints) {
            $prompt .= "QUERY ROUTING (use these to pick the correct dataset):\n{$routingHints}\n\n";
        }

        // Spell out that these are tables in ONE database. Listing them as
        // "datasets" made models treat them as mutually exclusive and answer a
        // cross-table question with "which dataset did you mean?" instead of
        // joining -  which is most questions on a normalised schema.
        $prompt .= "AVAILABLE TABLES -  all in the SAME database, and you may JOIN across them\n"
            . "(these are the ONLY tables you can query):\n\n{$allSchemaInfo}\n";

        $prompt .= <<<PROMPT

IMPORTANT RULES:
1. ONLY generate SELECT queries -  no INSERT, UPDATE, DELETE, DROP, etc.
2. ONLY use tables listed above with their exact schema-qualified names.
3. ONLY use columns listed under each table.
4. COMPUTED METRICS are NOT database columns! Use the SQL expression.
5. Use proper {$dialect} syntax.
6. {$limitRule}
7. If a JOIN is specified for a dataset, ALWAYS include it.
8. Column aliases tell you what users might call a column -  match user words.
8b. When a question needs columns from more than one table, JOIN them using the
    RELATED lines above. Do NOT ask which table to use, and do NOT answer with a
    raw id column when the name it points to is available through a join:
    "top customers by revenue" means joining orders to customers and selecting
    the customer's name, not grouping by customer_id.
8c. Check EVERY part of the question against the table you are selecting from.
    If the measure, the grouping, or a FILTER names something that table does
    not have, the query is not finished -  join the table that has it. A filter
    is the easiest one to miss, because the question often does not name the
    table it lives on: "shipments to Europe" filters on a country that may sit
    on the customer, not the shipment. Answering from one table and dropping
    the rest of the question returns a plausible number for a question nobody
    asked.
8d. SELECT the measure alongside the label. A ranking that returns only names,
    with no column for what they are ranked by, does not answer the question.
8e. 8d is about RANKINGS and BREAKDOWNS, where the measure is the answer. A
    question that asks WHICH things, or to list them, has no measure in it and
    wants the identifying column ALONE: "which products cost more than 200"
    wants the product name -  the price is what you filter on, not what you
    return. "List all product names" wants names, not names and a count.
8f. A singular superlative wants exactly ONE row. "Which carrier shipped the
    most orders" is ORDER BY ... DESC LIMIT 1, not a ranked list to read the
    top off. "Top 5 carriers" is the ranked list.
9. For specific record lookup, match with LOWER(column) = LOWER('value'), falling
   back to LOWER(column) LIKE LOWER('%value%'). Never use ILIKE -  it is
   PostgreSQL-only and a syntax error on MySQL.
PROMPT;

        // Global example queries (cross-schema)
        $globalExamples = $this->buildGlobalExamples();
        if ($globalExamples) {
            $prompt .= "\n\nGLOBAL EXAMPLES:\n{$globalExamples}\n";
        }

        // Include past corrections for all datasets
        $corrections = $this->buildAllCorrections();
        if ($corrections) {
            $prompt .= "\n\nPAST CORRECTIONS (avoid these mistakes):\n{$corrections}\n";
        }

        $prompt .= <<<PROMPT

USER QUERY: "{$userQuery}"

Return JSON only (no markdown):
{
    "sql": "SELECT ... FROM ... ORDER BY ... LIMIT ...",
    "dataset": "dataset_key",
    "query_type": "ranking|group_detail|aggregation",
    "metric": "main metric name or null",
    "group_value": "specific group name if mentioned, or null",
    "order": "DESC or ASC",
    "limit": {$defaultLimit},
    "period": "the date range your WHERE clause applies, as YYYY-MM-DD to YYYY-MM-DD, or null if you filtered no dates",
    "explanation": "Brief explanation"
}

Or if unclear:
{
    "error": "Reason",
    "needs_clarification": true,
    "clarification_type": "dataset|metric|ambiguous",
    "suggestions": ["try asking about X", "try asking about Y"]
}

JSON ONLY:
PROMPT;

        return $prompt;
    }

    /**
     * Build FULL schema info for a single dataset -  includes everything the AI needs.
     */
    protected function buildFullSchemaInfo(string $datasetKey, array $schema): string
    {
        $lines = [];
        $primary = $schema['tables']['primary'] ?? [];
        $tableName = $primary['name'] ?? 'unknown';
        $groupColumn = $primary['group_column'] ?? 'name';
        $joinClause = $primary['required_join'] ?? null;
        $selectOverride = $primary['select_override'] ?? null;

        $lines[] = "TABLE: {$tableName}";
        $lines[] = "  Dataset: {$datasetKey} ({$schema['name']})";
        $lines[] = '  Description: ' . ($schema['description'] ?? '');
        $lines[] = "  Group/Filter Column: {$groupColumn}";

        if ($joinClause) {
            $lines[] = "  REQUIRED JOIN: {$joinClause}";
            $lines[] = "  Use \"{$selectOverride}\" in SELECT for the group column (alias it AS {$groupColumn})";
        }

        // Foreign keys, so a question whose answer spans tables can be joined
        // rather than answered with raw id values. Without this the model sees
        // `customer_id` as just another integer and groups by it.
        foreach ($this->joinsFor($tableName, $primary['relationships'] ?? []) as $line) {
            $lines[] = $line;
        }

        // Columns with full detail
        $columns = $primary['columns'] ?? [];
        $lines[] = '';
        $lines[] = '  COLUMNS (only use these -  do not invent column names):';
        foreach ($columns as $colName => $colDef) {
            $desc = $colDef['description'] ?? '';
            $type = $colDef['type'] ?? '';
            $unit = isset($colDef['unit']) ? " [{$colDef['unit']}]" : '';
            $aliases = !empty($colDef['aliases']) ? ' (user might say: ' . implode(', ', $colDef['aliases']) . ')' : '';

            $flags = [];
            if ($colDef['filterable'] ?? false) {
                $flags[] = 'filterable';
            }
            if ($colDef['groupable'] ?? false) {
                $flags[] = 'groupable';
            }
            if ($colDef['aggregatable'] ?? false) {
                $flags[] = 'aggregatable';
            }
            if ($colDef['sortable'] ?? false) {
                $flags[] = 'sortable';
            }
            $flagStr = !empty($flags) ? ' [' . implode(', ', $flags) . ']' : '';

            $lines[] = "    - {$colName} ({$type}){$unit}: {$desc}{$aliases}{$flagStr}";
        }

        // Computed metrics
        $computed = $schema['computed_metrics'] ?? [];
        if (!empty($computed)) {
            $lines[] = '';
            $lines[] = '  COMPUTED METRICS (NOT database columns -  use the SQL expression in SELECT/ORDER BY):';
            foreach ($computed as $metricKey => $metricData) {
                $expr = $metricData['expression'] ?? '';
                $desc = $metricData['description'] ?? '';
                $unit = isset($metricData['unit']) ? " [{$metricData['unit']}]" : '';
                $aliases = !empty($metricData['aliases']) ? ' (user might say: ' . implode(', ', $metricData['aliases']) . ')' : '';
                $lines[] = "    - {$metricKey}{$unit}: {$desc}{$aliases}";
                $lines[] = "      SQL expression: {$expr} AS {$metricKey}";
            }
        }

        // Required filter.
        //
        // VERBATIM is asked for on purpose. The guard that enforces this
        // (QueryOrchestrator::requiredFilterMissing) is a literal string match
        // and has to stay one: it decides whether rows the schema says must
        // never be counted were excluded, so a loose match that accepted an
        // equivalent-looking predicate would be guessing about the one thing
        // nobody may guess about. Failing closed there means a model that
        // writes `status NOT IN ('cancelled')` for a rule written
        // `status != 'cancelled'` is refused - correct SQL, refused, and the
        // dataset unanswerable until someone notices.
        //
        // So the prompt is what gives, not the guard. Asking for the exact
        // characters makes the check satisfiable instead of loosening it.
        $requiredFilter = $primary['required_filter'] ?? null;
        if ($requiredFilter) {
            $lines[] = '';
            $lines[] = '  REQUIRED FILTER -  this dataset excludes rows that must never be counted.';
            $lines[] = '  Copy the predicate below into your WHERE clause EXACTLY as written,';
            $lines[] = '  character for character. Do not rewrite it (no NOT IN, no reordering,';
            $lines[] = '  no requoting) -  an equivalent form is rejected and the query fails.';
            $lines[] = "      {$requiredFilter}";
        }

        // Query patterns (SQL templates for different query types)
        $queryPatterns = $schema['query_patterns'] ?? [];
        if (!empty($queryPatterns)) {
            $lines[] = '';
            $lines[] = '  SQL QUERY PATTERNS (use these as templates):';
            foreach ($queryPatterns as $patternKey => $pattern) {
                $desc = $pattern['description'] ?? $patternKey;
                $sql = $pattern['sql'] ?? '';
                $lines[] = "    [{$patternKey}] {$desc}:";
                $lines[] = "      {$sql}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Build full schema info for ALL datasets -  used in multi-dataset prompts.
     */
    /**
     * Render foreign keys as ready-to-use JOIN clauses.
     *
     * Grouped by constraint, because a composite key is ONE join with an AND,
     * not several. Emitting a line per column invited two joins to the same
     * table, or a join on half the key -  and half a composite key matches rows
     * it should not, producing a total that is silently too large.
     *
     * Hand-written relationships without a constraint name fall back to
     * grouping by referenced table, which is right in every case except two
     * separate single-column keys pointing at the same table.
     *
     * @param  array<int, array<string, mixed>>  $relationships
     * @return string[]
     */
    protected function joinsFor(string $tableName, array $relationships): array
    {
        $byConstraint = [];

        foreach ($relationships as $rel) {
            if (empty($rel['column']) || empty($rel['references_table'])) {
                continue;
            }

            // Never advertise a join the validator will refuse. Discovering a
            // subset of tables (`discover --table=orders`) leaves foreign keys
            // pointing at tables that have no schema file, and the allowed-table
            // list is built from schema files. Suggesting those joins turned a
            // partial install into "query validation failed" on SQL this package
            // had itself asked the model to write. The whitelist is where the
            // user drew the line; the prompt follows it.
            if (!$this->registry->allowsTable($rel['references_table'])) {
                continue;
            }

            $key = $rel['constraint'] ?? ('table:' . $rel['references_table']);
            $byConstraint[$key]['table'] = $rel['references_table'];
            $byConstraint[$key]['on'][] = sprintf(
                '%s.%s = %s.%s',
                $rel['references_table'],
                $rel['references_column'] ?? 'id',
                $tableName,
                $rel['column']
            );
        }

        $lines = [];

        foreach ($byConstraint as $join) {
            $conditions = implode(' AND ', $join['on']);
            $note = count($join['on']) > 1
                ? ' (composite key -  ALL conditions are required)'
                : '';

            $lines[] = sprintf(
                '  RELATED: JOIN %s ON %s%s -  do this to use its columns',
                $join['table'],
                $conditions,
                $note
            );
        }

        return $lines;
    }

    protected function buildAllSchemasFullInfo(): string
    {
        $sections = [];

        foreach ($this->registry->all() as $key => $schema) {
            $sections[] = $this->buildFullSchemaInfo($key, $schema);
            $sections[] = ''; // blank line between schemas
        }

        return implode("\n", $sections);
    }

    /**
     * Build example queries section.
     */
    protected function buildExamples(string $datasetKey): string
    {
        $examples = $this->registry->getExampleQueries($datasetKey);
        if (empty($examples)) {
            return '';
        }

        $lines = [];
        foreach ($examples as $ex) {
            $lines[] = "Q: {$ex['natural']}";
            $lines[] = "SQL: {$ex['sql']}";
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Build corrections section for a specific dataset.
     */
    protected function buildCorrections(string $datasetKey): string
    {
        $corrections = $this->feedback->getCorrectionsForPrompt(
            $datasetKey,
            (int) config('jeeves.feedback.max_per_prompt', 5)
        );
        if (empty($corrections)) {
            return '';
        }

        $lines = [];
        foreach ($corrections as $c) {
            if (!$this->correctionIsSafeToReplay($c)) {
                continue;
            }

            $lines[] = '- When user asked: "' . $this->flatten($c['query']) . '"';
            $lines[] = '  Problem: ' . $this->flatten($c['correction']);
            if (!empty($c['corrected_sql'])) {
                $lines[] = '  Correct SQL: ' . $this->flatten($c['corrected_sql']);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * NQ-012. Stored feedback is replayed into a prompt, so it is screened here
     * as well as where it was accepted.
     *
     * The endpoint screens it on the way in, and that is the right place to
     * refuse a person. It does nothing for rows ALREADY in the table: an
     * install that ran before the endpoint was fixed has whatever it was given,
     * and no change to the controller reaches backwards. Same shape as the
     * credential columns sitting in schema files written before discovery
     * learned to withhold them.
     *
     * A row that fails is skipped rather than sanitised. A correction exists to
     * teach the model something; one that cannot be trusted to do that teaches
     * it nothing, and dropping it costs only the lesson it carried.
     *
     * @param  array<string, mixed>  $correction
     */
    protected function correctionIsSafeToReplay(array $correction): bool
    {
        $guard = app(InputGuard::class);

        foreach (['query', 'correction', 'corrected_sql'] as $field) {
            $text = (string) ($correction[$field] ?? '');

            if ($text !== '' && !$guard->validate($text)['safe']) {
                Log::warning('[Jeeves] Stored feedback withheld from the prompt', [
                    'field' => $field,
                    'dataset' => $correction['dataset'] ?? null,
                ]);

                return false;
            }
        }

        return true;
    }

    /**
     * One line, bounded.
     *
     * Every correction occupies a known number of lines in the prompt. Text
     * carrying its own newlines can close that block and open something that
     * reads like a new instruction, which is an injection that needs no
     * forbidden word in it at all - so the shape is taken away rather than
     * searched for.
     */
    protected function flatten(?string $text, int $max = 300): string
    {
        $flat = trim(preg_replace('/\s+/', ' ', (string) $text) ?? '');

        return mb_strlen($flat) > $max ? mb_substr($flat, 0, $max) . '…' : $flat;
    }

    /**
     * Build corrections section for all datasets.
     */
    protected function buildAllCorrections(): string
    {
        // Twice the per-dataset cap: this prompt spans every dataset, so the
        // same ceiling would show fewer corrections per dataset than the
        // single-dataset prompt does.
        $corrections = $this->feedback->getAllCorrectionsForPrompt(
            (int) config('jeeves.feedback.max_per_prompt', 5) * 2
        );
        if (empty($corrections)) {
            return '';
        }

        $lines = [];
        foreach ($corrections as $c) {
            if (!$this->correctionIsSafeToReplay($c)) {
                continue;
            }

            $lines[] = '- [' . $this->flatten($c['dataset'], 100) . '] When user asked: "'
                . $this->flatten($c['query']) . '"';
            $lines[] = '  Problem: ' . $this->flatten($c['correction']);
            if (!empty($c['corrected_sql'])) {
                $lines[] = '  Correct SQL: ' . $this->flatten($c['corrected_sql']);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Build routing hints from config for multi-dataset prompts.
     */
    protected function buildRoutingHints(): string
    {
        $routing = config('jeeves.query_routing', []);
        if (empty($routing)) {
            return '';
        }

        $lines = [];
        // Group by dataset
        $grouped = [];
        foreach ($routing as $keyword => $dataset) {
            $grouped[$dataset][] = $keyword;
        }

        foreach ($grouped as $dataset => $keywords) {
            $schemaData = $this->registry->get($dataset);
            $name = $schemaData['name'] ?? $dataset;
            $lines[] = '- If query mentions: ' . implode(', ', $keywords) . " → use dataset \"{$dataset}\" ({$name})";
        }

        return implode("\n", $lines);
    }

    /**
     * Build global example queries from config.
     */
    protected function buildGlobalExamples(): string
    {
        $examples = config('jeeves.global_examples', []);
        if (empty($examples)) {
            return '';
        }

        $lines = [];
        foreach ($examples as $ex) {
            $lines[] = 'Q: ' . ($ex['natural'] ?? '');
            if (!empty($ex['sql'])) {
                $lines[] = 'SQL: ' . $ex['sql'];
            }
            if (!empty($ex['note'])) {
                $lines[] = 'Note: ' . $ex['note'];
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }
}
