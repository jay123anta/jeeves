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
 * Value aliases are a mechanism, not a feature about places.
 *
 * ValueAliasesTest uses renamed districts because that is where the need was
 * first met. This file is the same mechanism in an orders database with no
 * geography at all - order statuses and country names - so nothing about the
 * implementation can quietly depend on one domain's shape.
 */
class ValueAliasesAnyDomainTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/value-alias-orders-schemas');
        $app['config']->set('jeeves.query_mode', 'sql_generation');
        $app['config']->set('jeeves.cache.enabled', false);
        $app['config']->set('jeeves.verification.enabled', false);
    }

    private function seedTables(): void
    {
        Schema::dropIfExists('vo_orders');
        Schema::create('vo_orders', function (Blueprint $t) {
            $t->id();
            $t->string('status');
            $t->string('country');
            $t->decimal('total', 10, 2);
        });
        DB::table('vo_orders')->insert([
            ['status' => 'cancelled', 'country' => 'United Kingdom', 'total' => 100],
            ['status' => 'shipped', 'country' => 'United Kingdom', 'total' => 50],
            ['status' => 'cancelled', 'country' => 'France', 'total' => 30],
        ]);

        Schema::dropIfExists('vo_returns');
        Schema::create('vo_returns', function (Blueprint $t) {
            $t->id();
            $t->string('status');
            $t->integer('qty');
        });
        DB::table('vo_returns')->insert([['status' => 'canceled', 'qty' => 7]]);
    }

    private function answer(string $sql, string $dataset = 'vo_orders'): array
    {
        $provider = new RecordingProvider;
        $provider->sqlResponse = ['success' => true, 'data' => [
            'sql' => $sql,
            'dataset' => $dataset,
            'metric' => 'total',
            'query_type' => 'ranking',
        ]];

        $this->app->instance(LlmProviderInterface::class, $provider);
        $this->app->forgetInstance(QueryOrchestrator::class);

        return $this->app->make(QueryOrchestrator::class)->query('orders', $dataset);
    }

    private function firstRow(array $result): array
    {
        return (array) (($result['rows'] ?? [])[0] ?? []);
    }

    /** GUARD. The aliases really are absent from the stored data. */
    #[Test]
    public function the_aliases_are_not_in_the_data(): void
    {
        $this->seedTables();

        $this->assertSame(0, DB::table('vo_orders')->whereIn('status', ['canceled', 'void'])->count());
        $this->assertSame(0, DB::table('vo_orders')->whereIn('country', ['UK', 'Britain'])->count());
    }

    #[Test]
    public function a_us_spelling_reaches_the_stored_spelling(): void
    {
        $this->seedTables();

        $result = $this->answer("SELECT status, SUM(total) AS total FROM vo_orders WHERE status = 'canceled' GROUP BY status LIMIT 10");

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        $this->assertSame('cancelled', $this->firstRow($result)['status'] ?? null);
        $this->assertEquals(130, $this->firstRow($result)['total'] ?? null);
    }

    /** Exact matching carries no short-word risk, so a two-letter alias is fine. */
    #[Test]
    public function a_two_letter_alias_works(): void
    {
        $this->seedTables();

        $result = $this->answer("SELECT country, SUM(total) AS total FROM vo_orders WHERE country = 'UK' GROUP BY country LIMIT 10");

        $this->assertSame('United Kingdom', $this->firstRow($result)['country'] ?? null, json_encode($result));
        $this->assertEquals(150, $this->firstRow($result)['total'] ?? null);
    }

    #[Test]
    public function two_aliases_in_one_query_are_both_applied_and_both_reported(): void
    {
        $this->seedTables();

        $result = $this->answer(
            "SELECT SUM(total) AS total FROM vo_orders WHERE status = 'void' AND country = 'Britain'"
        );

        $this->assertSame('success', $result['status'] ?? null, json_encode($result));
        $this->assertEquals(100, $this->firstRow($result)['total'] ?? null);

        $applied = $result['metadata']['value_aliases_applied'] ?? [];
        $this->assertCount(2, $applied, json_encode($applied));
        $this->assertEqualsCanonicalizing(
            ['status', 'country'],
            array_column($applied, 'column')
        );
    }

    /** COUNTERWEIGHT. The table that stores "canceled" is left exactly as it is. */
    #[Test]
    public function a_table_that_stores_the_alias_is_left_alone(): void
    {
        $this->seedTables();

        $result = $this->answer(
            "SELECT status, SUM(qty) AS qty FROM vo_returns WHERE status = 'canceled' GROUP BY status LIMIT 10",
            'vo_returns'
        );

        $this->assertSame('canceled', $this->firstRow($result)['status'] ?? null, json_encode($result));
        $this->assertArrayNotHasKey('value_aliases_applied', $result['metadata'] ?? []);
    }
}
