<?php

namespace Jayanta\Jeeves\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Jayanta\Jeeves\Benchmark\ResultComparator;
use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Schema\SchemaRegistry;
use Jayanta\Jeeves\Security\ExecutionConnection;

/**
 * How accurate is this package on YOUR database?
 *
 * The package quotes its own figure -  roughly one question in five wrong on an
 * uncurated schema, near-perfect once curated -  and nobody should take that on
 * trust for their own data. This grades the same way that figure was produced:
 * run the generated query and a reference query you wrote by hand against the
 * same database, and compare the RESULT SETS. A query is right when its answer
 * is right, however differently it is written.
 *
 * Its real use is the before-and-after. Run it, run `jeeves:audit-schema`,
 * write the descriptions it asks for, run this again. The difference is what
 * curation bought you, in a number you produced yourself.
 *
 * This DOES call the provider -  one or more calls per question -  so it costs
 * real money and is never run implicitly.
 */
class BenchmarkCommand extends Command
{
    protected $signature = 'jeeves:benchmark
                            {--file= : PHP file returning the question set (default: config/jeeves-benchmark.php)}
                            {--dataset= : Only questions tagged with this dataset}
                            {--min= : Exit non-zero below this percentage, for CI}
                            {--json : Machine-readable output}';

    protected $description = 'Measure answer accuracy on your own schema against reference SQL you supply';

    public function handle(QueryOrchestrator $orchestrator, ResultComparator $comparator, SchemaRegistry $registry): int
    {
        $questions = $this->loadQuestions();

        if ($questions === null) {
            return self::FAILURE;
        }

        if ($dataset = $this->option('dataset')) {
            $questions = array_values(array_filter(
                $questions,
                fn ($q) => ($q['dataset'] ?? null) === $dataset
            ));
        }

        if (!$questions) {
            $this->warn('No questions to run.');

            return self::SUCCESS;
        }

        $count = count($questions);

        // Never bill someone by surprise. --no-interaction skips the prompt,
        // which is what CI passes.
        if (!$this->confirm("Run {$count} question(s)? This calls your AI provider and costs money.", true)) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        $results = [];

        foreach ($questions as $i => $q) {
            $results[] = $this->runQuestion($orchestrator, $comparator, $registry, $q, $i + 1, $count);
        }

        return $this->option('json')
            ? $this->emitJson($results)
            : $this->emitReport($results);
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function loadQuestions(): ?array
    {
        $path = $this->option('file')
            ?: (function_exists('config_path') ? config_path('jeeves-benchmark.php') : null);

        if (!$path || !is_file($path)) {
            $this->error('No question set found' . ($path ? " at {$path}" : '') . '.');
            $this->newLine();
            $this->line('  Create one: a PHP file returning an array of questions with the SQL you');
            $this->line('  would have written by hand. There is a commented example in the package at');
            $this->line('  <fg=cyan>stubs/benchmark-example.php</>.');
            $this->newLine();
            $this->line('  <fg=gray>Write the reference SQL from the QUESTION, never from what the</>');
            $this->line('  <fg=gray>package produced -  otherwise you are marking its own homework.</>');

            return null;
        }

        $questions = require $path;

        if (!is_array($questions) || !$questions) {
            $this->error("{$path} did not return a non-empty array of questions.");

            return null;
        }

        foreach ($questions as $i => $q) {
            $hasGold = !empty($q['gold']);
            $hasExpect = !empty($q['expect']);

            // A question is graded against a reference query, against checks
            // on the answer, or both. With neither there is nothing to grade.
            if (empty($q['question']) || (!$hasGold && !$hasExpect)) {
                $this->error('Question #' . ($i + 1) . " needs a 'question' and either a reference query in 'gold' or checks in 'expect'.");

                return null;
            }

            // The reference SQL runs unvalidated against the adopter's own
            // database. It is their SQL, but a benchmark file is the kind of
            // thing that gets copied between projects, and a stray UPDATE in
            // one would be executed without a word. Reads only.
            if ($hasGold && !preg_match('/^\s*(SELECT|WITH)\b/i', (string) $q['gold'])) {
                $this->error('Question #' . ($i + 1) . "'s reference SQL is not a SELECT. Refusing to run it.");

                return null;
            }

            // Refused up front rather than failing every question at run time:
            // a misspelt check would otherwise read as the package being wrong.
            if ($hasExpect && ($problem = $this->invalidExpectation($q['expect'])) !== null) {
                $this->error('Question #' . ($i + 1) . ': ' . $problem);

                return null;
            }
        }

        return array_values($questions);
    }

    /**
     * @param  array<string, mixed>  $q
     * @return array<string, mixed>
     */
    private function runQuestion(QueryOrchestrator $orchestrator, ResultComparator $comparator, SchemaRegistry $registry, array $q, int $n, int $of): array
    {
        $question = (string) $q['question'];
        $ordered = (bool) ($q['ordered'] ?? false);

        $quiet = (bool) $this->option('json');
        $tick = function (string $verdict) use ($quiet) {
            if (!$quiet) {
                $this->line($verdict);
            }
        };

        if (!$quiet) {
            $this->output->write(sprintf('  [%d/%d] %s … ', $n, $of, mb_strimwidth($question, 0, 48, '…')));
        }

        $row = [
            'question' => $question,
            'hardness' => $q['hardness'] ?? null,
            'status' => 'failed',
            'reason' => null,
        ];

        // The engine runs FIRST, so the reference SQL can be run wherever the
        // engine actually went.
        //
        // Both must read the same database or the accuracy figure — the whole
        // output of this command — compares rows from two different ones. The
        // engine resolves the connection from the dataset it RESOLVED, which
        // is not necessarily the one the question named: `dataset` is optional
        // in a benchmark file and the shipped example leaves it out. Reading
        // it from the question therefore fixed nothing in the documented case.
        //
        // The cost of this ordering is one provider call spent before a broken
        // reference query is discovered. That is the right way round: an
        // adopter learns about both problems in the same run.
        try {
            $answer = $orchestrator->query($question, $q['dataset'] ?? null);
        } catch (\Throwable $e) {
            $tick('<fg=red>error</>');

            return array_merge($row, ['reason' => 'the engine threw: ' . $e->getMessage()]);
        }

        if (($answer['status'] ?? null) !== 'success') {
            // A clarification is the model asking instead of answering, and its
            // text is in `message`, not `error`. Reading only `error` reported
            // every clarification as a bare "no answer" - on a real database it
            // hid that the model had asked which metric was meant. Still graded
            // as not correct: a question is not an answer.
            if (($answer['status'] ?? null) === 'clarification_needed') {
                $tick('<fg=yellow>asked instead</>');

                return array_merge($row, [
                    'reason' => 'asked for clarification: ' . ($answer['message'] ?? 'no message'),
                    'error_code' => $answer['error_code'] ?? null,
                ]);
            }

            $tick('<fg=red>no answer</>');

            return array_merge($row, ['reason' => $answer['error'] ?? 'no answer', 'error_code' => $answer['error_code'] ?? null]);
        }

        // A reference query, when the question has one. Checks in `expect` run
        // after it, so a question carrying both has to satisfy both.
        if (!empty($q['gold'])) {
            try {
                $dataset = $answer['parsed_query']['dataset'] ?? ($q['dataset'] ?? null);
                // NQ-001. The gold answer is hand-written rather than generated,
                // so this is not the finding itself -  but it is a benchmark run
                // executing arbitrary SQL, and the reason to point it at anything
                // other than the read-only connection is nil. Same rule, so the
                // command cannot become the one place the guarantee is absent.
                $connection = ExecutionConnection::resolve(
                    is_string($dataset) && $registry->has($dataset)
                        ? $registry->getConnection($dataset)
                        : null
                );

                $expected = DB::connection($connection)->select((string) $q['gold']);
            } catch (\Throwable $e) {
                $tick('<fg=yellow>reference SQL failed</>');

                return array_merge($row, ['reason' => 'the reference SQL did not run: ' . $e->getMessage()]);
            }

            if (!$comparator->matches($expected, $answer['rows'] ?? [], $ordered)) {
                $tick('<fg=red>wrong</>');

                return array_merge($row, [
                    'reason' => 'different result',
                    'expected' => $comparator->preview($comparator->normalize($expected, $ordered)),
                    'got' => $comparator->preview($comparator->normalize($answer['rows'] ?? [], $ordered)),
                    // The most useful thing on a wrong answer: what it thought it was
                    // being asked. Nine times in ten the misreading is right there.
                    'understood_as' => $answer['parsed_summary'] ?? null,
                ]);
            }
        }

        // Checks on the answer itself. They grade what a reference query cannot
        // always say - that a figure falls in a plausible range, that a name
        // made the list - and they close the loophole a bare reference leaves
        // open: a SUM over no rows is one row holding NULL, the comparator
        // skips NULLs, and a reference returning the same NULL "matches". An
        // answer about no data graded correct.
        if (!empty($q['expect'])) {
            $unmet = $this->unmetExpectations((array) $q['expect'], $answer['rows'] ?? []);

            if ($unmet !== []) {
                $tick('<fg=red>wrong</>');

                return array_merge($row, [
                    'reason' => 'expectation not met: ' . implode('; ', $unmet),
                    'understood_as' => $answer['parsed_summary'] ?? null,
                ]);
            }
        }

        $tick('<fg=green>correct</>');

        return array_merge($row, ['status' => 'correct', 'understood_as' => $answer['parsed_summary'] ?? null]);
    }

    /**
     * Why an `expect` block cannot be used, or null when it can.
     */
    private function invalidExpectation(mixed $expect): ?string
    {
        if (!is_array($expect)) {
            return "'expect' must be an array of checks.";
        }

        $known = ['rows', 'min_rows', 'max_rows', 'min', 'max', 'column', 'contains'];
        $unknown = array_diff(array_keys($expect), $known);

        if ($unknown !== []) {
            return "'expect' has unknown check(s): " . implode(', ', $unknown)
                . '. Known: ' . implode(', ', $known) . '.';
        }

        foreach (['rows', 'min_rows', 'max_rows'] as $key) {
            if (isset($expect[$key]) && (!is_int($expect[$key]) || $expect[$key] < 0)) {
                return "'expect.{$key}' must be a whole number of rows.";
            }
        }

        foreach (['min', 'max'] as $key) {
            if (isset($expect[$key]) && !is_numeric($expect[$key])) {
                return "'expect.{$key}' must be a number.";
            }
        }

        // A nested list passed here once, and died at grading time with "Array
        // to string conversion" - after every question had been paid for.
        foreach ((array) ($expect['contains'] ?? []) as $wanted) {
            if (!is_scalar($wanted)) {
                return "'expect.contains' must be a name or a list of names, not a nested list.";
            }
        }

        if (isset($expect['column']) && !isset($expect['min']) && !isset($expect['max'])) {
            return "'expect.column' names the value 'min' and 'max' check, and neither is set.";
        }

        return null;
    }

    /**
     * Every check in `expect` the answer fails, in words a person can act on.
     *
     * @param  array<string, mixed>  $expect
     * @param  array<int, mixed>  $rows
     * @return array<int, string>
     */
    private function unmetExpectations(array $expect, array $rows): array
    {
        $unmet = [];
        $count = count($rows);

        if (isset($expect['rows']) && $count !== (int) $expect['rows']) {
            $unmet[] = "{$count} row(s), expected exactly {$expect['rows']}";
        }

        if (isset($expect['min_rows']) && $count < (int) $expect['min_rows']) {
            $unmet[] = "{$count} row(s), expected at least {$expect['min_rows']}";
        }

        if (isset($expect['max_rows']) && $count > (int) $expect['max_rows']) {
            $unmet[] = "{$count} row(s), expected at most {$expect['max_rows']}";
        }

        if (isset($expect['min']) || isset($expect['max'])) {
            $column = isset($expect['column']) ? (string) $expect['column'] : null;
            $value = $this->checkedValue($rows, $column);

            if ($value === null) {
                $unmet[] = $column !== null
                    ? "no number in column '{$column}' of the first row"
                    : 'no number in the first row';
            } else {
                if (isset($expect['min']) && $value < (float) $expect['min']) {
                    $unmet[] = "{$value} is below the minimum {$expect['min']}";
                }

                if (isset($expect['max']) && $value > (float) $expect['max']) {
                    $unmet[] = "{$value} is above the maximum {$expect['max']}";
                }
            }
        }

        foreach ((array) ($expect['contains'] ?? []) as $wanted) {
            if (!$this->rowsContain($rows, (string) $wanted)) {
                $unmet[] = "no row contains '{$wanted}'";
            }
        }

        return $unmet;
    }

    /**
     * The number a min/max check reads: the named column of the first row, or
     * the first numeric cell of it when no column is named.
     *
     * @param  array<int, mixed>  $rows
     */
    private function checkedValue(array $rows, ?string $column): ?float
    {
        $first = (array) ($rows[0] ?? []);

        if ($column !== null) {
            $value = $first[$column] ?? null;

            return is_numeric($value) ? (float) $value : null;
        }

        foreach ($first as $value) {
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    /**
     * Whether any cell of any row equals $wanted, ignoring case and the space
     * around it.
     *
     * @param  array<int, mixed>  $rows
     */
    private function rowsContain(array $rows, string $wanted): bool
    {
        $wanted = mb_strtolower(trim($wanted));

        foreach ($rows as $row) {
            foreach ((array) $row as $cell) {
                if (is_scalar($cell) && mb_strtolower(trim((string) $cell)) === $wanted) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     */
    private function emitJson(array $results): int
    {
        $correct = count(array_filter($results, fn ($r) => $r['status'] === 'correct'));

        $this->line((string) json_encode([
            'total' => count($results),
            'correct' => $correct,
            'accuracy' => count($results) ? round($correct / count($results) * 100, 1) : 0.0,
            'results' => $results,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $this->exitCode($results);
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     */
    private function emitReport(array $results): int
    {
        $total = count($results);
        $correct = count(array_filter($results, fn ($r) => $r['status'] === 'correct'));
        $wrong = array_filter($results, fn ($r) => $r['status'] !== 'correct');

        $this->newLine();

        if ($wrong) {
            $this->line('  <options=bold>Not correct</>');
            $this->newLine();

            foreach ($wrong as $r) {
                $this->line("    <fg=red>✗</> {$r['question']}");
                $this->line("      <fg=gray>{$r['reason']}</>");

                if (!empty($r['understood_as'])) {
                    $this->line("      <fg=gray>read as: {$r['understood_as']}</>");
                }

                if (isset($r['expected'])) {
                    $this->line("      <fg=gray>expected {$r['expected']}</>");
                    $this->line("      <fg=gray>got      {$r['got']}</>");
                }

                $this->newLine();
            }
        }

        $pct = $total ? round($correct / $total * 100, 1) : 0.0;
        $this->line("  <options=bold>{$correct}/{$total} correct ({$pct}%)</>");

        // By hardness, when the set says so. A package that answers every easy
        // question and no hard one is a different proposition from one that is
        // uniformly patchy, and the average hides which you have.
        $tiers = array_filter(array_unique(array_column($results, 'hardness')));

        if ($tiers) {
            sort($tiers);

            foreach ($tiers as $tier) {
                $inTier = array_filter($results, fn ($r) => ($r['hardness'] ?? null) === $tier);
                $ok = count(array_filter($inTier, fn ($r) => $r['status'] === 'correct'));
                $this->line(sprintf('    %-8s %d/%d', $tier, $ok, count($inTier)));
            }
        }

        if ($wrong) {
            $this->newLine();
            $this->line('  <fg=gray>Run `php artisan jeeves:audit-schema` -  most wrong answers are a</>');
            $this->line('  <fg=gray>missing description or an ambiguous term, not a model failure. Fix</>');
            $this->line('  <fg=gray>those, run this again, and compare.</>');
        }

        $this->newLine();

        return $this->exitCode($results);
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     */
    private function exitCode(array $results): int
    {
        $min = $this->option('min');

        if ($min === null) {
            return self::SUCCESS;
        }

        $total = count($results);
        $correct = count(array_filter($results, fn ($r) => $r['status'] === 'correct'));
        $pct = $total ? $correct / $total * 100 : 0.0;

        return $pct + 1e-9 >= (float) $min ? self::SUCCESS : self::FAILURE;
    }
}
