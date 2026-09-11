<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jayanta\Jeeves\Benchmark\ResultComparator;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Tests\Support\RecordingProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Letting an adopter measure accuracy on their own schema.
 *
 * The package quotes a figure about itself -  roughly one in five wrong
 * uncurated, near-perfect curated -  and nobody should take that on trust for
 * their own data. This grades the same way that figure was produced, using the
 * same comparator, so the two numbers mean the same thing.
 */
class BenchmarkCommandTest extends TestCase
{
    private string $questionFile;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/single-dataset-schemas');
        $app['config']->set('jeeves.cache.enabled', false);
        $app['config']->set('jeeves.query_mode', 'sql_generation');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->questionFile = sys_get_temp_dir() . '/nq-bench-' . getmypid() . '.php';
    }

    protected function tearDown(): void
    {
        @unlink($this->questionFile);
        parent::tearDown();
    }

    private function seedOrders(): void
    {
        Schema::dropIfExists('nq_orders');
        Schema::create('nq_orders', function ($t) {
            $t->id();
            $t->decimal('revenue', 12, 2);
        });
        foreach ([100, 200, 50] as $r) {
            DB::table('nq_orders')->insert(['revenue' => $r]);
        }
    }

    private function writeQuestions(string $php): void
    {
        file_put_contents($this->questionFile, "<?php\n\nreturn {$php};\n");
    }

    private function provider(string $sql): void
    {
        $p = new RecordingProvider;
        $p->sqlResponse = [
            'success' => true,
            'data' => ['sql' => $sql, 'dataset' => 'nq_orders', 'metric' => 'revenue', 'query_type' => 'aggregation'],
        ];
        $this->app->instance(LlmProviderInterface::class, $p);
    }

    private function bench(array $opts = []): string
    {
        Artisan::call('jeeves:benchmark', array_merge(
            ['--file' => $this->questionFile, '--no-interaction' => true],
            $opts
        ));

        return Artisan::output();
    }

    /** The engine agrees with the reference: correct. Total is 100+200+50. */
    #[Test]
    public function a_matching_answer_scores_correct()
    {
        $this->seedOrders();
        $this->provider('SELECT SUM(revenue) AS revenue FROM nq_orders');
        $this->writeQuestions("[['question' => 'total revenue', 'gold' => 'SELECT SUM(revenue) AS r FROM nq_orders']]");

        $out = $this->bench();

        $this->assertStringContainsString('1/1 correct', $out);
        $this->assertStringNotContainsString('Not correct', $out);
    }

    /**
     * The engine answers a DIFFERENT question: wrong, and the report says what
     * it thought it was asked -  which is where the misreading is visible.
     */
    #[Test]
    public function a_different_number_scores_wrong_and_shows_the_reading()
    {
        $this->seedOrders();

        // The engine returns the count; the reference asks for the sum.
        $this->provider('SELECT COUNT(*) AS revenue FROM nq_orders');
        $this->writeQuestions("[['question' => 'total revenue', 'gold' => 'SELECT SUM(revenue) AS r FROM nq_orders']]");

        $out = $this->bench();

        $this->assertStringContainsString('0/1 correct', $out);
        $this->assertStringContainsString('different result', $out);
        $this->assertStringContainsString('read as:', $out, 'the report does not say how the question was understood');
    }

    /** A reference query that is not a SELECT is refused, not executed. */
    #[Test]
    public function a_non_select_reference_query_is_refused()
    {
        $this->seedOrders();
        $this->writeQuestions("[['question' => 'x', 'gold' => 'DELETE FROM nq_orders']]");

        $code = Artisan::call('jeeves:benchmark', [
            '--file' => $this->questionFile,
            '--no-interaction' => true,
        ]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('not a SELECT', Artisan::output());
        $this->assertSame(3, DB::table('nq_orders')->count(), 'the benchmark file deleted rows');
    }

    /** A missing question file explains how to make one. */
    #[Test]
    public function a_missing_question_set_says_how_to_create_one()
    {
        $code = Artisan::call('jeeves:benchmark', [
            '--file' => $this->questionFile . '.nope',
            '--no-interaction' => true,
        ]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('benchmark-example.php', Artisan::output());
    }

    /** --min turns it into a CI gate. */
    #[Test]
    public function min_sets_the_exit_code()
    {
        $this->seedOrders();
        $this->provider('SELECT COUNT(*) AS revenue FROM nq_orders');
        $this->writeQuestions("[['question' => 'total revenue', 'gold' => 'SELECT SUM(revenue) AS r FROM nq_orders']]");

        $this->assertSame(1, Artisan::call('jeeves:benchmark', [
            '--file' => $this->questionFile, '--no-interaction' => true, '--min' => '50',
        ]), '0% did not fail a --min=50 gate');

        $this->assertSame(0, Artisan::call('jeeves:benchmark', [
            '--file' => $this->questionFile, '--no-interaction' => true, '--min' => '0',
        ]), '--min=0 should always pass');
    }

    /** --json for anyone charting this over time. */
    #[Test]
    public function it_can_emit_json()
    {
        $this->seedOrders();
        $this->provider('SELECT SUM(revenue) AS revenue FROM nq_orders');
        $this->writeQuestions("[['question' => 'total revenue', 'gold' => 'SELECT SUM(revenue) AS r FROM nq_orders']]");

        $decoded = json_decode($this->bench(['--json' => true]), true);

        $this->assertSame(1, $decoded['total'] ?? null);
        $this->assertSame(1, $decoded['correct'] ?? null);
        // json_encode renders 100.0 as 100, so the decoded type is int here.
        $this->assertEquals(100.0, $decoded['accuracy'] ?? null);
    }

    // ---------------------------------------------------------------- comparator

    /** Aliases and column order are not part of the answer. */
    #[Test]
    public function differently_named_columns_are_the_same_answer()
    {
        $c = new ResultComparator;

        $this->assertTrue($c->matches(
            [(object) ['total' => 350]],
            [(object) ['revenue' => 350]]
        ));
    }

    /** Row order matters only when the question asked for a ranking. */
    #[Test]
    public function row_order_matters_only_when_ordered()
    {
        $c = new ResultComparator;
        $a = [(object) ['n' => 'x', 'v' => 1], (object) ['n' => 'y', 'v' => 2]];
        $b = [(object) ['n' => 'y', 'v' => 2], (object) ['n' => 'x', 'v' => 1]];

        $this->assertTrue($c->matches($a, $b), 'an unordered question should not care about sequence');
        $this->assertFalse($c->matches($a, $b, true), 'a ranking in the wrong order is a wrong answer');
    }

    /** Float noise is not a different total. */
    #[Test]
    public function tiny_float_differences_are_the_same_number()
    {
        $c = new ResultComparator;

        $this->assertTrue($c->matches([(object) ['v' => 350.001]], [(object) ['v' => 350.004]]));
        $this->assertFalse($c->matches([(object) ['v' => 350]], [(object) ['v' => 351]]));
    }

    /**
     * A clarification is the model ASKING instead of answering, and its text
     * is in `message`, not `error`. Reading only `error` reported every
     * clarification as a bare "no answer". Found on a real database: the
     * report said nothing where the model had asked which metric was meant.
     * Still graded as not correct - a question is not an answer - but now it
     * says so.
     */
    #[Test]
    public function a_clarification_is_reported_as_the_question_it_was()
    {
        $this->seedOrders();

        $p = new RecordingProvider;
        $p->sqlResponse = [
            'success' => true,
            'data' => ['error' => 'ambiguous', 'needs_clarification' => true, 'clarification_type' => 'metric'],
        ];
        $this->app->instance(LlmProviderInterface::class, $p);

        $this->writeQuestions("[['question' => 'total revenue', 'gold' => 'SELECT SUM(revenue) AS r FROM nq_orders']]");

        $out = $this->bench();

        $this->assertStringContainsString('0/1 correct', $out);
        $this->assertStringContainsString('asked for clarification', $out, 'a clarification was reported as silence');
        $this->assertStringContainsString('What metric', $out, "the model's actual question is missing from the report");
    }
}
