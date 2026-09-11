<?php

/**
 * Albums, grouped by their TITLE and linked to an artist. The shape of the
 * question that answered a confident 0 on a real database: an artist's name
 * searched for in this table's title column.
 */
return [
    'name' => 'Albums',
    'description' => 'Albums, each by one artist',
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
                'artist_id' => [
                    'type' => 'integer',
                    'description' => 'artist_id (links to xt_artists)',
                    'filterable' => true,
                ],
            ],
            'relationships' => [
                ['column' => 'artist_id', 'references_table' => 'xt_artists', 'references_column' => 'id'],
            ],
        ],
    ],
];
