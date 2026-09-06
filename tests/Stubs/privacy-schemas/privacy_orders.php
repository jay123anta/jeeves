<?php

/**
 * Schema stub for PrivacyWallTest.
 *
 * Deliberately isolated from tests/Stubs/schemas so the privacy suite can seed
 * a real SQLite table without disturbing the other suites' dataset counts.
 *
 * IMPORTANT: nothing in this file may contain any of the sentinel strings the
 * test seeds into the database. The whole point is that a sentinel appearing in
 * an outbound payload can only have come from a result row.
 *
 * Table name is unqualified so it works on SQLite.
 */
return [
    'name' => 'Privacy Orders',
    'description' => 'Order records used to prove no row data reaches the provider',
    'aliases' => ['orders', 'sales'],
    'connection' => null,
    'llm_instructions' => 'Order table with buyer, total and state columns.',

    'tables' => [
        'primary' => [
            'name' => 'nq_privacy_orders',
            'description' => 'Order records',
            'group_column' => 'buyer',
            'columns' => [
                'buyer' => [
                    'type' => 'varchar',
                    'description' => 'Buyer name',
                    'filterable' => true,
                    'groupable' => true,
                ],
                'total' => [
                    'type' => 'decimal',
                    'description' => 'Order total',
                    'unit' => '$',
                    'aliases' => ['revenue', 'amount'],
                    'aggregatable' => true,
                    'sortable' => true,
                ],
                'state' => [
                    'type' => 'varchar',
                    'description' => 'Order state',
                    'filterable' => true,
                ],
            ],
        ],
    ],

    'example_queries' => [
        ['natural' => 'Top buyers', 'sql' => 'SELECT buyer, SUM(total) AS t FROM nq_privacy_orders GROUP BY buyer ORDER BY t DESC LIMIT 10'],
    ],

    'max_limit' => 100,
    'default_metric' => 'total',
    'defaults' => ['order' => 'DESC', 'limit' => 10],
];
