<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Tests\Support\RecordingProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * `correct_typos`: a filter value that matched nothing because it was
 * misspelled is corrected to the closest value the column actually holds, and
 * the query runs once more.
 *
 * Domain-neutral on purpose - product names and categories, no geography -
 * because the mechanism knows nothing about what a column contains. The
 * project it was learned from put a list of its own place names into the
 * prompt; this reads the column locally and sends nothing anywhere.
 */
class ValueCorrectionTest extends TestCase
{
    private ?RecordingProvider $provider = null;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/value-correction-schemas');
        $app['config']->set('jeeves.query_mode', 'sql_generation');
        $app['config']->set('jeeves.cache.enabled', false);
        $app['config']->set('jeeves.verification.enabled', false);
    }

    private function seedTables(): void
    {
        Schema::dropIfExists('vc_products');
        Schema::create('vc_products', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('category');
            $t->integer('stock');
        });
        DB::table('vc_products')->insert([
            ['name' => 'Keyboard', 'category' => 'Accessories', 'stock' => 12],
            ['name' => 'Monitor', 'category' => 'Electronics', 'stock' => 5],
            ['name' => 'Headphones', 'category' => 'Electronics', 'stock' => 9],
            ['name' => 'Mic', 'category' => 'Accessories', 'stock' => 4],
            ['name' => 'Cable A', 'category' => 'Accessories', 'stock' => 30],
            ['name' => 'Cable B', 'category' => 'Accessories', 'stock' => 25],
        ]);

        Schema::dropIfExists('vc_suppliers');
        Schema::create('vc_suppliers', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->integer('rating');
        });
        DB::table('vc_suppliers')->insert([['name' => 'Globex', 'rating' => 4]]);
    }

    private function answer(string $sql, string $dataset = 'vc_products'): array
    {
        $this->provider = new RecordingProvider;
        $this->provider->sqlResponse = ['success' => true, 'data' => [
            'sql' => $sql,
            'dataset' => $dataset,
            'metric' => 'stock',
            'query_type' => 'ranking',
        ]];

        $this->app->instance(LlmProviderInterface::class, $this->provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        return $this->app->make(QueryOrchestrator::class)->query('stock for the product', $dataset);
    }

    private function stockOf(string $name, string $extra = ''): string
    {
        return "SELECT name, SUM(stock) AS stock FROM vc_products WHERE name = '{$name}'{$extra} GROUP BY name LIMIT 10";
    }

    private function first(array $result): array
    {
        return (array) (($result['rows'] ?? [])[0] ?? []);
    }

    private function calls(string $method): int
    {
        return count(array_filter($this->provider?->calls ?? [], fn ($c) => $c['method'] === $method));
    }

    private function assertNotCorrected(array $result, string $why): void
    {
        $this->assertEmpty($result['rows'] ?? [], $why . ': ' . json_encode($result));
        $this->assertArrayNotHasKey('value_corrections', $result['metadata'] ?? [], $why);
    }

    /** GUARD. The misspellings really are absent, so an answer can only come from the correction. */
    #[Test]
    public function the_misspellings_used_here_are_not_in_the_data(): void
    {
        $this->seedTables();

        $this->assertSame(0, DB::table('vc_products')->whereIn('name', ['Keybord', 'monitor', 'Cable C'])->count());
    }

    #[Test]
    public function a_one_letter_typo_is_corrected_and_answered(): void
    {
        $this->seedTables();

        $result = $this->answer($this->stockOf('Keybord'));

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        $this->assertSame('Keyboard', $this->first($result)['name'] ?? null);
        $this->assertEquals(12, $this->first($result)['stock'] ?? null);

        // Rule 8: the answer says which value it actually used.
        $this->assertSame(
            [['from' => 'Keybord', 'to' => 'Keyboard', 'column' => 'name']],
            $result['metadata']['value_corrections'] ?? null
        );
    }

    /** The commonest empty result on a case-sensitive database, and safe at any length. */
    #[Test]
    public function a_value_in_the_wrong_case_is_corrected(): void
    {
        $this->seedTables();

        $result = $this->answer($this->stockOf('monitor'));

        $this->assertSame('Monitor', $this->first($result)['name'] ?? null, json_encode($result));
    }

    #[Test]
    public function wildcards_survive_the_correction(): void
    {
        $this->seedTables();

        $result = $this->answer("SELECT name, SUM(stock) AS stock FROM vc_products WHERE name LIKE '%Keybord%' GROUP BY name LIMIT 10");

        $this->assertSame('Keyboard', $this->first($result)['name'] ?? null, json_encode($result));
    }

    /**
     * COUNTERWEIGHT. A value that IS stored is never "corrected": the empty
     * result is then a true answer about the other conditions.
     */
    #[Test]
    public function a_stored_value_with_a_genuinely_empty_answer_is_left_alone(): void
    {
        $this->seedTables();

        $this->assertNotCorrected(
            $this->answer($this->stockOf('Keyboard', ' AND stock > 1000')),
            'a value that exists was rewritten because the answer was empty for another reason'
        );
    }

    /** "Cable C" is one edit from both "Cable A" and "Cable B". Picking one is a guess. */
    #[Test]
    public function two_equally_close_values_are_not_a_guess(): void
    {
        $this->seedTables();

        $this->assertNotCorrected(
            $this->answer($this->stockOf('Cable C')),
            'a typo equally close to two stored values was corrected to one of them'
        );
    }

    #[Test]
    public function a_short_value_is_corrected_for_case_but_never_fuzzed(): void
    {
        $this->seedTables();

        $this->assertNotCorrected(
            $this->answer($this->stockOf('Mik')),
            'a three-letter value was fuzzed - short values are where a typo becomes a different value'
        );

        $this->assertSame('Mic', $this->first($this->answer($this->stockOf('mic')))['name'] ?? null);
    }

    /** Correction belongs to the columns that asked for it. */
    #[Test]
    public function a_column_that_did_not_opt_in_is_left_alone(): void
    {
        $this->seedTables();

        $this->assertNotCorrected(
            $this->answer(
                "SELECT name, SUM(rating) AS rating FROM vc_suppliers WHERE name = 'Globx' GROUP BY name LIMIT 10",
                'vc_suppliers'
            ),
            'a column without correct_typos had its value corrected'
        );
    }

    /** Over max_distinct the column is not a list of names, and is not read. */
    #[Test]
    public function a_column_with_too_many_values_is_skipped(): void
    {
        $this->seedTables();
        config()->set('jeeves.value_correction.max_distinct', 2);

        $this->assertNotCorrected(
            $this->answer($this->stockOf('Keybord')),
            'a column over max_distinct was scanned anyway'
        );
    }

    #[Test]
    public function the_kill_switch_turns_it_off_everywhere(): void
    {
        $this->seedTables();
        config()->set('jeeves.value_correction.enabled', false);

        $this->assertNotCorrected(
            $this->answer($this->stockOf('Keybord')),
            'value correction ran with jeeves.value_correction.enabled set to false'
        );
    }

    /**
     * PRIVACY and COST. The corrected statement is the old one with a value
     * changed - no second generation - and no stored value reaches the model.
     */
    #[Test]
    public function correcting_costs_no_provider_call_and_sends_no_stored_value(): void
    {
        $this->seedTables();

        $result = $this->answer($this->stockOf('Keybord'));

        $this->assertSame('Keyboard', $this->first($result)['name'] ?? null);
        $this->assertSame(1, $this->calls('generateSql'), 'the correction regenerated the SQL');
        $this->assertSame(0, $this->calls('parseIntent'));

        $sent = json_encode($this->provider->calls);

        foreach (['Headphones', 'Cable B', 'Electronics', 'Globex'] as $stored) {
            $this->assertStringNotContainsString(
                $stored,
                (string) $sent,
                "'{$stored}' is a stored value and reached the provider"
            );
        }
    }

    /** One extra run, never a loop - even when the corrected query is empty too. */
    #[Test]
    public function a_correction_that_still_finds_nothing_stops_there(): void
    {
        $this->seedTables();

        $result = $this->answer($this->stockOf('Keybord', ' AND stock > 1000'));

        $this->assertEmpty($result['rows'] ?? [], json_encode($result));
        $this->assertSame(1, $this->calls('generateSql'));

        // Tried, and says so - the empty answer is about the corrected value.
        $this->assertSame('Keyboard', $result['metadata']['value_corrections'][0]['to'] ?? null);
    }

    /**
     * Intent mode builds its SQL with BINDINGS - a plain value and a
     * `!`-escaped LIKE pattern. A correction that only saw inline literals
     * would do nothing on this route.
     */
    #[Test]
    public function intent_mode_bindings_are_corrected_too(): void
    {
        $this->seedTables();
        config()->set('jeeves.query_mode', 'intent');

        $this->provider = new RecordingProvider;
        $this->provider->intentResponse = [
            'success' => true,
            'dataset' => 'vc_products',
            'metric' => 'stock',
            'group_value' => 'Keybord',
            'limit' => 10,
            'order' => 'desc',
            'needs_clarification' => false,
            'confidence' => 0.95,
        ];
        $this->app->instance(LlmProviderInterface::class, $this->provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        $result = $this->app->make(QueryOrchestrator::class)->query('stock of keybord', 'vc_products');

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        $this->assertSame('Keyboard', $this->first($result)['name'] ?? null);
        $this->assertNotEmpty($result['metadata']['value_corrections'] ?? []);
    }

    /**
     * REVIEW FINDING. Every filtered value used to be compared with the stored
     * values of EVERY opted-in column, so a value filtered on `category` could
     * be "corrected" toward a product name. A value is only corrected toward
     * the values of the column it is actually compared with.
     */
    #[Test]
    public function a_value_is_only_corrected_toward_the_column_it_is_compared_with(): void
    {
        $this->seedTables();

        $this->assertNotCorrected(
            $this->answer("SELECT name, SUM(stock) AS stock FROM vc_products WHERE category = 'Keybord' GROUP BY name LIMIT 10"),
            'a value filtered on `category` was corrected toward a value of `name`'
        );
    }

    /**
     * REVIEW FINDING. The headline question. An ungrouped SUM over no rows is
     * ONE row holding NULL, not zero rows, so the empty-result trigger never
     * fired and "stock of Keybord" still answered "nothing matched".
     */
    #[Test]
    public function an_ungrouped_total_over_a_misspelled_value_is_corrected(): void
    {
        $this->seedTables();

        $result = $this->answer("SELECT SUM(stock) AS stock FROM vc_products WHERE name = 'Keybord'");

        $this->assertEquals(12, $this->first($result)['stock'] ?? null, json_encode($result));
        $this->assertSame('Keyboard', $result['metadata']['value_corrections'][0]['to'] ?? null);
    }

    /** The same gap for COUNT, which answers 0 rather than NULL. */
    #[Test]
    public function an_ungrouped_count_over_a_misspelled_value_is_corrected(): void
    {
        $this->seedTables();

        $result = $this->answer("SELECT COUNT(*) AS n FROM vc_products WHERE name = 'Keybord'");

        $this->assertEquals(1, $this->first($result)['n'] ?? null, json_encode($result));
    }

    /** COUNTERWEIGHT. A zero that is true - the value is stored - is left exactly as it is. */
    #[Test]
    public function a_genuine_zero_is_not_touched(): void
    {
        $this->seedTables();

        $result = $this->answer("SELECT COUNT(*) AS n FROM vc_products WHERE name = 'Keyboard' AND stock > 1000");

        $this->assertEquals(0, $this->first($result)['n'] ?? null, json_encode($result));
        $this->assertArrayNotHasKey('value_corrections', $result['metadata'] ?? []);
    }
}
