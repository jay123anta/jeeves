<?php

/**
 * A second table that still STORES the old name "Karimganj" and declares no
 * aliases. A rewrite that followed the word "district" instead of the table
 * that owns the alias would break every query here - so this is the fixture
 * that proves the rewrite stays where the schema put it.
 */
return [
    'name' => 'Staff',
    'description' => 'Staff headcount by district',
    'aliases' => ['staff', 'headcount'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'va_staff',
            'description' => 'One row per district office',
            'group_column' => 'district',
            'columns' => [
                'district' => [
                    'type' => 'varchar',
                    'description' => 'District',
                    'groupable' => true,
                    'filterable' => true,
                ],
                'headcount' => [
                    'type' => 'integer',
                    'description' => 'People employed',
                    'aggregatable' => true,
                    'sortable' => true,
                ],
            ],
        ],
    ],
];
