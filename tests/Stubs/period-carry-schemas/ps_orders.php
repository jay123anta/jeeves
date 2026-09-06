<?php

/**
 * Fixture for the period-carrying conversation test.
 *
 * Deliberately arranged so a dropped period cannot pass by coincidence:
 * July 2026 = 300, West all-time = 600, West in July = 100. Three distinct
 * numbers, so the assertion can only be satisfied by the right one.
 */
return [
    'name' => 'Orders',
    'description' => 'Customer orders',
    'aliases' => ['orders'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'ps_orders',
            'description' => 'Orders',
            'group_column' => 'region',
            'date_column' => 'placed_on',
            'columns' => [
                'region' => ['type' => 'varchar', 'description' => 'Sales region', 'groupable' => true, 'filterable' => true],
                'placed_on' => ['type' => 'date', 'description' => 'Order date'],
                'revenue' => ['type' => 'decimal', 'description' => 'Order revenue', 'unit' => '₹', 'aggregatable' => true, 'sortable' => true],
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
