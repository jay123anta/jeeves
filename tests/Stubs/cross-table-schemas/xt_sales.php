<?php

/**
 * Sales, linked to an artist. The only table holding money - so "top 3
 * artists by revenue", placed on the artists table, has no measure there and
 * must reach this one through the join.
 */
return [
    'name' => 'Sales',
    'description' => 'Sales of recordings',
    'aliases' => ['sales'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'xt_sales',
            'description' => 'One row per sale',
            'group_column' => 'channel',
            'columns' => [
                'channel' => [
                    'type' => 'varchar',
                    'description' => 'Where it was sold',
                    'groupable' => true,
                    'filterable' => true,
                ],
                'amount' => [
                    'type' => 'decimal',
                    'description' => 'Money received',
                    'aggregatable' => true,
                    'sortable' => true,
                ],
                'artist_id' => [
                    'type' => 'integer',
                    'description' => 'artist_id (links to xt_artists)',
                    'filterable' => true,
                ],
            ],
            'relationships' => [
                ['column' => 'artist_id', 'references_table' => 'xt_artists', 'references_column' => 'id'],
            ],
        ],
    ],
];
