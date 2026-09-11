<?php

/**
 * The same albums table with no links to anything. On an unlinked schema a
 * name that matched nothing has nowhere else to live, so the existing answer -
 * the unfiltered result, saying the name did not match - must not change and
 * must not cost a second call.
 */
return [
    'name' => 'Albums',
    'description' => 'Albums',
    'aliases' => ['albums', 'records'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'xt_albums',
            'description' => 'One row per album',
            'group_column' => 'title',
            'columns' => [
                'title' => [
                    'type' => 'varchar',
                    'description' => 'Album title',
                    'groupable' => true,
                    'filterable' => true,
                ],
            ],
            'relationships' => [],
        ],
    ],
];
