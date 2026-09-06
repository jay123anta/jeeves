<?php

namespace Jayanta\Jeeves\Tests\Benchmark;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jayanta\Jeeves\Benchmark\ResultComparator;
use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Schema\SchemaRegistry;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Execution accuracy end to end, against a live model.
 *
 * "How accurate is it?" is the first question any evaluator asks, and until
 * now the honest answer was "nobody has measured". This measures it the way
 * the field does: ask in English, run whatever SQL comes out, run hand-written
 * gold SQL, compare the answers.
 *
 * WHAT THIS IS NOT. It is not a Spider or BIRD score. Those are 200-database
 * suites with thousands of questions, and a number from this set is not
 * comparable to a published one. This is a small set on one schema, authored
 * here, graded by the same METHOD. It answers "does this package answer
 * ordinary questions about an ordinary database correctly", which is the
 * question an adopter actually has.
 *
 * Skipped unless JEEVES_BENCHMARK=1 -  it costs real API calls, and a
 * test suite that silently spends money on every run is a bad neighbour.
 *
 *   JEEVES_BENCHMARK=1 vendor/bin/phpunit --filter ExecutionAccuracyTest
 */
class ExecutionAccuracyTest extends TestCase
{
    private string $schemaPath;

    /**
     * Prepare the provider, the database and the schema files.
     *
     * Deliberately NOT in setUp(): skipping from setUp() aborts after Laravel
     * has installed its exception handlers but before the framework tears them
     * down, which PHPUnit reports as an error on every ordinary run of the
     * suite. Nothing here happens unless the benchmark is actually being run.
     */
    private function prepare(): void
    {
        // The base TestCase pins a fake key so nothing calls out by accident.
        // The benchmark needs a real provider, taken from the environment.
        $driver = env('JEEVES_LLM_DRIVER', 'gemini');

        config([
            'jeeves.llm.driver' => $driver,
            "jeeves.llm.providers.{$driver}.api_key" => env('JEEVES_BENCHMARK_KEY'),
        ]);

        // XAMPP and similar stacks ship no CA bundle, so an HTTPS call fails
        // with "unable to connect" rather than anything about certificates.
        // Point at one when the environment names it -  never disable
        // verification to make a benchmark run.
        if ($caBundle = env('JEEVES_SSL_VERIFY')) {
            config(['jeeves.ssl_verify' => $caBundle]);
        }

        $this->schemaPath = sys_get_temp_dir() . '/nq-benchmark-' . getmypid();

        if (!is_dir($this->schemaPath)) {
            mkdir($this->schemaPath, 0777, true);
        }

        config([
            'jeeves.schema.config_path' => $this->schemaPath,
            'jeeves.cache.enabled' => false,
            'jeeves.feedback.enabled' => false,
        ]);

        $this->buildDatabase();
        $this->applyCurationIfRequested();
    }

    /**
     * The domain knowledge a user would supply, and the package cannot infer.
     *
     * With fourteen tables there are two defensible answers to "revenue" -
     * line_total on the order items and amount on the payments -  and no amount
     * of introspection can tell which one the business means. Column names,
     * types and foreign keys are identical in kind.
     *
     * This is the package's central bet: discovery gives you a working base,
     * and the handful of things only you know go in config on top of it. The
     * bet is testable, so it is tested. Run with
     * JEEVES_BENCHMARK_CURATED=1 to measure the same questions with these
     * three sentences in place, and compare.
     */
    private function applyCurationIfRequested(): void
    {
        if (env('JEEVES_BENCHMARK_CURATED') !== '1') {
            return;
        }

        config(['jeeves.system_instructions' => <<<'TEXT'
            Revenue means SUM(bm_order_items.line_total). Never use
            bm_payments.amount as revenue -  payments record what has been
            collected, which is a different question from what was sold, and
            using it silently drops customers who have not paid yet.

            bm_sessions, bm_jobs, bm_audit_log and bm_settings are
            infrastructure tables. They never answer a business question.
            TEXT]);
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

    /**
     * A normalised schema of the size real applications actually have.
     *
     * Fourteen tables, not four. Everything that has gone wrong in this package
     * went wrong once more than one table was involved, and a small fixture
     * hides exactly those failures: the measure sits in one table, the label in
     * another, the filter in a third, and several questions need three or four
     * joins to answer. There are also tables that answer nothing at all -
     * `bm_sessions`, `bm_jobs`, `bm_audit_log` -  because a real database is
     * mostly noise and choosing the right tables is half the problem.
     */
    private function buildDatabase(): void
    {
        Schema::create('bm_regions', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('country');
        });

        Schema::create('bm_customers', function ($t) {
            $t->id();
            $t->foreignId('region_id')->constrained('bm_regions');
            $t->string('name');
            $t->string('segment');
            $t->date('joined_on');
        });

        Schema::create('bm_categories', function ($t) {
            $t->id();
            $t->string('name');
        });

        Schema::create('bm_suppliers', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('country');
        });

        Schema::create('bm_products', function ($t) {
            $t->id();
            $t->foreignId('category_id')->constrained('bm_categories');
            $t->foreignId('supplier_id')->constrained('bm_suppliers');
            $t->string('name');
            $t->decimal('unit_price', 10, 2);
        });

        Schema::create('bm_orders', function ($t) {
            $t->id();
            $t->foreignId('customer_id')->constrained('bm_customers');
            $t->date('order_date');
            $t->string('status');
        });

        Schema::create('bm_order_items', function ($t) {
            $t->id();
            $t->foreignId('order_id')->constrained('bm_orders');
            $t->foreignId('product_id')->constrained('bm_products');
            $t->integer('quantity');
            $t->decimal('line_total', 12, 2);
        });

        Schema::create('bm_payments', function ($t) {
            $t->id();
            $t->foreignId('order_id')->constrained('bm_orders');
            $t->decimal('amount', 12, 2);
            $t->string('method');
            $t->date('paid_on');
        });

        Schema::create('bm_shipments', function ($t) {
            $t->id();
            $t->foreignId('order_id')->constrained('bm_orders');
            $t->string('carrier');
            $t->date('shipped_on')->nullable();
        });

        Schema::create('bm_support_tickets', function ($t) {
            $t->id();
            $t->foreignId('customer_id')->constrained('bm_customers');
            $t->string('subject');
            $t->string('priority');
            $t->date('opened_on');
        });

        // Tables that answer none of the questions. A real database is mostly
        // these, and picking the right table out of the noise is half the job.
        Schema::create('bm_sessions', function ($t) {
            $t->id();
            $t->string('token');
            $t->date('created_on');
        });

        Schema::create('bm_jobs', function ($t) {
            $t->id();
            $t->string('queue');
            $t->integer('attempts');
        });

        Schema::create('bm_audit_log', function ($t) {
            $t->id();
            $t->string('action');
            $t->date('logged_on');
        });

        Schema::create('bm_settings', function ($t) {
            $t->id();
            $t->string('key_name');
            $t->string('value');
        });

        DB::table('bm_regions')->insert([
            ['name' => 'West', 'country' => 'UK'],
            ['name' => 'East', 'country' => 'UK'],
        ]);

        DB::table('bm_customers')->insert([
            ['region_id' => 1, 'name' => 'Ada Lovelace', 'segment' => 'enterprise', 'joined_on' => '2025-01-10'],
            ['region_id' => 2, 'name' => 'Grace Hopper', 'segment' => 'smb', 'joined_on' => '2025-03-02'],
            ['region_id' => 1, 'name' => 'Alan Turing', 'segment' => 'smb', 'joined_on' => '2026-02-20'],
        ]);

        DB::table('bm_categories')->insert([
            ['name' => 'Furniture'],
            ['name' => 'Electronics'],
        ]);

        DB::table('bm_suppliers')->insert([
            ['name' => 'Northwind', 'country' => 'UK'],
            ['name' => 'Contoso', 'country' => 'DE'],
        ]);

        DB::table('bm_products')->insert([
            ['category_id' => 1, 'supplier_id' => 1, 'name' => 'Desk', 'unit_price' => 250],
            ['category_id' => 1, 'supplier_id' => 2, 'name' => 'Chair', 'unit_price' => 120],
            ['category_id' => 2, 'supplier_id' => 2, 'name' => 'Monitor', 'unit_price' => 300],
        ]);

        DB::table('bm_orders')->insert([
            ['customer_id' => 1, 'order_date' => '2026-07-03', 'status' => 'delivered'],
            ['customer_id' => 1, 'order_date' => '2026-07-18', 'status' => 'delivered'],
            ['customer_id' => 1, 'order_date' => '2026-08-02', 'status' => 'cancelled'],
            ['customer_id' => 2, 'order_date' => '2026-07-11', 'status' => 'delivered'],
            ['customer_id' => 3, 'order_date' => '2026-06-28', 'status' => 'pending'],
        ]);

        DB::table('bm_order_items')->insert([
            ['order_id' => 1, 'product_id' => 1, 'quantity' => 2, 'line_total' => 500],
            ['order_id' => 2, 'product_id' => 2, 'quantity' => 1, 'line_total' => 120],
            ['order_id' => 3, 'product_id' => 3, 'quantity' => 1, 'line_total' => 300],
            ['order_id' => 4, 'product_id' => 3, 'quantity' => 2, 'line_total' => 600],
            ['order_id' => 5, 'product_id' => 1, 'quantity' => 1, 'line_total' => 250],
        ]);

        DB::table('bm_payments')->insert([
            ['order_id' => 1, 'amount' => 500, 'method' => 'card', 'paid_on' => '2026-07-03'],
            ['order_id' => 2, 'amount' => 120, 'method' => 'invoice', 'paid_on' => '2026-07-20'],
            ['order_id' => 4, 'amount' => 600, 'method' => 'card', 'paid_on' => '2026-07-11'],
        ]);

        DB::table('bm_shipments')->insert([
            ['order_id' => 1, 'carrier' => 'Royal Mail', 'shipped_on' => '2026-07-05'],
            ['order_id' => 2, 'carrier' => 'DPD', 'shipped_on' => '2026-07-19'],
            ['order_id' => 4, 'carrier' => 'Royal Mail', 'shipped_on' => '2026-07-13'],
        ]);

        DB::table('bm_support_tickets')->insert([
            ['customer_id' => 1, 'subject' => 'Late delivery', 'priority' => 'high', 'opened_on' => '2026-07-06'],
            ['customer_id' => 1, 'subject' => 'Invoice query', 'priority' => 'low', 'opened_on' => '2026-07-22'],
            ['customer_id' => 2, 'subject' => 'Damaged item', 'priority' => 'high', 'opened_on' => '2026-07-14'],
        ]);

        DB::table('bm_sessions')->insert([['token' => 'abc', 'created_on' => '2026-07-01']]);
        DB::table('bm_jobs')->insert([['queue' => 'default', 'attempts' => 0]]);
        DB::table('bm_audit_log')->insert([['action' => 'login', 'logged_on' => '2026-07-01']]);
        DB::table('bm_settings')->insert([['key_name' => 'timezone', 'value' => 'UTC']]);

        Artisan::call('jeeves:discover', [
            '--output' => $this->schemaPath,
            '--no-verify' => true,
            '--force' => true,
        ]);

        $this->app->make(SchemaRegistry::class)->flush();
    }

    #[Test]
    public function it_answers_ordinary_questions_correctly()
    {
        if (env('JEEVES_BENCHMARK') !== '1') {
            $this->markTestSkipped('Set JEEVES_BENCHMARK=1 to run the benchmark (uses live API calls).');
        }

        if (!env('JEEVES_BENCHMARK_KEY')) {
            $this->markTestSkipped('Set JEEVES_BENCHMARK_KEY to the provider API key.');
        }

        $this->prepare();

        try {
            $results = $this->runQuestions();
        } finally {
            $this->cleanUp();
        }

        $this->report($results);

        $correct = count(array_filter($results, fn ($r) => $r['correct']));
        $total = count($results);

        // The number this prints is the deliverable; the assertion guards
        // against collapse.
        //
        // 76% of 46 rounds to 35, which is what three consecutive runs scored -
        // 35, 35, 35, with a curated fourth at 36. So this floor sits AT the
        // score rather than below it, and a weak run trips it. That is
        // deliberate and it is not flakiness to be tuned away: the bar is where
        // the package should be, the score is where it is, and the gap is the
        // work. Lowering the bar to make the light go green would delete the
        // only signal here.
        //
        // Raised from 70% (33) when the score moved from 32-33 to a steady 35.
        // A floor left two questions below the measured score stops being a
        // floor and becomes decoration.
        //
        // Read a single red run as "that run dipped", not as a regression.
        // A regression is a run that loses a whole hardness band, which this
        // still catches.
        $this->assertGreaterThanOrEqual(
            (int) ceil($total * 0.76),
            $correct,
            'execution accuracy fell below 76% -  see the breakdown above'
        );
    }

    /** @return array<int, array> one scored result per question */
    private function runQuestions(): array
    {
        $questions = require __DIR__ . '/questions.php';
        $orchestrator = $this->app->make(QueryOrchestrator::class);

        $results = [];

        foreach ($questions as $i => $case) {
            // Free-tier providers rate limit by the minute. Pacing keeps the
            // benchmark measuring accuracy rather than throttling.
            if ($i > 0) {
                sleep(5);
            }

            $results[] = $this->score($orchestrator, $case);
        }

        return $results;
    }

    private function score(QueryOrchestrator $orchestrator, array $case): array
    {
        $gold = $this->normalize(DB::select($case['gold']), $case['ordered'] ?? false);

        try {
            $response = $orchestrator->query($case['question']);
        } catch (\Throwable $e) {
            return $case + ['correct' => false, 'note' => 'exception: ' . $e->getMessage()];
        }

        if (($response['status'] ?? '') !== 'success') {
            // Say WHICH kind of non-answer, and why.
            //
            // This read `$response['error']` alone, and a clarification carries
            // `message` rather than `error` — so every clarification printed
            // "no answer: ?" and the run could not tell a refusal from a
            // question the engine asked back. One case sat in that blind spot
            // across four runs, and the guess about its cause was wrong: it
            // survived curation that defined the very term thought to be
            // ambiguous.
            //
            // A benchmark that cannot say why a question failed is a benchmark
            // that sends whoever reads it guessing, which is how that guess got
            // made in the first place.
            $status = (string) ($response['status'] ?? 'unknown');
            $why = (string) ($response['error'] ?? $response['message'] ?? '?');

            return $case + [
                'correct' => false,
                'note' => $status . ': ' . substr($why, 0, 60),
            ];
        }

        $rows = $response['rows'] ?? [];

        // A multi-step answer carries its numbers in the steps.
        if (($response['type'] ?? '') === 'multi_step') {
            $rows = [];
            foreach ($response['steps'] ?? [] as $step) {
                foreach ($step['rows'] ?? [] as $row) {
                    $rows[] = $row;
                }
            }
        }

        $actual = $this->normalize($rows, $case['ordered'] ?? false);

        return $case + [
            'correct' => $actual === $gold,
            'note' => $actual === $gold ? '' : 'got ' . $this->preview($actual) . ' want ' . $this->preview($gold),
            'mode' => $response['metadata']['query_mode_used'] ?? '?',
        ];
    }

    /**
     * Reduce a result set to the numbers a person would read off it.
     *
     * Aliases and column order are ignored -  many correct queries name things
     * differently. Row order is ignored too unless the question asked for a
     * ranking, in which case it is the answer.
     */
    private function normalize(array $rows, bool $ordered): array
    {
        return (new ResultComparator)->normalize($rows, $ordered);
    }

    private function preview(array $normalized): string
    {
        return (new ResultComparator)->preview($normalized);
    }

    private function report(array $results): void
    {
        $byHardness = [];

        foreach ($results as $r) {
            $byHardness[$r['hardness']]['total'] = ($byHardness[$r['hardness']]['total'] ?? 0) + 1;
            $byHardness[$r['hardness']]['correct'] = ($byHardness[$r['hardness']]['correct'] ?? 0) + ($r['correct'] ? 1 : 0);
        }

        $lines = [sprintf(
            "\n  EXECUTION ACCURACY -  %s -  %s\n",
            config('jeeves.llm.driver'),
            env('JEEVES_BENCHMARK_CURATED') === '1'
                ? 'WITH domain config'
                : 'out of the box (discovery only)'
        )];

        foreach ($results as $r) {
            $lines[] = sprintf(
                '  %-4s %-8s %-46s %s',
                $r['correct'] ? ' ok ' : 'FAIL',
                $r['hardness'],
                substr($r['question'], 0, 46),
                // Mode on every row, not only the passes: when something fails
                // the first question is always which path produced it.
                trim(($r['mode'] ?? '') . ' ' . ($r['correct'] ? '' : $r['note']))
            );
        }

        $lines[] = '';

        foreach (['easy', 'medium', 'hard', 'extra'] as $level) {
            if (!isset($byHardness[$level])) {
                continue;
            }
            $b = $byHardness[$level];
            $lines[] = sprintf('  %-8s %d/%d', $level, $b['correct'], $b['total']);
        }

        // Accuracy by join depth. Single-table questions passing while
        // four-table ones fail is the failure mode that matters most, and a
        // headline percentage hides it completely.
        $byJoins = [];

        foreach ($results as $r) {
            $n = $r['joins'] ?? 1;
            $byJoins[$n]['total'] = ($byJoins[$n]['total'] ?? 0) + 1;
            $byJoins[$n]['correct'] = ($byJoins[$n]['correct'] ?? 0) + ($r['correct'] ? 1 : 0);
        }

        ksort($byJoins);
        $lines[] = '';

        foreach ($byJoins as $n => $b) {
            $lines[] = sprintf('  %d-table %d/%d', $n, $b['correct'], $b['total']);
        }

        $correct = count(array_filter($results, fn ($r) => $r['correct']));
        $total = count($results);
        $lines[] = sprintf("\n  OVERALL  %d/%d (%.0f%%)\n", $correct, $total, 100 * $correct / max(1, $total));

        fwrite(STDERR, implode("\n", $lines) . "\n");
    }
}
