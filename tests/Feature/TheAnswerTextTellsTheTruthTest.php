<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Engine\ErrorCode;
use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Tests\Support\RecordingProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The sentence a user reads, and hears, must be as true as the number.
 *
 * `parsed_summary` was made honest first, and that left a worse state than
 * before: the caption fell silent about the breakdown while the ANSWER
 * SENTENCE went on asserting one. A reader who checks the line under the
 * number finds nothing, and believes the headline.
 *
 * Four defects, all of which produced a confident false statement with
 * `status: success`, and none of which any of the 699 existing tests could
 * see.
 *
 * Fixture `ul_sales`, hand-checked:
 *   Acme/West/pending/2026-07-05/100, Beta/East/pending/2026-07-06/200,
 *   Acme/West/cancelled/2025-01-06/500
 *   all time 800, West 600, East 200. Schema default group column is
 *   `customer_name`, deliberately not what these queries group by.
 */
class TheAnswerTextTellsTheTruthTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/understood-line-schemas');
        $app['config']->set('jeeves.cache.enabled', false);
        $app['config']->set('jeeves.verification.enabled', false);
    }

    private function seedSales(): void
    {
        Schema::dropIfExists('ul_sales');
        Schema::create('ul_sales', function ($t) {
            $t->id();
            $t->string('customer_name');
            $t->string('region');
            $t->string('status');
            $t->date('placed_on');
            $t->decimal('revenue', 12, 2);
        });

        // The fourth row breaks the customer/region correlation deliberately.
        // With only the first three, Acme is always West and Beta always East,
        // so grouping by customer and grouping by region return the identical
        // multiset {600, 200} — and a test whose subject is "the sentence names
        // the RIGHT dimension" cannot tell a dimension swap from a correct
        // answer by its numbers.
        //
        // Hand-checked with the fourth row: by customer Acme 650 / Beta 200;
        // by region West 600 / East 250; all time 850.
        foreach ([
            ['Acme', 'West', 'pending', '2026-07-05', 100],
            ['Beta', 'East', 'pending', '2026-07-06', 200],
            ['Acme', 'West', 'cancelled', '2025-01-06', 500],
            ['Acme', 'East', 'pending', '2026-07-08', 50],
        ] as [$c, $r, $s, $d, $v]) {
            DB::table('ul_sales')->insert([
                'customer_name' => $c, 'region' => $r, 'status' => $s,
                'placed_on' => $d, 'revenue' => $v,
            ]);
        }
    }

    private function viaIntent(array $intent): array
    {
        config(['jeeves.query_mode' => 'intent']);

        $provider = new RecordingProvider;
        $provider->intentResponse = array_merge([
            'success' => true,
            'dataset' => 'ul_sales',
            'metric' => 'revenue',
            'query_type' => 'ranking',
            'limit' => 10,
            'order' => 'desc',
            'group_value' => null,
            'confidence' => 0.95,
            'needs_clarification' => false,
        ], $intent);

        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        return $this->app->make(QueryOrchestrator::class)->query('the overall figure', 'ul_sales');
    }

    private function viaSql(array $data): array
    {
        config(['jeeves.query_mode' => 'sql_generation']);

        $provider = new RecordingProvider;
        $provider->sqlResponse = ['success' => true, 'data' => $data + [
            'dataset' => 'ul_sales',
            'metric' => 'revenue',
        ]];
        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        return $this->app->make(QueryOrchestrator::class)->query('revenue', 'ul_sales');
    }

    /**
     * A capital letter must not change the question.
     *
     * Every consumer compares `query_type` with `===`, and no provider
     * normalised it, so a model answering "Aggregation" fell through to the
     * ranking default: one number became a league table, and `parsed_summary`
     * then announced a breakdown nobody asked for. `order` has been normalised
     * for exactly this reason since an earlier release.
     */
    #[Test]
    public function a_capitalised_query_type_is_still_an_aggregation()
    {
        $this->seedSales();

        $result = $this->viaIntent(['query_type' => 'Aggregation']);

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        $this->assertCount(
            1,
            $result['rows'] ?? [],
            'a whole-dataset total came back as a list, so a different question was answered: '
                . json_encode($result['rows'] ?? [])
        );
        $this->assertStringNotContainsString('Top ', $result['answer'] ?? '');
    }

    /**
     * A word that names a MEASURE must not be read as a shape.
     *
     * A synonym table mapping `total`/`sum`/`aggregate` onto `aggregation` was
     * tried and removed: "top 3 customers by revenue", answered by a model that
     * omits `group_by` and says `query_type: "total"`, collapsed a correct
     * two-row ranking into a single number. Unknown values fall back to
     * `ranking`, which is what every `===` comparison already did.
     */
    #[Test]
    public function a_measure_word_is_not_read_as_an_aggregation()
    {
        $this->seedSales();

        foreach (['total', 'sum', 'aggregate'] as $word) {
            // NO group_by. SqlBuilder's $wantsTotal requires `!$requestedDimension`,
            // so supplying one makes the collapse impossible whatever query_type
            // says — the first version of this test sent `group_by` and passed
            // with the synonym table restored, guarding nothing.
            $result = $this->viaIntent(['query_type' => $word]);

            $this->assertSame('success', $result['status'] ?? null, json_encode($result));
            $this->assertGreaterThan(
                1,
                count($result['rows'] ?? []),
                "query_type '{$word}' collapsed a ranking into one number, answering a different "
                    . 'question: ' . json_encode($result['rows'] ?? [])
            );
        }
    }

    /** A non-scalar query_type must not become a 500. */
    #[Test]
    public function a_malformed_query_type_does_not_break_the_query()
    {
        $this->seedSales();

        $result = $this->viaIntent(['query_type' => ['aggregation'], 'group_by' => 'region']);

        $this->assertSame(
            'success',
            $result['status'] ?? null,
            'a model sending an array for query_type turned an answerable question into an '
                . 'internal error: ' . json_encode($result)
        );
    }

    /**
     * An aggregate over zero rows is NULL, not zero.
     *
     * `$row[$key] ?? array_values($row)[0] ?? 0` treats SQL NULL as absent and
     * fell through to the literal 0, so "average amount for cancelled orders"
     * with no cancelled orders answered "0" — and said it aloud. A specific
     * false quantitative claim, reported as success.
     */
    #[Test]
    public function an_aggregate_over_no_rows_is_not_reported_as_zero()
    {
        $this->seedSales();

        $result = $this->viaSql([
            'sql' => "SELECT SUM(revenue) AS revenue FROM ul_sales WHERE region = 'Nowhere'",
            'query_type' => 'aggregation',
        ]);

        // Both assertions below are `assertStringNotContains` against fields
        // that are ABSENT on an error response, so without this the test passes
        // whenever the branch it covers throws. Proven: making the aggregation
        // branch throw on every call left this test green.
        $this->assertSame('success', $result['status'] ?? null, json_encode($result));

        $answer = $result['answer'] ?? '';
        $speech = $result['speech_text'] ?? '';

        $this->assertStringNotContainsString(
            ': 0 ',
            $answer,
            "an aggregate over zero rows was stated as 0: [{$answer}]"
        );
        $this->assertStringNotContainsString(' is 0 ', $speech, "spoken aloud as zero: [{$speech}]");
    }

    /**
     * The measure is found by NAME, not by position.
     *
     * A model that writes `SELECT status, SUM(revenue) AS revenue ... GROUP BY
     * status` puts the label first. Falling back to the first column by
     * position read that label as the aggregate, so a NULL label reported
     * "nothing matched" over a row holding a real number — contradicted by
     * `insights` in the same response.
     */
    #[Test]
    public function the_aggregate_is_read_by_name_not_by_position()
    {
        $this->seedSales();

        // A NULL first column, deliberately. With a non-null label the wrong
        // pick produces a wrong VALUE rather than "nothing matched", which an
        // absence assertion cannot see — the first version of this test missed
        // the mutation for exactly that reason. Here the positional fallback
        // reads null and claims no data over a row holding 850.
        $result = $this->viaSql([
            'sql' => 'SELECT NULL AS bucket, SUM(revenue) AS revenue FROM ul_sales LIMIT 1',
            'query_type' => 'aggregation',
        ]);

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        $this->assertStringNotContainsString(
            'nothing matched',
            $result['answer'] ?? '',
            'a row holding a real total was announced as no data, because the first column by '
                . 'position is the label and not the measure: ' . json_encode($result['rows'] ?? [])
        );
        $this->assertStringContainsString(
            '850',
            $result['answer'] ?? '',
            'the answer did not state the total; the measure was located by position rather than '
                . 'by name: ' . json_encode($result['answer'] ?? '')
        );
    }

    /**
     * The SQL route normalises too.
     *
     * `normalizeIntent()` is reached only from the intent path, so
     * sql_generation, the refinement retry and the cached recipe replay all
     * handed the formatter whatever the model said — and the result was cached,
     * so the wording survived every later ask.
     */
    #[Test]
    public function a_capitalised_query_type_is_normalised_on_the_sql_route()
    {
        $this->seedSales();

        $result = $this->viaSql([
            'sql' => 'SELECT SUM(revenue) AS revenue FROM ul_sales',
            'query_type' => 'Aggregation',
        ]);

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        $this->assertSame(
            'aggregation',
            $result['parsed_query']['query_type'] ?? null,
            'a non-canonical query_type reached the public response'
        );
        $this->assertStringContainsString(
            'Total',
            $result['answer'] ?? '',
            'the answer was worded as a ranking, so the row label sentinel appears where the '
                . 'number should be: ' . json_encode($result['answer'] ?? '')
        );
    }

    /** An invented query_type never reaches the public response. */
    #[Test]
    public function an_unrecognised_query_type_is_not_echoed_to_the_caller()
    {
        $this->seedSales();

        $result = $this->viaSql([
            'sql' => 'SELECT region, SUM(revenue) AS revenue FROM ul_sales GROUP BY region LIMIT 10',
            'query_type' => 'overview',
        ]);

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        $this->assertContains(
            $result['parsed_query']['query_type'] ?? null,
            ['aggregation', 'ranking', 'group_detail'],
            'docs/API.md documents query_type as an enum; the model invented a value and it was '
                . 'passed straight through'
        );
    }

    /** A real zero is still a zero — COUNT over no rows is genuinely 0. */
    #[Test]
    public function a_genuine_zero_is_still_reported()
    {
        $this->seedSales();

        $result = $this->viaSql([
            'sql' => "SELECT COUNT(*) AS record_count FROM ul_sales WHERE region = 'Nowhere'",
            'metric' => 'record_count',
            'query_type' => 'aggregation',
        ]);

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        // ': 0' rather than '0' — the loose form is satisfied by any answer
        // containing the digit, including "Total ...: 100".
        $this->assertStringContainsString(': 0', $result['answer'] ?? '');
    }

    /**
     * THE ONE THE CAPTION FIX LEFT BEHIND. On the sql_generation route the
     * engine did not write the SQL, so it cannot name the dimension — and must
     * not name the schema default instead.
     */
    #[Test]
    public function the_answer_sentence_does_not_name_a_dimension_it_cannot_know()
    {
        $this->seedSales();

        $result = $this->viaSql([
            'sql' => 'SELECT region, SUM(revenue) AS revenue FROM ul_sales GROUP BY region LIMIT 10',
            'query_type' => 'ranking',
        ]);

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));

        foreach (['answer', 'speech_text'] as $field) {
            $this->assertStringNotContainsString(
                'customer',
                strtolower($result[$field] ?? ''),
                "[{$field}] the rows are regions and the sentence called them customers, which is the "
                    . "schema's default group column: [" . ($result[$field] ?? '') . ']'
            );
        }
    }

    /** And when the engine DID write the query, it still names the dimension. */
    #[Test]
    public function a_built_ranking_still_names_its_dimension()
    {
        $this->seedSales();

        $result = $this->viaIntent(['group_by' => 'region', 'query_type' => 'ranking']);

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        $this->assertStringContainsString('region', strtolower($result['answer'] ?? ''));
    }

    /**
     * A schema naming a table that does not exist is a database fault, not a
     * phrasing fault. Reporting it as "Could not understand the query. Try
     * mentioning a dataset name" is a loop: the dataset IS named, and it is the
     * broken thing.
     */
    #[Test]
    public function a_missing_table_is_reported_as_a_database_error()
    {
        $this->seedSales();
        Schema::dropIfExists('ul_sales');

        // NO dataset hint, and a question naming nothing. With a hint the retry
        // takes its keyword-detection strategy and returns the database error
        // directly, never consulting the guard this test exists to cover — the
        // first version of this test passed with the guard reverted.
        config(['jeeves.query_mode' => 'sql_generation']);

        $provider = new RecordingProvider;
        $provider->sqlResponse = ['success' => true, 'data' => [
            'sql' => 'SELECT SUM(revenue) AS revenue FROM ul_sales',
            'dataset' => 'ul_sales',
            'metric' => 'revenue',
            'query_type' => 'aggregation',
        ]];
        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        $result = $this->app->make(QueryOrchestrator::class)->query('what is the overall figure');

        $this->assertSame('error', $result['status'] ?? null, json_encode($result));
        $this->assertStringNotContainsString(
            'Try mentioning a dataset name',
            $result['error'] ?? '',
            'the user was told to name a dataset; the dataset IS named and is the broken thing, so '
                . 'the advice is a loop with no exit'
        );
        $this->assertSame(
            ErrorCode::DATABASE_ERROR,
            $result['error_code'] ?? null,
            'a missing table was blamed on the wording, so the advice sends the user round a loop '
                . 'they cannot escape: ' . json_encode($result)
        );
    }
}
