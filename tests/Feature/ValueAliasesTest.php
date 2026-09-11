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
 * `value_aliases`: a schema-declared rename or spelling variant reaches the
 * rows stored under the current name.
 *
 * The data is the adopter's, written in their schema file. The package ships
 * the mechanism only - the project this was learned from hard-coded three
 * district pairs and keyed its rewrite on two column names, which is exactly
 * what a package installed by strangers cannot do.
 */
class ValueAliasesTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/value-alias-schemas');
        $app['config']->set('jeeves.query_mode', 'sql_generation');
        $app['config']->set('jeeves.cache.enabled', false);
        $app['config']->set('jeeves.verification.enabled', false);
    }

    private function seedTables(): void
    {
        Schema::dropIfExists('va_units');
        Schema::create('va_units', function (Blueprint $t) {
            $t->id();
            $t->string('district');
            $t->integer('units');
            $t->string('note')->nullable();
        });
        DB::table('va_units')->insert([
            ['district' => 'Sribhumi', 'units' => 40, 'note' => null],
            ['district' => 'Sivasagar', 'units' => 25, 'note' => null],
            ['district' => 'Kamrup', 'units' => 10, 'note' => null],
        ]);

        Schema::dropIfExists('va_staff');
        Schema::create('va_staff', function (Blueprint $t) {
            $t->id();
            $t->string('district');
            $t->integer('headcount');
        });
        DB::table('va_staff')->insert([['district' => 'Karimganj', 'headcount' => 3]]);
    }

    private function answer(string $sql, string $dataset = 'va_units'): array
    {
        $provider = new RecordingProvider;
        $provider->sqlResponse = ['success' => true, 'data' => [
            'sql' => $sql,
            'dataset' => $dataset,
            'metric' => 'units',
            'query_type' => 'ranking',
        ]];

        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        return $this->app->make(QueryOrchestrator::class)->query('units in the district', $dataset);
    }

    private function districts(array $result): array
    {
        return array_map(fn ($r) => (array) $r, $result['rows'] ?? []);
    }

    /**
     * GUARD. The old name is genuinely absent from the table, so a successful
     * answer below can only have come from the rewrite.
     */
    #[Test]
    public function the_old_name_is_not_in_the_data(): void
    {
        $this->seedTables();

        $this->assertSame(0, DB::table('va_units')->where('district', 'Karimganj')->count());
        $this->assertSame(0, DB::table('va_units')->where('district', 'Sibsagar')->count());
    }

    #[Test]
    public function a_renamed_value_still_reaches_its_rows(): void
    {
        $this->seedTables();

        $result = $this->answer("SELECT district, SUM(units) AS units FROM va_units WHERE district = 'Karimganj' GROUP BY district LIMIT 10");

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        $this->assertSame('Sribhumi', $this->districts($result)[0]['district'] ?? null);

        // Rule 8: the answer says the name it actually used.
        $this->assertSame(
            [['from' => 'Karimganj', 'to' => 'Sribhumi', 'column' => 'district']],
            $result['metadata']['value_aliases_applied'] ?? null
        );
    }

    #[Test]
    public function a_like_pattern_keeps_its_wildcards_and_ignores_case(): void
    {
        $this->seedTables();

        $result = $this->answer("SELECT district, SUM(units) AS units FROM va_units WHERE district LIKE '%karimganj%' GROUP BY district LIMIT 10");

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        $this->assertSame('Sribhumi', $this->districts($result)[0]['district'] ?? null);
    }

    #[Test]
    public function a_spelling_variant_reaches_the_stored_spelling(): void
    {
        $this->seedTables();

        $result = $this->answer("SELECT district, SUM(units) AS units FROM va_units WHERE district = 'Sibsagar' GROUP BY district LIMIT 10");

        $this->assertSame('Sivasagar', $this->districts($result)[0]['district'] ?? null, json_encode($result));
    }

    /** COUNTERWEIGHT. A value that is not an alias is untouched and unreported. */
    #[Test]
    public function a_value_that_is_not_an_alias_is_left_alone(): void
    {
        $this->seedTables();

        $result = $this->answer("SELECT district, SUM(units) AS units FROM va_units WHERE district = 'Kamrup' GROUP BY district LIMIT 10");

        $this->assertSame('Kamrup', $this->districts($result)[0]['district'] ?? null, json_encode($result));
        $this->assertArrayNotHasKey('value_aliases_applied', $result['metadata'] ?? []);
    }

    /**
     * An alias belongs to the column that declares it. The same word compared
     * to a column with no aliases is somebody's actual data.
     */
    #[Test]
    public function the_same_word_in_another_column_is_left_alone(): void
    {
        $this->seedTables();

        $result = $this->answer("SELECT COUNT(*) AS n FROM va_units WHERE note = 'Karimganj'");

        $this->assertArrayNotHasKey(
            'value_aliases_applied',
            $result['metadata'] ?? [],
            'a literal compared to `note` was rewritten because `district` declares that alias'
        );
    }

    /**
     * An alias belongs to the TABLE whose schema declares it. va_staff still
     * stores "Karimganj"; rewriting it there would make a correct query wrong.
     */
    #[Test]
    public function another_table_keeps_its_own_spelling(): void
    {
        $this->seedTables();

        $result = $this->answer(
            "SELECT district, SUM(headcount) AS headcount FROM va_staff WHERE district = 'Karimganj' GROUP BY district LIMIT 10",
            'va_staff'
        );

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        $this->assertSame(
            'Karimganj',
            $this->districts($result)[0]['district'] ?? null,
            'an alias declared on va_units rewrote a query on va_staff, which stores the old name'
        );
        $this->assertArrayNotHasKey('value_aliases_applied', $result['metadata'] ?? []);
    }

    /**
     * Intent mode builds its SQL with BINDINGS rather than inline literals,
     * including a `!`-escaped LIKE pattern. An alias that only worked on the
     * model's literals would silently do nothing on this route.
     */
    #[Test]
    public function intent_mode_bindings_are_aliased_too(): void
    {
        $this->seedTables();
        config()->set('jeeves.query_mode', 'intent');

        $provider = new RecordingProvider;
        $provider->intentResponse = [
            'success' => true,
            'dataset' => 'va_units',
            'metric' => 'units',
            'group_value' => 'Karimganj',
            'limit' => 10,
            'order' => 'desc',
            'needs_clarification' => false,
            'confidence' => 0.95,
        ];
        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        $result = $this->app->make(QueryOrchestrator::class)->query('units in karimganj', 'va_units');

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        $this->assertSame('Sribhumi', $this->districts($result)[0]['district'] ?? null);
        $this->assertNotEmpty($result['metadata']['value_aliases_applied'] ?? []);
    }
}
