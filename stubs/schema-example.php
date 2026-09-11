<?php

/**
 * Jeeves Schema Configuration
 *
 * This file defines one queryable dataset for the Jeeves engine.
 * Place schema files in: config/jeeves-schemas/
 *
 * The filename becomes the dataset key (e.g., 'sales.php' → dataset key 'sales').
 * You can have unlimited schema files - one per dataset/topic.
 *
 * PRIVACY: Only the structure defined here (table names, column names, types)
 * is sent to the AI. Your actual data NEVER leaves your system.
 */

return [

    // Human-readable name for this dataset
    'name' => 'My Dataset',

    // Description helps the AI understand context
    'description' => 'Describe what this data is about',

    // Aliases: different ways users might refer to this dataset
    // Include common misspellings for better matching
    'aliases' => [
        'my data', 'my dataset', 'the data',
    ],

    // Optional: which database connection to use (null = default)
    'connection' => null,

    // Instructions for the AI - describe your data in plain English
    // The better you describe your data here, the better the AI understands it
    'llm_instructions' => '
        Describe your data structure here in plain English.
        The AI reads this to understand how to query your data.

        Example:
        - This table has sales orders with customer and product info
        - GROUP BY customer_name for customer-level analysis
        - GROUP BY region for geographical analysis
        - revenue = SUM(total_amount)
        - Use order_date for time-based filtering (YYYY-MM-DD format)
    ',

    // Tables involved in this dataset
    'tables' => [
        // Primary table (required) - the main table to query
        'primary' => [
            'name' => 'schema_name.table_name',   // Fully qualified table name
            'description' => 'Main data table',
            'group_column' => 'category_column',   // Default column for grouping results

            // Column definitions - tell the AI what each column does
            'columns' => [
                'id' => [
                    'type' => 'integer',
                    'description' => 'Primary key',
                ],
                'name_column' => [
                    'type' => 'varchar',
                    'description' => 'Name of the entity',
                    'filterable' => true,    // Can be used in WHERE clauses
                    'groupable' => true,     // Can be used in GROUP BY
                ],
                'category_column' => [
                    'type' => 'varchar',
                    'description' => 'Category for grouping',
                    'filterable' => true,
                    'groupable' => true,
                ],
                'date_column' => [
                    'type' => 'date',
                    'description' => 'Date of the record',
                    'filterable' => true,
                ],
                'amount_column' => [
                    'type' => 'decimal',
                    'description' => 'Monetary value',
                    'unit' => '$',           // Unit for display/speech
                    'metric_type' => 'positive', // positive, negative, or neutral
                    'aliases' => ['revenue', 'total', 'sales'], // User might say these instead
                    // aggregatable: for TRANSACTIONAL tables (many rows per
                    // group value) this makes rankings/details build
                    // SUM(column) ... GROUP BY group_column. For
                    // PRE-AGGREGATED tables (one row per group value), omit
                    // it and set only 'sortable' -  rows are read as-is.
                    'aggregatable' => true,
                    'sortable' => true,
                ],
                'count_column' => [
                    'type' => 'integer',
                    'description' => 'Count value',
                    'unit' => 'items',
                    'aliases' => ['quantity', 'number'],
                    'aggregatable' => true,
                    'sortable' => true,
                ],
                'status' => [
                    'type' => 'varchar',
                    'description' => 'Status field',
                    'filterable' => true,
                    // Optional: list known values to help AI filter correctly
                    // 'values' => ['Active', 'Inactive', 'Pending'],
                    // Optional: other names for a stored value. What the user
                    // typed is swapped for the name on the left before the
                    // query runs - for renames, spelling variants, synonyms.
                    // Exact matches only, and only for this column of this table.
                    // 'value_aliases' => [
                    //     'Cancelled' => ['Canceled', 'Void'],
                    // ],
                    // Optional: when a query filtering on this column finds
                    // NOTHING, compare the value typed with the values stored
                    // and retry once with a clearly closest one - a different
                    // case, or an edit or two away. Read on your server and
                    // never sent to the model. For columns with a manageable
                    // number of values: names, categories, statuses.
                    // 'correct_typos' => true,
                ],
            ],

            // Optional: a rule every query on this table must obey.
            // Useful for time-series tables where you always want latest data.
            //
            // Intent mode APPLIES it. SQL generation CHECKS it and refuses an
            // answer that omits it, because the model wrote the statement.
            //
            // It is a correctness rule, not a security boundary: it keeps
            // ANSWERS right, and does not keep DATA private. Never put a tenant
            // discriminator here - see docs/SCHEMA.md for what does work.
            // 'required_filter' => "date = (SELECT MAX(date) FROM schema_name.table_name)",

            // Optional: JOIN clause when your data table uses an ID instead of a name
            // Use this when the primary table has a foreign key (e.g., region_id)
            // and you need to JOIN a lookup table to get the display name.
            // 'required_join' => 'INNER JOIN lookup_table l ON l.id = primary_table.region_id',
            // 'select_override' => 'l.region_name',  // Column from the joined table to use as group_column
        ],

        // Optional: related tables for JOINs
        // 'related_table' => [
        //     'name' => 'schema_name.other_table',
        //     'description' => 'Related data',
        //     'join_to_primary' => 'other_table.foreign_key = primary_table.id',
        //     'columns' => [
        //         'extra_info' => ['type' => 'varchar', 'description' => '...'],
        //     ],
        // ],
    ],

    // Computed metrics: calculated fields the AI can use
    // These are safe SQL expressions, never user input
    'computed_metrics' => [
        // 'total_amount' => [
        //     'expression' => 'SUM(amount_column)',
        //     'description' => 'Total monetary value',
        //     'unit' => '$',
        //     'aliases' => ['total', 'revenue', 'sum'],
        // ],
        // 'average_amount' => [
        //     'expression' => 'ROUND(AVG(amount_column), 2)',
        //     'description' => 'Average value',
        //     'unit' => '$',
        //     'aliases' => ['average', 'avg', 'mean'],
        // ],
        // 'completion_rate' => [
        //     'expression' => "ROUND(COUNT(*) FILTER(WHERE status='Completed') * 100.0 / NULLIF(COUNT(*), 0), 2)",
        //     'description' => 'Completion percentage',
        //     'unit' => '%',
        //     'aliases' => ['completion', 'completion rate', 'completion percentage'],
        // ],
    ],

    // Example queries: teach the AI how to query YOUR data
    //
    // MORE EXAMPLES = FEWER ERRORS. Cover these patterns:
    // 1. Ranking (top N by metric)
    // 2. Bottom/worst (ASC ordering)
    // 3. Specific record detail (WHERE with smart matching)
    // 4. Computed metric in SELECT/ORDER BY
    // 5. Multi-column SELECT
    // 6. Aggregation (SUM/COUNT across all rows)
    // 7. If JOIN required, show it in EVERY example
    //
    // The AI learns SQL patterns from these examples.
    // Without examples, the AI may generate incorrect SQL.
    'example_queries' => [
        // [
        //     'natural' => 'Top 10 items by amount',
        //     'sql' => "SELECT name_column, SUM(amount_column) AS total FROM schema_name.table_name GROUP BY name_column ORDER BY total DESC LIMIT 10",
        // ],
        // [
        //     'natural' => 'Overall status summary',
        //     'sql' => "SELECT status, COUNT(*) AS count FROM schema_name.table_name GROUP BY status ORDER BY count DESC",
        // ],
    ],

    // Optional: SQL query patterns (templates for different query types)
    // These are included in the AI prompt to show exact SQL patterns.
    // Use {metric}, {order}, {limit}, {value} as placeholders.
    // More patterns = more accurate SQL generation.
    'query_patterns' => [
        // 'ranking' => [
        //     'description' => 'Top/bottom N by a metric',
        //     'sql' => 'SELECT name, {metric} FROM table ORDER BY {metric} {order} LIMIT {limit}',
        // ],
        // 'detail' => [
        //     'description' => 'All columns for a specific record',
        //     'sql' => "SELECT * FROM table WHERE LOWER(name) = LOWER('{value}') LIMIT 1",
        // ],
        // 'service_comparison' => [
        //     'description' => 'Compare different categories using UNION ALL',
        //     'sql' => "SELECT 'Cat A' AS category, SUM(col) AS total FROM table UNION ALL SELECT 'Cat B', SUM(col) FROM table",
        // ],
    ],

    // Optional: max results for this specific schema
    // null = use global setting from config/jeeves.php
    'max_limit' => null,

    // Optional: default metric when user doesn't specify
    'default_metric' => null,

    // Optional: default sort order
    'defaults' => [
        'order' => 'DESC',
        'limit' => 10,
        'level' => 'primary',  // Default grouping level
    ],

];
