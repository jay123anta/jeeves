<?php

/**
 * A question set for `php artisan jeeves:benchmark`.
 *
 * Copy this to config/jeeves-benchmark.php and rewrite it for your own
 * database. Fifteen to twenty questions is enough to be useful; the point is a
 * number you produced yourself rather than one this package asserts.
 *
 * HOW IT IS GRADED
 *
 * Not by comparing SQL -  many different queries are correct. Your reference
 * query and the one the package generates are both run against your database
 * and the RESULT SETS are compared. Column names and column order are ignored.
 * Row order is ignored unless you set `ordered`, because a ranking's order is
 * its answer.
 *
 * THE ONE RULE THAT MATTERS
 *
 * Write the reference SQL from the QUESTION, before you look at what the
 * package produced. Writing it afterwards means copying whatever it did,
 * including its mistakes, and the benchmark then marks its own homework and
 * reports 100% forever.
 *
 * WHAT TO INCLUDE
 *
 * Real questions your users ask, not questions you know it handles. Include
 * the awkward ones: two measures that could both be "revenue", a period
 * ("last quarter"), a filter and a grouping together. Those are where a
 * schema's missing descriptions show up.
 *
 * CHECKS, INSTEAD OF OR AS WELL AS A REFERENCE
 *
 * A question may carry `expect` - checks on the answer itself:
 *
 *   'rows' => 1              exactly this many rows
 *   'min_rows' / 'max_rows'  at least / at most this many
 *   'min' / 'max'            the first number in the first row (or the
 *                            column named by 'column') falls in this range
 *   'contains' => ['Acme']   some row has a cell equal to each of these
 *
 * Use them where the right SQL is hard to write but a wrong answer is easy to
 * recognise, and alongside a reference to catch what a comparison cannot: a
 * SUM over no rows is one row of NULL, and a reference returning the same
 * NULL would otherwise grade it correct. With both, both must hold.
 *
 * Running this calls your AI provider once or more per question, so it costs
 * money. Nothing runs it implicitly.
 */

return [
    [
        // What a user would type.
        'question' => 'how many orders are there',

        // The SQL you would have written. SELECT or WITH only.
        'gold' => 'SELECT COUNT(*) AS n FROM orders',

        // Optional. Groups the summary so you can see whether the easy ones
        // pass and the hard ones do not, which the average hides.
        'hardness' => 'easy',
    ],

    [
        'question' => 'total revenue last month',
        'gold' => "SELECT SUM(line_total) AS revenue
                     FROM order_items
                     JOIN orders ON orders.id = order_items.order_id
                    WHERE orders.placed_at >= date_trunc('month', current_date - interval '1 month')
                      AND orders.placed_at <  date_trunc('month', current_date)",
        'hardness' => 'medium',
    ],

    [
        'question' => 'top 5 customers by revenue',
        'gold' => 'SELECT customers.name, SUM(order_items.line_total) AS revenue
                     FROM order_items
                     JOIN orders    ON orders.id = order_items.order_id
                     JOIN customers ON customers.id = orders.customer_id
                    GROUP BY customers.name
                    ORDER BY revenue DESC
                    LIMIT 5',

        // The question said "top 5", so the sequence is part of the answer.
        'ordered' => true,
        'hardness' => 'medium',
    ],

    [
        'question' => 'revenue by region for pending orders',
        'gold' => 'SELECT regions.name, SUM(order_items.line_total) AS revenue
                     FROM order_items
                     JOIN orders    ON orders.id = order_items.order_id
                     JOIN customers ON customers.id = orders.customer_id
                     JOIN regions   ON regions.id = customers.region_id
                    WHERE orders.status = \'pending\'
                    GROUP BY regions.name',
        'hardness' => 'hard',

        // Optional. Restricts this question to one dataset, the same as
        // passing a dataset to the API.
        // 'dataset' => 'orders',
    ],
    [
        // No reference query - checks on the answer instead.
        'question' => 'which customer spent the most',
        'expect' => [
            'rows' => 1,                  // one winner, not a list
            'contains' => ['Acme Ltd'],   // the name you know should win
        ],
    ],

    [
        // Both: the reference must match AND the figure must be plausible.
        'question' => 'total revenue this year',
        'gold' => 'SELECT SUM(line_total) AS revenue FROM order_items',
        'expect' => ['min' => 1],
    ],
];
