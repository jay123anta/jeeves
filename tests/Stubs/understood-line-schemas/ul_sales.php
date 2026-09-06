<?php

/**
 * Fixture for the `parsed_summary` semantics tests.
 *
 * Two deliberate properties:
 *
 *  - `group_column` is `customer_name`, which is NOT the dimension any of
 *    those tests groups by. A summary built from the schema default rather
 *    than from the executed query announces "by customer name" and the test
 *    catches it.
 *  - `placed_on` exists and is the declared `date_column`, so a period label
 *    can be checked against the WHERE clause that was actually written. The
 *    model reports the period itself on the sql_generation route, and a report
 *    is not evidence.
 */
return [
    'name' => 'Sales',
    'description' => 'Order lines',
    'aliases' => ['sales', 'orders'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'ul_sales',
            'description' => 'Order lines',
            'group_column' => 'customer_name',
            'date_column' => 'placed_on',
            'columns' => [
                'customer_name' => ['type' => 'varchar', 'description' => 'Customer', 'groupable' => true, 'filterable' => true],
                'region' => ['type' => 'varchar', 'description' => 'Sales region', 'groupable' => true, 'filterable' => true],
                'status' => ['type' => 'varchar', 'description' => 'Order status', 'groupable' => true, 'filterable' => true],
                'placed_on' => ['type' => 'date', 'description' => 'Order date'],
                'revenue' => ['type' => 'decimal', 'description' => 'Line revenue', 'unit' => '₹', 'aggregatable' => true, 'sortable' => true],
            ],
            'relationships' => [],
        ],
    ],
    'computed_metrics' => [],
    'example_queries' => [],
    'max_limit' => 50,
    'default_metric' => 'revenue',
    'defaults' => ['order' => 'DESC', 'limit' => 10],
];
