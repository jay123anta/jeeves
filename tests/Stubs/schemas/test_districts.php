<?php

// Pre-aggregated schema: ONE row per district (CM-Dashboard style).
// No column is marked 'aggregatable', so SqlBuilder must NOT add GROUP BY/SUM.
return [
    'name' => 'Test Districts',
    'description' => 'Pre-aggregated test schema for unit tests',
    'aliases' => ['districts'],
    'connection' => null,
    'llm_instructions' => 'One row per district with pre-computed totals.',

    'tables' => [
        'primary' => [
            'name' => 'public.district_stats',
            'description' => 'Pre-aggregated district statistics',
            'group_column' => 'district_name',
            'columns' => [
                'district_name' => [
                    'type' => 'varchar',
                    'description' => 'District name',
                    'filterable' => true,
                    'groupable' => true,
                ],
                'total_households' => [
                    'type' => 'integer',
                    'description' => 'Total households (pre-computed)',
                    'sortable' => true,
                ],
            ],
        ],
    ],

    'computed_metrics' => [
        'coverage_pct' => [
            // Scalar row expression -  valid ONLY without GROUP BY
            'expression' => 'ROUND(covered_households * 100.0 / total_households, 2)',
            'description' => 'Coverage percentage',
            'unit' => '%',
        ],
    ],

    'max_limit' => 50,
    'default_metric' => 'total_households',
    'defaults' => ['order' => 'DESC', 'limit' => 10],
];
