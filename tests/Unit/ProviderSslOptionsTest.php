<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Jayanta\Jeeves\LlmProviders\AbstractProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ProviderSslOptionsTest extends TestCase
{
    private object $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new class extends AbstractProvider
        {
            public function getName(): string
            {
                return 'test';
            }

            public function exposedSslOptions(): array
            {
                return $this->sslOptions();
            }

            public function exposedRedactSecrets(string $message): string
            {
                return $this->redactSecrets($message);
            }
        };
    }

    #[Test]
    public function default_true_returns_no_overrides()
    {
        config(['jeeves.ssl_verify' => true]);
        $this->assertSame([], $this->provider->exposedSslOptions());
    }

    #[Test]
    public function false_disables_verification()
    {
        config(['jeeves.ssl_verify' => false]);
        $this->assertSame(['verify' => false], $this->provider->exposedSslOptions());
    }

    #[Test]
    public function stringy_booleans_are_treated_as_booleans()
    {
        foreach (['1', 'true', 'on', 'yes', ''] as $truthy) {
            config(['jeeves.ssl_verify' => $truthy]);
            $this->assertSame([], $this->provider->exposedSslOptions(), "'{$truthy}' should mean default verification");
        }

        foreach (['0', 'false', 'off', 'no'] as $falsy) {
            config(['jeeves.ssl_verify' => $falsy]);
            $this->assertSame(['verify' => false], $this->provider->exposedSslOptions(), "'{$falsy}' should disable verification");
        }
    }

    #[Test]
    public function a_path_string_is_used_as_ca_bundle()
    {
        $path = 'C:\\xampp\\htdocs\\app\\storage\\cacert.pem';
        config(['jeeves.ssl_verify' => $path]);
        $this->assertSame(['verify' => $path], $this->provider->exposedSslOptions());

        config(['jeeves.ssl_verify' => '/etc/ssl/certs/ca-certificates.crt']);
        $this->assertSame(['verify' => '/etc/ssl/certs/ca-certificates.crt'], $this->provider->exposedSslOptions());
    }

    #[Test]
    public function redact_secrets_strips_keys_but_keeps_diagnostics()
    {
        $message = 'cURL error 60: SSL certificate problem for '
            . 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=AIzaSyFakeKey123_-abc';

        $redacted = $this->provider->exposedRedactSecrets($message);

        $this->assertStringNotContainsString('AIzaSyFakeKey123_-abc', $redacted);
        $this->assertStringContainsString('key=***', $redacted);
        // Diagnostic detail must survive redaction (unlike sanitizeError)
        $this->assertStringContainsString('cURL error 60', $redacted);
    }

    #[Test]
    public function redact_secrets_strips_bearer_tokens_and_api_key_headers()
    {
        $redacted = $this->provider->exposedRedactSecrets('HTTP 401 with Bearer sk-abc123.def and x-api-key: sk-ant-xyz');

        $this->assertStringNotContainsString('sk-abc123.def', $redacted);
        $this->assertStringNotContainsString('sk-ant-xyz', $redacted);
        $this->assertStringContainsString('Bearer ***', $redacted);
    }
}
