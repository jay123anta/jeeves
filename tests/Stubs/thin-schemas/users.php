<?php

/**
 * What `jeeves:discover` writes before anybody describes anything: the same
 * placeholder sentence for the dataset AND its table, no aliases worth the
 * name, no column aliases at all.
 *
 * Copied from a real generated file rather than invented - this is the state
 * every install passes through, and the corpus it produces is the one most
 * likely to be handed to a matching service by someone who has not read the
 * documentation yet.
 */
return [
    'name' => 'Users',
    'description' => 'Data from Users',
    'aliases' => ['users'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'users',
            'description' => 'Data from Users',
            'group_column' => 'name',
            'columns' => [
                'name' => [
                    'type' => 'varchar',
                    'description' => '',
                    'aliases' => [],
                    'groupable' => true,
                    'filterable' => true,
                ],
            ],
        ],
    ],
];
