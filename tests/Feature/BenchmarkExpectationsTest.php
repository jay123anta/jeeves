<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Tests\Support\RecordingProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * `expect` in a benchmark question: checks on the answer itself, instead of or
 * as well as a reference query.
 *
 * The project this was learned from attached value bounds to its regression
 * questions to catch answers that were technically well-formed and empty of
 * meaning. The same idea, generic: a row count, a range, a name that must
 * appear - written by the adopter about their own data.
 */
class BenchmarkExpectationsTest extends TestCase
{
    private string $questionFile;

    private ?RecordingProvider $provider = null;

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
        $this->questionFile = sys_get_temp_dir() . '/nq-bench-expect-' . getmypid() . '.php';
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
            $t->string('customer')->nullable();
        });

        foreach ([[100, 'Acme'], [200, 'Globex'], [50, 'Initech']] as [$revenue, $customer]) {
            DB::table('nq_orders')->insert(['revenue' => $revenue, 'customer' => $customer]);
        }
    }

    private function writeQuestions(string $php): void
    {
        file_put_contents($this->questionFile, "<?php\n\nreturn {$php};\n");
    }

    private function provider(string $sql): void
    {
        $this->provider = new RecordingProvider;
        $this->provider->sqlResponse = [
            'success' => true,
            'data' => ['sql' => $sql, 'dataset' => 'nq_orders', 'metric' => 'revenue', 'query_type' => 'aggregation'],
        ];
        $this->app->instance(LlmProviderInterface::class, $this->provider);
    }

    private function bench(): string
    {
        Artisan::call('jeeves:benchmark', ['--file' => $this->questionFile, '--no-interaction' => true]);

        return Artisan::output();
    }

    /** No reference query at all - the checks alone grade it. Total is 100+200+50. */
    #[Test]
    public function a_question_with_checks_and_no_reference_is_graded(): void
    {
        $this->seedOrders();
        $this->provider('SELECT SUM(revenue) AS r FROM nq_orders');
        $this->writeQuestions("[['question' => 'total revenue', 'expect' => ['rows' => 1, 'min' => 300, 'max' => 400]]]");

        $this->assertStringContainsString('1/1 correct', $this->bench());
    }

    #[Test]
    public function a_figure_outside_its_range_is_wrong_and_says_why(): void
    {
        $this->seedOrders();
        $this->provider('SELECT SUM(revenue) AS r FROM nq_orders');
        $this->writeQuestions("[['question' => 'total revenue', 'expect' => ['min' => 1000]]]");

        $out = $this->bench();

        $this->assertStringContainsString('0/1 correct', $out);
        $this->assertStringContainsString('expectation not met', $out);
        $this->assertStringContainsString('below the minimum 1000', $out);
    }

    /**
     * THE LOOPHOLE. A SUM over no rows is one row holding NULL; the comparator
     * skips NULLs, so a reference returning the same NULL "matches" and an
     * answer about no data is graded correct.
     *
     * The first half proves the loophole is real - if it ever stops being, the
     * second half would pass without `expect` doing anything.
     */
    #[Test]
    public function a_null_total_no_longer_passes_by_matching_a_null_reference(): void
    {
        $this->seedOrders();
        $sql = 'SELECT SUM(revenue) AS r FROM nq_orders WHERE revenue > 99999';
        $this->provider($sql);

        $this->writeQuestions("[['question' => 'total revenue', 'gold' => '{$sql}']]");
        $this->assertStringContainsString(
            '1/1 correct',
            $this->bench(),
            'a NULL total no longer matches a NULL reference, so the loophole this test closes is gone'
        );

        $this->writeQuestions("[['question' => 'total revenue', 'gold' => '{$sql}', 'expect' => ['min' => 1]]]");
        $out = $this->bench();

        $this->assertStringContainsString('0/1 correct', $out, 'expect did not catch a NULL total');
        $this->assertStringContainsString('no number in the first row', $out);
    }

    #[Test]
    public function a_name_that_should_appear_is_checked(): void
    {
        $this->seedOrders();
        $this->provider('SELECT customer, SUM(revenue) AS r FROM nq_orders GROUP BY customer ORDER BY r DESC LIMIT 1');

        $this->writeQuestions("[['question' => 'top customer', 'expect' => ['rows' => 1, 'contains' => ['globex']]]]");
        $this->assertStringContainsString('1/1 correct', $this->bench(), 'a case-insensitive name match was missed');

        $this->writeQuestions("[['question' => 'top customer', 'expect' => ['contains' => ['Acme']]]]");
        $out = $this->bench();

        $this->assertStringContainsString('0/1 correct', $out);
        $this->assertStringContainsString("no row contains 'Acme'", $out);
    }

    /** A matching reference does not excuse a figure outside its range. */
    #[Test]
    public function both_the_reference_and_the_checks_must_hold(): void
    {
        $this->seedOrders();
        $this->provider('SELECT SUM(revenue) AS r FROM nq_orders');
        $this->writeQuestions(
            "[['question' => 'total revenue', 'gold' => 'SELECT SUM(revenue) AS r FROM nq_orders', 'expect' => ['max' => 100]]]"
        );

        $out = $this->bench();

        $this->assertStringContainsString('0/1 correct', $out, 'a matching reference excused a figure outside its range');
        $this->assertStringContainsString('above the maximum 100', $out);
    }

    /** A misspelt check would otherwise read as the package being wrong. */
    #[Test]
    public function an_unknown_check_is_refused_before_anything_runs(): void
    {
        $this->seedOrders();
        $this->provider('SELECT SUM(revenue) AS r FROM nq_orders');
        $this->writeQuestions("[['question' => 'total revenue', 'expect' => ['minimum' => 5]]]");

        $this->assertStringContainsString('unknown check(s): minimum', $this->bench());
        $this->assertSame([], $this->provider->calls, 'the provider was called for a question set that was refused');
    }

    #[Test]
    public function a_question_with_nothing_to_grade_is_refused(): void
    {
        $this->writeQuestions("[['question' => 'total revenue']]");

        $this->assertStringContainsString(
            "either a reference query in 'gold' or checks in 'expect'",
            $this->bench()
        );
    }
}
