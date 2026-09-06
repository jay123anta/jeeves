<?php

/**
 * A schema shaped like nothing else in the test suite.
 *
 * Deliberately:
 *   - no `group_column` declared (hand-written files often omit it; only
 *     jeeves:discover always writes one)
 *   - no column called `name`, `id`, `title` or anything else the engine
 *     might be tempted to assume
 *   - domain vocabulary unrelated to orders, districts or government datasets
 *
 * If the package only works because a table happens to have familiar column
 * names, this file is where that shows up.
 */
return [
    'name' => 'Warehouse Stock',
    'description' => 'Stock levels per storage bin',
    'aliases' => ['stock', 'warehouse', 'bins'],
    'connection' => null,
    'llm_instructions' => 'Stock held in each storage bin.',

    'tables' => [
        'primary' => [
            'name' => 'nq_warehouse_stock',
            'description' => 'Stock per bin',
            // group_column deliberately absent
            'columns' => [
                'bin_code' => [
                    'type' => 'varchar',
                    'description' => 'Storage bin identifier',
                    'filterable' => true,
                    'groupable' => true,
                ],
                'units_held' => [
                    'type' => 'integer',
                    'description' => 'Units currently in the bin',
                    'aggregatable' => true,
                    'sortable' => true,
                ],
            ],
        ],
    ],

    'max_limit' => 50,
    'default_metric' => 'units_held',
    'defaults' => ['order' => 'DESC', 'limit' => 10],
];
