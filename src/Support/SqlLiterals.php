<?php

namespace Jayanta\Jeeves\Support;

/**
 * Quote-aware access to the string literals inside a SQL statement.
 *
 * Two features need to change a VALUE in generated SQL without touching its
 * structure: value aliases (a renamed value still reaches its rows) and
 * value correction (a misspelled name is fixed when it matched nothing). Both
 * are the same operation - find each quoted literal, look at what is inside,
 * perhaps put something else there - and doing it with a regex is how the
 * project these features came from ended up keying its rewrite on two
 * hard-coded column names.
 *
 * The walk understands what a regex does not: a doubled quote is part of a
 * literal rather than its end, a quote inside a comment opens nothing, and a
 * double-quoted identifier is a name rather than a value. An unterminated
 * literal is left exactly as it was, because a statement that cannot be read
 * must not be rewritten into one that can.
 *
 * It changes values only. Whatever comes out still goes through SqlValidator
 * before it runs, so nothing here is a way around the whitelist.
 */
final class SqlLiterals
{
    /**
     * Rewrite literals. The callback receives each literal's unescaped content
     * and returns a replacement, or null to leave that literal untouched.
     *
     * @param  callable(string): ?string  $fn
     */
    public static function map(string $sql, callable $fn): string
    {
        $out = '';

        foreach (self::segments($sql) as [$type, $text, $inner]) {
            if ($type !== 'literal') {
                $out .= $text;

                continue;
            }

            $replacement = $fn($inner);

            $out .= $replacement === null
                ? $text
                : "'" . str_replace("'", "''", $replacement) . "'";
        }

        return $out;
    }

    /**
     * The unescaped content of every literal, in order.
     *
     * @return array<int, string>
     */
    public static function values(string $sql): array
    {
        $values = [];

        foreach (self::segments($sql) as [$type, , $inner]) {
            if ($type === 'literal') {
                $values[] = $inner;
            }
        }

        return $values;
    }

    /**
     * Whether an identifier appears in the statement as CODE - not inside a
     * literal, not inside a comment. Word-bounded and case-insensitive, and a
     * double-quoted identifier counts, so `"region"` mentions region while
     * `subregion` and `'region'` do not.
     */
    public static function mentions(string $sql, string $identifier): bool
    {
        if ($identifier === '') {
            return false;
        }

        $code = '';

        foreach (self::segments($sql) as [$type, $text]) {
            if ($type === 'code') {
                $code .= $text;
            } elseif ($type === 'ident') {
                $code .= ' ' . trim($text, '"') . ' ';
            } else {
                $code .= ' ';
            }
        }

        return (bool) preg_match(
            '/(?<![A-Za-z0-9_])' . preg_quote($identifier, '/') . '(?![A-Za-z0-9_])/i',
            $code
        );
    }

    /**
     * Rewrite literals, telling the callback which column each one is compared
     * with - or null when the literal is not in a comparison this can read.
     *
     * A value belongs to the column it is compared with, not to every column
     * the statement happens to name. Reading that relationship is what stops a
     * rewrite meant for `district` from reaching a value compared to `note` in
     * the same WHERE clause.
     *
     * Readable: `col = 'v'` and the other comparison operators, LIKE / ILIKE
     * and their NOT forms, `col IN ('a', 'b')`, and any of those with LOWER,
     * UPPER or TRIM around either side. Anything else - BETWEEN, COALESCE, a
     * reversed `'v' = col`, a value in SELECT - yields null, and callers leave
     * a null alone. Not reading a comparison is always safe; misreading one is
     * not.
     *
     * @param  callable(string, ?string): ?string  $fn
     */
    public static function mapCompared(string $sql, callable $fn): string
    {
        $out = '';
        $context = '';

        foreach (self::segments($sql) as [$type, $text, $inner]) {
            if ($type === 'literal') {
                $replacement = $fn($inner, self::comparedColumn($context));

                $out .= $replacement === null
                    ? $text
                    : "'" . str_replace("'", "''", $replacement) . "'";

                // Seen from later literals, a value is just a value - which is
                // what lets the IN pattern read the second item of a list.
                $context .= "''";

                continue;
            }

            $out .= $text;
            $context .= $type === 'comment' ? ' ' : $text;
        }

        return $out;
    }

    /**
     * The column each `?` placeholder is compared with, in order, with null
     * wherever the comparison cannot be read. Bindings are positional, so this
     * is what tells a caller which binding belongs to which column.
     *
     * @return array<int, ?string>
     */
    public static function placeholderColumns(string $sql): array
    {
        $columns = [];
        $context = '';

        foreach (self::segments($sql) as [$type, $text]) {
            if ($type === 'literal') {
                $context .= "''";

                continue;
            }

            if ($type !== 'code') {
                $context .= $type === 'comment' ? ' ' : $text;

                continue;
            }

            $parts = explode('?', $text);
            $last = count($parts) - 1;

            foreach ($parts as $k => $part) {
                $context .= $part;

                if ($k < $last) {
                    $columns[] = self::comparedColumn($context);
                    $context .= "''";
                }
            }
        }

        return $columns;
    }

    /**
     * Tables in FROM and JOIN, keyed by what a column reference may qualify
     * them with: the lower-cased table name, its unqualified name, and its
     * alias. `FROM va_units u` maps `u`, `va_units` to `va_units`.
     *
     * @return array<string, string>
     */
    public static function tableAliases(string $sql): array
    {
        $code = '';

        foreach (self::segments($sql) as [$type, $text]) {
            $code .= ($type === 'code' || $type === 'ident') ? $text : ' ';
        }

        $id = self::IDENTIFIER;
        $reserved = [
            'where', 'join', 'inner', 'left', 'right', 'full', 'cross', 'outer', 'natural',
            'on', 'using', 'group', 'order', 'limit', 'having', 'union', 'lateral', 'window',
        ];

        preg_match_all(
            '/\b(?:FROM|JOIN)\s+(?P<table>' . $id . '(?:\s*\.\s*' . $id . ')*)(?:\s+(?:AS\s+)?(?P<alias>' . $id . '))?/i',
            $code,
            $matches,
            PREG_SET_ORDER
        );

        $map = [];

        foreach ($matches as $hit) {
            $table = self::unquote($hit['table']);
            $short = strtolower(str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table);

            $map[strtolower($table)] = $table;
            $map[$short] = $table;

            $alias = isset($hit['alias']) ? strtolower(self::unquote($hit['alias'])) : '';

            if ($alias !== '' && !in_array($alias, $reserved, true)) {
                $map[$alias] = $table;
            }
        }

        return $map;
    }

    /** A bare or quoted identifier: `name`, `"name"` or a backtick-quoted name. */
    private const IDENTIFIER = '(?:"[^"]+"|`[^`]+`|[A-Za-z_][A-Za-z0-9_]*)';

    /**
     * The column reference a value placed at the END of $before is compared
     * with, or null. $before is the statement up to the value, with every
     * earlier value shown as ''.
     */
    private static function comparedColumn(string $before): ?string
    {
        $id = self::IDENTIFIER;
        $reference = $id . '(?:\s*\.\s*' . $id . ')*';
        $wrappers = '(?:\s*\b(?:LOWER|UPPER|TRIM)\s*\()*';

        $patterns = [
            // col = v, LOWER(col) LIKE LOWER(v), t.col <> v ...
            '/(?:\b(?:LOWER|UPPER|TRIM)\s*\(\s*)*(?P<col>' . $reference . ')\s*\)*\s*'
                . '(?:=|<>|!=|<=|>=|<|>|\bNOT\s+I?LIKE\b|\bI?LIKE\b)' . $wrappers . '\s*$/i',
            // col IN ('', '', v  - any item of the list
            '/(?:\b(?:LOWER|UPPER|TRIM)\s*\(\s*)*(?P<col>' . $reference . ')\s*\)*\s*'
                . '(?:\bNOT\s+)?\bIN\s*\(' . $wrappers . '\s*(?:\'\'\s*\)*\s*,' . $wrappers . '\s*)*$/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $before, $m)) {
                return self::unquote($m['col']);
            }
        }

        return null;
    }

    /** `"t" . "col"` and `t.col` alike become `t.col`. */
    private static function unquote(string $identifier): string
    {
        return (string) preg_replace('/\s+/', '', str_replace(['"', '`'], '', $identifier));
    }

    /**
     * Split a statement into typed runs: code, literal, comment, ident.
     *
     * Byte-wise, which is safe for UTF-8 because every delimiter looked for is
     * ASCII and no UTF-8 continuation byte can be mistaken for one.
     *
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    private static function segments(string $sql): array
    {
        $segments = [];
        $code = '';
        $len = strlen($sql);
        $i = 0;

        while ($i < $len) {
            $c = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($c === '-' && $next === '-') {
                self::flushCode($code, $segments);
                $end = strpos($sql, "\n", $i);
                $end = $end === false ? $len : $end;
                $segments[] = ['comment', substr($sql, $i, $end - $i), ''];
                $i = $end;

                continue;
            }

            if ($c === '/' && $next === '*') {
                self::flushCode($code, $segments);
                $end = strpos($sql, '*/', $i + 2);
                $end = $end === false ? $len : $end + 2;
                $segments[] = ['comment', substr($sql, $i, $end - $i), ''];
                $i = $end;

                continue;
            }

            if ($c === '"') {
                self::flushCode($code, $segments);
                $end = strpos($sql, '"', $i + 1);
                $end = $end === false ? $len : $end + 1;
                $segments[] = ['ident', substr($sql, $i, $end - $i), ''];
                $i = $end;

                continue;
            }

            if ($c === "'") {
                self::flushCode($code, $segments);
                $j = $i + 1;
                $inner = '';
                $closed = false;

                while ($j < $len) {
                    if ($sql[$j] === "'") {
                        if (($sql[$j + 1] ?? '') === "'") {
                            $inner .= "'";
                            $j += 2;

                            continue;
                        }

                        $closed = true;

                        break;
                    }

                    $inner .= $sql[$j];
                    $j++;
                }

                if (!$closed) {
                    // Unterminated. Kept as code so nothing reads or rewrites
                    // it: the validator will refuse the statement anyway, and
                    // it should refuse the statement it was actually given.
                    $code .= substr($sql, $i);

                    break;
                }

                $segments[] = ['literal', substr($sql, $i, $j - $i + 1), $inner];
                $i = $j + 1;

                continue;
            }

            $code .= $c;
            $i++;
        }

        self::flushCode($code, $segments);

        return $segments;
    }

    /**
     * Close the run of code collected so far, if there is one.
     *
     * A method with by-reference parameters rather than a closure over
     * by-reference variables. The closure hid the state it changed - static
     * analysis read `$code !== ''` inside it as always false, because nothing
     * in the closure itself said the variable was ever anything else.
     *
     * @param  array<int, array{0: string, 1: string, 2: string}>  $segments
     */
    private static function flushCode(string &$code, array &$segments): void
    {
        if ($code !== '') {
            $segments[] = ['code', $code, ''];
            $code = '';
        }
    }
}
