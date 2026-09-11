<?php

/**
 * A second table that STORES the US spelling "canceled" and declares no
 * aliases, so a rewrite that leaked across tables would break it.
 */
return [
    'name' => 'Returns',
    'description' => 'Returned items',
    'aliases' => ['returns'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'vo_returns',
            'description' => 'One row per return',
            'group_column' => 'status',
            'columns' => [
                'status' => [
                    'type' => 'varchar',
                    'description' => 'Return status',
                    'groupable' => true,
                    'filterable' => true,
                ],
                'qty' => [
                    'type' => 'integer',
                    'description' => 'Items returned',
                    'aggregatable' => true,
                    'sortable' => true,
                ],
            ],
        ],
    ],
];
