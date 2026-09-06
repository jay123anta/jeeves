<?php

namespace Jayanta\Jeeves\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Contracts\SchemaIntrospectorInterface;
use Jayanta\Jeeves\Contracts\SqlValidatorInterface;

/**
 * Schema Discovery Command
 *
 * Auto-generates schema configuration files by introspecting an existing
 * database -  one file per table/view. With --ai it also fills in the human
 * layer (name, description, aliases, instructions, computed metrics, example
 * queries) that would otherwise be written by hand.
 *
 * Two guarantees this command makes, because both failures are silent and
 * expensive:
 *
 *  1. Every generated file is verified to PARSE and return an array before it
 *     is reported as created. Schema files are loaded on every request, so a
 *     malformed one takes the whole application down.
 *  2. Every AI-suggested example query is validated (SELECT-only, whitelisted
 *     table) and checked against the live database with EXPLAIN. Examples are
 *     fed to the AI as few-shot guidance, so a hallucinated column here
 *     silently corrupts every future query.
 *
 * Neither check ever reads a data value -  EXPLAIN plans a query, it does not
 * run it, so the privacy wall holds.
 */
class DiscoverSchemaCommand extends Command
{
    protected $signature = 'jeeves:discover
                            {--connection= : Database connection name}
                            {--schema=* : Schema names to scan (default: public/dbo)}
                            {--table=* : Specific tables to discover (default: all)}
                            {--output= : Output directory (default: config/jeeves-schemas)}
                            {--force : Overwrite existing schema files, discarding any hand-written curation}
                            {--merge : Update existing files from the database while keeping descriptions, aliases, instructions, metrics and examples}
                            {--views : Include database views}
                            {--all-tables : Include framework/system tables normally skipped}
                            {--dry-run : Show what would be generated without writing files}
                            {--no-verify : Skip EXPLAIN verification of AI example queries}
                            {--ai : Use AI to auto-generate descriptions, aliases, metrics and example queries}';

    protected $description = 'Auto-discover database tables and generate Jeeves schema files';

    /**
     * Framework and plumbing tables nobody asks questions about. Without this,
     * a first run on a stock Laravel app buries the two tables you care about
     * under migrations, jobs, sessions and cache. Trailing '*' matches a prefix.
     */
    protected const DEFAULT_EXCLUDED_TABLES = [
        'migrations', 'password_resets', 'password_reset_tokens', 'failed_jobs',
        'jobs', 'job_batches', 'sessions', 'cache', 'cache_locks',
        'personal_access_tokens', 'oauth_*', 'telescope_*', 'pulse_*',
        'jeeves_*',
    ];

    protected SchemaIntrospectorInterface $introspector;

    protected SqlValidatorInterface $validator;

    public function handle(SchemaIntrospectorInterface $introspector, SqlValidatorInterface $validator): int
    {
        $this->introspector = $introspector;
        $this->validator = $validator;

        $connection = $this->option('connection') ?? config('jeeves.sql.database_connection');
        // Cast, because these are array options ({--table=*}) and a scalar
        // reaches them the moment anyone calls this programmatically:
        //
        //     Artisan::call('jeeves:discover', ['--table' => 'invoices'])
        //
        // From the command line Symfony wraps it; through Artisan::call it does
        // not, and the string went straight into in_array() as a haystack -  a
        // raw TypeError from inside the command, naming nothing the caller did.
        // The package's own harnesses invoke it this way, so it is a supported
        // path and not a misuse to be punished.
        $schemas = (array) ($this->option('schema') ?: []);
        $specificTables = (array) ($this->option('table') ?: []);
        $outputPath = $this->option('output') ?? config('jeeves.schema.config_path', config_path('jeeves-schemas'));
        $includeViews = $this->option('views');
        $dryRun = $this->option('dry-run');

        $this->info('Discovering database schema...');
        $this->line("  Driver: {$introspector->getDriver($connection)}");
        $this->line("  Dialect: {$introspector->getDialect($connection)}");

        if (empty($schemas)) {
            $schemas = $introspector->getSchemas($connection);
            $this->line('  Available schemas: ' . implode(', ', $schemas));
        }

        $tables = $introspector->listTables($connection, $schemas);

        if (!$includeViews) {
            $tables = array_filter($tables, fn ($t) => ($t['type'] ?? 'table') === 'table');
        }

        if (!empty($specificTables)) {
            $tables = array_filter($tables, fn ($t) => in_array($t['short_name'], $specificTables, true));
        } elseif (!$this->option('all-tables')) {
            $before = count($tables);
            $tables = array_filter($tables, fn ($t) => !$this->isExcluded($t['short_name']));
            $removed = $before - count($tables);
            if ($removed > 0) {
                $this->line("  Skipped {$removed} framework/system table(s) -  use --all-tables to include them");
            }
        }

        if (empty($tables)) {
            $this->warn('No tables found to discover.');

            return self::FAILURE;
        }

        $this->info('Found ' . count($tables) . ' table(s)/view(s):');

        if (!$dryRun && !is_dir($outputPath)) {
            mkdir($outputPath, 0755, true);
        }

        $created = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($tables as $table) {
            $shortName = $table['short_name'];
            $fullName = $table['name'];
            $type = $table['type'] ?? 'table';
            $estimate = !empty($table['row_estimate']) ? " (~{$table['row_estimate']} rows)" : '';

            $this->line("  [{$type}] {$fullName}{$estimate}");

            $columns = $introspector->getColumns($fullName, $connection);
            $relationships = $introspector->getRelationships($fullName, $connection);

            if (empty($columns)) {
                $this->warn('    Skipping -  no columns found');
                $skipped++;

                continue;
            }

            $schemaKey = $this->generateSchemaKey($shortName);
            $outputFile = rtrim($outputPath, '/\\') . DIRECTORY_SEPARATOR . $schemaKey . '.php';

            // What the human wrote into an existing file is the part that makes
            // the dataset usable, and it cannot be recovered from the database.
            // --merge refreshes the structural layer around it; --force starts
            // over and throws it away.
            $existing = null;
            if (file_exists($outputFile) && !$dryRun) {
                if ($this->option('merge')) {
                    $existing = $this->readExistingSchema($outputFile);
                    if ($existing === null) {
                        $this->warn("    Cannot merge -  {$schemaKey}.php did not parse; leaving it untouched");
                        $skipped++;

                        continue;
                    }
                } elseif (!$this->option('force')) {
                    $this->line("    Skipping -  {$schemaKey}.php already exists (--merge to update it, --force to regenerate)");
                    $skipped++;

                    continue;
                }
            }

            $aiMeta = null;
            if ($this->option('ai')) {
                $this->line('    Asking AI to describe this table...');
                $aiMeta = $this->generateAiMeta($fullName, $columns, $relationships);

                if ($aiMeta && !empty($aiMeta['example_queries'])) {
                    $aiMeta['example_queries'] = $this->keepOnlyValidExamples(
                        $aiMeta['example_queries'],
                        $fullName,
                        $connection
                    );
                }
            }

            $content = $this->generateSchemaFile($fullName, $shortName, $columns, $relationships, $table, $aiMeta, $existing);

            if ($dryRun) {
                $this->line("    Would create {$schemaKey}.php (" . count($columns) . ' columns)');
                $created++;

                continue;
            }

            if ($existing !== null) {
                $this->reportMerge($existing, $columns);
            }

            file_put_contents($outputFile, $content);

            // A schema file is loaded on every request. Never report success
            // for a file that would fatal the application.
            $parseError = $this->verifyGeneratedFile($outputFile);
            if ($parseError !== null) {
                @unlink($outputFile);
                $this->error("    ✗ Generated file was invalid and has been removed: {$parseError}");
                $this->line('      Please report this -  include the table name and column comments.');
                $failed++;

                continue;
            }

            $extras = [];
            if ($aiMeta) {
                $extras[] = 'AI-enhanced';
            }
            if (!empty($aiMeta['example_queries'])) {
                $extras[] = count($aiMeta['example_queries']) . ' verified examples';
            }
            $suffix = $extras ? ', ' . implode(', ', $extras) : '';

            $this->info("    ✓ Created {$schemaKey}.php (" . count($columns) . " columns{$suffix})");
            $created++;
        }

        $this->newLine();
        $verb = $dryRun ? 'would be created' : 'created';
        $this->info("Discovery complete: {$created} {$verb}, {$skipped} skipped" . ($failed ? ", {$failed} failed" : ''));

        if (!$dryRun) {
            $this->line("Schema files at: {$outputPath}");
            $this->line('Review the generated files, then run: php artisan jeeves:doctor');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    // =====================================================================
    // Table selection
    // =====================================================================

    protected function isExcluded(string $shortName): bool
    {
        $patterns = config('jeeves.schema.discover_exclude', static::DEFAULT_EXCLUDED_TABLES);
        $name = strtolower($shortName);

        foreach ((array) $patterns as $pattern) {
            $pattern = strtolower((string) $pattern);

            if (str_ends_with($pattern, '*')) {
                if (str_starts_with($name, rtrim($pattern, '*'))) {
                    return true;
                }
            } elseif ($name === $pattern) {
                return true;
            }
        }

        return false;
    }

    // =====================================================================
    // Example query validation
    // =====================================================================

    /**
     * Keep only AI example queries that are safe AND actually run against this
     * database. Examples become few-shot guidance in every prompt, so a bad
     * one is worse than no example at all.
     *
     * @param  array  $examples  Raw [{natural, sql}, …] from the AI
     * @return array Validated subset, re-indexed
     */
    protected function keepOnlyValidExamples(array $examples, string $fullName, ?string $connection): array
    {
        $allowed = array_unique([$fullName, $this->bareTableName($fullName)]);
        $kept = [];

        foreach ($examples as $example) {
            $natural = trim((string) ($example['natural'] ?? ''));
            $sql = trim((string) ($example['sql'] ?? ''));

            if ($natural === '' || $sql === '') {
                continue;
            }

            // Illustrative examples legitimately omit LIMIT (e.g. "total
            // revenue"), so only the safety rules apply here -  not the
            // runtime LIMIT policy.
            $result = $this->validator->validate($sql, $allowed, [
                'require_limit' => false,
                'max_limit' => null,
            ]);

            if (!($result['valid'] ?? false)) {
                $this->line('      · dropped unsafe example: ' . ($result['reason'] ?? 'failed validation'));

                continue;
            }

            if (!$this->option('no-verify')) {
                $error = $this->explainFails($sql, $connection);
                if ($error !== null) {
                    $this->line('      · dropped example that does not run: ' . $error);

                    continue;
                }
            }

            $kept[] = ['natural' => $natural, 'sql' => $sql];
        }

        return $kept;
    }

    /**
     * Ask the database to PLAN the query without running it. Catches
     * hallucinated columns and bad syntax; reads no data.
     *
     * @return string|null Error message, or null when the query plans cleanly
     */
    protected function explainFails(string $sql, ?string $connection): ?string
    {
        try {
            DB::connection($connection)->select('EXPLAIN ' . $sql);

            return null;
        } catch (\Throwable $e) {
            $message = $e->getMessage();

            return strlen($message) > 120 ? substr($message, 0, 117) . '...' : $message;
        }
    }

    protected function bareTableName(string $fullName): string
    {
        return str_contains($fullName, '.')
            ? substr(strrchr($fullName, '.'), 1)
            : $fullName;
    }

    // =====================================================================
    // File generation
    // =====================================================================

    protected function generateSchemaKey(string $tableName): string
    {
        return str_replace([' ', '-', '.'], '_', strtolower($tableName));
    }

    /**
     * Confirm a generated file parses and returns an array.
     *
     * @return string|null Error message, or null when the file is valid
     */
    protected function verifyGeneratedFile(string $path): ?string
    {
        $code = @file_get_contents($path);

        if ($code === false) {
            return 'file could not be read back after writing';
        }

        // Check syntax WITHOUT executing. A parse error inside include/require
        // is a fatal compile error, not a catchable ParseError -  the catch
        // below never fires and the whole PHP process dies, which is precisely
        // the outcome this verification exists to prevent. token_get_all with
        // TOKEN_PARSE parses the same grammar and throws catchably instead.
        try {
            token_get_all($code, TOKEN_PARSE);
        } catch (\ParseError $e) {
            return 'PHP syntax error -  ' . $e->getMessage();
        }

        // Syntax is proven, so including it can no longer kill the process.
        try {
            $value = include $path;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return is_array($value) ? null : 'file did not return an array';
    }

    protected function generateSchemaFile(
        string $fullName,
        string $shortName,
        array $columns,
        array $relationships,
        array $tableMeta,
        ?array $aiMeta = null,
        ?array $existing = null
    ): string {
        $humanName = $aiMeta['name'] ?? ucwords(str_replace(['_', '-'], ' ', $shortName));
        $comment = $tableMeta['comment'] ?? '';
        $description = $aiMeta['description'] ?? ($comment ?: "Data from {$humanName}");

        $groupColumn = $this->guessGroupColumn($columns);

        $columnDefs = [];
        foreach ($columns as $col) {
            $definition = [
                'type' => $col['type'],
                'description' => $col['comment'] ?: ucwords(str_replace('_', ' ', $col['name'])),
            ];

            switch ($col['suggested_role'] ?? null) {
                case 'dimension':
                    $definition['filterable'] = true;
                    $definition['groupable'] = true;
                    break;
                case 'measure':
                    // Drives SUM + GROUP BY on transactional tables. See README.
                    $definition['aggregatable'] = true;
                    $definition['sortable'] = true;
                    break;
                case 'date_filter':
                    $definition['filterable'] = true;
                    $definition['sortable'] = true;
                    break;
            }

            $columnDefs[$col['name']] = $definition;
        }

        $aliases = array_values(array_unique(array_merge(
            [$shortName],
            array_filter((array) ($aiMeta['aliases'] ?? []), 'is_string')
        )));

        $llmInstructions = $aiMeta['llm_instructions']
            ?? "This dataset contains {$description}.\nGROUP BY {$groupColumn} for category-level analysis.";

        $schema = [
            'name' => $humanName,
            'description' => $description,
            'aliases' => $aliases,
            'connection' => null,
            'llm_instructions' => is_string($llmInstructions) ? $llmInstructions : (string) json_encode($llmInstructions),
            'tables' => [
                'primary' => [
                    'name' => $fullName,
                    'description' => $description,
                    'group_column' => $groupColumn,
                    // Which date "last month" narrows on, written out rather
                    // than inferred. A table often has several -  ordered,
                    // shipped, created -  and they answer different questions;
                    // the engine falls back to the first one it finds, which
                    // is a silent choice on exactly the kind of question where
                    // a silent choice has bitten us before. Stated here, it is
                    // visible and editable.
                    'date_column' => $this->guessDateColumn($columnDefs),
                    'columns' => $columnDefs,
                    // Real foreign keys, as config rather than a comment. The
                    // prompt lists these so the model can join to reach a name
                    // that lives in another table -  without them, "top
                    // customers by revenue" on a normalised schema can only be
                    // answered with raw customer_id values.
                    'relationships' => $this->normalizeRelationships($relationships),
                ],
            ],
            'computed_metrics' => $this->normalizeComputedMetrics($aiMeta['computed_metrics'] ?? []),
            'example_queries' => array_values($aiMeta['example_queries'] ?? []),
            'max_limit' => null,
            'default_metric' => null,
            'defaults' => [
                'order' => 'DESC',
                'limit' => 10,
            ],
        ];

        if ($existing !== null) {
            $schema = $this->mergeSchema($schema, $existing);
            $humanName = $schema['name'] ?? $humanName;
        }

        $header = $this->fileHeader($humanName, $fullName, $relationships, $aiMeta);

        // Rendered programmatically rather than string-interpolated: any value
        // here can contain quotes, backslashes or newlines (column comments
        // routinely do), and one unescaped apostrophe would produce a file
        // that fatals the application on every request.
        return $header . 'return ' . $this->renderValue($schema, 0) . ";\n";
    }

    /**
     * Read an existing schema file so its curation can be carried forward.
     *
     * Syntax-checked before including, for the same reason
     * verifyGeneratedFile() does it: a parse error inside include is a fatal
     * compile error, not a catchable exception, and would take the command
     * down instead of reporting a bad file.
     *
     * @return array<string, mixed>|null null when the file is unusable
     */
    protected function readExistingSchema(string $path): ?array
    {
        $code = @file_get_contents($path);

        if ($code === false) {
            return null;
        }

        try {
            token_get_all($code, TOKEN_PARSE);
            $value = include $path;
        } catch (\Throwable $e) {
            return null;
        }

        return is_array($value) ? $value : null;
    }

    /**
     * Keys a human curates and the database cannot tell us.
     *
     * Everything else -  types, which columns exist -  is refreshed from the
     * live schema, because that is the whole point of re-running discovery.
     */
    protected const CURATED_TOP_LEVEL = [
        'name', 'description', 'aliases', 'connection', 'llm_instructions',
        'computed_metrics', 'example_queries', 'max_limit', 'default_metric',
        'defaults', 'query_routing',
    ];

    protected const CURATED_TABLE_KEYS = [
        'description', 'group_column', 'required_join', 'select_override',
        'required_filter',
    ];

    /** Per-column keys that are judgement calls, not facts. */
    protected const CURATED_COLUMN_KEYS = [
        'description', 'aliases', 'unit', 'filterable', 'groupable',
        'aggregatable', 'sortable',
        // `values` is never generated -  the privacy wall keeps sampled
        // contents out of a file that is sent to the model -  but a human may
        // write one so `audit-schema` can name the exact value to exclude.
        // Left off this list, re-running discover silently deleted it.
        'values',
    ];

    /**
     * Fold a freshly generated schema into what is already on disk.
     *
     * Structure wins for facts; the existing file wins for judgement. A column
     * dropped from the database is dropped here too -  leaving it would keep
     * telling the model about a column that no longer exists, which is exactly
     * the silent failure `doctor` reports.
     *
     * @param  array<string, mixed>  $generated
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    protected function mergeSchema(array $generated, array $existing): array
    {
        $merged = $generated;

        foreach (self::CURATED_TOP_LEVEL as $key) {
            if (array_key_exists($key, $existing)) {
                $merged[$key] = $existing[$key];
            }
        }

        $existingTable = $existing['tables']['primary'] ?? [];

        foreach (self::CURATED_TABLE_KEYS as $key) {
            if (array_key_exists($key, $existingTable)) {
                $merged['tables']['primary'][$key] = $existingTable[$key];
            }
        }

        // Any extra table-level keys the user added by hand survive too.
        foreach ($existingTable as $key => $value) {
            if ($key !== 'name' && $key !== 'columns' && !array_key_exists($key, $merged['tables']['primary'])) {
                $merged['tables']['primary'][$key] = $value;
            }
        }

        $existingColumns = $existingTable['columns'] ?? [];

        foreach ($merged['tables']['primary']['columns'] as $name => $definition) {
            if (!isset($existingColumns[$name]) || !is_array($existingColumns[$name])) {
                continue;
            }

            foreach (self::CURATED_COLUMN_KEYS as $key) {
                if (array_key_exists($key, $existingColumns[$name])) {
                    $definition[$key] = $existingColumns[$name][$key];
                } else {
                    // The user removed a flag deliberately; do not put it back.
                    unset($definition[$key]);
                }
            }

            $merged['tables']['primary']['columns'][$name] = $definition;
        }

        return $merged;
    }

    /** Tell the user exactly what the merge changed. */
    protected function reportMerge(array $existing, array $columns): void
    {
        $before = array_keys($existing['tables']['primary']['columns'] ?? []);
        $after = array_column($columns, 'name');

        $added = array_diff($after, $before);
        $removed = array_diff($before, $after);

        $this->line('    Merged -  your descriptions, aliases, instructions, metrics and examples kept');

        if ($added) {
            $this->line('      + new column(s): ' . implode(', ', $added));
        }

        if ($removed) {
            $this->line('      - column(s) gone from the database: ' . implode(', ', $removed));
        }

        if (!$added && !$removed) {
            $this->line('      no column changes');
        }
    }

    /**
     * Turn introspected foreign keys into the shape the schema file carries.
     *
     * Kept minimal on purpose -  column, the table it points at, and the column
     * there. That is everything needed to write a JOIN, and nothing that would
     * go stale differently from the rest of the file.
     *
     * @param  array<int, array<string, mixed>>  $relationships
     * @return array<int, array{column: string, references_table: string, references_column: string}>
     */
    protected function normalizeRelationships(array $relationships): array
    {
        $out = [];

        foreach ($relationships as $rel) {
            $column = $rel['column'] ?? null;
            $table = $rel['referenced_table'] ?? null;
            $refColumn = $rel['referenced_column'] ?? null;

            if (!$column || !$table || !$refColumn) {
                continue;
            }

            $entry = [
                'column' => (string) $column,
                'references_table' => (string) $table,
                'references_column' => (string) $refColumn,
            ];

            // Keep the constraint name so a composite key can be rendered as
            // ONE join with AND rather than several joins to the same table.
            if (!empty($rel['constraint_name'])) {
                $entry['constraint'] = (string) $rel['constraint_name'];
            }

            $out[] = $entry;
        }

        return $out;
    }

    /**
     * Pick the column results should be grouped and labelled by.
     *
     * "First dimension wins" chose `status` for an orders table, because
     * status is the first text column -  so every answer came back grouped by
     * "settled" rather than by customer. Prefer a column that reads like a
     * label; fall back to the first dimension only when there isn't one.
     */
    /**
     * The date a period should narrow on.
     *
     * Prefers the one that reads like when the thing HAPPENED -  an order date
     * over a created_at -  because that is what "last month" means to the person
     * asking. Null when the table has no dates, which is honest: the schema
     * then says so instead of leaving the reader to guess.
     */
    protected function guessDateColumn(array $columns): ?string
    {
        $dates = [];

        foreach ($columns as $name => $definition) {
            if (in_array(strtolower((string) ($definition['type'] ?? '')), ['date', 'datetime', 'timestamp'], true)) {
                $dates[] = $name;
            }
        }

        if (!$dates) {
            return null;
        }

        // A business date beats a bookkeeping one.
        foreach (['/_date$/i', '/^date_/i', '/_on$/i', '/_at$/i'] as $preference) {
            foreach ($dates as $name) {
                if (preg_match($preference, $name) && !preg_match('/^(created|updated|deleted)_/i', $name)) {
                    return $name;
                }
            }
        }

        foreach ($dates as $name) {
            if (!preg_match('/^(created|updated|deleted)_/i', $name)) {
                return $name;
            }
        }

        return $dates[0];
    }

    protected function guessGroupColumn(array $columns): string
    {
        $dimensions = array_values(array_filter(
            $columns,
            fn ($c) => ($c['suggested_role'] ?? null) === 'dimension'
        ));

        // A column literally called name/title/label is what a human would
        // label a row with.
        foreach ($dimensions as $col) {
            if (preg_match('/^(name|title|label|full_name|display_name)$/i', $col['name'])) {
                return $col['name'];
            }
        }

        // Then anything ending that way: customer_name, product_title, …
        foreach ($dimensions as $col) {
            if (preg_match('/(_name|_title|_label)$/i', $col['name'])) {
                return $col['name'];
            }
        }

        // Codes and references identify a row; a status or type merely buckets
        // it, so prefer the former when both are present.
        foreach ($dimensions as $col) {
            if (!preg_match('/(status|state|type|category|kind|flag)$/i', $col['name'])) {
                return $col['name'];
            }
        }

        return $dimensions[0]['name'] ?? ($columns[0]['name'] ?? 'id');
    }

    /**
     * Accept computed metrics from the AI only in the shape the engine reads.
     */
    protected function normalizeComputedMetrics($metrics): array
    {
        if (!is_array($metrics)) {
            return [];
        }

        $normalized = [];

        foreach ($metrics as $key => $metric) {
            if (!is_string($key) || !is_array($metric) || empty($metric['expression'])) {
                continue;
            }

            $normalized[$key] = array_filter([
                'expression' => (string) $metric['expression'],
                'description' => isset($metric['description']) ? (string) $metric['description'] : null,
                'unit' => isset($metric['unit']) ? (string) $metric['unit'] : null,
                'aliases' => isset($metric['aliases']) && is_array($metric['aliases'])
                    ? array_values(array_filter($metric['aliases'], 'is_string'))
                    : null,
            ], fn ($v) => $v !== null);
        }

        return $normalized;
    }

    protected function fileHeader(string $humanName, string $fullName, array $relationships, ?array $aiMeta): string
    {
        $lines = [
            '<?php',
            '',
            '/**',
            " * Jeeves Schema: {$humanName}",
            ' *',
            ' * Auto-generated by: php artisan jeeves:discover'
                . ($aiMeta ? ' --ai' : ''),
            " * Source table: {$fullName}",
            ' *',
        ];

        if (!empty($relationships)) {
            $lines[] = ' * Foreign keys detected (add a required_join under tables.primary';
            $lines[] = ' * if questions need data from the related table):';
            foreach ($relationships as $rel) {
                $lines[] = ' *   - ' . ($rel['column'] ?? '?') . ' → '
                    . ($rel['referenced_table'] ?? '?') . '.' . ($rel['referenced_column'] ?? '?');
            }
            $lines[] = ' *';
        }

        $lines[] = ' * This file is yours to edit -  it is plain config, no code changes needed:';
        $lines[] = ' *   description / aliases  what users call this data';
        $lines[] = ' *   llm_instructions       business rules the AI must follow';
        $lines[] = ' *   aggregatable           set on measures so totals SUM per group';
        $lines[] = ' *   computed_metrics       named SQL expressions (rates, averages)';
        $lines[] = ' *   example_queries        few-shot examples; keep them correct';
        $lines[] = ' *';
        $lines[] = ' * After editing run: php artisan jeeves:doctor';
        $lines[] = ' */';
        $lines[] = '';

        return implode("\n", $lines) . "\n";
    }

    /**
     * Render a value as valid, readable PHP source.
     */
    protected function renderValue($value, int $depth): string
    {
        if (!is_array($value)) {
            return var_export($value, true);
        }

        if ($value === []) {
            return '[]';
        }

        $pad = str_repeat('    ', $depth + 1);
        $closePad = str_repeat('    ', $depth);
        $isList = array_keys($value) === range(0, count($value) - 1);

        $parts = [];
        foreach ($value as $key => $item) {
            $rendered = $this->renderValue($item, $depth + 1);
            $parts[] = $isList
                ? $pad . $rendered
                : $pad . var_export((string) $key, true) . ' => ' . $rendered;
        }

        return "[\n" . implode(",\n", $parts) . ",\n" . $closePad . ']';
    }

    // =====================================================================
    // AI metadata
    // =====================================================================

    protected function generateAiMeta(string $tableName, array $columns, array $relationships): ?array
    {
        try {
            $llm = app(LlmProviderInterface::class);

            $colSummary = [];
            foreach ($columns as $col) {
                $role = $col['suggested_role'] ?? 'unknown';
                $colSummary[] = "{$col['name']} ({$col['type']}, {$role})"
                    . ($col['comment'] ? " - {$col['comment']}" : '');
            }
            $colStr = implode("\n", $colSummary);

            $relStr = '';
            if (!empty($relationships)) {
                $rels = array_map(
                    fn ($r) => "{$r['column']} → {$r['referenced_table']}.{$r['referenced_column']}",
                    $relationships
                );
                $relStr = "\nRelationships:\n" . implode("\n", $rels);
            }

            $dialect = $this->introspector->getDialect(
                $this->option('connection') ?? config('jeeves.sql.database_connection')
            );

            $prompt = <<<PROMPT
Analyze this database table and generate metadata for a natural language query system.

Table: {$tableName}
SQL dialect: {$dialect}
Columns:
{$colStr}
{$relStr}

Generate a JSON response with:
1. "name": A short human-readable name for this dataset (e.g., "Sales Orders", "Employee Data")
2. "description": One sentence describing what this table contains
3. "aliases": Array of 5-8 alternative names users might use for this data
4. "llm_instructions": Plain English rules (3-8 lines) covering what the data
   represents, which columns are dimensions vs measures, any rows that should
   normally be excluded from totals, and how dates should be interpreted
5. "computed_metrics": Object mapping metric_key to
   {"expression": "<SQL aggregate expression>", "description": "...", "unit": "...", "aliases": [...]}
   Only include metrics that are genuinely useful (rates, ratios, averages).
   Every expression MUST be a valid aggregate for this dialect.
6. "example_queries": Array of 4-6 objects with "natural" (user question) and
   "sql" (correct SELECT for this dialect).

CRITICAL: use ONLY the exact column names listed above -  do not invent columns.
Reference the table as {$tableName}. Every SQL statement must be a SELECT.

Return JSON only.
PROMPT;

            $response = $llm->generateSql($prompt);

            if (($response['success'] ?? false) && isset($response['data']) && is_array($response['data'])) {
                return $response['data'];
            }

            $this->warn('    AI generation failed: ' . ($response['error'] ?? 'unknown error'));
        } catch (\Throwable $e) {
            $this->warn('    AI generation failed: ' . substr($e->getMessage(), 0, 80));
        }

        return null;
    }
}
