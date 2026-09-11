<?php

/**
 * A district renamed (Karimganj is now Sribhumi) and a district with two
 * spellings in circulation (Sivasagar / Sibsagar). The table stores only the
 * current names, so a question using the old one finds nothing - unless the
 * schema says the two are the same place.
 *
 * `note` is here to prove a literal equal to an alias is left alone when it
 * is compared to a column that declares no aliases.
 */
return [
    'name' => 'Units',
    'description' => 'Sanctioned units by district',
    'aliases' => ['units'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'va_units',
            'description' => 'One row per district',
            'group_column' => 'district',
            'columns' => [
                'district' => [
                    'type' => 'varchar',
                    'description' => 'District',
                    'aliases' => ['zila'],
                    'groupable' => true,
                    'filterable' => true,
                    'value_aliases' => [
                        'Sribhumi' => ['Karimganj'],
                        'Sivasagar' => ['Sibsagar', 'Sibsagor'],
                    ],
                ],
                'units' => [
                    'type' => 'integer',
                    'description' => 'Units sanctioned',
                    'aggregatable' => true,
                    'sortable' => true,
                ],
                'note' => [
                    'type' => 'varchar',
                    'description' => 'Free text',
                    'filterable' => true,
                ],
            ],
        ],
    ],
];
