<?php

namespace Jayanta\Jeeves\Tests\Security;

use Illuminate\Support\Facades\Artisan;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * NQ-003, the half the introspector fix cannot reach.
 *
 * Withholding credential columns at discovery protects files generated from
 * 3.0 onwards. It does nothing for the ones already on disk - written by an
 * earlier version, by hand, or copied from another project - and those are
 * exactly the installs that have been running with `password` in a dataset.
 * A fix that only protects new installs would leave every existing adopter
 * exposed while the changelog said the problem was solved.
 *
 * So `audit-schema` names them. A warning rather than an automatic edit:
 * rewriting somebody's schema file without being asked is not a thing a
 * read-only audit command should do, and the adopter may have a reason.
 */
class NQ003AuditNamesCredentialColumnsAlreadyOnDiskTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/credential-column-schemas');
        $app['config']->set('jeeves.system_instructions', '');
    }

    #[Test]
    public function audit_schema_names_every_credential_column_in_a_file_written_before_the_fix(): void
    {
        Artisan::call('jeeves:audit-schema');
        $out = Artisan::output();

        foreach (['password', 'remember_token', 'two_factor_secret'] as $column) {
            $this->assertStringContainsString(
                $column,
                $out,
                "audit-schema did not name `{$column}`, which is exposed to queries in a schema "
                . 'file that discovery can no longer produce but which is already on disk'
            );
        }

        $this->assertStringContainsString(
            'credentials',
            $out,
            'the columns were listed but not identified as a disclosure'
        );
    }

    /**
     * Counterweight: the ordinary columns of that same file are not swept up
     * into the warning. A report that names everything names nothing.
     */
    #[Test]
    public function it_does_not_accuse_the_ordinary_columns(): void
    {
        Artisan::call('jeeves:audit-schema', ['--json' => true]);
        $out = Artisan::output();

        $decoded = json_decode($out, true);
        $this->assertIsArray($decoded, 'audit-schema --json did not return JSON: ' . $out);

        $credential = array_values(array_filter(
            $decoded['findings'] ?? $decoded,
            static fn ($f) => is_array($f) && ($f['kind'] ?? null) === 'credential-columns'
        ));

        $this->assertNotSame([], $credential, 'no credential-columns finding was reported at all');

        $detail = (string) ($credential[0]['detail'] ?? '');

        foreach (['email', 'created_at', 'name'] as $ordinary) {
            $this->assertStringNotContainsString(
                $ordinary,
                $detail,
                "`{$ordinary}` was reported as a credential column"
            );
        }
    }
}
