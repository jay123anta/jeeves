<?php

namespace Jayanta\Jeeves\Tests\Benchmark;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Jayanta\Jeeves\Contracts\SchemaIntrospectorInterface;
use Jayanta\Jeeves\Engine\NextStepSuggester;
use Jayanta\Jeeves\Engine\PromptBuilder;
use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Engine\QueryPlanner;
use Jayanta\Jeeves\Engine\SqlBuilder;
use Jayanta\Jeeves\Schema\SchemaRegistry;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The package against REAL questions from the Spider dev set.
 *
 * Everything measured so far used questions written here, against a schema
 * written here. That measures whether the package does what its author
 * expected, which is worth something and is not the same as accuracy. Spider
 * is the standard benchmark for this task: 200 databases, human-written
 * questions, human-written gold SQL, and nobody involved has seen this package.
 *
 * WHAT IS REAL: the schema (Spider's published concert_singer definition, four
 * tables with its real foreign keys), the 45 dev questions, and the gold SQL.
 * The package is given no curation of any kind -  no system_instructions, no
 * aliases, no llm_instructions. Discovery runs and that is all it gets. This is
 * the hardest and most honest test available: an unfamiliar schema, phrased by
 * strangers.
 *
 * WHAT IS NOT REAL, and what that costs:
 *
 * Spider does not distribute its SQLite files through the channel available
 * here, so the tables are created from the published schema and filled with
 * synthetic rows. Execution accuracy compares result sets, and both queries run
 * against the same synthetic data, so the COMPARISON stays valid -  but the
 * resulting number is NOT comparable to a published Spider score, which uses
 * the real data.
 *
 * Synthetic data also creates a specific way to cheat: if gold returns nothing,
 * a generated query returning nothing "matches" while having answered nothing.
 * Spider's own test-suite accuracy exists to close that hole. The mitigation
 * here is cruder and stated plainly: questions whose gold SQL returns no rows
 * on this data are EXCLUDED from the score rather than counted as passes.
 */
class SpiderSampleTest extends TestCase
{
    private string $schemaPath;

    #[Test]
    public function it_answers_real_spider_questions()
    {
        if (env('JEEVES_BENCHMARK') !== '1') {
            $this->markTestSkipped('Set JEEVES_BENCHMARK=1 to run the benchmark (uses live API calls).');
        }

        if (!env('JEEVES_BENCHMARK_KEY')) {
            $this->markTestSkipped('Set JEEVES_BENCHMARK_KEY to the provider API key.');
        }

        // One database is one schema's worth of evidence. Several, with
        // different shapes, is the difference between a number and a claim.
        $databases = array_filter(
            array_map('trim', explode(',', (string) (env('JEEVES_BENCHMARK_DB') ?: 'concert_singer,car_1')))
        );

        $results = [];

        foreach ($databases as $dbId) {
            $this->prepare($dbId);

            try {
                foreach ($this->runQuestions($dbId) as $row) {
                    $results[] = $row + ['db' => $dbId];
                }
            } finally {
                $this->cleanUp();
            }
        }

        $this->report($results);

        $scored = array_values(array_filter($results, fn ($r) => !$r['skipped']));
        $correct = count(array_filter($scored, fn ($r) => $r['correct']));

        $this->assertNotEmpty($scored, 'no question produced a gold result to compare against');

        // Deliberately loose. This is an unfamiliar schema with no curation and
        // questions phrased by strangers; the number it prints is the point,
        // and the assertion only catches a collapse.
        $this->assertGreaterThan(
            0,
            $correct,
            'not one real Spider question was answered correctly'
        );
    }

    private function prepare(string $dbId): void
    {
        $driver = env('JEEVES_LLM_DRIVER', 'gemini');

        config([
            'jeeves.llm.driver' => $driver,
            "jeeves.llm.providers.{$driver}.api_key" => env('JEEVES_BENCHMARK_KEY'),
            'jeeves.cache.enabled' => false,
            'jeeves.feedback.enabled' => false,
            // No curation whatsoever. Whatever discovery produces is what the
            // model is told, which is the situation on day one of an install.
            'jeeves.system_instructions' => '',
        ]);

        if ($caBundle = env('JEEVES_SSL_VERIFY')) {
            config(['jeeves.ssl_verify' => $caBundle]);
        }

        $this->schemaPath = sys_get_temp_dir() . '/nq-spider-' . getmypid() . '-' . $dbId;

        if (!is_dir($this->schemaPath)) {
            mkdir($this->schemaPath, 0777, true);
        }

        config(['jeeves.schema.config_path' => $this->schemaPath]);

        // Each database must start from nothing. Running two in one process
        // left the first one's tables in place and the registry still holding
        // the first one's config path, so discovery saw both schemas at once
        // and the second database scored zero -  a harness failure that reads
        // exactly like a package failure.
        $this->dropAllTables();
        $this->forgetSchemaBoundServices();

        $this->buildSpiderDatabase($dbId);

        Artisan::call('jeeves:discover', [
            '--output' => $this->schemaPath,
            '--no-verify' => true,
            '--force' => true,
        ]);

        $this->app->make(SchemaRegistry::class)->flush();
    }

    private function cleanUp(): void
    {
        foreach (glob(($this->schemaPath ?? '') . '/*.php') ?: [] as $file) {
            unlink($file);
        }

        if (!empty($this->schemaPath) && is_dir($this->schemaPath)) {
            rmdir($this->schemaPath);
        }
    }

    private function dropAllTables(): void
    {
        foreach (DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'") as $t) {
            DB::statement('DROP TABLE IF EXISTS "' . str_replace('"', '""', $t->name) . '"');
        }
    }

    /**
     * Anything holding a SchemaRegistry holds its config path with it, so the
     * whole chain has to go, not just the registry.
     */
    private function forgetSchemaBoundServices(): void
    {
        foreach ([
            SchemaRegistry::class,
            QueryOrchestrator::class,
            SqlBuilder::class,
            PromptBuilder::class,
            QueryPlanner::class,
            NextStepSuggester::class,
            SchemaIntrospectorInterface::class,
        ] as $service) {
            $this->app->forgetInstance($service);
        }
    }

    /**
     * Create one Spider database from its published schema and fill it.
     *
     * Table and column names are Spider's, untouched. Half the difficulty of
     * an unfamiliar schema is that it is not named the way you would name it.
     */
    private function buildSpiderDatabase(string $dbId): void
    {
        $databases = require __DIR__ . '/spider/databases.php';

        if (!isset($databases[$dbId])) {
            $this->fail("No definition for Spider database '{$dbId}'.");
        }

        foreach ($databases[$dbId]['ddl'] as $statement) {
            DB::statement($statement);
        }

        foreach ($databases[$dbId]['rows'] as $table => $rows) {
            DB::table($table)->insert($rows);
        }
    }

    /** @return array<int, array> */
    private function runQuestions(string $dbId): array
    {
        $all = require __DIR__ . "/spider/{$dbId}_questions.php";

        // Bounded because each question is a live API call on a free tier. The
        // cap is reported, never silent.
        $limit = (int) (env('JEEVES_BENCHMARK_LIMIT') ?: 20);
        $questions = array_slice($all, 0, $limit);

        $orchestrator = $this->app->make(QueryOrchestrator::class);
        $results = [];

        foreach ($questions as $i => $case) {
            if ($i > 0) {
                sleep(5);
            }

            $results[] = $this->score($orchestrator, $case) + ['total_available' => count($all)];
        }

        return $results;
    }

    private function score(QueryOrchestrator $orchestrator, array $case): array
    {
        try {
            $goldRows = DB::select($case['gold']);
        } catch (\Throwable $e) {
            return $case + ['correct' => false, 'skipped' => true, 'note' => 'gold SQL failed here', 'mode' => '-'];
        }

        // Both-empty would "match" while answering nothing. Excluded, not passed.
        if (empty($goldRows)) {
            return $case + ['correct' => false, 'skipped' => true, 'note' => 'gold empty on synthetic data', 'mode' => '-'];
        }

        $gold = $this->normalize($goldRows);

        try {
            $response = $orchestrator->query($case['question']);
        } catch (\Throwable $e) {
            return $case + ['correct' => false, 'skipped' => false, 'note' => 'exception', 'mode' => '-'];
        }

        $mode = $response['metadata']['query_mode_used'] ?? '-';

        if (($response['status'] ?? '') !== 'success') {
            return $case + [
                'correct' => false,
                'skipped' => false,
                'mode' => $mode,
                'note' => substr((string) ($response['error'] ?? $response['message'] ?? 'no answer'), 0, 48),
            ];
        }

        $actual = $this->normalize($response['rows'] ?? []);

        return $case + [
            'correct' => $actual === $gold,
            'skipped' => false,
            'mode' => $mode,
            'note' => $actual === $gold ? '' : 'got ' . $this->preview($actual) . ' want ' . $this->preview($gold),
        ];
    }

    /** Values only, order-insensitive: aliases and column order do not matter. */
    private function normalize(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $values = [];

            foreach ((array) $row as $value) {
                if ($value === null) {
                    continue;
                }
                $values[] = is_numeric($value)
                    ? (string) round((float) $value, 2)
                    : strtolower(trim((string) $value));
            }

            sort($values);
            $out[] = implode('|', $values);
        }

        sort($out);

        return $out;
    }

    private function preview(array $n): string
    {
        return '[' . implode(' ; ', array_slice($n, 0, 2)) . (count($n) > 2 ? ' …' : '') . ']';
    }

    private function report(array $results): void
    {
        $lines = ["\n  SPIDER dev -  no curation, synthetic data\n"];

        foreach ($results as $r) {
            $lines[] = sprintf(
                '  %-5s %-16s %-46s %-15s %s',
                $r['skipped'] ? 'skip' : ($r['correct'] ? ' ok ' : 'FAIL'),
                $r['db'] ?? '',
                substr($r['question'], 0, 46),
                $r['mode'],
                $r['note']
            );
        }

        // Per database, because one schema's worth of evidence is not a claim.
        $byDb = [];

        foreach ($results as $r) {
            if ($r['skipped']) {
                continue;
            }
            $db = $r['db'] ?? '?';
            $byDb[$db]['total'] = ($byDb[$db]['total'] ?? 0) + 1;
            $byDb[$db]['correct'] = ($byDb[$db]['correct'] ?? 0) + ($r['correct'] ? 1 : 0);
        }

        $lines[] = '';

        foreach ($byDb as $db => $b) {
            $lines[] = sprintf('  %-16s %d/%d', $db, $b['correct'], $b['total']);
        }

        $scored = array_values(array_filter($results, fn ($r) => !$r['skipped']));
        $correct = count(array_filter($scored, fn ($r) => $r['correct']));
        $skipped = count($results) - count($scored);

        $lines[] = '';
        $lines[] = sprintf(
            '  asked %d of %d available; %d excluded (gold empty on synthetic data)',
            count($results),
            $results[0]['total_available'] ?? count($results),
            $skipped
        );
        $lines[] = sprintf(
            "  SCORED  %d/%d (%.0f%%)\n",
            $correct,
            count($scored),
            count($scored) ? 100 * $correct / count($scored) : 0
        );

        fwrite(STDERR, implode("\n", $lines) . "\n");
    }
}
