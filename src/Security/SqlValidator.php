<?php

namespace Jayanta\Jeeves\Security;

use Illuminate\Support\Facades\Log;
use Jayanta\Jeeves\Contracts\SqlValidatorInterface;

/**
 * SQL Validator - Defense-in-Depth Security Layer
 *
 * Validates AI-generated SQL before execution to ensure:
 * 1. Only SELECT queries are allowed
 * 2. Only whitelisted tables/views can be queried
 * 3. No SQL injection patterns
 * 4. No dangerous keywords (INSERT, DROP, etc.)
 * 5. LIMIT enforcement
 *
 * This is the last line of defense - even if the AI generates
 * dangerous SQL, it will be caught here before execution.
 */
class SqlValidator implements SqlValidatorInterface
{
    /**
     * Words that are followed by "(" and are not function calls.
     *
     * Excluded from the allowlist check rather than added to it, because they
     * are grammar. `WHERE NOT (a AND b)` is three of them in one clause.
     */
    private const NOT_A_FUNCTION = [
        'SELECT', 'FROM', 'WHERE', 'AND', 'OR', 'NOT', 'IN', 'ON', 'AS', 'BY',
        'WITH', 'ORDER', 'GROUP', 'HAVING', 'LIMIT', 'OFFSET', 'VALUES',
        'CASE', 'WHEN', 'THEN', 'ELSE', 'END', 'OVER', 'PARTITION', 'FILTER',
        'JOIN', 'LEFT', 'RIGHT', 'INNER', 'OUTER', 'FULL', 'CROSS', 'USING',
        'UNION', 'INTERSECT', 'EXCEPT', 'ALL', 'ANY', 'SOME', 'EXISTS',
        'DISTINCT', 'BETWEEN', 'LIKE', 'ILIKE', 'IS', 'NULL', 'ASC', 'DESC',
        'RECURSIVE', 'INTERVAL', 'RETURNING', 'FETCH', 'ROW', 'ROWS',
    ];

    /**
     * SQL functions a question may legitimately need.
     *
     * Chosen from measurement, then widened. Across 178 real SQL strings - the
     * gold answers in both benchmark sets, every example query and every
     * computed-metric expression in the shipped and test schemas - only COUNT,
     * SUM, AVG, MAX, MIN, ROUND and DATE_TRUNC appear. The rest of this list
     * exists so a curated schema on any of the four supported databases does
     * not trip over the check.
     *
     * What is deliberately ABSENT is the point: pg_sleep, dblink, lo_import,
     * xp_cmdshell, LOAD_FILE, REPEAT and everything nobody has thought of yet
     * are all refused without being named.
     */
    private const DEFAULT_ALLOWED_FUNCTIONS = [
        // aggregates
        'COUNT', 'SUM', 'AVG', 'MIN', 'MAX', 'STRING_AGG', 'GROUP_CONCAT',
        // numbers
        'ROUND', 'ABS', 'CEIL', 'CEILING', 'FLOOR', 'MOD', 'POWER', 'SQRT',
        'GREATEST', 'LEAST', 'TRUNC',
        // nulls and conditionals
        'COALESCE', 'NULLIF', 'IFNULL', 'NVL', 'IF', 'IIF',
        // casting
        'CAST', 'CONVERT', 'TO_CHAR', 'TO_NUMBER', 'TO_DATE',
        // strings
        'LOWER', 'UPPER', 'TRIM', 'LTRIM', 'RTRIM', 'LENGTH', 'CHAR_LENGTH',
        'SUBSTR', 'SUBSTRING', 'CONCAT', 'CONCAT_WS', 'REPLACE', 'SPLIT_PART',
        // dates
        'DATE', 'DATE_TRUNC', 'DATE_PART', 'DATEPART', 'EXTRACT', 'DATEDIFF',
        'DATE_ADD', 'DATE_SUB', 'DATEADD', 'YEAR', 'MONTH', 'DAY', 'WEEK',
        'QUARTER', 'HOUR', 'MINUTE', 'SECOND', 'NOW', 'CURRENT_DATE',
        'CURRENT_TIMESTAMP', 'CURDATE', 'GETDATE', 'STRFTIME', 'JULIANDAY',
        'AGE', 'MAKE_DATE',
    ];

    /**
     * Validate a SQL query against security rules.
     *
     * @param  string  $sql  The SQL to validate
     * @param  array  $allowedTables  List of allowed table/view names
     * @param  array  $options  {
     *                          max_limit?: int,
     *                          forbidden_keywords?: string[],
     *                          allow_union_all?: bool,
     *                          allow_cte?: bool,
     *                          require_limit?: bool
     *                          }
     * @return array {valid: bool, reason: string|null}
     */
    public function validate(string $sql, array $allowedTables, array $options = []): array
    {
        $sql = trim($sql);

        // Remove trailing semicolons for validation
        $sqlClean = rtrim($sql, '; ');

        // 1. Must start with SELECT (or WITH for CTEs if allowed)
        $allowCte = $options['allow_cte'] ?? config('jeeves.sql.allow_cte', true);

        if ($allowCte) {
            if (!preg_match('/^\s*(SELECT|WITH)\s/i', $sqlClean)) {
                return $this->fail('Query must start with SELECT or WITH');
            }
        } else {
            if (!preg_match('/^\s*SELECT\s/i', $sqlClean)) {
                return $this->fail('Query must start with SELECT');
            }
        }

        // 2. Forbidden keywords check
        $forbiddenKeywords = $options['forbidden_keywords']
            ?? config('jeeves.sql.forbidden_keywords', [
                'INSERT', 'UPDATE', 'DELETE', 'DROP', 'CREATE', 'ALTER', 'TRUNCATE',
                'EXEC', 'EXECUTE', 'GRANT', 'REVOKE', 'INTO',
                // 'pg_' was here and never matched anything: keyword checks use
                // \b on both sides, and \b after an underscore needs a non-word
                // character next. PostgreSQL system FUNCTIONS are caught by an
                // injection pattern below; the two catalogues are named here in
                // full, where \b does work.
                'COPY', 'information_schema', 'pg_catalog',
                'DO',  // PL/pgSQL anonymous blocks
            ]);

        // Block PL/pgSQL dollar-quoting (DO $$ ... $$)
        if (str_contains($sqlClean, '$$')) {
            return $this->fail('Dollar-quoting ($$) not allowed');
        }

        // If UNION ALL is not allowed, add UNION to forbidden list
        $allowUnionAll = $options['allow_union_all'] ?? config('jeeves.sql.allow_union_all', true);
        if (!$allowUnionAll) {
            $forbiddenKeywords[] = 'UNION';
        }

        foreach ($forbiddenKeywords as $keyword) {
            if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/i', $sqlClean)) {
                Log::warning('[Jeeves:SqlValidator] Forbidden keyword detected', [
                    'keyword' => $keyword,
                    'sql' => substr($sqlClean, 0, 200),
                ]);

                return $this->fail("Forbidden keyword: {$keyword}");
            }
        }

        // 3. SQL injection pattern detection
        $injectionPatterns = [
            '/;\s*--/' => 'Statement terminator with comment',
            '/;\s*\/\*/' => 'Statement terminator with block comment',
            '/\bOR\s+1\s*=\s*1/i' => 'OR 1=1 tautology',
            '/\bAND\s+1\s*=\s*1/i' => 'AND 1=1 tautology',
            '/\'\s*OR\s*\'/i' => 'String-based OR injection',
            '/\'\s*;\s*/' => 'String termination with semicolon',
            '/--\s*$/' => 'Trailing SQL comment',
            '/;\s*SELECT\b/i' => 'Stacked query attempt',
            '/\bSLEEP\s*\(/i' => 'Time-based injection',
            '/\bBENCHMARK\s*\(/i' => 'Time-based injection',
            '/\bWAITFOR\s+DELAY/i' => 'Time-based injection',
            '/\bLOAD_FILE\s*\(/i' => 'File read attempt',
            '/\bINTO\s+(OUT|DUMP)FILE/i' => 'File write attempt',

            // PostgreSQL's system functions, as a class.
            //
            // MySQL's SLEEP( and SQL Server's WAITFOR were both here and
            // PostgreSQL's pg_sleep( was not -  on a package whose first-listed
            // database is PostgreSQL. `pg_` was in the forbidden KEYWORD list
            // instead, where it could never fire: keywords are matched with
            // \b on both sides, and \b after an underscore needs a non-word
            // character next, which "pg_sleep" does not have.
            //
            // Matching the CALL rather than the name is what keeps this from
            // touching an ordinary column that happens to start with pg_. The
            // catalogues themselves (pg_catalog, information_schema) are
            // handled by the table whitelist and by their own keyword entries,
            // both of which do work.
            '/\bpg_[a-z_]+\s*\(/i' => 'PostgreSQL system function',
            '/\blo_(import|export)\s*\(/i' => 'File read/write attempt',

            // Session state. `SET` on its own never gets here -  a statement
            // that does not start with SELECT or WITH is already refused -  but
            // set_config() is a FUNCTION and rides inside an ordinary SELECT.
            // It can change search_path, which decides which table an
            // unqualified name resolves to, so it is a way to answer a
            // question from a table the whitelist never approved.
            '/\bset_config\s*\(/i' => 'Session state change',

            // Locking reads. FOR UPDATE was refused only by accident, because
            // "UPDATE" is a forbidden keyword; FOR SHARE was not refused at
            // all. Both take locks that block writers for as long as the
            // transaction lives, and neither has any business in a question.
            '/\bFOR\s+(NO\s+KEY\s+)?UPDATE\b/i' => 'Locking read',
            '/\bFOR\s+(KEY\s+)?SHARE\b/i' => 'Locking read',
        ];

        // Match against the statement WITH COMMENTS REMOVED as well as the
        // original.
        //
        // A comment is whitespace to the tokeniser, so `pg_sleep/**/(10)` is a
        // valid call - and `\s*` in a pattern does not match `/**/`. Every
        // function-shaped rule above was one comment away from useless, the
        // new ones and the ones that were already here.
        //
        // Both versions are checked because the stripped copy cannot see the
        // rules that are ABOUT comments (`;--`, a trailing `--`), and the
        // original cannot see through them. Stripping can only ever join
        // tokens together, so it creates false positives rather than false
        // negatives -  the safe direction for a refusal.
        $sqlNoComments = preg_replace(['#/\*.*?\*/#s', '/--[^\r\n]*/'], ' ', $sqlClean) ?? $sqlClean;

        // 3a. Function ALLOWLIST.
        //
        // Everything above this line is a denylist, and a denylist over an
        // open vocabulary loses. Three rounds of attack sweeps found three
        // holes, and the third round broke the second round's fix -  each time
        // by naming something nobody had thought of. pg_sleep, dblink,
        // lo_import, xp_cmdshell and REPEAT('x', 1e9) were all legal here
        // until someone wrote them down.
        //
        // Deciding what is PERMITTED inverts the failure mode. An unknown
        // function is refused instead of allowed, so the cost of missing one
        // is a refusal an adopter can see and fix in a line of config, rather
        // than a breach nobody notices. That is the same direction the rest of
        // this package chooses.
        //
        // Measured before choosing the default: across 178 real SQL strings -
        // every gold answer in both benchmark sets, every example query and
        // every computed-metric expression in the shipped and test schemas -
        // the functions actually used are COUNT, SUM, AVG, MAX, MIN, ROUND and
        // DATE_TRUNC. The list below is far wider than that on purpose, so a
        // curated schema does not trip over it.
        $allowedFunctions = $options['allowed_functions']
            ?? config('jeeves.sql.allowed_functions', self::DEFAULT_ALLOWED_FUNCTIONS);

        if ($allowedFunctions !== []) {
            $allowed = array_map('strtoupper', $allowedFunctions);

            // String literals are blanked first. Without this, a DATA VALUE
            // containing a parenthesis reads as a function call: the customer
            // "Acme (UK) Ltd" makes `Acme(` and the query is refused. Company
            // names shaped that way are ordinary, so the allowlist would have
            // refused real questions on real schemas -  found by running it
            // against every gold answer in both benchmark sets before shipping
            // it, where a Spider value tripped exactly this.
            $sqlNoLiterals = preg_replace("/'(?:[^']|'')*'/", "''", $sqlNoComments) ?? $sqlNoComments;

            preg_match_all('/\b([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $sqlNoLiterals, $calls);

            foreach (array_unique($calls[1]) as $called) {
                $name = strtoupper($called);

                if (in_array($name, self::NOT_A_FUNCTION, true) || in_array($name, $allowed, true)) {
                    continue;
                }

                Log::warning('[Jeeves:SqlValidator] Function not on the allowlist', [
                    'function' => $name,
                    'sql' => substr($sqlClean, 0, 200),
                ]);

                // Named, because the fix is one line of config and a refusal
                // that does not say what to add is a dead end.
                return $this->fail(
                    "Function not allowed: {$called}. Add it to sql.allowed_functions if your "
                    . 'schema needs it.'
                );
            }
        }

        foreach ($injectionPatterns as $pattern => $description) {
            if (preg_match($pattern, $sqlClean) || preg_match($pattern, $sqlNoComments)) {
                Log::warning('[Jeeves:SqlValidator] Injection pattern detected', [
                    'pattern' => $description,
                    'sql' => substr($sqlClean, 0, 200),
                ]);

                return $this->fail("Potential SQL injection: {$description}");
            }
        }

        // 4. Verify ONLY allowed tables/views are referenced
        // Extract all table references from FROM and JOIN clauses
        if (!empty($allowedTables)) {
            $referencedTables = $this->extractTableReferences($sqlClean);

            if (empty($referencedTables)) {
                return $this->fail('Could not identify any table references in query');
            }

            // Check that EVERY referenced table is in the whitelist.
            //
            // Matching rules:
            //   - exact match ('public.orders' vs 'public.orders', 'orders' vs 'orders')
            //   - a BARE reference may match a schema-qualified whitelist entry
            //     ('orders' matches allowed 'public.orders' -  the DB resolves it
            //     via search_path to the same table)
            //   - a SCHEMA-QUALIFIED reference must match exactly. It must NOT
            //     match a bare whitelist entry: if 'users' is whitelisted,
            //     'other_schema.users' would otherwise slip through and expose a
            //     same-named table in a different schema (cross-schema bypass).
            $allowedLower = array_map('strtolower', $allowedTables);
            foreach ($referencedTables as $table) {
                $tableLower = strtolower($table);
                $found = false;
                foreach ($allowedLower as $allowed) {
                    if ($tableLower === $allowed
                        || (!str_contains($tableLower, '.') && str_ends_with($allowed, '.' . $tableLower))) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    Log::warning('[Jeeves:SqlValidator] Unauthorized table reference', [
                        'table' => $table,
                        'sql' => substr($sqlClean, 0, 200),
                    ]);

                    return $this->fail("Unauthorized table: {$table}");
                }
            }
        }

        // 5. LIMIT enforcement
        $requireLimit = $options['require_limit'] ?? true;
        $maxLimit = $options['max_limit'] ?? config('jeeves.sql.max_limit');

        // A bare aggregate returns exactly one row. Requiring a LIMIT on
        // "SELECT SUM(revenue) FROM orders WHERE …" refuses a correct query
        // for a danger that cannot arise, and it refused every total the
        // moment a schema set max_limit.
        if ($requireLimit && $this->returnsOneRow($sqlClean)) {
            $requireLimit = false;
        }

        if ($requireLimit && !preg_match('/\bLIMIT\s+\d+/i', $sqlClean)) {
            return $this->fail('Query must include a LIMIT clause');
        }

        if ($maxLimit !== null && preg_match('/\bLIMIT\s+(\d+)/i', $sqlClean, $matches)) {
            if (intval($matches[1]) > $maxLimit) {
                return $this->fail("LIMIT cannot exceed {$maxLimit}");
            }
        }

        // 6. Check for multiple statements (semicolons not at end)
        $withoutStrings = preg_replace("/'[^']*'/", '', $sqlClean);
        if (substr_count($withoutStrings, ';') > 0) {
            return $this->fail('Multiple SQL statements not allowed');
        }

        return ['valid' => true, 'reason' => null];
    }

    /**
     * Extract table/view names from FROM and JOIN clauses.
     *
     * Handles: FROM schema.table, FROM table alias, JOIN schema.table ON ...,
     * and tables inside CTE definitions (WITH x AS (SELECT ... FROM table))
     */
    protected function extractTableReferences(string $sql): array
    {
        $tables = [];
        // (table extraction continues below)

        // FROM is not always a table reference. EXTRACT(YEAR FROM order_date),
        // TRIM(BOTH ' ' FROM name) and SUBSTRING(name FROM 1 FOR 3) all use the
        // keyword inside a function call, and reading the argument as a table
        // refused ordinary SQL with "Unauthorized table: order_date" -  a column
        // name, reported as an unauthorised table, on a question as plain as
        // "total revenue this year".
        //
        // Neutralised on a copy used only for finding tables, so the real SQL
        // is untouched and a genuine table inside such a query is still seen.
        $sql = $this->neutraliseFunctionKeywords($sql);

        // NQ-009. Scan each branch of a compound SELECT separately.
        //
        // The FROM pattern below stops at a clause keyword, a closing paren or
        // end of string. UNION was not one of those, so in
        //
        //     SELECT id FROM orders UNION SELECT api_key FROM secrets LIMIT 100
        //
        // the lazy capture after the first FROM ran to the LIMIT and swallowed
        // the second SELECT whole - and because preg_match_all resumes AFTER
        // the text a match consumed, the second FROM was never examined. One
        // table was reported; two were read. `secrets` was returned to the
        // caller with the validator reporting no problem.
        //
        // Adding UNION to the terminator list would fix that statement. Split-
        // ting on the set operators fixes the shape: every branch is scanned in
        // full, however many there are, and INTERSECT, EXCEPT and MINUS come
        // along for free.
        //
        // CTE and alias removal stays whole-statement, below. A CTE declared in
        // the first branch and used in the third is one name across the
        // statement, and diffing per branch would refuse valid SQL.
        foreach ($this->splitSetOperations($sql) as $branch) {
            // Match FROM clause: FROM schema.table, FROM table1, table2
            // Handles comma-separated tables and tables inside CTEs
            if (preg_match_all('/\bFROM\s+([a-zA-Z_][a-zA-Z0-9_.,\s]*?)(?:\s+WHERE\b|\s+ORDER\b|\s+GROUP\b|\s+LIMIT\b|\s+HAVING\b|\s+ON\b|\s*\)|\s*$)/i', $branch, $matches)) {
                foreach ($matches[1] as $fromClause) {
                    // Split comma-separated tables: "public.orders, secret_data alias"
                    $parts = preg_split('/\s*,\s*/', trim($fromClause));
                    foreach ($parts as $part) {
                        // Extract table name (first identifier, ignore alias)
                        if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_.]*)/', trim($part), $m)) {
                            $tables[] = $m[1];
                        }
                    }
                }
            }

            // Match JOIN clause: [LEFT|RIGHT|INNER|CROSS] JOIN schema.table [alias]
            if (preg_match_all('/\bJOIN\s+([a-zA-Z_][a-zA-Z0-9_.]*)/i', $branch, $matches)) {
                $tables = array_merge($tables, $matches[1]);
            }
        }

        // Remove CTE names (WITH x AS ...) -  these are not real tables
        if (preg_match_all('/\bWITH\s+([a-zA-Z_]\w*)\s+AS\s*\(/i', $sql, $cteMatches)) {
            $tables = array_diff($tables, $cteMatches[1]);
        }

        // Also remove common SQL aliases that look like table names
        // (subquery aliases after closing parenthesis)
        if (preg_match_all('/\)\s+(?:AS\s+)?([a-zA-Z_]\w*)/i', $sql, $aliasMatches)) {
            $tables = array_diff($tables, $aliasMatches[1]);
        }

        return array_unique(array_values(array_filter($tables)));
    }

    /**
     * Return a validation failure result.
     */
    /**
     * Split a statement into the branches of its compound SELECT.
     *
     * Top level only: an operator inside parentheses belongs to a subquery and
     * the branch that contains it keeps it. Operators inside a string literal
     * are text. A statement with no set operator returns one branch, which is
     * the whole statement - so the caller needs no special case.
     *
     * @return array<int, string>
     */
    protected function splitSetOperations(string $sql): array
    {
        $branches = [];
        $depth = 0;
        $inString = false;
        $start = 0;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($inString) {
                if ($char === "'") {
                    $inString = false;
                }

                continue;
            }

            if ($char === "'") {
                $inString = true;

                continue;
            }

            if ($char === '(') {
                $depth++;

                continue;
            }

            if ($char === ')') {
                $depth--;

                continue;
            }

            if ($depth !== 0) {
                continue;
            }

            // Only at a word boundary: a column called `union_id` is not an
            // operator, and neither is the tail of `reunion`.
            $previous = $i > 0 ? $sql[$i - 1] : ' ';
            if ($previous === '_' || ctype_alnum($previous)) {
                continue;
            }

            if (preg_match('/\G(UNION\s+ALL|UNION|INTERSECT|EXCEPT|MINUS)\b/i', $sql, $m, 0, $i) !== 1) {
                continue;
            }

            $branches[] = substr($sql, $start, $i - $start);
            $i += strlen($m[1]) - 1;
            $start = $i + 1;
        }

        $branches[] = substr($sql, $start);

        return $branches;
    }

    /**
     * Blank out FROM where it belongs to a function, not to a table.
     *
     * EXTRACT(YEAR FROM col), TRIM(BOTH ' ' FROM col), SUBSTRING(col FROM 1 FOR
     * 2) and OVERLAY(... FROM ...) are standard SQL. Only the keyword is
     * replaced -  the argument is left in place, so nothing that follows can
     * shift position and nothing real is hidden.
     */
    protected function neutraliseFunctionKeywords(string $sql): string
    {
        $patterns = [
            '/\b(EXTRACT\s*\(\s*\w+\s+)FROM\b/i',
            '/\b(TRIM\s*\(\s*(?:BOTH|LEADING|TRAILING)?\s*(?:\'[^\']*\'\s*)?)FROM\b/i',
            '/\b(SUBSTRING\s*\(\s*[^()]*?\s)FROM\b/i',
            '/\b(OVERLAY\s*\(\s*[^()]*?\s)FROM\b/i',
        ];

        foreach ($patterns as $pattern) {
            $replaced = preg_replace($pattern, '$1     ', $sql);

            // A failed pattern returns null. Keeping the original is the safe
            // direction: the table check then runs on unmodified SQL and can
            // only be stricter, never blinder.
            if (is_string($replaced)) {
                $sql = $replaced;
            }
        }

        return $sql;
    }

    /**
     * Does this query return exactly one row regardless of the data?
     *
     * True for a SELECT whose every output is an aggregate and which has no
     * GROUP BY. Anything less certain returns false, so LIMIT enforcement still
     * applies to it.
     */
    protected function returnsOneRow(string $sql): bool
    {
        if (preg_match('/\bGROUP\s+BY\b/i', $sql) || preg_match('/\bUNION\b/i', $sql)) {
            return false;
        }

        if (!preg_match('/^\s*SELECT\s+(.*?)\s+FROM\b/is', $sql, $m)) {
            return false;
        }

        foreach ($this->splitTopLevel($m[1]) as $expression) {
            if (!preg_match('/^\s*(?:COUNT|SUM|AVG|MIN|MAX)\s*\(/i', $expression)) {
                return false;
            }
        }

        return true;
    }

    /** Split a select list on commas that are not inside parentheses. */
    protected function splitTopLevel(string $list): array
    {
        $parts = [];
        $depth = 0;
        $current = '';

        foreach (str_split($list) as $char) {
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            }

            if ($char === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $parts[] = $current;

        return array_filter(array_map('trim', $parts), fn ($p) => $p !== '');
    }

    protected function fail(string $reason): array
    {
        return ['valid' => false, 'reason' => $reason];
    }
}
