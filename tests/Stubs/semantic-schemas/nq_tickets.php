<?php

/**
 * A second dataset, because a one-dataset install has nothing to route BETWEEN
 * and would let a broken matcher look correct. Its aliases are equally unable
 * to catch the dwellings question, so a wrong semantic answer is a wrong
 * ANSWER here rather than a silently harmless one.
 */
return [
    'name' => 'Support tickets',
    'description' => 'Helpdesk tickets raised by customers',
    'aliases' => ['tickets', 'helpdesk'],
    'connection' => null,
    'llm_instructions' => '',
    'tables' => [
        'primary' => [
            'name' => 'nq_tickets',
            'description' => 'One row per ticket',
            'group_column' => 'queue',
            'columns' => [
                'queue' => [
                    'type' => 'varchar',
                    'description' => 'Queue the ticket sits in',
                    'aliases' => ['bucket'],
                    'groupable' => true,
                    'filterable' => true,
                ],
            ],
        ],
    ],
];
