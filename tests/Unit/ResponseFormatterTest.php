<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Jayanta\Jeeves\Engine\ResponseFormatter;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ResponseFormatterTest extends TestCase
{
    private ResponseFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new ResponseFormatter;
    }

    #[Test]
    public function it_formats_success_response()
    {
        $queryResult = [
            'dataset' => 'test',
            'dataset_name' => 'Test',
            'metric' => 'amount',
            'metric_description' => 'Order amount',
            'metric_unit' => '$',
            'metric_type' => 'positive',
            'group_value' => null,
            'limit' => 10,
            'order' => 'DESC',
            'query_type' => 'ranking',
            'group_column' => 'name',
        ];

        $rows = [
            (object) ['name' => 'Alice', 'amount' => 1000],
            (object) ['name' => 'Bob', 'amount' => 500],
        ];

        $result = $this->formatter->format($queryResult, $rows);

        $this->assertEquals('success', $result['status']);
        $this->assertCount(2, $result['rows']);
        $this->assertNotEmpty($result['answer']);
        $this->assertArrayHasKey('visualization', $result);
    }

    #[Test]
    public function it_formats_no_data_response()
    {
        $queryResult = [
            'dataset' => 'test',
            'dataset_name' => 'Test',
            'metric' => 'amount',
            'group_value' => 'Unknown',
        ];

        $result = $this->formatter->formatNoData($queryResult);

        $this->assertEquals('success', $result['status']);
        $this->assertEquals('no_data', $result['type']);
        $this->assertEmpty($result['rows']);
        $this->assertStringContainsString('Unknown', $result['answer']);
    }

    #[Test]
    public function it_formats_error_response()
    {
        $result = $this->formatter->formatError('Something went wrong', ['key' => 'val']);

        $this->assertEquals('error', $result['status']);
        $this->assertEquals('Something went wrong', $result['error']);
    }

    #[Test]
    public function it_formats_clarification_response()
    {
        $intent = ['dataset' => null, 'metric' => null, 'group_value' => null, 'confidence' => 0.3];
        $datasets = [['key' => 'orders', 'name' => 'Orders', 'description' => 'test']];

        $result = $this->formatter->formatClarification($intent, $datasets);

        $this->assertEquals('clarification_needed', $result['status']);
        $this->assertNotEmpty($result['alternatives']);
    }

    #[Test]
    public function it_determines_visualization_type()
    {
        $queryResult = [
            'dataset' => 'test', 'metric' => 'amount', 'group_value' => null,
            'query_type' => 'ranking', 'group_column' => 'name',
            'dataset_name' => 'Test', 'metric_description' => 'Amount',
            'metric_unit' => '$', 'metric_type' => 'positive',
            'limit' => 3, 'order' => 'DESC',
        ];

        // Few rows = bar chart
        $rows = [(object) ['name' => 'A', 'amount' => 1], (object) ['name' => 'B', 'amount' => 2]];
        $result = $this->formatter->format($queryResult, $rows);
        $this->assertEquals('bar', $result['visualization']);
    }

    #[Test]
    public function it_generates_insights_for_numeric_data()
    {
        $queryResult = [
            'dataset' => 'test', 'metric' => 'amount', 'group_value' => null,
            'query_type' => 'ranking', 'group_column' => 'name',
            'dataset_name' => 'Test', 'metric_description' => 'Amount',
            'metric_unit' => '$', 'metric_type' => 'positive',
            'limit' => 10, 'order' => 'DESC',
        ];

        $rows = [
            (object) ['name' => 'A', 'amount' => 100],
            (object) ['name' => 'B', 'amount' => 200],
            (object) ['name' => 'C', 'amount' => 300],
        ];

        $result = $this->formatter->format($queryResult, $rows);

        $this->assertArrayHasKey('insights', $result);
        $this->assertEquals(3, $result['insights']['count']);
    }

    /**
     * When a query joins, the model aliases the label column to whatever it
     * likes, so the schema's group_column is often absent from the result.
     * Every row then rendered as "?" -  a correct answer that looked broken.
     */
    #[Test]
    public function rows_are_labelled_even_when_the_group_column_is_not_in_the_result()
    {
        $result = $this->formatter->format(
            [
                'dataset' => 'orders',
                'dataset_name' => 'Orders',
                'metric' => 'revenue',
                'group_column' => 'status',   // not present in the rows below
                'order' => 'desc',
                'query_type' => 'ranking',
            ],
            [
                ['customer_name' => 'Acme Industrial', 'revenue' => 2500],
                ['customer_name' => 'Northwind Traders', 'revenue' => 450],
            ]
        );

        $this->assertStringContainsString('Acme Industrial', $result['answer']);
        $this->assertStringNotContainsString('?', $result['answer']);
    }

    /** With nothing but numbers, an id still beats a question mark. */
    #[Test]
    public function a_row_of_only_numbers_falls_back_to_an_identifier()
    {
        $result = $this->formatter->format(
            [
                'dataset' => 'orders',
                'dataset_name' => 'Orders',
                'metric' => 'revenue',
                'group_column' => 'status',
                'order' => 'desc',
                'query_type' => 'ranking',
            ],
            [
                ['customer_id' => 7, 'revenue' => 2500],
                ['customer_id' => 9, 'revenue' => 450],
            ]
        );

        $this->assertStringContainsString('7', $result['answer']);
        $this->assertStringNotContainsString('?', $result['answer']);
    }
}
