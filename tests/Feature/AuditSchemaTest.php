<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The curation coach.
 *
 * Roughly one question in five is misread on an uncurated schema, and the same
 * questions land near-perfectly once a handful of sentences are written. Until
 * now an adopter had no way to see WHICH sentences were missing -  "it gets
 * things wrong sometimes" is not something anyone can act on.
 *
 * Everything this reports is something introspection genuinely cannot recover.
 * Two columns called `amount`, in two tables, are identical in name, type and
 * nullability; only a person knows which one the business calls revenue.
 */
class AuditSchemaTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/uncurated-schemas');
        $app['config']->set('jeeves.system_instructions', '');
    }

    private function audit(array $options = []): string
    {
        Artisan::call('jeeves:audit-schema', $options);

        return Artisan::output();
    }

    /**
     * The ambiguity that matters most: two datasets both answer to "amount",
     * so the model picks one and a wrong pick is a plausible number for a
     * different question rather than an error.
     */
    #[Test]
    public function it_names_a_term_two_datasets_both_claim()
    {
        $out = $this->audit();

        $this->assertStringContainsString('"amount" could mean any of', $out);
        $this->assertStringContainsString('nq_orders.amount', $out);
        $this->assertStringContainsString('nq_invoices.amount', $out);
        $this->assertStringContainsString('system_instructions', $out);
    }

    /** Undescribed columns: the model sees only the name. */
    #[Test]
    public function it_counts_columns_with_no_description()
    {
        $out = $this->audit();

        $this->assertStringContainsString('columns have no description', $out);
        $this->assertStringContainsString('status_cd', $out);
    }

    /** A dataset users will never name by its key. */
    #[Test]
    public function it_reports_a_dataset_with_no_aliases()
    {
        $out = $this->audit();

        $this->assertStringContainsString('No aliases', $out);
    }

    /** A measure with no unit renders bare, so 1500 could be anything. */
    #[Test]
    public function it_reports_a_measure_with_no_unit()
    {
        $out = $this->audit();

        $this->assertStringContainsString('Measures with no unit', $out);
    }

    /** Infrastructure that discover picked up and nobody removed. */
    #[Test]
    public function it_flags_an_infrastructure_table()
    {
        $out = $this->audit();

        $this->assertStringContainsString('infrastructure table', $out);
        $this->assertStringContainsString('discover_exclude', $out);
    }

    /**
     * The gap whose absence produces a wrong NUMBER rather than a vague answer.
     *
     * A status column holding "cancelled" and no required_filter means the
     * model decides whether those rows count towards a total -  and a total
     * that quietly includes cancelled orders looks exactly like a correct one.
     * The audit cannot know the rule; it can recognise the shape of a column
     * that usually needs one.
     */
    #[Test]
    public function it_asks_for_a_rule_when_a_column_holds_excludable_values()
    {
        $out = $this->audit();

        $this->assertStringContainsString('required_filter', $out);
        $this->assertStringContainsString('cancelled', $out);
        $this->assertStringContainsString('status_cd', $out);
    }

    /**
     * The same gap on a schema `discover` could actually have produced.
     *
     * The test above passes because its stub hand-writes a `values` list -  a
     * key discover has never written, and one its merge deleted if an adopter
     * added it. So the check was proven against a shape the documented
     * workflow does not produce, and on a real discover → audit → curate loop
     * it reported "Nothing to add" on precisely the schemas that needed most.
     */
    #[Test]
    public function it_asks_for_a_rule_from_a_column_name_alone()
    {
        config(['jeeves.schema.config_path' => __DIR__ . '/../Stubs/softdelete-schemas']);

        $out = $this->audit();

        $this->assertStringContainsString('required_filter', $out);
        $this->assertStringContainsString('deleted_at', $out);
    }

    /**
     * The suggested rule is copy-pasteable PHP that will be applied to every
     * query, so it must be SQL that keeps the rows that count.
     *
     * The first version suggested `!= 'cancelled'` for every column shape. On a
     * soft-delete timestamp that is exactly inverted: `NULL != 'cancelled'` is
     * NULL, not TRUE, so every live row is excluded and only deleted rows
     * survive. Measured on three rows — 350 total, 300 correct, 50 with that
     * rule — and reported as a success, on the one command whose purpose is
     * preventing wrong numbers.
     *
     * Asserting the words `required_filter` and `deleted_at` appear, which is
     * what the test above does, cannot see any of that. This runs it.
     */
    #[Test]
    public function the_suggested_rule_is_sql_that_keeps_the_rows_that_count()
    {
        config(['jeeves.schema.config_path' => __DIR__ . '/../Stubs/softdelete-schemas']);

        $out = $this->audit();

        preg_match("/'required_filter' => \"([^\"]+)\"/", $out, $m);
        $this->assertNotEmpty($m, "the audit printed no copy-pasteable rule:\n{$out}");

        $predicate = $m[1];

        Schema::dropIfExists('sd_orders');
        Schema::create('sd_orders', function ($t) {
            $t->id();
            $t->string('region');
            $t->decimal('revenue', 12, 2);
            $t->timestamp('deleted_at')->nullable();
        });
        DB::table('sd_orders')->insert([
            ['region' => 'West', 'revenue' => 100, 'deleted_at' => null],
            ['region' => 'East', 'revenue' => 200, 'deleted_at' => null],
            ['region' => 'West', 'revenue' => 50, 'deleted_at' => '2026-01-01 00:00:00'],
        ]);

        $total = (float) DB::selectOne('SELECT SUM(revenue) AS t FROM sd_orders')->t;
        $filtered = (float) DB::selectOne("SELECT SUM(revenue) AS t FROM sd_orders WHERE {$predicate}")->t;

        $this->assertSame(350.0, $total, 'fixture check');
        $this->assertSame(
            300.0,
            $filtered,
            "the audit suggested `{$predicate}`, which keeps {$filtered} of 350 — the live rows total "
                . '300, so this rule is excluding the wrong ones and every answer for this dataset '
                . 'would be wrong while reporting success'
        );
    }

    /** And says nothing once the rule exists. */
    #[Test]
    public function it_stays_quiet_when_the_rule_is_already_written()
    {
        config(['jeeves.schema.config_path' => __DIR__ . '/../Stubs/required-filter-schemas']);

        $this->assertStringNotContainsString('required_filter', $this->audit());
    }

    /** A curated schema must come back clean, or the report is noise. */
    #[Test]
    public function a_curated_schema_reports_nothing()
    {
        config(['jeeves.schema.config_path' => __DIR__ . '/../Stubs/single-dataset-schemas']);

        $out = $this->audit();

        $this->assertStringContainsString('Nothing to add', $out);
    }

    /** Narrowing to one dataset skips the cross-dataset check. */
    #[Test]
    public function auditing_one_dataset_does_not_report_cross_dataset_ambiguity()
    {
        $out = $this->audit(['--dataset' => 'nq_orders']);

        $this->assertStringContainsString('status_cd', $out, 'the single-dataset findings are still reported');
        $this->assertStringNotContainsString('could mean any of', $out);
    }

    /** An unknown dataset is an error, not an empty report. */
    #[Test]
    public function an_unknown_dataset_is_reported_as_an_error()
    {
        $code = Artisan::call('jeeves:audit-schema', ['--dataset' => 'nope']);

        $this->assertSame(1, $code);
    }

    /** --json stays parseable for anyone wiring this into CI. */
    #[Test]
    public function it_can_emit_json()
    {
        $decoded = json_decode($this->audit(['--json' => true]), true);

        $this->assertIsArray($decoded);
        $this->assertGreaterThan(0, $decoded['total'] ?? 0);
        $this->assertArrayHasKey('kind', $decoded['findings'][0]);
    }

    /**
     * Exit 0 even with findings. An unaudited schema is not a broken install,
     * and a non-zero code would fail CI for every adopter still writing
     * descriptions.
     */
    #[Test]
    public function findings_do_not_fail_the_command()
    {
        $this->assertSame(0, Artisan::call('jeeves:audit-schema'));
    }
}
