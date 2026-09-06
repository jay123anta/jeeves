<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Jayanta\Jeeves\Engine\PromptBuilder;
use Jayanta\Jeeves\Schema\Introspectors\Concerns\SuggestsColumnRoles;
use Jayanta\Jeeves\Schema\SchemaRegistry;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Most Laravel applications have a normalised schema, and the first realistic
 * question about one spans tables: the name lives in `customers`, the money
 * lives in `orders`.
 *
 * Tested against a real three-table database, that used to fail in four ways
 * at once. Foreign keys were classified as measures, so the model was told
 * `customer_id` was a number worth totalling. The foreign keys themselves were
 * written into a PHP comment, so the prompt never mentioned that the tables
 * related at all. Routing matched the table NAMED in the question -  the
 * dimension table -  and produced a single-table prompt with no revenue in it.
 * And rows were labelled "?" whenever the result did not contain the schema's
 * group_column.
 *
 * The outcome was either "which dataset did you mean?" or an answer listing
 * raw id values. These cases pin each part of the fix.
 */
class RelatedTablesTest extends TestCase
{
    use SuggestsColumnRoles;

    /** Exposed so the trait's helper can be exercised directly. */
    protected function normalizeType(string $type): string
    {
        return strtolower($type);
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/related-schemas');
    }

    #[Test]
    public function a_foreign_key_is_not_treated_as_a_number_to_total()
    {
        $this->assertSame('foreign_key', $this->suggestRole('customer_id', 'integer', false));
        $this->assertSame('foreign_key', $this->suggestRole('product_uuid', 'varchar', false));
        $this->assertSame('identifier', $this->suggestRole('id', 'integer', true));
    }

    #[Test]
    public function genuine_measures_are_still_measures()
    {
        // The exclusion must not swallow columns that merely end in a noun.
        $this->assertSame('measure', $this->suggestRole('quantity', 'integer', false));
        $this->assertSame('measure', $this->suggestRole('unit_price', 'decimal', false));
        $this->assertSame('measure', $this->suggestRole('order_count', 'integer', false));
        $this->assertSame('measure', $this->suggestRole('total_paid', 'decimal', false));
    }

    #[Test]
    public function text_columns_are_dimensions_on_every_driver()
    {
        // The MySQL copy of this heuristic never handled TEXT before the two
        // implementations were merged.
        $this->assertSame('dimension', $this->suggestRole('notes', 'text', false));
        $this->assertSame('dimension', $this->suggestRole('region', 'varchar', false));
    }

    #[Test]
    public function the_prompt_tells_the_model_how_the_tables_join()
    {
        $prompt = $this->app->make(PromptBuilder::class)->buildSqlPrompt('rel_orders', 'top customers by revenue');

        $this->assertStringContainsString('RELATED:', $prompt);
        $this->assertStringContainsString('rel_customers', $prompt);
        $this->assertStringContainsString('customer_id', $prompt);
    }

    #[Test]
    public function the_multi_table_prompt_says_the_tables_may_be_joined()
    {
        // Listing them as separate "datasets" with no further comment made
        // models answer a cross-table question with "which one did you mean?".
        $prompt = $this->app->make(PromptBuilder::class)->buildMultiDatasetPrompt('top customers by revenue');

        $this->assertStringContainsString('SAME database', $prompt);
        $this->assertStringContainsString('JOIN across them', $prompt);
        $this->assertStringContainsString('RELATED:', $prompt);
    }

    /**
     * A composite key is ONE join with an AND. Rendering a line per column
     * invites the model to join the same table twice, or to join on half the
     * key -  and half a composite key matches rows it should not, so the total
     * comes back silently too large.
     */
    #[Test]
    public function a_composite_key_is_rendered_as_a_single_join_with_and()
    {
        $prompt = $this->app->make(PromptBuilder::class)->buildSqlPrompt('rel_sales', 'revenue by region');

        $related = array_values(array_filter(
            explode("\n", $prompt),
            fn ($l) => str_contains($l, 'RELATED:')
        ));

        $this->assertCount(1, $related, 'a two-column key must not produce two JOIN lines');
        $this->assertStringContainsString(' AND ', $related[0]);
        $this->assertStringContainsString('tenant_id = rel_sales.tenant_id', $related[0]);
        $this->assertStringContainsString('region_code = rel_sales.region_code', $related[0]);
        $this->assertStringContainsString('ALL conditions are required', $related[0]);
    }

    #[Test]
    public function a_single_column_key_stays_a_plain_join()
    {
        $prompt = $this->app->make(PromptBuilder::class)->buildSqlPrompt('rel_orders', 'top customers');

        $related = array_values(array_filter(
            explode("\n", $prompt),
            fn ($l) => str_contains($l, 'RELATED:')
        ));

        $this->assertCount(1, $related);
        $this->assertStringNotContainsString(' AND ', $related[0]);
    }

    #[Test]
    public function related_datasets_are_recognised_as_linked()
    {
        $registry = $this->app->make(SchemaRegistry::class);

        $this->assertTrue($registry->hasLinkedSchemas());
    }

    /**
     * `discover --table=orders` writes one schema file whose foreign keys
     * still point at tables that were never discovered. The allowed-table list
     * the SqlValidator enforces is built from schema files, so those tables are
     * not queryable -  and advertising the join anyway produced the worst kind
     * of failure: "query validation failed" on SQL the package's own prompt had
     * instructed the model to write.
     */
    #[Test]
    public function a_join_is_never_suggested_to_a_table_the_validator_forbids()
    {
        config(['jeeves.schema.config_path' => __DIR__ . '/../Stubs/subset-schemas']);
        $this->app->forgetInstance(SchemaRegistry::class);
        $this->app->forgetInstance(PromptBuilder::class);

        $registry = $this->app->make(SchemaRegistry::class);
        $this->assertNotContains(
            'sub_customers',
            $registry->getAllowedTables(),
            'precondition: the referenced table must be outside the whitelist'
        );

        $prompt = $this->app->make(PromptBuilder::class)
            ->buildSqlPrompt('sub_orders', 'top customers by quantity');

        $this->assertStringNotContainsString('sub_customers', $prompt);
        $this->assertStringNotContainsString('RELATED:', $prompt);
    }

    #[Test]
    public function a_single_dataset_is_never_treated_as_linked()
    {
        // With one table there is nothing to join to, and the focused prompt
        // is both cheaper and more accurate.
        config(['jeeves.schema.config_path' => __DIR__ . '/../Stubs/unfamiliar-schemas']);
        $this->app->forgetInstance(SchemaRegistry::class);

        $this->assertFalse($this->app->make(SchemaRegistry::class)->hasLinkedSchemas());
    }
}
