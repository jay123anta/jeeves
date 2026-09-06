<?php

namespace Jayanta\Jeeves\Tests\Security;

use Illuminate\Support\Facades\Schema;
use Jayanta\Jeeves\Schema\Introspectors\SqliteIntrospector;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * NQ-003 — fixed. This was the reproduction and is now the regression guard.
 *
 * `schema.discover_exclude` filters TABLES — migrations, password_resets,
 * sessions, personal_access_tokens, oauth_*, telescope_*. There is no
 * column-level equivalent anywhere in src/ (checked: 0 hits across all 59
 * files).
 *
 * `users` is not an excluded table, so a stock Laravel app that runs
 * `jeeves:discover` gets a users dataset whose columns include
 * `password`, `remember_token` and `two_factor_secret`. Those columns become
 * selectable, groupable and filterable, and "show me all users" returns the
 * hashes into the HTTP response.
 *
 * Eloquent's protections do not apply. Generated SQL is DB::select(), so
 * $hidden is not consulted and an encrypted cast is returned as ciphertext.
 *
 * Closed by `Concerns\WithholdsSecretColumns`, applied in all three
 * introspectors - the three places columns are produced - rather than in
 * `jeeves:discover`, which is only one of the callers.
 */
class NQ003SensitiveColumnsAreDiscoverableTest extends TestCase
{
    /** Columns no analytics question ever needs, and that must never be exposed. */
    private const MUST_NEVER_BE_EXPOSED = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    #[Test]
    public function discovery_does_not_expose_credential_columns_on_a_stock_users_table()
    {
        // A stock Laravel users table, verbatim from the default migration.
        Schema::dropIfExists('users');
        Schema::create('users', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->timestamp('email_verified_at')->nullable();
            $t->string('password');
            $t->string('remember_token', 100)->nullable();
            $t->text('two_factor_secret')->nullable();
            $t->text('two_factor_recovery_codes')->nullable();
            $t->timestamps();
        });

        // getColumns() returns a LIST of descriptors, not a name-keyed map.
        // The first version of this test read array_keys() and compared
        // integers against column names, so it passed while proving nothing -
        // the exact shape of vacuous test this file exists to avoid.
        $columns = array_map(
            static fn (array $c): string => strtolower((string) $c['name']),
            $this->app->make(SqliteIntrospector::class)->getColumns('users')
        );

        $exposed = array_values(array_intersect($columns, self::MUST_NEVER_BE_EXPOSED));

        $this->assertSame(
            [],
            $exposed,
            "NQ-003: discovery exposed credential columns on a stock users table:\n  "
                . implode("\n  ", $exposed)
                . "\n\nThese become selectable, groupable and filterable. Generated SQL is "
                . 'DB::select(), so $hidden is not consulted and an encrypted cast is returned '
                . 'as ciphertext. discover_exclude filters tables only; there is no column-level '
                . 'equivalent anywhere in src/.'
        );
    }

    /**
     * The counterweight, and the thing that stops the fix above from being
     * "return nothing". Withholding the credentials must leave the table
     * usable, or discovery has been fixed by breaking it.
     */
    #[Test]
    public function the_ordinary_columns_of_that_same_table_survive()
    {
        Schema::dropIfExists('users');
        Schema::create('users', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->timestamp('email_verified_at')->nullable();
            $t->string('password');
            $t->string('remember_token', 100)->nullable();
            $t->text('two_factor_secret')->nullable();
            $t->timestamp('two_factor_confirmed_at')->nullable();
            $t->timestamps();
        });

        $columns = array_map(
            static fn (array $c): string => strtolower((string) $c['name']),
            $this->app->make(SqliteIntrospector::class)->getColumns('users')
        );

        foreach (['id', 'name', 'email', 'email_verified_at', 'created_at'] as $expected) {
            $this->assertContains($expected, $columns, "discovery lost the ordinary column `{$expected}`");
        }

        // Not a secret, and it answers a real question: how many users turned
        // 2FA on. A `two_factor_*` pattern would have taken it, and a filter
        // that removes real analytics is one people switch off entirely.
        $this->assertContains(
            'two_factor_confirmed_at',
            $columns,
            'a timestamp carrying no secret was withheld along with the credentials'
        );
    }

    /**
     * The adopter's own list ADDS to Laravel's; it does not replace it.
     *
     * `forbidden_keywords` and `allowed_functions` both replace their defaults,
     * and that is right for lists an adopter curates deliberately. This one
     * protects against names nobody thought about, so an entry meant to add
     * `ssn` must not silently put `password` back on the table.
     */
    #[Test]
    public function the_configured_list_adds_to_the_built_in_one()
    {
        config(['jeeves.schema.discover_exclude_columns' => ['ssn']]);

        Schema::dropIfExists('patients');
        Schema::create('patients', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('ssn');
            $t->string('password');
        });

        $columns = array_map(
            static fn (array $c): string => strtolower((string) $c['name']),
            $this->app->make(SqliteIntrospector::class)->getColumns('patients')
        );

        $this->assertNotContains('ssn', $columns, 'the configured column was not withheld');
        $this->assertNotContains('password', $columns, 'configuring the list disabled the built-in defaults');
        $this->assertContains('name', $columns);
    }
}
