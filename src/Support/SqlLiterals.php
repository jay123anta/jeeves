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
