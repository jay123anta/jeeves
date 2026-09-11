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
}
