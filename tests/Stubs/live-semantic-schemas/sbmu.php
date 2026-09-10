<?php

/**
 * The second dataset, so the routing test has somewhere WRONG to land. Without
 * it a matcher that always answered "the only dataset" would look correct.
 */
return [
    'name' => 'SBMU',
    'description' => 'Urban sanitation: toilet construction and coverage',
    'aliases' => ['swachh'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'nq_sbmu_toilets',
            'description' => 'One row per toilet',
            'group_column' => 'ward',
            'columns' => [
                'ward' => [
                    'type' => 'varchar',
                    'description' => 'Ward',
                    'aliases' => [],
                    'groupable' => true,
                    'filterable' => true,
                ],
            ],
        ],
    ],
];
