<?php

/**
 * Schema for MysqlIntrospectorTest.
 *
 * Unqualified table name: on MySQL a schema IS a database, so the connection
 * already selects it and qualifying would point at the wrong place.
 */
return [
    'name' => 'Stock Moves',
    'description' => 'Stock movements per storage bin',
    'aliases' => ['stock', 'moves'],
    'connection' => null,

    'tables' => [
        'primary' => [
            'name' => 'nq_stock_moves',
            'description' => 'Stock movements',
            'group_column' => 'bin_code',
            'columns' => [
                'bin_code' => [
                    'type' => 'varchar',
                    'description' => 'Storage bin identifier',
                    'filterable' => true,
                    'groupable' => true,
                ],
                'units_held' => [
                    'type' => 'integer',
                    'description' => 'Units moved',
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
