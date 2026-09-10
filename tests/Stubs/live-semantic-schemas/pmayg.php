<?php

/**
 * Keyed `pmayg` to match the reference deployment's corpus, so the orchestrator
 * can be run end to end against a LIVE embedding service.
 *
 * The aliases are deliberately useless for the question the test asks. "how
 * many houses were built" contains neither the key nor any alias here, so
 * DatasetSeeder cannot place it and the semantic stage is genuinely the thing
 * under test.
 */
return [
    'name' => 'PMAY-G',
    'description' => 'Rural housing scheme: sanctioned and completed units',
    'aliases' => ['pmay-g'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'nq_pmayg_units',
            'description' => 'One row per sanctioned unit',
            'group_column' => 'district',
            'columns' => [
                'district' => [
                    'type' => 'varchar',
                    'description' => 'District',
                    'aliases' => [],
                    'groupable' => true,
                    'filterable' => true,
                ],
            ],
        ],
    ],
];
