<?php

/**
 * The schema behind the `parsed_summary` examples in README.md and docs/API.md.
 *
 * Those documents quote exact output strings. Every one of them was
 * unproducible by any input for the whole of the release that introduced the
 * feature, because the line was built from a result array whose keys did not
 * match the slots the builder read. Nothing caught it: the only test asserted
 * that the line contained "Orders" and "revenue".
 *
 * So the documented strings are now pinned by a test against this fixture. If
 * the rendering changes, the docs fail rather than quietly becoming fiction.
 */
return [
    'name' => 'Orders',
    'description' => 'Customer orders',
    'aliases' => ['orders'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'doc_orders',
            'description' => 'Orders',
            'group_column' => 'region',
            'date_column' => 'placed_on',
            'columns' => [
                'region' => ['type' => 'varchar', 'description' => 'Sales region', 'groupable' => true, 'filterable' => true],
                'status' => ['type' => 'varchar', 'description' => 'Order status', 'groupable' => true, 'filterable' => true],
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
