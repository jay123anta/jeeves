<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Jayanta\Jeeves\Security\SqlValidator;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * FROM is not always a table reference.
 *
 * `EXTRACT(YEAR FROM order_date)` is ordinary SQL and the obvious way to filter
 * by year -  and the table extractor read `order_date` as a table, so the query
 * was refused with "Unauthorized table: order_date". A column name, reported as
 * an unauthorised table, on a question as plain as "total revenue this year".
 *
 * Found by running the planner against a live model for the first time: both
 * steps of "revenue this year versus last year" failed, and the multi-step
 * answer said only that none of its steps could be answered.
 *
 * The failure was safe -  valid SQL refused, never invalid SQL admitted -  but it
 * made a common question impossible and the message pointed nowhere.
 */
class SqlFunctionKeywordTest extends TestCase
{
    private function validate(string $sql): array
    {
        return (new SqlValidator)->validate($sql, ['demo_orders', 'public.orders']);
    }

    #[Test]
    public function extract_year_from_a_column_is_not_a_table_reference()
    {
        $result = $this->validate(
            'SELECT SUM(revenue) AS revenue FROM demo_orders WHERE EXTRACT(YEAR FROM order_date) = 2026'
        );

        $this->assertTrue($result['valid'], $result['reason'] ?? '');
    }

    #[Test]
    public function extract_in_the_select_list_is_not_a_table_reference()
    {
        // Grouped, so a LIMIT is genuinely required -  included here so the
        // case tests the FROM keyword and nothing else.
        $result = $this->validate(
            'SELECT EXTRACT(MONTH FROM order_date) AS m, SUM(revenue) AS r FROM demo_orders GROUP BY m LIMIT 12'
        );

        $this->assertTrue($result['valid'], $result['reason'] ?? '');
    }

    #[Test]
    public function trim_and_substring_use_from_the_same_way()
    {
        foreach ([
            "SELECT TRIM(BOTH ' ' FROM customer_name) AS n FROM demo_orders LIMIT 10",
            'SELECT SUBSTRING(customer_name FROM 1 FOR 3) AS s FROM demo_orders LIMIT 10',
        ] as $sql) {
            $result = $this->validate($sql);
            $this->assertTrue($result['valid'], $sql . ' → ' . ($result['reason'] ?? ''));
        }
    }

    /**
     * The guard on the fix. Neutralising FROM inside function calls must not
     * blind the validator to a real table it has no business reading.
     */
    #[Test]
    public function a_genuinely_unauthorised_table_is_still_refused()
    {
        foreach ([
            'SELECT * FROM secret_salaries',
            'SELECT SUM(revenue) FROM demo_orders JOIN secret_salaries ON 1=1',
            'SELECT EXTRACT(YEAR FROM order_date) FROM secret_salaries',
            'SELECT * FROM demo_orders, secret_salaries',
        ] as $sql) {
            $result = $this->validate($sql);
            $this->assertFalse($result['valid'], "should have been refused: {$sql}");
            $this->assertStringContainsString('secret_salaries', $result['reason']);
        }
    }

    #[Test]
    public function ordinary_date_filtering_still_validates()
    {
        $result = $this->validate(
            'SELECT SUM(revenue) AS revenue FROM demo_orders WHERE order_date >= ? AND order_date <= ?'
        );

        $this->assertTrue($result['valid'], $result['reason'] ?? '');
    }
}
