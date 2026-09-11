<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Jayanta\Jeeves\Support\SqlLiterals;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The literal walk that value aliases and value correction both stand on.
 *
 * Each test is a way a regex over SQL gets this wrong. A rewrite that
 * mistakes a comment for a value, or a doubled quote for the end of one, does
 * not fail loudly - it changes what the query asks for.
 */
class SqlLiteralsTest extends TestCase
{
    #[Test]
    public function every_literal_is_read_with_doubled_quotes_unescaped(): void
    {
        $this->assertSame(
            ["O'Brien", 'Kamrup'],
            SqlLiterals::values("SELECT * FROM t WHERE name = 'O''Brien' AND district = 'Kamrup'")
        );
    }

    #[Test]
    public function a_replacement_is_escaped_on_the_way_back_in(): void
    {
        $sql = SqlLiterals::map(
            "SELECT * FROM t WHERE name = 'O''Brien'",
            fn (string $v) => $v === "O'Brien" ? "O'Neil" : null
        );

        $this->assertSame("SELECT * FROM t WHERE name = 'O''Neil'", $sql);
    }

    #[Test]
    public function a_literal_left_alone_is_returned_byte_for_byte(): void
    {
        $original = "SELECT * FROM t WHERE a = 'x' AND b = 'it''s'";

        $this->assertSame($original, SqlLiterals::map($original, fn () => null));
    }

    /** An apostrophe in a comment must not open a literal that swallows the real one. */
    #[Test]
    public function a_quote_inside_a_line_comment_opens_nothing(): void
    {
        $this->assertSame(
            ['Kamrup'],
            SqlLiterals::values("SELECT 1 -- the user's district\nFROM t WHERE district = 'Kamrup'")
        );
    }

    #[Test]
    public function a_quote_inside_a_block_comment_opens_nothing(): void
    {
        $this->assertSame(['yes'], SqlLiterals::values("/* don't */ SELECT 'yes'"));
    }

    /** A double-quoted identifier is a column name, not a value, even with an apostrophe in it. */
    #[Test]
    public function a_quoted_identifier_is_not_a_value(): void
    {
        $this->assertSame(['v'], SqlLiterals::values("SELECT \"o'col\" FROM t WHERE x = 'v'"));
    }

    /** A statement that cannot be read is never rewritten into one that can. */
    #[Test]
    public function an_unterminated_literal_is_left_exactly_as_it_was(): void
    {
        $broken = "SELECT * FROM t WHERE a = 'unterminated";

        $this->assertSame([], SqlLiterals::values($broken));
        $this->assertSame($broken, SqlLiterals::map($broken, fn () => 'REWRITTEN'));
    }

    #[Test]
    public function wildcards_reach_the_callback_so_it_can_keep_them(): void
    {
        $sql = SqlLiterals::map(
            "SELECT * FROM t WHERE d LIKE '%karimganj%'",
            fn (string $v) => str_replace('karimganj', 'Sribhumi', $v)
        );

        $this->assertSame("SELECT * FROM t WHERE d LIKE '%Sribhumi%'", $sql);
    }

    #[Test]
    public function a_column_named_in_code_is_mentioned(): void
    {
        $this->assertTrue(SqlLiterals::mentions("SELECT * FROM t WHERE district = 'x'", 'district'));
        $this->assertTrue(SqlLiterals::mentions("SELECT * FROM t WHERE LOWER(District) = 'x'", 'district'));
        $this->assertTrue(SqlLiterals::mentions('SELECT * FROM t WHERE "district" = 1', 'district'));
        $this->assertTrue(SqlLiterals::mentions("SELECT * FROM t WHERE t.district = 'x'", 'district'));
    }

    #[Test]
    public function a_column_named_only_in_a_value_or_comment_is_not_mentioned(): void
    {
        $this->assertFalse(SqlLiterals::mentions("SELECT * FROM t WHERE name = 'district'", 'district'));
        $this->assertFalse(SqlLiterals::mentions("SELECT * FROM t -- district\nWHERE a = 1", 'district'));
        $this->assertFalse(SqlLiterals::mentions("SELECT * FROM t WHERE subdistrict = 'x'", 'district'));
        $this->assertFalse(SqlLiterals::mentions("SELECT * FROM t WHERE district_code = 'x'", 'district'));
    }

    /**
     * Each literal reaches the callback with the column it is COMPARED with -
     * the relationship the first version of value aliases never read, which
     * let a rewrite meant for one column reach a value compared to another.
     */
    #[Test]
    public function each_literal_is_bound_to_the_column_it_is_compared_with(): void
    {
        $seen = [];

        SqlLiterals::mapCompared(
            "SELECT * FROM t WHERE a = 'x' AND LOWER(b) = LOWER('y') AND c IN ('p', 'q') "
            . "AND d LIKE '%z%' AND u.e <> 'w' AND \"f\" NOT ILIKE 'v'",
            function (string $value, ?string $column) use (&$seen) {
                $seen[] = [$value, $column];

                return null;
            }
        );

        $this->assertSame(
            [['x', 'a'], ['y', 'b'], ['p', 'c'], ['q', 'c'], ['%z%', 'd'], ['w', 'u.e'], ['v', 'f']],
            $seen
        );
    }

    /**
     * A value outside a comparison this can read has no column, and every
     * caller leaves a null alone. Not reading is safe; misreading is not.
     */
    #[Test]
    public function a_literal_outside_a_readable_comparison_has_no_column(): void
    {
        $seen = [];

        SqlLiterals::mapCompared(
            "SELECT 'label' AS x, COALESCE(a, 'n') FROM t WHERE b BETWEEN 'c' AND 'd' AND e LIKE ? ESCAPE '!'",
            function (string $value, ?string $column) use (&$seen) {
                $seen[] = $column;

                return null;
            }
        );

        $this->assertSame([null, null, null, null, null], $seen);
    }

    /** Intent mode's positional bindings, in the exact shape SqlBuilder writes them. */
    #[Test]
    public function each_placeholder_is_bound_to_the_column_it_is_compared_with(): void
    {
        $this->assertSame(
            ['district', 'district', 'created_at', 'district'],
            SqlLiterals::placeholderColumns(
                "SELECT * FROM t WHERE (LOWER(district) = LOWER(?) OR LOWER(district) LIKE LOWER(?) ESCAPE '!') "
                . 'AND created_at >= ? ORDER BY CASE WHEN LOWER(district) = LOWER(?) THEN 0 ELSE 1 END LIMIT 1'
            )
        );

        $this->assertSame(['s', 's'], SqlLiterals::placeholderColumns('SELECT * FROM t WHERE s IN (?, ?)'));
    }

    #[Test]
    public function table_aliases_come_from_from_and_join(): void
    {
        $map = SqlLiterals::tableAliases('SELECT * FROM public.va_units u JOIN va_staff AS s ON s.id = u.id WHERE u.x = 1');

        $this->assertSame('public.va_units', $map['u'] ?? null);
        $this->assertSame('public.va_units', $map['va_units'] ?? null);
        $this->assertSame('va_staff', $map['s'] ?? null);
    }

    /** `FROM t WHERE` must not make WHERE an alias of t. */
    #[Test]
    public function a_keyword_after_a_table_is_not_taken_for_an_alias(): void
    {
        $this->assertSame(
            ['va_units' => 'va_units'],
            SqlLiterals::tableAliases('SELECT * FROM va_units WHERE x = 1 GROUP BY y')
        );
    }
}
