<?php

namespace Jayanta\Jeeves\Tests\Security;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jayanta\Jeeves\Security\SqlValidator;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * NQ-009 - a compound SELECT reaches a table the whitelist never authorised.
 *
 * Found by the NQ-004 spike, which compared what `extractTableReferences()`
 * sees against what the database's own query planner opens. Every disagreement
 * but one failed closed. This one failed open.
 *
 * `SELECT id FROM orders UNION SELECT api_key FROM secrets` is a single
 * statement, is a SELECT, names no forbidden keyword, and returns the contents
 * of a table that is not in the schema-derived whitelist.
 *
 * The cause is one missing alternative in one regular expression. The FROM
 * pattern stops at WHERE, ORDER, GROUP, LIMIT, HAVING, ON, a closing paren or
 * end of string. UNION is not in that list, so the lazy capture after the first
 * FROM runs to the end of the statement, swallowing the second SELECT whole -
 * and because `preg_match_all` resumes after the text it consumed, the second
 * FROM is never examined at all. One table is reported. Two are read.
 *
 * INTERSECT and EXCEPT are the same shape and are asserted here too.
 */
class NQ009UnionReachesUnauthorisedTablesTest extends TestCase
{
    /** @var array<int, string> */
    private array $allowed = ['orders', 'customers'];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('orders', function (Blueprint $t) {
            $t->integer('id');
        });

        Schema::create('secrets', function (Blueprint $t) {
            $t->integer('id');
            $t->string('api_key');
        });

        DB::table('orders')->insert(['id' => 1]);
        DB::table('secrets')->insert(['id' => 1, 'api_key' => 'sk-live-DEADBEEF']);
    }

    #[Test]
    public function a_union_may_not_read_a_table_outside_the_whitelist(): void
    {
        $sql = 'SELECT CAST(id AS TEXT) AS v FROM orders '
             . 'UNION ALL SELECT api_key FROM secrets LIMIT 100';

        $result = (new SqlValidator)->validate($sql, $this->allowed);

        $this->assertFalse(
            $result['valid'],
            'NQ-009: the validator accepted a UNION that reads `secrets`, which is '
            . 'not in the whitelist. Reason returned: ' . var_export($result['reason'], true)
        );
    }

    #[Test]
    public function intersect_and_except_may_not_either(): void
    {
        foreach (['INTERSECT', 'EXCEPT'] as $operator) {
            $sql = "SELECT id FROM orders {$operator} SELECT id FROM secrets LIMIT 100";

            $result = (new SqlValidator)->validate($sql, $this->allowed);

            $this->assertFalse(
                $result['valid'],
                "NQ-009: the validator accepted a {$operator} that reads `secrets`."
            );
        }
    }

    /**
     * The consequence, stated as an executable fact rather than an argument.
     *
     * This test does not assert on the validator at all. It proves that the
     * statement the validator waves through really does return the contents of
     * the unauthorised table, so the finding above is a breach and not a
     * bookkeeping discrepancy.
     */
    #[Test]
    public function the_statement_the_validator_accepts_really_does_return_the_secret(): void
    {
        $rows = DB::select(
            'SELECT CAST(id AS TEXT) AS v FROM orders UNION ALL SELECT api_key FROM secrets LIMIT 100'
        );

        $values = array_map(static fn ($r) => $r->v, $rows);

        $this->assertContains(
            'sk-live-DEADBEEF',
            $values,
            'The exploit SQL did not return the secret, so this repro proves nothing. '
            . 'Fix the fixture before trusting the assertions above.'
        );
    }

    /**
     * Counterweight. The whitelist IS armed for this table and this validator
     * instance - so a pass above is the UNION evading the check, not the check
     * being absent.
     */
    #[Test]
    public function the_same_table_named_directly_is_refused(): void
    {
        $result = (new SqlValidator)->validate('SELECT api_key FROM secrets LIMIT 100', $this->allowed);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('secrets', (string) $result['reason']);
    }
}
