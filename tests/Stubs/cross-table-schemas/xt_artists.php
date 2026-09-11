<?php

/** Recording artists. Albums and sales both link here. */
return [
    'name' => 'Artists',
    'description' => 'Recording artists',
    'aliases' => ['artists', 'bands'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'xt_artists',
            'description' => 'One row per artist',
            'group_column' => 'name',
            'columns' => [
                'name' => [
                    'type' => 'varchar',
                    'description' => 'Artist name',
                    'groupable' => true,
                    'filterable' => true,
                ],
            ],
            'relationships' => [],
        ],
    ],
];
