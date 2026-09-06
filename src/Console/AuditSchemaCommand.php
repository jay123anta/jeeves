<?php

namespace Jayanta\Jeeves\Console;

use Illuminate\Console\Command;
use Jayanta\Jeeves\Schema\Introspectors\Concerns\WithholdsSecretColumns;
use Jayanta\Jeeves\Schema\SchemaRegistry;

/**
 * What the model cannot work out for itself, and you can.
 *
 * Roughly one question in five is misread on an UNCURATED schema, and the same
 * questions land near-perfectly once a handful of sentences are written. The
 * gap between those two numbers is the whole product -  and until now an
 * adopter had no way to see which sentences were missing. "It gets things
 * wrong sometimes" is not actionable. "These four columns have no description
 * and two of them could both be revenue" is.
 *
 * Everything reported here is something introspection genuinely cannot
 * recover: a column called `amount` in two tables is identical in name, type
 * and nullability, and only you know which one the business calls revenue.
 * `jeeves:discover` writes the structure; this says what to add on top.
 *
 * Read-only. Contacts nothing.
 */
class AuditSchemaCommand extends Command
{
    use WithholdsSecretColumns;

    protected $signature = 'jeeves:audit-schema
                            {--dataset= : Audit a single dataset}
                            {--json : Machine-readable output}';

    protected $description = 'Find the schema descriptions the AI needs and does not have';

    /**
     * Table names that answer no business question.
     *
     * Every one of these in the registry is noise in the prompt: more tables
     * for the model to pick a wrong column from, and on a large schema, real
     * pressure against prompts.max_chars.
     */
    private const INFRASTRUCTURE = [
        'migrations', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'cache',
        'cache_locks', 'password_resets', 'password_reset_tokens', 'personal_access_tokens',
        'telescope_entries', 'telescope_entries_tags', 'telescope_monitoring',
        'notifications', 'audit_log', 'audit_logs', 'activity_log', 'settings',
    ];

    /** @var array<int, array{dataset: string, kind: string, detail: string, fix: string}> */
    private array $findings = [];

    public function handle(SchemaRegistry $registry): int
    {
        // Artisan reuses a resolved command instance, and a long-lived process
        // -  Octane, a queue worker, a test run, `Artisan::call()` twice in one
        // request -  calls handle() again on the same object. Accumulated
        // findings would be reported a second time alongside the new ones, so
        // the audit's count grows every run while the schema stays still.
        $this->findings = [];

        $only = $this->option('dataset');
        $datasets = $registry->all();

        if ($only !== null) {
            if (!isset($datasets[$only])) {
                $this->error("No such dataset: {$only}");

                return self::FAILURE;
            }

            $datasets = [$only => $datasets[$only]];
        }

        if (!$datasets) {
            $this->warn('No schemas registered. Run `php artisan jeeves:discover` first.');

            return self::SUCCESS;
        }

        foreach ($datasets as $key => $schema) {
            $this->auditDataset((string) $key, $schema);
        }

        // Only meaningful across the whole registry, so it is skipped when the
        // audit is narrowed to one dataset.
        if ($only === null) {
            $this->auditCompetingMeasures($datasets);
        }

        return $this->option('json') ? $this->emitJson() : $this->emitReport(count($datasets));
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function auditDataset(string $key, array $schema): void
    {
        $table = (string) ($schema['tables']['primary']['name'] ?? '');

        // The shipped template names a placeholder table. Auditing it produces
        // findings about a file that queries nothing.
        if (SchemaRegistry::isUntouchedTemplate(['tables' => ['primary' => ['name' => $table]]])) {
            return;
        }

        if ($this->isInfrastructure($table)) {
            $this->add($key, 'infrastructure', "`{$table}` looks like an infrastructure table",
                'It answers no business question, and every table in the registry is another set of '
                . 'columns the model can pick a wrong one from. Delete its schema file, and add it to '
                . 'jeeves.schema.discover_exclude so the next discover run does not recreate it.');
        }

        if (trim((string) ($schema['description'] ?? '')) === '') {
            $this->add($key, 'dataset-description', 'The dataset has no description',
                'One sentence saying what a row IS -  "one row per despatched order line" -  stops the '
                . 'model inferring it from the table name.');
        }

        if (empty($schema['aliases'])) {
            $this->add($key, 'aliases', 'No aliases',
                "Users will not type \"{$key}\". Add the words they actually say -  sales, orders, "
                . 'invoices -  so a question routes here without an API call.');
        }

        $columns = $schema['tables']['primary']['columns'] ?? [];
        $undescribed = [];
        $unitless = [];

        foreach ($columns as $name => $column) {
            if (trim((string) ($column['description'] ?? '')) === '') {
                $undescribed[] = (string) $name;
            }

            // A number with no unit is rendered bare, so 1500 could be rupees,
            // kilograms or a count, and nothing in the answer says which.
            if (!empty($column['aggregatable']) && trim((string) ($column['unit'] ?? '')) === '') {
                $unitless[] = (string) $name;
            }
        }

        if ($undescribed) {
            $this->add($key, 'column-descriptions',
                count($undescribed) . ' of ' . count($columns) . ' columns have no description: '
                    . implode(', ', array_slice($undescribed, 0, 8))
                    . (count($undescribed) > 8 ? ' …' : ''),
                'The model sees only the name. A column called `status_cd` or `amt2` is a guess; a '
                . 'described one is not.');
        }

        // NQ-003. A credential column already written into a schema file.
        //
        // The introspectors withhold these now, so a file generated from 3.0
        // onwards cannot carry one. A file generated BEFORE 3.0 - or written by
        // hand, or copied from another project - keeps whatever it was given,
        // and the fix in discovery cannot reach back and change it. Those
        // columns stay selectable, groupable and filterable, and `$hidden` does
        // not apply because generated SQL is `DB::select()`.
        //
        // Reported first: it is the only finding in this command that is a
        // disclosure rather than a quality problem.
        $credentials = array_values(array_filter(
            array_map('strval', array_keys($columns)),
            fn (string $name): bool => $this->looksLikeCredential($name)
        ));

        if ($credentials !== []) {
            $this->add($key, 'credential-columns',
                'Columns that look like credentials are exposed to queries: ' . implode(', ', $credentials),
                'Delete them from this schema file. They are selectable, groupable and filterable, '
                . 'and "show me all users" returns them in the HTTP response - Eloquent\'s $hidden '
                . 'does not apply, because generated SQL is DB::select(), and an encrypted cast comes '
                . 'back as ciphertext rather than decrypted. Discovery withholds these from now on; '
                . 'a file written before 3.0 keeps what it was given, which is why this is a warning '
                . 'and not a silent repair - editing your schema file behind your back is not this '
                . 'command\'s job.');
        }

        // A rule the schema cannot imply, and the only kind of gap here whose
        // absence produces a WRONG NUMBER rather than a vague answer.
        //
        // A `status` column holding "cancelled" is the classic case: nothing in
        // the type, the name or the foreign keys says whether those rows count
        // towards a total, so the model includes them, and the total is wrong
        // in a way nobody notices. Only a person knows. This cannot detect the
        // rule -  it detects the SHAPE of a column that usually needs one.
        $noRule = trim((string) ($schema['tables']['primary']['required_filter'] ?? '')) === '';

        // A declared `values` list names the excluded value exactly, so it is
        // always the better prompt. Ordered first because only one finding is
        // reported per dataset: iterating the columns in declaration order let
        // a soft-delete timestamp preempt a `status` column that already said
        // what it holds, which is the ordinary Laravel shape.
        $candidates = [];

        foreach ($noRule ? $columns : [] as $name => $column) {
            if ($this->exclusionValues($column)) {
                $candidates[(string) $name] = $column;
            }
        }

        foreach ($noRule ? $columns : [] as $name => $column) {
            if (!isset($candidates[(string) $name]) && $this->looksExcludable((string) $name)) {
                $candidates[(string) $name] = $column;
            }
        }

        foreach ($candidates as $name => $column) {
            $excluding = $this->exclusionValues($column);

            $detail = $excluding
                ? "`{$name}` holds " . implode('/', $excluding) . ' and this dataset has no required_filter'
                : "`{$name}` looks like it marks rows that should not count, and this dataset has no required_filter";

            $this->add($key, 'required-filter',
                $detail,
                'Should those rows count towards totals? If not, say so once and every query obeys: '
                . "'required_filter' => \"" . $this->suggestedPredicate((string) $name, $column, $excluding)
                . '" in the table block. Intent mode '
                . 'appends it to the SQL; SQL generation refuses an answer that omits it. Without a rule '
                . 'the model decides, and a total that quietly includes cancelled rows looks exactly like '
                . 'a correct one.');

            break; // One prompt per dataset is enough; the point is made.
        }

        if ($unitless) {
            $this->add($key, 'units', 'Measures with no unit: ' . implode(', ', $unitless),
                'Answers render the number bare, so 1500 could be currency, kilograms or a count. '
                . "Add 'unit' => '₹' or 'kg' and the answer says so.");
        }
    }

    /**
     * Words that would route to more than one dataset.
     *
     * This is the ambiguity introspection can never resolve, and the one that
     * produces a plausible wrong number rather than an error: ask for
     * "revenue" when two datasets both offer it and the model picks, silently.
     *
     * @param  array<string, mixed>  $datasets
     */
    private function auditCompetingMeasures(array $datasets): void
    {
        $claims = [];

        foreach ($datasets as $key => $schema) {
            $table = (string) ($schema['tables']['primary']['name'] ?? '');

            if (SchemaRegistry::isUntouchedTemplate(['tables' => ['primary' => ['name' => $table]]])) {
                continue;
            }

            foreach ($schema['tables']['primary']['columns'] ?? [] as $name => $column) {
                if (empty($column['aggregatable'])) {
                    continue;
                }

                // The column's own name and every word a user might use for
                // it: those are the terms this dataset lays claim to.
                foreach (array_merge([(string) $name], (array) ($column['aliases'] ?? [])) as $term) {
                    $claims[strtolower(trim((string) $term))][$key] = "{$key}.{$name}";
                }
            }
        }

        $settled = trim((string) config('jeeves.system_instructions', '')) !== '';

        foreach ($claims as $term => $owners) {
            if (count($owners) < 2) {
                continue;
            }

            $this->add('(across datasets)', 'competing-measure',
                "\"{$term}\" could mean any of: " . implode(', ', $owners),
                $settled
                    ? 'system_instructions is set -  check it names this term explicitly, because the '
                    . 'model chooses whenever it does not.'
                    : 'Nothing says which is authoritative, so the model picks and a wrong pick returns '
                    . 'a plausible number for a different question. Write it in '
                    . "'system_instructions': \"{$term} means SUM(" . reset($owners) . '), never the '
                    . 'others." This is the single highest-value sentence you can add.');
        }
    }

    /**
     * Values in a column that usually mean "do not count this row".
     *
     * Read from the schema file's own `values` list, not guessed from the
     * database -  this command contacts nothing and reads no data, and a column
     * whose permitted values someone has already written down is the only
     * place this can be known from structure alone.
     *
     * @param  array<string, mixed>  $column
     * @return array<int, string>
     */
    private function exclusionValues(array $column): array
    {
        static $suspect = [
            'cancelled', 'canceled', 'void', 'voided', 'deleted', 'draft',
            'refunded', 'reversed', 'rejected', 'archived', 'test',
        ];

        $found = [];

        foreach ((array) ($column['values'] ?? []) as $value) {
            if (in_array(strtolower(trim((string) $value)), $suspect, true)) {
                $found[] = (string) $value;
            }
        }

        return $found;
    }

    /**
     * Whether a column's NAME marks it as the kind that usually needs a rule.
     *
     * The check used to rest entirely on `values`, a key `discover` has never
     * written and that its merge deleted if a human added it by hand -  so on
     * the documented discover → audit → curate loop this finding could not
     * fire at all. It read as "nothing to add" on exactly the schemas that
     * needed the most.
     *
     * Names are used rather than sampled contents because a schema file is
     * sent to the model, and the privacy wall admits schema STRUCTURE only.
     *
     * The list is deliberately narrow. A plain `status` column is not on it:
     * nearly every table has one, most of them hold values that all count, and
     * an audit that fires on every dataset is an audit adopters learn to skip
     * -  which costs more than the check gains. What is listed are names that
     * do not merely permit exclusion but state it. A `deleted_at` with no rule
     * is a near-certain gap; a `status` with no rule is a maybe, and the
     * `values` path above still catches those when the adopter has said what
     * the column holds.
     */
    /**
     * A predicate that KEEPS the rows that count, matched to the column shape.
     *
     * This is copy-pasteable PHP that becomes a rule applied to every query, so
     * getting it wrong is not a bad hint — it is a wrong number on every
     * answer, reported as a success.
     *
     * The first version of this suggested `{$name} != 'cancelled'` for every
     * column, including soft-delete timestamps. `deleted_at != 'cancelled'`
     * evaluates to NULL for every live row, because `NULL != 'cancelled'` is
     * NULL and not TRUE — so the rule excludes every row that should count and
     * keeps only the deleted ones. Measured on a three-row fixture: 350 total,
     * 300 correct, 50 with that rule. Exactly inverted.
     *
     * @param  array<int, string>  $excluding  values the adopter declared, if any
     */
    private function suggestedPredicate(string $name, array $column, array $excluding): string
    {
        // A declared value names the exclusion exactly; nothing to infer.
        if ($excluding) {
            return "{$name} != '{$excluding[0]}'";
        }

        $type = strtolower((string) ($column['type'] ?? ''));
        $lower = strtolower($name);

        // A nullable marker column — `deleted_at`, `cancelled_at` — marks the
        // row by being SET, so the rows that count are the null ones.
        if (str_ends_with($lower, '_at') || str_contains($type, 'time') || str_contains($type, 'date')) {
            return "{$name} IS NULL";
        }

        // A boolean flag. `= 0` rather than `IS NOT TRUE` because it reads the
        // same on every driver this package supports.
        if (str_starts_with($lower, 'is_') || str_contains($type, 'bool') || str_contains($type, 'int') || str_contains($type, 'tiny')) {
            return "{$name} = 0";
        }

        // Unknown shape: a placeholder the adopter must replace, phrased so it
        // cannot be pasted unread.
        return "{$name} != 'REPLACE_WITH_THE_VALUE_TO_EXCLUDE'";
    }

    /**
     * NQ-003. Reuses the introspectors' own list, deliberately.
     *
     * Two lists would drift, and the direction they drift in is silent: a name
     * added to discovery but not here means a schema file already carrying that
     * column is never reported, and the adopter is told everything is fine.
     */
    private function looksLikeCredential(string $name): bool
    {
        foreach ($this->secretColumnPatterns() as $pattern) {
            if (fnmatch($pattern, strtolower($name))) {
                return true;
            }
        }

        return false;
    }

    private function looksExcludable(string $name): bool
    {
        static $suspect = [
            'is_deleted', 'is_cancelled', 'is_canceled', 'is_archived', 'is_void',
            'is_voided', 'is_test', 'is_draft', 'deleted_at', 'cancelled_at',
            'canceled_at', 'archived_at', 'voided_at', 'soft_deleted',
        ];

        return in_array(strtolower(trim($name)), $suspect, true);
    }

    private function isInfrastructure(string $table): bool
    {
        $bare = strtolower((string) preg_replace('/^.*\./', '', $table));

        return in_array($bare, self::INFRASTRUCTURE, true);
    }

    private function add(string $dataset, string $kind, string $detail, string $fix): void
    {
        $this->findings[] = compact('dataset', 'kind', 'detail', 'fix');
    }

    private function emitJson(): int
    {
        $this->line((string) json_encode([
            'findings' => $this->findings,
            'total' => count($this->findings),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function emitReport(int $datasetCount): int
    {
        $this->newLine();
        $this->line('  <fg=cyan;options=bold>Jeeves Schema Audit</>');
        $this->line("  <fg=gray>{$datasetCount} dataset(s). Nothing was sent anywhere.</>");
        $this->newLine();

        if (!$this->findings) {
            $this->line('  <fg=green;options=bold>Nothing to add.</> Every column is described, every '
                . 'dataset is named, and no term is ambiguous.');
            $this->newLine();

            return self::SUCCESS;
        }

        $byDataset = [];

        foreach ($this->findings as $f) {
            $byDataset[$f['dataset']][] = $f;
        }

        foreach ($byDataset as $dataset => $items) {
            $this->line("  <options=bold>{$dataset}</>");

            foreach ($items as $item) {
                $this->line("    <fg=yellow>!</> {$item['detail']}");
                $this->line("      <fg=gray>{$item['fix']}</>");
            }

            $this->newLine();
        }

        $competing = count(array_filter($this->findings, fn ($f) => $f['kind'] === 'competing-measure'));

        $this->line('  ' . count($this->findings) . ' thing(s) the model currently has to guess.');

        if ($competing > 0) {
            $this->line('  <fg=yellow>Start with the ambiguous terms</> -  those are the ones that return a '
                . 'wrong number rather than a poor one.');
        }

        $this->newLine();

        // Exit 0 deliberately: an unaudited schema is not a broken install,
        // and a non-zero code here would fail CI for every adopter who has not
        // finished writing descriptions.
        return self::SUCCESS;
    }
}
