<?php

/** Infrastructure that discover picked up and nobody removed. */
return [
    'name' => 'Sessions',
    'description' => 'Session store',
    'aliases' => ['sessions'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'sessions',
            'description' => 'Sessions',
            'group_column' => 'id',
            'columns' => [
                'payload' => ['type' => 'text', 'description' => 'Session payload'],
            ],
            'relationships' => [],
        ],
    ],
    'computed_metrics' => [],
    'example_queries' => [],
    'max_limit' => 50,
    'default_metric' => null,
    'defaults' => ['order' => 'DESC', 'limit' => 10],
];
