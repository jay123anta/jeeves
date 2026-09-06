<?php

namespace Jayanta\Jeeves\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Schema\IntrospectorRegistry;
use Jayanta\Jeeves\Schema\SchemaRegistry;
use Jayanta\Jeeves\Security\InputGuard;

/**
 * Doctor Command -  diagnose a Jeeves installation.
 *
 * Answers the question a stuck user actually has: "why isn't this working?"
 * Every check that can fail prints a concrete fix, because the raw symptom is
 * rarely the cause -  a missing CA bundle surfaces as a generic cURL error, a
 * retired model as a 404, a typo'd table name as an empty result.
 *
 * Safe to run in any environment: read-only, and it never prints secrets.
 */
class DoctorCommand extends Command
{
    protected $signature = 'jeeves:doctor
                            {--skip-api : Skip the live AI provider check (no API call, no quota used)}';

    protected $description = 'Diagnose your Jeeves setup and explain how to fix any problems';

    /** @var array<int, array{title: string, fix: string}> */
    protected array $problems = [];

    /** @var array<int, array{title: string, fix: string}> */
    protected array $warnings = [];

    public function handle(SchemaRegistry $registry): int
    {
        // Same reason as audit-schema: a reused command instance would report
        // the previous run's problems again, and a second `doctor` in one
        // process would show a setup getting worse while nothing changed.
        $this->problems = [];
        $this->warnings = [];

        $this->newLine();
        $this->line('  <fg=cyan;options=bold>Jeeves Doctor</>');
        $this->line('  <fg=gray>Read-only checkup. No data is sent anywhere except the provider ping.</>');

        $this->checkConfiguration();
        $this->checkPublishedConfigDrift();
        $this->checkProvider();
        $this->checkExecutionConnection();
        $this->checkDatabase();
        $this->checkSchemas($registry);
        $this->checkTenantColumns($registry);
        $this->checkRoutes();
        $this->checkOptionalAiGuard();

        return $this->summarize();
    }

    // =====================================================================
    // Checks
    // =====================================================================

    protected function checkConfiguration(): void
    {
        $this->section('Configuration');

        if (file_exists(config_path('jeeves.php'))) {
            $this->pass('Config published (config/jeeves.php)');
        } else {
            $this->warn_('Config not published -  using package defaults', 'php artisan jeeves:install');
        }

        $driver = config('jeeves.llm.driver');
        if (!$driver) {
            $this->problem('No LLM driver configured', 'Set JEEVES_LLM_DRIVER in .env (e.g. gemini, openai, claude, ollama)');

            return;
        }

        $providers = config('jeeves.llm.providers', []);
        if (!isset($providers[$driver])) {
            $available = implode(', ', array_keys($providers));
            $this->problem(
                "Driver '{$driver}' has no provider block",
                "Add a 'llm.providers.{$driver}' block in config/jeeves.php, or switch JEEVES_LLM_DRIVER to one of: {$available}"
            );

            return;
        }

        $this->pass("LLM driver: {$driver}");

        $providerConfig = $providers[$driver];
        $needsKey = !$this->looksSelfHosted($driver, (string) ($providerConfig['base_url'] ?? ''));

        if (empty($providerConfig['api_key'])) {
            $envVar = strtoupper($driver) . '_API_KEY';
            if ($needsKey) {
                $this->problem('API key is empty', "Set {$envVar} in .env, then run: php artisan config:clear");
            } else {
                $this->pass('No API key needed (self-hosted provider)');
            }
        } else {
            // Never print the key -  only confirm its presence and shape.
            $this->pass('API key is set (' . strlen((string) $providerConfig['api_key']) . ' chars)');
        }

        if (!empty($providerConfig['model'])) {
            $this->pass('Model: ' . $providerConfig['model']);
        }

        $this->checkSslSetting();
    }

    /**
     * Is this model running on infrastructure the adopter controls?
     *
     * Only 'ollama', 'localhost' and '127.0.0.1' counted, so a perfectly
     * ordinary self-hosted setup -  vLLM on a LAN address, LM Studio reached by
     * hostname, a model behind an internal name -  was told its API key was
     * empty and doctor exited non-zero over a key that service does not want.
     * That is the same vendor bias as everywhere else: hosted assumed, local
     * treated as the exception.
     */
    protected function looksSelfHosted(string $driver, string $baseUrl): bool
    {
        if ($driver === 'ollama') {
            return true;
        }

        if ($baseUrl === '') {
            return false;
        }

        $host = strtolower((string) (parse_url($baseUrl, PHP_URL_HOST) ?: $baseUrl));

        // Loopback, private ranges (RFC 1918 and the Docker/K8s defaults that
        // sit in them), and names with no public TLD.
        return $host === 'localhost'
            || $host === '::1'
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')
            || (bool) preg_match('/^127\./', $host)
            || (bool) preg_match('/^10\./', $host)
            || (bool) preg_match('/^192\.168\./', $host)
            || (bool) preg_match('/^172\.(1[6-9]|2\d|3[01])\./', $host)
            // A bare hostname with no dot is a container or service name.
            || !str_contains($host, '.');
    }

    /**
     * The optional ai-guard companion, if it is installed at all.
     *
     * Silent about it when absent -  it is genuinely optional and the built-in
     * guard covers the same ground. The case worth reporting is when it IS
     * installed but cannot actually be used, because then the user believes
     * they have a layer they do not have.
     */
    /**
     * Settings this version ships that the app's published config does not have.
     *
     * Laravel merges package config ONE LEVEL deep. `config/jeeves.php`
     * is published by jeeves:install, so an app that installed under an
     * earlier version has a top-level block -  `prompts`, `cache`, `llm` -
     * that replaces the package's wholesale. Any key added inside that block
     * since then does not exist for that app, and nothing says so: you set
     * JEEVES_PROMPT_MAX_CHARS, nothing happens, and there is no error to
     * search for.
     *
     * Two settings hit this in 2.1.0 alone. One of them
     * (cache.similarity_threshold) used to fail towards a silent wrong answer
     * rather than a crash; it was retired with the fuzzy tier in 2.3.0, so
     * drift in that key is harmless now - but drift in the block AROUND it is
     * not, and that is what this checks.
     */
    protected function checkPublishedConfigDrift(): void
    {
        $this->section('Published config');

        $publishedPath = function_exists('config_path') ? config_path('jeeves.php') : null;

        if (!$publishedPath || !is_file($publishedPath)) {
            $this->skip('Config not published -  you are on the package defaults, which are always current.');

            return;
        }

        $packagePath = __DIR__ . '/../../config/jeeves.php';

        if (!is_file($packagePath)) {
            $this->skip('Package config not found; cannot compare.');

            return;
        }

        $missing = $this->missingKeys(require $packagePath, require $publishedPath);

        if (!$missing) {
            $this->pass('Published config has every setting this version ships.');

            return;
        }

        $shown = array_slice($missing, 0, 8);
        $more = count($missing) - count($shown);

        $this->warn_(
            count($missing) . ' setting(s) added since your config was published are missing from it: '
                . implode(', ', $shown) . ($more > 0 ? " (+{$more} more)" : ''),
            'Laravel merges package config only one level deep, so these do not exist for your app and '
                . 'setting their env vars will do nothing. Re-publish with '
                . '`php artisan vendor:publish --tag=jeeves-config --force` after diffing your '
                . 'changes, or copy the missing keys across by hand.'
        );
    }

    /**
     * Dotted paths present in the package config and absent from the app's.
     *
     * Compared by KEY only. A published file is expected to hold different
     * values -  that is the point of publishing one -  so a differing value is
     * not drift. A missing key is.
     *
     * @param  array<array-key, mixed>  $package  Int keys are real: a config block
     *                                            may hold a list, and the loop below skips those deliberately.
     * @param  array<array-key, mixed>  $published
     * @return array<int, string>
     */
    protected function missingKeys(array $package, array $published, string $prefix = ''): array
    {
        $missing = [];

        foreach ($package as $key => $value) {
            if (is_int($key)) {
                continue;
            }

            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (!array_key_exists($key, $published)) {
                $missing[] = $path;

                continue;
            }

            // Only descend through associative arrays. A list is a value.
            if (is_array($value) && is_array($published[$key]) && !array_is_list($value)) {
                $missing = array_merge($missing, $this->missingKeys($value, $published[$key], $path));
            }
        }

        return $missing;
    }

    /**
     * Whether the configured model is one that answers reliably.
     *
     * Measured on this package's own conformance battery, not guessed: model
     * SIZE matters far more than vendor. Gemini 2.5 Flash, Claude Sonnet 5,
     * DeepSeek v4 Flash, Mistral Large and Llama 3.3 70B all score 17/17.
     * Llama 3.1 8B scores 12/17 -  it drops filters and ignores date periods,
     * which produces confident numbers that answer a narrower question than
     * the one asked.
     *
     * Someone running an 8B model locally and seeing wrong answers will
     * conclude the package is broken. Naming the cause costs one line.
     */
    protected function checkModelCapability(): void
    {
        $driver = config('jeeves.llm.driver');
        $model = (string) config("jeeves.llm.providers.{$driver}.model", '');

        if ($model === '') {
            return;
        }

        // An explicit parameter count of 12B or under, however the id spells
        // it: llama3.1:8b, Llama-3-8B-Instruct, qwen2.5:7b.
        //
        // Deliberately NOT matching words like "mini", "small" or "tiny". They
        // catch gpt-4o-mini, which is a capable hosted model this package has
        // never measured -  and warning about a model on no evidence is the
        // same sin as the confident wrong answers §0 is about. The claim below
        // is exactly as wide as the data behind it: small parameter counts,
        // measured, scored badly.
        if (!preg_match('/(?<![\d.])([0-9]|1[0-2])\s*b\b/i', $model)) {
            $this->pass("Model '{$model}' is not in the parameter range that measured badly here.");

            return;
        }

        $this->warn_(
            "Model '{$model}' looks like a small model (12B or under), and those measured badly here.",
            'Llama 3.1 8B scores 12/17 on this package\'s battery, dropping filters and ignoring date '
                . 'periods -  a confident number for a narrower question than the one asked. 70B-class '
                . 'and current hosted models score 17/17. Use one of those wherever a wrong number '
                . 'matters; if this model is deliberate, render the parsed_summary line so misreadings '
                . 'are visible.'
        );
    }

    protected function checkOptionalAiGuard(): void
    {
        /** @var InputGuard $guard */
        $guard = app(InputGuard::class);

        if (!$guard->hasAiGuard()) {
            return;
        }

        $this->section('ai-guard (optional companion)');

        if (!$guard->aiGuardSupportsTextScan()) {
            $this->warn_(
                'ai-guard is installed but this version cannot scan text -  it has no detectText()',
                'Upgrade jayanta/laravel-ai-guard (v2.0.0 only exposes detect(Request)). '
                . 'Until then it is skipped entirely and the built-in InputGuard does the work, '
                . 'so nothing is unprotected -  but ai-guard is contributing nothing.'
            );

            return;
        }

        $mode = (string) config('ai-guard.mode', 'log_only');
        $threshold = (int) config('ai-guard.confidence_threshold', 70);
        $enforce = config('jeeves.privacy.ai_guard.enforce', 'auto');

        $this->pass("Installed and scanning (mode: {$mode}, threshold: {$threshold})");

        if ($enforce !== 'always' && $mode !== 'block') {
            $this->skip(
                "Detections are logged, not blocked -  ai-guard is in '{$mode}' mode. "
                . "Set its mode to 'block', or privacy.ai_guard.enforce to 'always', to refuse them."
            );
        }
    }

    protected function checkSslSetting(): void
    {
        $verify = config('jeeves.ssl_verify', true);

        if ($verify === false || (is_string($verify) && in_array(strtolower(trim($verify)), ['0', 'false', 'off', 'no'], true))) {
            $this->warn_(
                'SSL verification is DISABLED',
                'This exposes AI traffic to man-in-the-middle attacks. Download https://curl.se/ca/cacert.pem and set JEEVES_SSL_VERIFY to its full path instead of false.'
            );

            return;
        }

        if (is_string($verify) && !in_array(strtolower(trim($verify)), ['1', 'true', 'on', 'yes', ''], true)) {
            if (is_file($verify)) {
                $this->pass('SSL verified against CA bundle: ' . $verify);
            } else {
                $this->problem(
                    'CA bundle not found: ' . $verify,
                    'Fix the JEEVES_SSL_VERIFY path, or download a bundle from https://curl.se/ca/cacert.pem'
                );
            }

            return;
        }

        // Verification is on and no bundle was named, so PHP's own store is
        // what will be used. On XAMPP and WAMP there usually isn't one, and
        // every HTTPS call fails at the handshake.
        //
        // This used to be a green tick regardless, with the truth only
        // emerging from the live provider check -  so `--skip-api`, the fast
        // check the docs recommend, reported a healthy setup that could not
        // reach a provider at all. It cost this project an afternoon: a
        // benchmark run scored 0/36 and read exactly like a package
        // regression.
        if ($bundle = $this->phpCaBundle()) {
            $this->pass('SSL verification enabled (PHP CA store: ' . $bundle . ')');

            return;
        }

        $suggestion = $this->bundleOnDisk();

        $this->warn_(
            'SSL verification is on, but PHP has no CA certificate store',
            $suggestion
                ? 'Common on XAMPP/WAMP. There is a bundle at ' . $suggestion
                    . ' -  set JEEVES_SSL_VERIFY to that path in .env, then run: php artisan config:clear'
                : 'Common on XAMPP/WAMP. Download https://curl.se/ca/cacert.pem, set JEEVES_SSL_VERIFY'
                    . ' to its full path in .env, then run: php artisan config:clear'
        );
    }

    /** The CA store PHP will use on its own, or null if it has none. */
    protected function phpCaBundle(): ?string
    {
        foreach ([ini_get('curl.cainfo'), ini_get('openssl.cafile')] as $configured) {
            if (is_string($configured) && $configured !== '' && is_file($configured)) {
                return $configured;
            }
        }

        if (function_exists('openssl_get_cert_locations')) {
            $default = openssl_get_cert_locations()['default_cert_file'] ?? null;
            if (is_string($default) && $default !== '' && is_file($default)) {
                return $default;
            }
        }

        return null;
    }

    /** A bundle already sitting on this machine, so the fix is one line long. */
    protected function bundleOnDisk(): ?string
    {
        $candidates = [
            'C:/xampp/apache/bin/curl-ca-bundle.crt',
            'C:/wamp64/bin/apache/apache2.4.51/bin/curl-ca-bundle.crt',
            '/etc/ssl/certs/ca-certificates.crt',
            '/etc/pki/tls/certs/ca-bundle.crt',
            '/usr/local/etc/openssl/cert.pem',
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    protected function checkProvider(): void
    {
        $this->section('AI provider');

        // Before the --skip-api gate: this reads config and contacts nothing,
        // and it is the check most likely to explain "the answers are wrong".
        $this->checkModelCapability();

        if ($this->option('skip-api')) {
            $this->skip('Live provider check skipped (--skip-api)');

            return;
        }

        try {
            $provider = app(LlmProviderInterface::class);
        } catch (\Throwable $e) {
            $this->problem('Could not build the LLM provider: ' . $e->getMessage(), 'Check the llm.driver and llm.providers config.');

            return;
        }

        try {
            $health = $provider->healthCheck();
        } catch (\Throwable $e) {
            $this->diagnoseProviderError($e->getMessage());

            return;
        }

        if (($health['status'] ?? null) === 'ok') {
            $this->pass('Provider reachable -  ' . ($health['model'] ?? 'model') . ' is live');

            return;
        }

        $this->diagnoseProviderError((string) ($health['message'] ?? 'unknown error'));
    }

    /**
     * Turn a raw provider error into the fix it actually needs.
     */
    protected function diagnoseProviderError(string $message): void
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'certificate') || str_contains($lower, 'curl error 60') || str_contains($lower, 'ssl')) {
            $this->problem(
                'TLS failure talking to the provider',
                'Your PHP has no CA certificate store (common on XAMPP/WAMP). Download https://curl.se/ca/cacert.pem, then set JEEVES_SSL_VERIFY to its full path in .env and run: php artisan config:clear'
            );

            return;
        }

        if (str_contains($lower, '404')) {
            $this->problem(
                'Provider returned 404 -  the configured model does not exist',
                'Models get retired (gemini-2.0-flash was). Check your provider\'s current model list and update the model in .env, then run: php artisan config:clear'
            );

            return;
        }

        if (str_contains($lower, '401') || str_contains($lower, '403') || str_contains($lower, 'api key')) {
            $this->problem(
                'Provider rejected the API key',
                'Verify the key is correct, active, and has access to this model. After editing .env run: php artisan config:clear'
            );

            return;
        }

        if (str_contains($lower, '429')) {
            $this->warn_(
                'Provider is rate limiting (429)',
                'Free-tier keys have low per-minute quotas. Wait a minute, keep the query cache enabled so repeats skip the API, or upgrade the key.'
            );

            return;
        }

        if (str_contains($lower, 'could not resolve') || str_contains($lower, 'connection') || str_contains($lower, 'timed out')) {
            $this->problem(
                'Cannot reach the provider',
                'Check network access and any proxy/firewall between this server and the provider. For self-hosted models, confirm base_url is correct and the server is running.'
            );

            return;
        }

        $this->problem('Provider health check failed: ' . $message, 'Run with -v for more detail, or check storage/logs/laravel.log.');
    }

    /**
     * NQ-001. Is generated SQL isolated from the application's own database
     * user, and can that user write?
     *
     * The identity half of this is enforced at execution by
     * Security\ExecutionConnection, which refuses to run anything when the
     * connection is missing or names the app default. Repeating it here is not
     * redundant: a refusal at question time reaches whoever asked a question,
     * and this reaches the person who can fix it, before they ship.
     *
     * The PRIVILEGE half lives only here, deliberately. Proving a connection
     * cannot write costs a round trip, it is unprovable on SQLite where every
     * connection can write, and a diagnostic that runs once says it better
     * than a check on every question.
     */
    /**
     * NQ-002. A tenant discriminator this package cannot scope by.
     *
     * Generated SQL is `DB::select()`, so a global scope is never consulted
     * and neither is a policy - the two mechanisms a Laravel developer reaches
     * for first. The package cannot inject a tenant predicate either: doing it
     * safely means knowing which table every reference belongs to through
     * subqueries, CTEs, joins and unions, and PHP has no multi-dialect SQL
     * parser to answer that. A predicate placed on the wrong table is a
     * cross-tenant read, which is worse than none.
     *
     * So doctor says it out loud instead. A WARNING, not a problem: the
     * adopter may already have row-level security or a connection per tenant,
     * and failing the exit code for a risk they have handled would make doctor
     * something they stop running.
     */
    protected function checkTenantColumns(SchemaRegistry $registry): void
    {
        $suspect = ['tenant_id', 'account_id', 'organisation_id', 'organization_id', 'org_id', 'company_id'];
        $found = [];

        foreach (array_keys($registry->all()) as $key) {
            $columns = $registry->get($key)['tables']['primary']['columns'] ?? [];

            foreach (array_keys(is_array($columns) ? $columns : []) as $column) {
                if (in_array(strtolower((string) $column), $suspect, true)) {
                    $found[] = "{$key}.{$column}";
                }
            }
        }

        if ($found === []) {
            return;
        }

        $this->warn_(
            'Tenant-shaped columns this package cannot scope by: ' . implode(', ', $found),
            'Generated SQL bypasses Eloquent, so a global scope and a policy are both ignored, and '
            . 'the package will not inject a tenant predicate because placing one on the wrong '
            . 'table is a cross-tenant read. Isolate at the database: a connection per tenant, '
            . 'PostgreSQL row-level security, or a database per tenant. See docs/SCHEMA.md.'
        );
    }

    protected function checkExecutionConnection(): void
    {
        $this->section('Generated SQL isolation');

        $configured = config('jeeves.sql.database_connection');
        $default = config('database.default');

        if ($configured === null || $configured === '') {
            $this->problem(
                'No connection is configured for generated SQL, so no question can be answered.',
                "Set 'sql.database_connection' in config/jeeves.php to a connection whose "
                . 'database user holds SELECT only. See docs/CONNECTION.md for the grants.'
            );

            return;
        }

        if ($configured === $default) {
            $this->problem(
                "Generated SQL is pointed at '{$configured}', which is the application's own "
                . 'connection - the one it writes with.',
                "Point 'sql.database_connection' at a separate connection whose database user "
                . 'holds SELECT only. See docs/CONNECTION.md for the grants.'
            );

            return;
        }

        $this->pass("Generated SQL runs on '{$configured}', separate from the application's '{$default}'");

        $this->checkConnectionCannotWrite($configured);
    }

    /**
     * Can the read connection write? Answered by asking the database, not by
     * reading config, and rolled back either way.
     *
     * SQLite has no grants, so the honest answer there is "cannot tell" rather
     * than a false pass.
     */
    protected function checkConnectionCannotWrite(string $name): void
    {
        try {
            $connection = DB::connection($name);
        } catch (\Throwable $e) {
            $this->skip("Cannot test write privileges: {$e->getMessage()}");

            return;
        }

        if ($connection->getDriverName() === 'sqlite') {
            $this->skip(
                'SQLite has no per-user grants, so read-only cannot be verified. On MySQL or '
                . 'PostgreSQL this check proves it.'
            );

            return;
        }

        $probe = 'nq_write_probe_' . bin2hex(random_bytes(4));

        try {
            $connection->statement("CREATE TABLE {$probe} (id int)");
            $connection->statement("DROP TABLE {$probe}");

            $this->problem(
                "The '{$name}' connection can CREATE and DROP tables, so it is not read-only.",
                'Grant its database user SELECT on your reporting tables and nothing else. '
                . 'See docs/CONNECTION.md.'
            );
        } catch (\Throwable $e) {
            $this->pass("The '{$name}' connection cannot create tables");
        }
    }

    protected function checkDatabase(): void
    {
        $this->section('Database');

        // Check the connection Jeeves actually uses, which is not
        // necessarily the app default -  sql.database_connection can point it
        // at a different one.
        $configured = config('jeeves.sql.database_connection');

        try {
            $connection = DB::connection($configured);
            $connection->getPdo();
            $this->pass(
                'Connected (' . $connection->getDriverName() . ', database "' . $connection->getDatabaseName() . '"'
                . ($configured ? ', connection "' . $configured . '"' : '') . ')'
            );
        } catch (\Throwable $e) {
            $this->problem(
                'Cannot connect to the database: ' . $e->getMessage(),
                'Check DB_* settings in .env. The database must exist before migrating.'
            );

            return;
        }

        // Connecting is not the same as being usable. The engine introspects
        // the schema, which only Postgres and MySQL/MariaDB support -  and
        // Laravel 11+ defaults to SQLite, so reporting a healthy connection
        // here while every query 500s would be the exact "confidently wrong"
        // failure this command exists to prevent.
        $driver = $connection->getDriverName();

        if (!IntrospectorRegistry::supports($driver)) {
            $this->problem(
                "Driver '{$driver}' is connected but Jeeves cannot introspect it. "
                . 'Supported: ' . implode(', ', IntrospectorRegistry::supportedDrivers())
                . '. Every query and every package route will fail.',
                "Set DB_CONNECTION to a supported driver in .env, or point 'sql.database_connection' "
                . 'in config/jeeves.php at a supported connection. To add a driver of your own, '
                . "map it to a class implementing SchemaIntrospectorInterface under 'sql.introspectors'."
            );
            // Deliberately no early return: the remaining checks still work and
            // the point of this command is to surface every problem in one pass.
        }

        if (config('jeeves.cache.enabled', true)) {
            $table = config('jeeves.cache.table_name', 'jeeves_cache');
            $this->checkTable($table, 'Query cache table', 'php artisan migrate');
        } else {
            $this->skip('Query cache disabled -  every question costs an API call');
        }

        if (config('jeeves.feedback.enabled', false)) {
            $table = config('jeeves.feedback.table_name', 'jeeves_feedback');
            $this->checkTable($table, 'Feedback table', 'php artisan migrate');
        }
    }

    protected function checkTable(string $table, string $label, string $fix): void
    {
        try {
            if (Schema::hasTable($table)) {
                $this->pass("{$label} '{$table}' exists");
            } else {
                $this->problem("{$label} '{$table}' is missing", $fix);
            }
        } catch (\Throwable $e) {
            $this->warn_("Could not inspect '{$table}': " . $e->getMessage(), $fix);
        }
    }

    protected function checkSchemas(SchemaRegistry $registry): void
    {
        $this->section('Schemas');

        $path = config('jeeves.schema.config_path', config_path('jeeves-schemas'));

        if (!is_dir($path)) {
            $this->problem(
                'Schema directory does not exist: ' . $path,
                'Run: php artisan jeeves:install (or create the directory and add a schema file)'
            );

            return;
        }

        $schemas = $registry->all();

        // Reported from the DIRECTORY, because the registry deliberately drops
        // these -  an unedited template names a placeholder table, and offering
        // it to the model produces "no such table: schema" on a fresh install.
        // Without this the file would vanish from view entirely: excluded from
        // the engine and unmentioned by the one command meant to explain the
        // setup.
        $templates = $this->unEditedTemplates($path);

        foreach ($templates as $name) {
            $this->skip("'{$name}' is the shipped template, still on its placeholder table -  not queried. Edit it or delete it.");
        }

        if (empty($schemas)) {
            $this->problem(
                $templates
                    ? 'No usable schema files in ' . $path . ' -  only the unedited template'
                    : 'No schema files found in ' . $path,
                'Generate one from your live database: php artisan jeeves:discover'
            );

            return;
        }

        $this->pass(count($schemas) . ' schema(s) loaded: ' . implode(', ', array_keys($schemas)));

        // 2.0.0 renamed this key. Laravel merges package config one level deep,
        // so a config published under 1.0.0 keeps `default_scheme` and simply
        // never has `default_dataset` read from it -  the pinned dataset goes
        // quiet and every question starts going through detection instead.
        // Nothing else notices, so it is reported here.
        if (config('jeeves.default_scheme') !== null) {
            $this->problem(
                "Your published config still sets 'default_scheme', which 2.0.0 no longer reads",
                "Rename the key to 'default_dataset' in config/jeeves.php "
                . '(and JEEVES_DEFAULT_SCHEME to JEEVES_DEFAULT_DATASET in .env), '
                . 'then run: php artisan config:clear'
            );
        }

        $default = config('jeeves.default_dataset');
        if ($default && !$registry->has($default)) {
            $this->problem(
                "default_dataset '{$default}' does not match any loaded dataset",
                'Set default_dataset to one of: ' . implode(', ', array_keys($schemas))
            );
        } elseif (!$default && count($schemas) === 1) {
            $this->warn_(
                'Single dataset but no default_dataset set',
                "Set 'default_dataset' => '" . array_key_first($schemas) . "' in config/jeeves.php so users never have to name the dataset."
            );
        }

        $this->checkRelationshipTargets($registry);
        $this->checkCompetingMeasures($registry);

        foreach ($schemas as $key => $schema) {
            $this->checkSchemaAgainstDatabase($registry, $key);
        }
    }

    /**
     * More than one table can answer "revenue", and nothing says which one.
     *
     * Measured, not guessed: on a fourteen-table schema with both
     * order_items.line_total and payments.amount, the model chose payments.
     * Customers who had not paid vanished from the ranking and a region came
     * back at half its real revenue -  a confident number from a defensible
     * join path, answering a different question.
     *
     * No amount of introspection can resolve this. The column names, types and
     * foreign keys are identical in kind; only the business knows which one it
     * means. Adding that in system_instructions took three sentences and moved
     * accuracy from 79% to 86%, with every multi-table question passing. So the
     * one thing worth saying here is: you have this choice, and nobody has made
     * it yet.
     */
    /**
     * A separate front end needs CORS, and the failure without it is silent.
     *
     * When the routes are configured for token auth or the `api` stack, the
     * app is almost certainly being called from another origin -  a Vite dev
     * server, a mobile client. If the prefix is not listed in config/cors.php
     * the browser blocks the response before any JavaScript sees it, so the
     * developer gets a network error with no mention of policy, on a request
     * the server answered perfectly.
     */
    protected function checkCrossOriginSetup(array $middleware): void
    {
        $looksLikeSpa = (bool) array_filter(
            $middleware,
            fn ($m) => is_string($m) && ($m === 'api' || str_starts_with($m, 'auth:sanctum') || str_starts_with($m, 'auth:api'))
        );

        if (!$looksLikeSpa) {
            return;
        }

        $prefix = trim((string) config('jeeves.routes.prefix', 'jeeves'), '/');
        $paths = (array) config('cors.paths', []);

        foreach ($paths as $path) {
            $pattern = rtrim((string) $path, '*');

            if ($path === '*' || ($pattern !== '' && str_starts_with($prefix . '/', $pattern))) {
                $this->pass("CORS covers /{$prefix} (cors.paths)");

                return;
            }
        }

        $this->warn_(
            "Routes are set up for a separate front end, but /{$prefix} is not in cors.paths",
            'A browser on another origin will block the response before your code sees it, and it will '
                . "look like a network error rather than a policy one. Add '{$prefix}/*' to 'paths' in "
                . 'config/cors.php (and set supports_credentials if you use cookies).'
        );
    }

    protected function checkCompetingMeasures(SchemaRegistry $registry): void
    {
        if (trim((string) config('jeeves.system_instructions', '')) !== '') {
            return; // The choice has been made somewhere.
        }

        $withMeasures = [];

        foreach ($registry->all() as $key => $schema) {
            // The shipped template names a placeholder table, so counting it
            // manufactures an ambiguity between one real dataset and a file
            // that queries nothing.
            if ($this->isUntouchedExample((string) ($schema['tables']['primary']['name'] ?? ''))) {
                continue;
            }

            foreach ($schema['tables']['primary']['columns'] ?? [] as $column) {
                if (!empty($column['aggregatable'])) {
                    $withMeasures[] = $key;

                    continue 2;
                }
            }
        }

        if (count($withMeasures) < 2) {
            return;
        }

        sort($withMeasures);

        $this->warn_(
            count($withMeasures) . ' datasets have their own measures: ' . implode(', ', $withMeasures),
            'A question like "total revenue" can be answered from any of them, and nothing says which is '
                . 'authoritative -  so the model picks, and a wrong pick returns a plausible number for a '
                . 'different question. Say which one you mean in \'system_instructions\' in '
                . 'config/jeeves.php, e.g. "Revenue means SUM(order_items.line_total), never '
                . 'payments.amount." This is the single highest-value thing you can write.'
        );
    }

    /**
     * A foreign key pointing at a table nobody described is a join the package
     * will not offer, because generated SQL is validated against the tables
     * your schema files declare. That is the right call, but silently doing it
     * looks like the AI is simply bad at joins -  so say it out loud.
     */
    protected function checkRelationshipTargets(SchemaRegistry $registry): void
    {
        $missing = $registry->undescribedRelationshipTargets();

        if (empty($missing)) {
            return;
        }

        $tables = array_unique(array_merge(...array_values($missing)));
        sort($tables);

        $discover = implode(' ', array_map(
            fn ($t) => '--table=' . (str_contains($t, '.') ? substr(strrchr($t, '.'), 1) : $t),
            $tables
        ));

        $this->warn_(
            'Foreign keys point at tables with no schema file: ' . implode(', ', $tables),
            'Questions needing those tables cannot be answered, and no join to them will be suggested. '
                . "To include them: php artisan jeeves:discover {$discover} --merge"
        );
    }

    /**
     * Verify a schema file actually matches the live database. A typo here is
     * the most common cause of "it returns nothing" -  and the AI can't tell
     * you, because it never sees the database.
     */
    protected function checkSchemaAgainstDatabase(SchemaRegistry $registry, string $key): void
    {
        $table = $registry->getTableName($key);

        if (!$table) {
            $this->problem("Schema '{$key}' has no table name", "Add tables.primary.name to the '{$key}' schema file.");

            return;
        }

        // The template `jeeves:install` writes, still untouched.
        //
        // It points at a placeholder table by design, so doctor reported a red
        // ✗ and exited non-zero on a brand-new install -  a first-run failure
        // the user did not cause, on the command the docs recommend as a
        // deployment smoke test. Its documentation value is worth keeping, so
        // it is named for what it is instead.
        if ($this->isUntouchedExample($table)) {
            $this->skip("Schema '{$key}' is the shipped template (placeholder table). Edit it or delete it -  it is not queried.");

            return;
        }

        try {
            $connectionName = $registry->getConnection($key);
            $schemaBuilder = Schema::connection($connectionName);

            // A schema-qualified name (public.orders) may need the bare form
            // depending on driver and search_path. Laravel 11+ parses the
            // "schema.table" form and can throw when that schema is unknown to
            // the driver, so each form is probed independently -  one throwing
            // must not abort the whole check and leave the schema unverified.
            $bare = str_contains($table, '.') ? substr(strrchr($table, '.'), 1) : $table;
            $exists = $this->tableExists($schemaBuilder, $table)
                || $this->tableExists($schemaBuilder, $bare);

            if (!$exists) {
                $this->problem(
                    "Schema '{$key}': table '{$table}' not found in the database",
                    'Fix the table name in the schema file, run your migrations, or regenerate with: php artisan jeeves:discover'
                );

                return;
            }

            $actual = array_map('strtolower', $schemaBuilder->getColumnListing($bare));
            $declared = array_keys($registry->getColumns($key));
            $missing = array_values(array_filter(
                $declared,
                fn ($column) => !in_array(strtolower($column), $actual, true)
            ));

            if ($missing) {
                $this->problem(
                    "Schema '{$key}': column(s) not in '{$table}': " . implode(', ', $missing),
                    'Correct these names in the schema file -  the AI is told they exist, so queries using them will fail at execution.'
                );

                return;
            }

            $groupColumn = $registry->getGroupColumn($key);
            if ($groupColumn && !in_array(strtolower($groupColumn), $actual, true)) {
                $this->problem(
                    "Schema '{$key}': group_column '{$groupColumn}' is not a real column",
                    'Set group_column to the column results should be grouped/labelled by.'
                );

                return;
            }

            $this->pass("Schema '{$key}' → {$table} (" . count($declared) . ' columns verified)');
        } catch (\Throwable $e) {
            $this->warn_("Schema '{$key}': could not verify against the database -  " . $e->getMessage(), 'Check the connection setting in this schema file.');
        }
    }

    /**
     * Does this table exist, as far as this naming form is concerned?
     *
     * Deliberately swallows driver errors rather than letting them bubble:
     * a qualified name that the driver cannot parse means "not found by this
     * form", not "verification impossible". Letting it escape would turn a
     * real, reportable problem into a warning and leave the command exiting 0
     * on a schema that does not match the database.
     */
    protected function tableExists($schemaBuilder, string $name): bool
    {
        try {
            return $schemaBuilder->hasTable($name);
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function checkRoutes(): void
    {
        $this->section('HTTP endpoints');

        if (!config('jeeves.routes.enabled', true)) {
            $this->skip('Routes disabled -  the widget and API endpoints are unavailable');

            return;
        }

        $prefix = trim((string) config('jeeves.routes.prefix', 'jeeves'), '/');
        $this->pass("Routes registered under /{$prefix} (widget.js, text, conversation, health)");

        $middleware = (array) config('jeeves.routes.middleware', []);
        $hasAuth = (bool) array_filter($middleware, fn ($m) => is_string($m) && str_starts_with($m, 'auth'));
        $hasThrottle = (bool) array_filter($middleware, fn ($m) => is_string($m) && str_starts_with($m, 'throttle'));

        $this->checkCrossOriginSetup($middleware);

        if (!$hasAuth && !$hasThrottle) {
            $this->warn_(
                'Endpoints are public and unthrottled',
                "Add 'throttle:60,1' (and auth middleware for private data) to routes.middleware in config/jeeves.php."
            );
        } elseif (!$hasAuth) {
            // Throttling bounds the rate, not the spend, and without auth the
            // daily ceiling falls back to counting by IP -  which is the weakest
            // form of it, on the one configuration where it matters most.
            $perDay = config('jeeves.limits.queries_per_day');

            if ($perDay && (int) $perDay > 0) {
                $this->warn_(
                    "Endpoints are public (no auth). Daily ceiling: {$perDay} questions per IP",
                    'Every question spends your API key, and an IP is easy to change. Fine for a demo on '
                        . 'non-sensitive data; add auth middleware in config/jeeves.php before this '
                        . 'faces the open internet.'
                );
            } else {
                $this->problem(
                    'Endpoints are public AND have no daily limit',
                    'Anyone who finds the URL can spend your API key without bound. Set '
                        . "'limits.queries_per_day' in config/jeeves.php, add auth middleware, or both."
                );
            }
        } else {
            $this->pass('Protected by auth middleware');

            // Laravel's auth middleware redirects guests to a route named
            // 'login'. A fresh app has no such route until a starter kit is
            // installed, so the first request to any endpoint here dies with
            // RouteNotFoundException -  a 500 that says nothing about the real
            // cause. Cheap to detect, baffling to debug.
            if (!Route::has('login')) {
                $this->warn_(
                    "auth middleware is on, but this app has no route named 'login'",
                    'Any unauthenticated request will fail with "Route [login] not defined" rather than a redirect. '
                    . "Install an auth starter kit, or drop 'auth' from routes.middleware in config/jeeves.php "
                    . 'if these endpoints should be reachable without logging in.'
                );
            }
        }
    }

    // =====================================================================
    // Output helpers
    // =====================================================================

    protected function section(string $title): void
    {
        $this->newLine();
        $this->line("  <options=bold>{$title}</>");
    }

    protected function pass(string $message): void
    {
        $this->line("    <fg=green>✓</> {$message}");
    }

    protected function skip(string $message): void
    {
        $this->line("    <fg=gray>-</> {$message}");
    }

    /**
     * The placeholder table name the shipped template carries.
     *
     * Matched on the table rather than the file name so a copy under any name
     * is recognised, and so a template that HAS been pointed at a real table is
     * checked like any other schema -  which is the whole point of editing it.
     */
    /**
     * Schema files in the directory that are still the unedited template.
     *
     * @return array<int, string> file basenames, without .php
     */
    protected function unEditedTemplates(string $path): array
    {
        $found = [];

        foreach (glob(rtrim($path, '/\\') . '/*.php') ?: [] as $file) {
            try {
                $schema = require $file;
            } catch (\Throwable $e) {
                continue; // a broken file is a different problem, reported elsewhere
            }

            if (is_array($schema) && SchemaRegistry::isUntouchedTemplate($schema)) {
                $found[] = pathinfo($file, PATHINFO_FILENAME);
            }
        }

        return $found;
    }

    protected function isUntouchedExample(string $table): bool
    {
        // Delegated so doctor and the registry can never disagree about which
        // files are real. They did briefly: doctor said the template "is not
        // queried" while the registry was happily offering it to the model.
        return SchemaRegistry::isUntouchedTemplate(['tables' => ['primary' => ['name' => $table]]]);
    }

    protected function problem(string $message, string $fix): void
    {
        $this->line("    <fg=red>✗</> {$message}");
        $this->line("      <fg=yellow>→ Fix:</> {$fix}");
        $this->problems[] = ['title' => $message, 'fix' => $fix];
    }

    /** Named with a trailing underscore to avoid clashing with Command::warn(). */
    protected function warn_(string $message, string $fix): void
    {
        $this->line("    <fg=yellow>!</> {$message}");
        $this->line("      <fg=yellow>→</> {$fix}");
        $this->warnings[] = ['title' => $message, 'fix' => $fix];
    }

    protected function summarize(): int
    {
        $this->newLine();

        $problems = count($this->problems);
        $warnings = count($this->warnings);

        if ($problems === 0 && $warnings === 0) {
            $this->line('  <fg=green;options=bold>All checks passed.</> Ask something: <fg=cyan>php artisan jeeves:debug "top 10 by revenue"</>');
            $this->newLine();

            return self::SUCCESS;
        }

        if ($problems === 0) {
            $this->line("  <fg=yellow;options=bold>{$warnings} warning(s)</>, nothing broken. Queries should work.");
            $this->newLine();

            return self::SUCCESS;
        }

        $this->line("  <fg=red;options=bold>{$problems} problem(s) found</>" . ($warnings ? " and {$warnings} warning(s)" : '') . '. Fix the ✗ items above, then re-run this command.');
        $this->newLine();

        return self::FAILURE;
    }
}
