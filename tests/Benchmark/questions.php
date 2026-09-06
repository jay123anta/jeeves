<?php

/**
 * A question set with gold SQL, for measuring execution accuracy.
 *
 * Modelled on how Spider and BIRD grade text-to-SQL: you do not compare query
 * strings, because many different queries are correct. You run the generated
 * query and a hand-written reference against the same database and compare the
 * RESULT SETS. A query is right when its answer is right.
 *
 * Most of these need MORE THAN ONE TABLE, because that is what real questions
 * need. The measure, the label and the filter routinely live in three
 * different places: "revenue by region" is line_total in bm_order_items,
 * region name in bm_regions, and the path between them runs through
 * bm_orders and bm_customers -  four tables for a question a person would call
 * simple. Every serious defect in this package has been found by a question of
 * that shape, and none by a single-table one.
 *
 * `hardness` follows Spider's component-count definition:
 *
 *   easy   -  one table, at most one aggregate or filter
 *   medium -  grouping, ordering, or a join
 *   hard   -  several components at once: joins AND a grouping AND/OR a period
 *   extra  -  needs SQL the intent contract cannot express at all:
 *            HAVING, DISTINCT, a ratio of aggregates, a window function
 *
 * `joins` records how many tables the gold answer touches, so the report can
 * show whether accuracy degrades with join depth.
 *
 * Gold SQL is written from the QUESTION, never from the package's output.
 * Where a question implies an order ("top 3"), `ordered` is set and the
 * comparison respects row order; otherwise results are compared as multisets.
 */

return [
    // ---------------------------------------------------------------- easy
    [
        'question' => 'how many customers are there',
        'gold' => 'SELECT COUNT(*) AS n FROM bm_customers',
        'hardness' => 'easy',
        'joins' => 1,
    ],
    [
        'question' => 'what is the total revenue',
        'gold' => 'SELECT SUM(line_total) AS revenue FROM bm_order_items',
        'hardness' => 'easy',
        'joins' => 1,
    ],
    [
        'question' => 'how many support tickets are there',
        'gold' => 'SELECT COUNT(*) AS n FROM bm_support_tickets',
        'hardness' => 'easy',
        'joins' => 1,
    ],

    // -------------------------------------------------------------- medium
    [
        'question' => 'revenue by product category',
        'gold' => 'SELECT c.name, SUM(i.line_total) AS revenue
                   FROM bm_order_items i
                   JOIN bm_products p ON p.id = i.product_id
                   JOIN bm_categories c ON c.id = p.category_id
                   GROUP BY c.name',
        'hardness' => 'medium',
        'joins' => 3,
    ],
    [
        'question' => 'how many orders by status',
        'gold' => 'SELECT status, COUNT(*) AS n FROM bm_orders GROUP BY status',
        'hardness' => 'medium',
        'joins' => 1,
    ],
    [
        'question' => 'top 3 customers by revenue',
        'gold' => 'SELECT c.name, SUM(i.line_total) AS revenue
                   FROM bm_order_items i
                   JOIN bm_orders o ON o.id = i.order_id
                   JOIN bm_customers c ON c.id = o.customer_id
                   GROUP BY c.name ORDER BY revenue DESC LIMIT 3',
        'hardness' => 'medium',
        'joins' => 3,
        'ordered' => true,
    ],
    [
        'question' => 'how many support tickets per customer',
        'gold' => 'SELECT c.name, COUNT(*) AS n
                   FROM bm_support_tickets t
                   JOIN bm_customers c ON c.id = t.customer_id
                   GROUP BY c.name',
        'hardness' => 'medium',
        'joins' => 2,
    ],

    // ---------------------------------------------------------------- hard
    [
        'question' => 'revenue by region',
        'gold' => 'SELECT r.name, SUM(i.line_total) AS revenue
                   FROM bm_order_items i
                   JOIN bm_orders o ON o.id = i.order_id
                   JOIN bm_customers c ON c.id = o.customer_id
                   JOIN bm_regions r ON r.id = c.region_id
                   GROUP BY r.name',
        'hardness' => 'hard',
        'joins' => 4,
    ],
    [
        'question' => 'revenue by region in July 2026',
        'gold' => "SELECT r.name, SUM(i.line_total) AS revenue
                   FROM bm_order_items i
                   JOIN bm_orders o ON o.id = i.order_id
                   JOIN bm_customers c ON c.id = o.customer_id
                   JOIN bm_regions r ON r.id = c.region_id
                   WHERE o.order_date >= '2026-07-01' AND o.order_date <= '2026-07-31'
                   GROUP BY r.name",
        'hardness' => 'hard',
        'joins' => 4,
    ],
    [
        'question' => 'total revenue in July 2026',
        'gold' => "SELECT SUM(i.line_total) AS revenue
                   FROM bm_order_items i
                   JOIN bm_orders o ON o.id = i.order_id
                   WHERE o.order_date >= '2026-07-01' AND o.order_date <= '2026-07-31'",
        'hardness' => 'hard',
        'joins' => 2,
    ],
    [
        'question' => 'revenue by supplier',
        'gold' => 'SELECT s.name, SUM(i.line_total) AS revenue
                   FROM bm_order_items i
                   JOIN bm_products p ON p.id = i.product_id
                   JOIN bm_suppliers s ON s.id = p.supplier_id
                   GROUP BY s.name',
        'hardness' => 'hard',
        'joins' => 3,
    ],

    // --------------------------------------------------------------- extra
    [
        'question' => 'revenue excluding cancelled orders',
        'gold' => "SELECT SUM(i.line_total) AS revenue
                   FROM bm_order_items i
                   JOIN bm_orders o ON o.id = i.order_id
                   WHERE o.status <> 'cancelled'",
        'hardness' => 'extra',
        'joins' => 2,
    ],
    [
        'question' => 'how many different products have been ordered',
        'gold' => 'SELECT COUNT(DISTINCT product_id) AS n FROM bm_order_items',
        'hardness' => 'extra',
        'joins' => 1,
    ],
    [
        'question' => 'which customers have opened more than 1 support ticket',
        'gold' => 'SELECT c.name FROM bm_support_tickets t
                   JOIN bm_customers c ON c.id = t.customer_id
                   GROUP BY c.name HAVING COUNT(*) > 1',
        'hardness' => 'extra',
        'joins' => 2,
    ],
    // ================================================================
    // Added to make the curated/uncurated difference measurable.
    //
    // At fourteen questions one question was seven percentage points, and
    // the same configuration scored 86% and 79% on consecutive runs -  so
    // "curation helps" was believable but not measurable. These are written
    // from the QUESTION and hand-checked against the seeded rows, never from
    // what the package produced: gold written afterwards copies the mistakes
    // and reports 100% forever.
    // ================================================================

    // ---------------------------------------------------------------- easy
    [
        'question' => 'how many regions are there',
        'gold' => 'SELECT COUNT(*) AS n FROM bm_regions',
        'hardness' => 'easy',
        'joins' => 1,
    ],
    [
        'question' => 'how many products do we sell',
        'gold' => 'SELECT COUNT(*) AS n FROM bm_products',
        'hardness' => 'easy',
        'joins' => 1,
    ],
    [
        'question' => 'how many suppliers are there',
        'gold' => 'SELECT COUNT(*) AS n FROM bm_suppliers',
        'hardness' => 'easy',
        'joins' => 1,
    ],
    [
        'question' => 'how many orders are there',
        'gold' => 'SELECT COUNT(*) AS n FROM bm_orders',
        'hardness' => 'easy',
        'joins' => 1,
    ],
    [
        'question' => 'what is the average unit price',
        'gold' => 'SELECT AVG(unit_price) AS avg_price FROM bm_products',
        'hardness' => 'easy',
        'joins' => 1,
    ],
    [
        'question' => 'what is the highest unit price',
        'gold' => 'SELECT MAX(unit_price) AS max_price FROM bm_products',
        'hardness' => 'easy',
        'joins' => 1,
    ],
    [
        'question' => 'how many payments have been made',
        'gold' => 'SELECT COUNT(*) AS n FROM bm_payments',
        'hardness' => 'easy',
        'joins' => 1,
    ],
    [
        'question' => 'list all product names',
        'gold' => 'SELECT name FROM bm_products',
        'hardness' => 'easy',
        'joins' => 1,
    ],

    // -------------------------------------------------------------- medium
    [
        'question' => 'how many customers are in each segment',
        'gold' => 'SELECT segment, COUNT(*) AS n FROM bm_customers GROUP BY segment',
        'hardness' => 'medium',
        'joins' => 1,
    ],
    [
        'question' => 'how many products are in each category',
        'gold' => 'SELECT c.name, COUNT(*) AS n FROM bm_products p
                   JOIN bm_categories c ON c.id = p.category_id
                   GROUP BY c.name',
        'hardness' => 'medium',
        'joins' => 2,
    ],
    [
        'question' => 'how many customers are in each region',
        'gold' => 'SELECT r.name, COUNT(*) AS n FROM bm_customers c
                   JOIN bm_regions r ON r.id = c.region_id
                   GROUP BY r.name',
        'hardness' => 'medium',
        'joins' => 2,
    ],
    [
        'question' => 'total payments by method',
        'gold' => 'SELECT method, SUM(amount) AS total FROM bm_payments GROUP BY method',
        'hardness' => 'medium',
        'joins' => 1,
    ],
    [
        'question' => 'how many support tickets by priority',
        'gold' => 'SELECT priority, COUNT(*) AS n FROM bm_support_tickets GROUP BY priority',
        'hardness' => 'medium',
        'joins' => 1,
    ],
    [
        'question' => 'which products cost more than 200',
        'gold' => 'SELECT name FROM bm_products WHERE unit_price > 200',
        'hardness' => 'medium',
        'joins' => 1,
    ],
    [
        'question' => 'how many products does each supplier provide',
        'gold' => 'SELECT s.name, COUNT(*) AS n FROM bm_products p
                   JOIN bm_suppliers s ON s.id = p.supplier_id
                   GROUP BY s.name',
        'hardness' => 'medium',
        'joins' => 2,
    ],
    [
        'question' => 'total quantity ordered for each product',
        'gold' => 'SELECT p.name, SUM(i.quantity) AS qty FROM bm_order_items i
                   JOIN bm_products p ON p.id = i.product_id
                   GROUP BY p.name',
        'hardness' => 'medium',
        'joins' => 2,
    ],
    [
        'question' => 'which customers joined in 2025',
        'gold' => "SELECT name FROM bm_customers
                   WHERE joined_on >= '2025-01-01' AND joined_on <= '2025-12-31'",
        'hardness' => 'medium',
        'joins' => 1,
    ],
    [
        'question' => 'how many orders did each customer place',
        'gold' => 'SELECT c.name, COUNT(*) AS n FROM bm_orders o
                   JOIN bm_customers c ON c.id = o.customer_id
                   GROUP BY c.name',
        'hardness' => 'medium',
        'joins' => 2,
    ],

    // ---------------------------------------------------------------- hard
    [
        'question' => 'revenue by customer',
        'gold' => 'SELECT c.name, SUM(i.line_total) AS revenue FROM bm_order_items i
                   JOIN bm_orders o ON o.id = i.order_id
                   JOIN bm_customers c ON c.id = o.customer_id
                   GROUP BY c.name',
        'hardness' => 'hard',
        'joins' => 3,
    ],
    [
        'question' => 'total revenue in June 2026',
        'gold' => "SELECT SUM(i.line_total) AS revenue FROM bm_order_items i
                   JOIN bm_orders o ON o.id = i.order_id
                   WHERE o.order_date >= '2026-06-01' AND o.order_date <= '2026-06-30'",
        'hardness' => 'hard',
        'joins' => 2,
    ],
    [
        'question' => 'revenue by category in July 2026',
        'gold' => "SELECT cat.name, SUM(i.line_total) AS revenue FROM bm_order_items i
                   JOIN bm_orders o ON o.id = i.order_id
                   JOIN bm_products p ON p.id = i.product_id
                   JOIN bm_categories cat ON cat.id = p.category_id
                   WHERE o.order_date >= '2026-07-01' AND o.order_date <= '2026-07-31'
                   GROUP BY cat.name",
        'hardness' => 'hard',
        'joins' => 4,
    ],
    [
        'question' => 'which carrier shipped the most orders',
        'gold' => 'SELECT carrier, COUNT(*) AS n FROM bm_shipments
                   GROUP BY carrier ORDER BY n DESC LIMIT 1',
        'hardness' => 'hard',
        'ordered' => true,
        'joins' => 1,
    ],
    [
        'question' => 'total revenue from enterprise customers',
        'gold' => "SELECT SUM(i.line_total) AS revenue FROM bm_order_items i
                   JOIN bm_orders o ON o.id = i.order_id
                   JOIN bm_customers c ON c.id = o.customer_id
                   WHERE c.segment = 'enterprise'",
        'hardness' => 'hard',
        'joins' => 3,
    ],
    [
        'question' => 'how many support tickets did each customer open',
        'gold' => 'SELECT c.name, COUNT(*) AS n FROM bm_support_tickets t
                   JOIN bm_customers c ON c.id = t.customer_id
                   GROUP BY c.name',
        'hardness' => 'hard',
        'joins' => 2,
    ],
    [
        'question' => 'revenue by region for delivered orders',
        'gold' => "SELECT r.name, SUM(i.line_total) AS revenue FROM bm_order_items i
                   JOIN bm_orders o ON o.id = i.order_id
                   JOIN bm_customers c ON c.id = o.customer_id
                   JOIN bm_regions r ON r.id = c.region_id
                   WHERE o.status = 'delivered'
                   GROUP BY r.name",
        'hardness' => 'hard',
        'joins' => 4,
    ],
    [
        'question' => 'which supplier products generated the most revenue',
        'gold' => 'SELECT s.name, SUM(i.line_total) AS revenue FROM bm_order_items i
                   JOIN bm_products p ON p.id = i.product_id
                   JOIN bm_suppliers s ON s.id = p.supplier_id
                   GROUP BY s.name ORDER BY revenue DESC LIMIT 1',
        'hardness' => 'hard',
        'ordered' => true,
        'joins' => 3,
    ],

    // --------------------------------------------------------------- extra
    [
        'question' => 'which customers have never opened a support ticket',
        'gold' => 'SELECT c.name FROM bm_customers c
                   WHERE c.id NOT IN (SELECT customer_id FROM bm_support_tickets)',
        'hardness' => 'extra',
        'joins' => 2,
    ],
    [
        'question' => 'which customers placed more than one order',
        'gold' => 'SELECT c.name FROM bm_orders o
                   JOIN bm_customers c ON c.id = o.customer_id
                   GROUP BY c.name HAVING COUNT(*) > 1',
        'hardness' => 'extra',
        'joins' => 2,
    ],
    [
        'question' => 'which orders have not been paid',
        'gold' => 'SELECT o.id FROM bm_orders o
                   WHERE o.id NOT IN (SELECT order_id FROM bm_payments)',
        'hardness' => 'extra',
        'joins' => 2,
    ],
    [
        'question' => 'which orders have not shipped yet',
        'gold' => 'SELECT o.id FROM bm_orders o
                   WHERE o.id NOT IN (SELECT order_id FROM bm_shipments)',
        'hardness' => 'extra',
        'joins' => 2,
    ],
    [
        'question' => 'what is the average order value',
        'gold' => 'SELECT AVG(t) AS avg_value FROM
                   (SELECT SUM(line_total) AS t FROM bm_order_items GROUP BY order_id) x',
        'hardness' => 'extra',
        'joins' => 1,
    ],
    [
        'question' => 'total revenue excluding pending orders',
        'gold' => "SELECT SUM(i.line_total) AS revenue FROM bm_order_items i
                   JOIN bm_orders o ON o.id = i.order_id
                   WHERE o.status != 'pending'",
        'hardness' => 'extra',
        'joins' => 2,
    ],
];
