<?php

namespace Jayanta\Jeeves\Schema;

use Illuminate\Support\Facades\Log;

/**
 * Schema Registry
 *
 * Loads and manages schema configuration files from the config directory.
 * Each .php file in the schemas directory defines one queryable dataset.
 *
 * Provides methods to:
 * - List all available datasets
 * - Get schema details for a specific dataset
 * - Resolve dataset by alias
 * - Get available metrics for a dataset
 * - Build dataset info for LLM prompts
 */
class SchemaRegistry
{
    protected string $configPath;

    protected ?array $schemas = null;

    /** Lazily built lookup of validator-permitted tables, keyed lowercase. */
    protected ?array $allowedTableLookup = null;

    public function __construct(string $configPath)
    {
        $this->configPath = $configPath;
    }

    /**
     * Load all schema files from the config directory.
     */
    public function all(): array
    {
        if ($this->schemas !== null) {
            return $this->schemas;
        }

        $this->schemas = [];

        if (!is_dir($this->configPath)) {
            Log::warning('[Jeeves:SchemaRegistry] Schema directory not found', ['path' => $this->configPath]);

            return $this->schemas;
        }

        $files = glob($this->configPath . '/*.php');

        foreach ($files as $file) {
            $key = pathinfo($file, PATHINFO_FILENAME);
            try {
                $schema = require $file;

                if (is_array($schema) && self::isUntouchedTemplate($schema)) {
                    // The file jeeves:install writes, still pointing at
                    // its placeholder table. Loading it makes a dataset the
                    // model can pick and no query can satisfy -  on a fresh
                    // install a plain "total amount" chose it roughly half the
                    // time and came back "no such table: schema", which names
                    // nothing the user has ever seen.
                    //
                    // Skipped rather than repaired: there is nothing to repair.
                    // jeeves:doctor still reports the file so it does not
                    // vanish silently.
                    Log::info('[Jeeves:SchemaRegistry] Ignoring the unedited example template', [
                        'file' => $file,
                    ]);

                    continue;
                }

                if (is_array($schema)) {
                    $this->schemas[$key] = $schema;
                }
            } catch (\Throwable $e) {
                Log::error('[Jeeves:SchemaRegistry] Failed to load schema file', [
                    'file' => $file,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->schemas;
    }

    /**
     * Is this the shipped template, still pointing at its placeholder table?
     *
     * Matched on the table name rather than the file name, so a copy under any
     * name is recognised -  and so a template that HAS been pointed at a real
     * table is treated as the ordinary schema it now is, which is the whole
     * point of editing it.
     *
     * Static and public because jeeves:doctor asks the same question,
     * and the two must never disagree about which files are real.
     *
     * @param  array<string, mixed>  $schema
     */
    public static function isUntouchedTemplate(array $schema): bool
    {
        $table = $schema['tables']['primary']['name'] ?? null;

        return is_string($table) && strtolower(trim($table)) === 'schema_name.table_name';
    }

    /**
     * Get a specific schema by key.
     */
    public function get(string $key): ?array
    {
        $schemas = $this->all();

        return $schemas[$key] ?? null;
    }

    /**
     * Check if a schema exists.
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Get all schema keys.
     */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    /**
     * Find a dataset key by alias.
     */
    public function findByAlias(string $input): ?string
    {
        $input = strtolower(trim($input));

        foreach ($this->all() as $key => $schema) {
            if (strtolower($key) === $input) {
                return $key;
            }

            foreach ($schema['aliases'] ?? [] as $alias) {
                if (strtolower($alias) === $input) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * Get the primary table name for a schema.
     */
    public function getTableName(string $key): ?string
    {
        $schema = $this->get($key);

        return $schema['tables']['primary']['name'] ?? null;
    }

    /**
     * Are there several datasets, at least one of which points at another?
     *
     * When true, a question can legitimately need columns from more than one
     * table, so narrowing the prompt to a single dataset can make it
     * unanswerable. Discovery records real foreign keys, so this is a fact
     * about the database rather than a guess.
     */
    public function hasLinkedSchemas(): bool
    {
        $schemas = $this->all();

        if (count($schemas) < 2) {
            return false;
        }

        foreach ($schemas as $schema) {
            if (!empty($schema['tables']['primary']['relationships'])) {
                return true;
            }

            // A hand-written join counts too.
            if (!empty($schema['tables']['primary']['required_join'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The column results are grouped and labelled by.
     *
     * When a schema file does not declare `group_column`, this used to assume
     * a column literally called `name`. On a table without one -  most tables -
     * that produced `SELECT name ... GROUP BY name` and a hard SQL error, not
     * a degraded label. `jeeves:discover` always writes group_column, but
     * the README documents hand-written schema files too, and those are exactly
     * the ones that omit it.
     *
     * So derive it from the schema's own columns instead of guessing a name:
     * the first column marked groupable, else the first column that is not a
     * measure, else simply the first column. Any of those produce valid SQL on
     * any table.
     */
    /**
     * Is this column a point in time rather than a quantity?
     *
     * By declared type first, since that is what the schema actually states,
     * falling back to the name for hand-written schemas that omit it.
     */
    protected function looksLikeDate(string $name, array $column): bool
    {
        $type = strtolower((string) ($column['type'] ?? ''));

        if (in_array($type, ['date', 'datetime', 'timestamp', 'time'], true)) {
            return true;
        }

        return (bool) preg_match('/(^|_)(date|time|at|on)$/i', $name);
    }

    /**
     * The column a time filter applies to.
     *
     * "Revenue last month" has to narrow on something, and which column that
     * is cannot be guessed from the question -  a table may carry order_date,
     * shipped_at and created_at, and they answer different questions. The
     * schema decides, explicitly via `date_column` on the primary table, or by
     * falling back to the first date-like column declared.
     */
    public function getDateColumn(string $key): ?string
    {
        $primary = $this->get($key)['tables']['primary'] ?? [];

        if (!empty($primary['date_column'])) {
            return (string) $primary['date_column'];
        }

        foreach ($primary['columns'] ?? [] as $name => $column) {
            $type = strtolower((string) ($column['type'] ?? ''));

            if (in_array($type, ['date', 'datetime', 'timestamp'], true)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Resolve a dimension the user asked to break results down by.
     *
     * "revenue by region" must group by region, not by whatever the schema
     * nominates as its default group_column. Only columns the schema marks
     * `groupable` qualify -  grouping by a measure produces one row per distinct
     * amount, which is noise, and grouping by an unlisted column is how a typo
     * or a hallucinated name would reach the database.
     *
     * Returns null when the request cannot be honoured, so the caller can say
     * so rather than quietly answering a different question.
     */
    public function resolveGroupColumn(string $key, ?string $userDimension): ?string
    {
        if (!$userDimension) {
            return null;
        }

        $wanted = strtolower(trim($userDimension));
        $columns = $this->get($key)['tables']['primary']['columns'] ?? [];

        foreach ($columns as $name => $column) {
            if (empty($column['groupable'])) {
                continue;
            }

            if (strtolower($name) === $wanted) {
                return $name;
            }

            foreach ($column['aliases'] ?? [] as $alias) {
                if (strtolower($alias) === $wanted) {
                    return $name;
                }
            }
        }

        return null;
    }

    /**
     * Columns the schema allows grouping by, for error messages and prompts.
     *
     * @return array<int, string>
     */
    public function getGroupableColumns(string $key): array
    {
        $columns = $this->get($key)['tables']['primary']['columns'] ?? [];

        return array_keys(array_filter($columns, fn ($c) => !empty($c['groupable'])));
    }

    public function getGroupColumn(string $key): string
    {
        $primary = $this->get($key)['tables']['primary'] ?? [];

        if (!empty($primary['group_column'])) {
            return $primary['group_column'];
        }

        $columns = $primary['columns'] ?? [];

        foreach ($columns as $name => $column) {
            if (!empty($column['groupable'])) {
                return $name;
            }
        }

        // A measure is a thing to total, not a thing to group by.
        foreach ($columns as $name => $column) {
            if (empty($column['aggregatable'])) {
                return $name;
            }
        }

        // Nothing left to reason about: a schema with no columns is broken and
        // jeeves:doctor reports it. 'name' keeps the old behaviour there.
        return (string) (array_key_first($columns) ?? 'name');
    }

    /**
     * Get all column definitions for a schema's primary table.
     */
    public function getColumns(string $key): array
    {
        $schema = $this->get($key);

        return $schema['tables']['primary']['columns'] ?? [];
    }

    /**
     * Get all metrics (regular columns that are aggregatable/sortable).
     */
    public function getMetrics(string $key): array
    {
        $columns = $this->getColumns($key);
        $metrics = [];

        foreach ($columns as $colName => $colDef) {
            if (($colDef['aggregatable'] ?? false) || ($colDef['sortable'] ?? false)) {
                $metrics[$colName] = $colDef;
            }
        }

        return $metrics;
    }

    /**
     * Get computed metrics for a schema.
     */
    /**
     * The counting metric every dataset has, whether or not anyone declared it.
     *
     * "How many orders by status" has no answer built from aggregatable
     * columns -  counting rows is not summing a measure. Without this, such a
     * question resolved to no metric, fell through to the default, and was
     * answered with revenue per status: right grouping, wrong question, and a
     * number that looks entirely reasonable.
     */
    public const COUNT_METRIC = 'record_count';

    public function getComputedMetrics(string $key): array
    {
        $schema = $this->get($key);
        $metrics = $schema['computed_metrics'] ?? [];

        if (!config('jeeves.sql.implicit_count_metric', true)) {
            return $metrics;
        }

        // A schema that defines its own counting metric wins -  it may need to
        // count something other than rows (DISTINCT, or filtered).
        foreach (array_keys($metrics) as $declared) {
            if (strtolower($declared) === self::COUNT_METRIC || strtolower($declared) === 'count') {
                return $metrics;
            }
        }

        // "Number of orders" reads better than "Number of orders records",
        // and this description is shown to the user in the answer sentence.
        $label = trim(strtolower($schema['name'] ?? ''));

        // `jeeves:discover` names a dataset after its table, and tables are
        // often singular: Chinook's `Album` answered "Number of album". Only
        // the last word takes the plural, and a plural name is left alone.
        if ($label !== '' && !str_ends_with($label, 's')) {
            $label = match (true) {
                (bool) preg_match('/[^aeiou]y$/', $label) => substr($label, 0, -1) . 'ies',
                (bool) preg_match('/(?:x|z|ch|sh)$/', $label) => $label . 'es',
                default => $label . 's',
            };
        }

        $description = $label !== '' ? "Number of {$label}" : 'Number of records';

        return $metrics + [
            self::COUNT_METRIC => [
                'expression' => 'COUNT(*)',
                'description' => $description,
                'unit' => 'records',
                'aliases' => [
                    'count', 'counts', 'number', 'how many', 'total count',
                    'number of records', 'record count', 'rows', 'volume',
                ],
            ],
        ];
    }

    /**
     * Get all allowed table/view names across all schemas.
     */
    public function getAllowedTables(): array
    {
        $tables = [];
        foreach ($this->all() as $schema) {
            if (isset($schema['tables']['primary']['name'])) {
                $tables[] = $schema['tables']['primary']['name'];
            }
            // Include related tables
            foreach ($schema['tables'] ?? [] as $tableKey => $table) {
                if ($tableKey !== 'primary' && isset($table['name'])) {
                    $tables[] = $table['name'];
                }
            }
        }

        return array_unique($tables);
    }

    /**
     * Will the SQL validator accept a query touching this table?
     *
     * Matched on the unqualified name as well as the exact string: Postgres
     * reports foreign key targets schema-qualified (`public.customers`) while a
     * hand-written schema file may simply say `customers`, and the two mean the
     * same table.
     */
    public function allowsTable(string $table): bool
    {
        if ($this->allowedTableLookup === null) {
            $this->allowedTableLookup = [];

            foreach ($this->getAllowedTables() as $allowed) {
                $this->allowedTableLookup[strtolower($allowed)] = true;
                $this->allowedTableLookup[strtolower($this->unqualify($allowed))] = true;
            }
        }

        return isset($this->allowedTableLookup[strtolower($table)])
            || isset($this->allowedTableLookup[strtolower($this->unqualify($table))]);
    }

    /**
     * Tables that a schema file points at through a foreign key but that have
     * no schema file of their own, as [schema key => [table, ...]].
     *
     * These are joins the package deliberately will not offer, because the
     * validator would reject the resulting SQL. Surfaced by `jeeves:doctor`
     * so a partial discovery run is visible rather than silently limiting.
     */
    public function undescribedRelationshipTargets(): array
    {
        $missing = [];

        foreach ($this->all() as $key => $schema) {
            foreach ($schema['tables'] ?? [] as $table) {
                foreach ($table['relationships'] ?? [] as $rel) {
                    $target = $rel['references_table'] ?? null;

                    if ($target && !$this->allowsTable($target)) {
                        $missing[$key][$target] = true;
                    }
                }
            }
        }

        return array_map(fn ($t) => array_keys($t), $missing);
    }

    protected function unqualify(string $table): string
    {
        $parts = explode('.', $table);

        return end($parts);
    }

    /**
     * Get dataset list formatted for LLM providers.
     *
     * Returns an array of datasets with key, name, aliases, and metrics
     * suitable for passing to LlmProviderInterface::parseIntent().
     */
    public function getDatasetListForLlm(): array
    {
        $list = [];

        foreach ($this->all() as $key => $schema) {
            $metrics = $this->getMetrics($key);
            $computedMetrics = $this->getComputedMetrics($key);

            $allMetrics = array_merge($metrics, $computedMetrics);

            $list[] = [
                'key' => $key,
                'name' => $schema['name'] ?? $key,
                'aliases' => $schema['aliases'] ?? [],
                'metrics' => $allMetrics,
                // Without these, intent mode cannot answer "revenue by region":
                // the model has no way to know region is a column it may group
                // by, so the breakdown is dropped and the default grouping is
                // returned as though it had been asked for.
                'dimensions' => $this->getGroupableColumns($key),
                'default_dimension' => $this->getGroupColumn($key),
                'description' => $schema['description'] ?? '',
            ];
        }

        return $list;
    }

    /**
     * Get available datasets for clarification UI.
     */
    public function getAvailableDatasets(): array
    {
        $result = [];
        foreach ($this->all() as $key => $schema) {
            $result[] = [
                'key' => $key,
                'name' => $schema['name'] ?? $key,
                'description' => $schema['description'] ?? '',
                'aliases' => $schema['aliases'] ?? [],
            ];
        }

        return $result;
    }

    /**
     * Get available metrics for a specific dataset (for clarification UI).
     */
    public function getDatasetMetrics(string $key): array
    {
        $metrics = $this->getMetrics($key);
        $computedMetrics = $this->getComputedMetrics($key);

        $result = [];

        foreach ($metrics as $metricKey => $data) {
            // getMetrics() includes sortable columns, because sorting by a date
            // is perfectly reasonable. Offering one as the answer to "who is
            // the best?" is not -  "best by order_date" means nothing, and a
            // dead option in a list of live ones makes the whole list look
            // untrustworthy.
            if ($this->looksLikeDate($metricKey, $data)) {
                continue;
            }

            $result[] = [
                'key' => $metricKey,
                'description' => $data['description'] ?? $metricKey,
                'type' => $data['type'] ?? 'neutral',
                'computed' => false,
            ];
        }

        foreach ($computedMetrics as $metricKey => $data) {
            $result[] = [
                'key' => $metricKey,
                'description' => $data['description'] ?? $metricKey,
                'type' => $data['type'] ?? 'neutral',
                'computed' => true,
            ];
        }

        return $result;
    }

    /**
     * Get the database connection for a schema.
     */
    public function getConnection(string $key): ?string
    {
        $schema = $this->get($key);

        return $schema['connection'] ?? config('jeeves.sql.database_connection');
    }

    /**
     * Get the default metric for a schema.
     */
    public function getDefaultMetric(string $key): ?string
    {
        $schema = $this->get($key);

        if (!empty($schema['default_metric'])) {
            return $schema['default_metric'];
        }

        // Fall back to first metric
        $metrics = $this->getMetrics($key);

        return !empty($metrics) ? array_key_first($metrics) : null;
    }

    /**
     * Get the max limit for a schema (or global default).
     */
    public function getMaxLimit(string $key): ?int
    {
        $schema = $this->get($key);

        return $schema['max_limit'] ?? config('jeeves.sql.max_limit');
    }

    /**
     * Get example queries for a schema (used in prompt building).
     */
    public function getExampleQueries(string $key): array
    {
        $schema = $this->get($key);

        return $schema['example_queries'] ?? [];
    }

    /**
     * Get LLM instructions for a schema.
     */
    public function getLlmInstructions(string $key): string
    {
        $schema = $this->get($key);

        return trim($schema['llm_instructions'] ?? '');
    }

    /**
     * Resolve a metric name from user input or alias.
     */
    public function resolveMetric(string $key, ?string $userMetric): ?string
    {
        if (!$userMetric) {
            return null;
        }

        $userMetric = strtolower(trim($userMetric));

        // Check regular metrics
        foreach ($this->getMetrics($key) as $metricKey => $metricData) {
            if (strtolower($metricKey) === $userMetric) {
                return $metricKey;
            }
            foreach ($metricData['aliases'] ?? [] as $alias) {
                if (strtolower($alias) === $userMetric) {
                    return $metricKey;
                }
            }
        }

        // Check computed metrics
        foreach ($this->getComputedMetrics($key) as $metricKey => $metricData) {
            if (strtolower($metricKey) === $userMetric) {
                return $metricKey;
            }
            foreach ($metricData['aliases'] ?? [] as $alias) {
                if (strtolower($alias) === $userMetric) {
                    return $metricKey;
                }
            }
        }

        return null;
    }

    /**
     * Flush the cached schemas (useful after config changes).
     */
    public function flush(): void
    {
        $this->schemas = null;
        $this->allowedTableLookup = null;
    }
}
