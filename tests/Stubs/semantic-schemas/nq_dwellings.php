<?php

/**
 * Deliberately named and aliased so that "how many houses were built" matches
 * NOTHING by keyword: not the dataset key, not an alias, not a column alias.
 *
 * That miss is the whole point. It is the shape semantic matching exists for -
 * a question whose words the schema author never thought to list - and a stub
 * whose aliases happened to contain "houses" would make every test here pass
 * through DatasetSeeder without the matcher ever being consulted.
 */
return [
    'name' => 'Dwellings',
    'description' => 'Sanctioned and completed dwelling units by district',
    'aliases' => ['dwellings', 'sanctioned units'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'nq_dwellings',
            'description' => 'One row per sanctioned dwelling',
            'group_column' => 'district',
            'columns' => [
                'district' => [
                    'type' => 'varchar',
                    'description' => 'District the unit sits in',
                    'aliases' => ['zila'],
                    'groupable' => true,
                    'filterable' => true,
                ],
            ],
        ],
    ],
];
