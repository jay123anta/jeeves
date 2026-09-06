<?php

namespace Jayanta\Jeeves\Schema\Introspectors\Concerns;

use Illuminate\Support\Facades\Log;

/**
 * NQ-003. Columns discovery must never hand back.
 *
 * `schema.discover_exclude` filters TABLES - migrations, password_resets,
 * sessions, personal_access_tokens. There was no column-level equivalent, and
 * `users` is not an excluded table. So a stock Laravel app that ran
 * `jeeves:discover` got a users dataset carrying `password`,
 * `remember_token`, `two_factor_secret` and `two_factor_recovery_codes` -
 * selectable, groupable, filterable, and returned in full by "show me all
 * users".
 *
 * Eloquent's protections do not apply to any of it. Generated SQL is
 * `DB::select()`, so `$hidden` is never consulted and an encrypted cast comes
 * back as ciphertext rather than as a decrypted value.
 *
 * **Filtered here rather than in `jeeves:discover`.** Discovery is one
 * caller. `audit-schema`, `doctor` and any adopter using the introspector
 * directly are others, and the project's signature defect is a guard attached
 * to the call sites somebody remembered. Columns are produced in exactly three
 * places - the three introspectors - so that is where they are withheld.
 *
 * **A pattern list, which is a denylist, and that is deliberate here.** The
 * inversion applied elsewhere in this package does not fit: the permitted set
 * is "every column in the adopter's database", which cannot be enumerated in
 * advance. What CAN be enumerated is the small set of names the framework
 * itself creates for credentials. The list therefore claims to cover Laravel's
 * own conventions and nothing more, and says so - `discover_exclude_columns`
 * is there for the names a particular application invented.
 */
trait WithholdsSecretColumns
{
    /**
     * Laravel's own credential columns, by the names the framework gives them.
     *
     * Every entry is a name shipped by Laravel, Fortify, Jetstream, Sanctum or
     * Passport, not a guess about what an application might call something.
     * `*_token` covers `remember_token` and `api_token`; `*_secret` covers
     * `two_factor_secret` and `client_secret`.
     *
     * Note what is NOT here: `two_factor_*`. It would catch the two secrets
     * below, and also `two_factor_confirmed_at`, which is a timestamp holding
     * no secret and answering a question an adopter will genuinely ask - how
     * many users have enabled it. A pattern that withholds real analytics along
     * with the credentials teaches people to switch the whole thing off, and
     * then `password` goes back on the table with it.
     */
    protected const CREDENTIAL_COLUMN_PATTERNS = [
        'password',
        'password_*',
        '*_password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        '*_recovery_codes',
        '*_secret',
        '*_token',
        'token',
        'secret',
        'api_key',
        '*_api_key',
        'private_key',
    ];

    /**
     * Drop the columns no analytics question needs and no answer may carry.
     *
     * @param  array<int, array<string, mixed>>  $columns
     * @return array<int, array<string, mixed>>
     */
    protected function withholdSecretColumns(array $columns, string $tableName): array
    {
        $patterns = $this->secretColumnPatterns();

        if ($patterns === []) {
            return $columns;
        }

        $withheld = [];

        $kept = array_values(array_filter($columns, function (array $column) use ($patterns, &$withheld) {
            $name = strtolower((string) ($column['name'] ?? ''));

            foreach ($patterns as $pattern) {
                if (fnmatch($pattern, $name)) {
                    $withheld[] = $name;

                    return false;
                }
            }

            return true;
        }));

        if ($withheld !== []) {
            // Said out loud, because a column silently missing from a schema
            // file is a support question. The names are Laravel's own and
            // carry no values, so logging them exposes nothing.
            Log::info('[Jeeves] Withheld credential columns from discovery', [
                'table' => $tableName,
                'columns' => $withheld,
            ]);
        }

        return $kept;
    }

    /**
     * The patterns in force: Laravel's own, plus whatever the app adds.
     *
     * ADDITIVE, unlike `forbidden_keywords` and `allowed_functions`, which
     * replace their defaults. Those are lists an adopter curates deliberately.
     * This one protects against names the adopter did not think about, so a
     * config entry meant to add `ssn` must not silently drop `password` -
     * which is exactly what a replacing list would do, and it would do it
     * quietly.
     *
     * @return array<int, string>
     */
    protected function secretColumnPatterns(): array
    {
        $extra = config('jeeves.schema.discover_exclude_columns', []);

        if (!is_array($extra)) {
            $extra = [];
        }

        return array_values(array_unique(array_map(
            static fn ($pattern) => strtolower((string) $pattern),
            array_merge(static::CREDENTIAL_COLUMN_PATTERNS, $extra)
        )));
    }
}
