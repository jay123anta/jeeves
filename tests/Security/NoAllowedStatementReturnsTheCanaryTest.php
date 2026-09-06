<?php

namespace Jayanta\Jeeves\Tests\Security;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Jayanta\Jeeves\Security\SqlValidator;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The differential harness: the database is the oracle, not a second opinion.
 *
 * `SqlValidator` decides which tables a statement touches by reading it with
 * regular expressions. The database decides by planning it. When those two
 * disagree AND the disagreement favours the statement, an unauthorised table is
 * read and nothing reports a problem. That is NQ-009, and it was found by
 * running exactly this comparison by hand.
 *
 * The invariant, stated rather than the mechanism:
 *
 *     If the validator ALLOWS a statement, executing it must not return the
 *     contents of a table outside the whitelist.
 *
 * A canary row in an unauthorised table makes that checkable without parsing
 * anything. It is alias-proof - the planner naming `o` instead of `orders`
 * cannot confuse it.
 *
 * **Why this runs on three engines.** The validator is pure string work, so on
 * its own it behaves identically everywhere and a single-engine suite looks
 * like enough. It is not: whether a given string is a *bypass* is decided by
 * the engine, not by the validator. `[secrets]` is a quoted identifier on
 * SQLite and a syntax error on PostgreSQL. `` `secrets` `` is an identifier on
 * MySQL and a syntax error on both others. `"secrets"` is an identifier on
 * SQLite and PostgreSQL and a string literal on MySQL. `#` starts a comment on
 * MySQL alone. A vector that fails closed on the engine the suite happens to
 * use can be wide open on the engine the adopter happens to run.
 *
 * A statement the ENGINE rejects is a pass. This asks whether data escaped, not
 * whether the SQL was well-formed.
 */
class NoAllowedStatementReturnsTheCanaryTest extends TestCase
{
    private const CANARY = 'sk-live-CANARY-8f21';

    private const MYSQL = 'nq_canary_mysql';

    private const PGSQL = 'nq_canary_pgsql';

    /** @var array<int, string> */
    private array $allowed = ['orders', 'customers'];

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('database.connections.' . self::MYSQL, [
            'driver' => 'mysql',
            'host' => env('NQ_MYSQL_HOST', '127.0.0.1'),
            'port' => env('NQ_MYSQL_PORT', '3306'),
            'database' => env('NQ_MYSQL_DATABASE', 'nq_mysql_test'),
            'username' => env('NQ_MYSQL_USERNAME', 'root'),
            // Empty, matching MysqlIntrospectorTest and the XAMPP default this
            // package is most often installed on. CI sets NQ_MYSQL_PASSWORD
            // explicitly. Defaulting it to 'root' here meant the corpus skipped
            // on a machine with a perfectly good MySQL running on it, and
            // reported that as "no server reachable".
            'password' => env('NQ_MYSQL_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]);

        $app['config']->set('database.connections.' . self::PGSQL, [
            'driver' => 'pgsql',
            'host' => env('NQ_PGSQL_HOST', '127.0.0.1'),
            'port' => env('NQ_PGSQL_PORT', '5432'),
            'database' => env('NQ_PGSQL_DATABASE', 'postgres'),
            'username' => env('NQ_PGSQL_USERNAME', 'postgres'),
            'password' => env('NQ_PGSQL_PASSWORD', 'postgres'),
            'charset' => 'utf8',
            'search_path' => 'public',
            'sslmode' => 'prefer',
            'prefix' => '',
        ]);
    }

    #[Test]
    public function no_allowed_statement_returns_the_canary_on_sqlite(): void
    {
        $this->assertCorpusLeaksNothing(DB::connection());
    }

    #[Test]
    public function no_allowed_statement_returns_the_canary_on_mysql(): void
    {
        $this->assertCorpusLeaksNothing($this->reachable(self::MYSQL, 'MySQL/MariaDB'));
    }

    #[Test]
    public function no_allowed_statement_returns_the_canary_on_postgres(): void
    {
        $this->assertCorpusLeaksNothing($this->reachable(self::PGSQL, 'PostgreSQL'));
    }

    /**
     * Skip rather than fail when no server is there, so local runs stay
     * service-free. CI provides all three, and the workflow separately asserts
     * that these did not skip - a skipped security test is not a passing one.
     */
    private function reachable(string $connection, string $label): ConnectionInterface
    {
        try {
            DB::connection($connection)->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped("No {$label} server reachable: " . $e->getMessage());
        }

        return DB::connection($connection);
    }

    private function assertCorpusLeaksNothing(ConnectionInterface $conn): void
    {
        $driver = $conn->getDriverName();

        $this->createFixtures($conn);

        try {
            $validator = new SqlValidator;
            $leaked = [];
            $executed = 0;

            foreach ($this->vectors($driver) as $sql) {
                if (!$validator->validate($sql, $this->allowed)['valid']) {
                    continue;   // Refused. Nothing to execute.
                }

                try {
                    $rows = $conn->select($sql);
                } catch (\Throwable) {
                    continue;   // The engine rejected it. Not a leak.
                }

                $executed++;

                foreach ($rows as $row) {
                    foreach ((array) $row as $value) {
                        if (is_string($value) && str_contains($value, self::CANARY)) {
                            $leaked[] = $sql;
                            break 2;
                        }
                    }
                }
            }

            // Non-vacuity, asserted rather than assumed. On SQLite every attack
            // vector is refused before execution, so without the control
            // statement the inspection loop would never run once and "nothing
            // leaked" would be true of a validator that did nothing at all.
            $this->assertGreaterThan(
                0,
                $executed,
                "No vector reached execution on {$driver}, so the leak check inspected "
                . 'nothing and would pass however broken the validator was. The control '
                . 'statement should always validate clean and run.'
            );

            // And the canary must be readable at all here, or no vector could
            // have returned it whatever the validator did.
            $this->assertSame(
                self::CANARY,
                $conn->select('SELECT api_key AS v FROM secrets')[0]->v,
                "The fixture is broken on {$driver}: the canary is not readable even by a "
                . 'direct query, so this harness proves nothing.'
            );

            $this->assertSame(
                [],
                $leaked,
                'These statements were validated clean and returned the contents of a '
                . "table outside the whitelist, on {$driver}:\n  "
                . implode("\n  ", $leaked)
            );
        } finally {
            $this->dropFixtures($conn);
        }
    }

    private function createFixtures(ConnectionInterface $conn): void
    {
        $this->dropFixtures($conn);

        $conn->statement('create table orders (id integer, note varchar(64))');
        $conn->statement('create table customers (id integer, name varchar(64))');
        $conn->statement('create table secrets (id integer, api_key varchar(64))');

        $conn->insert("insert into orders (id, note) values (1, 'ok')");
        $conn->insert("insert into customers (id, name) values (1, 'ok')");
        $conn->insert('insert into secrets (id, api_key) values (1, ?)', [self::CANARY]);
    }

    private function dropFixtures(ConnectionInterface $conn): void
    {
        foreach (['orders', 'customers', 'secrets'] as $table) {
            try {
                $conn->statement("drop table if exists {$table}");
            } catch (\Throwable) {
                // Nothing to drop.
            }
        }
    }

    /**
     * Every way of naming a table that the regex might read differently from
     * the planner.
     *
     * @return array<int, string>
     */
    private function vectors(string $driver): array
    {
        $vectors = [
            // A CONTROL: allowed, executes, returns rows, carries no canary.
            // It is what proves the validate-execute-inspect path is live.
            'SELECT note AS v FROM orders UNION SELECT name FROM customers LIMIT 100',

            // NQ-009 and its family.
            'SELECT note AS v FROM orders UNION ALL SELECT api_key FROM secrets LIMIT 100',
            'SELECT note AS v FROM orders UNION SELECT api_key FROM secrets LIMIT 100',
            'SELECT note AS v FROM orders UNION ALL SELECT api_key FROM secrets UNION ALL SELECT api_key FROM secrets LIMIT 100',
            'SELECT note AS v FROM orders EXCEPT SELECT api_key FROM secrets LIMIT 100',
            'SELECT note AS v FROM orders INTERSECT SELECT api_key FROM secrets LIMIT 100',

            // The FROM clause read as something other than a table list.
            'SELECT * FROM (SELECT api_key AS v FROM secrets) AS t LIMIT 100',
            'SELECT (SELECT api_key FROM secrets LIMIT 1) AS v FROM orders LIMIT 100',

            // Comment where whitespace is expected.
            'SELECT api_key AS v FROM/**/secrets LIMIT 100',
            'SELECT s.api_key AS v FROM orders o JOIN/**/secrets s ON s.id = o.id LIMIT 100',

            // An alias colliding with a real table name: array_diff() removes
            // every entry equal to the alias, the real table included.
            'SELECT s.api_key AS v FROM secrets s, (SELECT 1 AS c) AS secrets LIMIT 100',

            // A CTE whose name shadows a real table.
            'WITH secrets AS (SELECT api_key AS v FROM secrets) SELECT v FROM secrets LIMIT 100',

            // Standard-SQL quoting: an identifier on SQLite and PostgreSQL, a
            // string literal on MySQL unless ANSI_QUOTES is set.
            'SELECT api_key AS v FROM "secrets" LIMIT 100',
        ];

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $vectors[] = 'SELECT api_key AS v FROM `secrets` LIMIT 100';
            $vectors[] = "SELECT api_key AS v FROM secrets #comment\nLIMIT 100";
            $vectors[] = "SELECT api_key AS v FROM secrets -- comment\nLIMIT 100";
        }

        if ($driver === 'sqlite') {
            $vectors[] = 'SELECT api_key AS v FROM [secrets] LIMIT 100';
        }

        if ($driver === 'pgsql') {
            // Dollar quoting and a schema-qualified reference are Postgres-only
            // shapes, and the schema qualifier is the one that matters: the
            // whitelist may hold a bare name.
            $vectors[] = 'SELECT api_key AS v FROM public.secrets LIMIT 100';
            $vectors[] = 'SELECT api_key AS v FROM public."secrets" LIMIT 100';
        }

        return $vectors;
    }
}
