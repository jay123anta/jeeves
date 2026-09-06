<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Illuminate\Support\Facades\Http;
use Jayanta\Jeeves\LlmProviders\OpenAiProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * A failed call should say what the provider said.
 *
 * "API error: HTTP 403" is a number. The same 403 carried "Your newly created
 * team doesn't have any credits or licenses yet" -  a five-minute fix once you
 * can read it, and an afternoon of guessing when you cannot. Found with a live
 * xAI key on an unfunded account.
 */
class ProviderErrorDetailTest extends TestCase
{
    private function errorFor(array $body, int $status): string
    {
        Http::fake(['*' => Http::response($body, $status)]);

        $result = (new OpenAiProvider(['api_key' => 'k', 'max_retries' => 1]))->generateSql('x');

        return (string) ($result['error'] ?? '');
    }

    #[Test]
    public function the_providers_own_explanation_is_included()
    {
        $error = $this->errorFor(
            ['error' => ['message' => "Your team doesn't have any credits yet."]],
            403
        );

        $this->assertStringContainsString('403', $error);
        $this->assertStringContainsString("doesn't have any credits", $error);
    }

    /**
     * Providers nest it differently, so several shapes are tried.
     *
     * One case per test on purpose: Http::fake accumulates within a test, so
     * three stubs in a row all matched the first wildcard and the second
     * assertion was reading the first response.
     */
    public static function bodyShapes(): array
    {
        return [
            'openai / anthropic nested' => [['error' => ['message' => 'nested form']], 'nested form'],
            'flat error string' => [['error' => 'flat form'], 'flat form'],
            'bare message key' => [['message' => 'message key'], 'message key'],
            'detail key' => [['detail' => 'detail form'], 'detail form'],
        ];
    }

    #[DataProvider('bodyShapes')]
    #[Test]
    public function it_reads_the_common_body_shapes(array $body, string $expected)
    {
        $this->assertStringContainsString($expected, $this->errorFor($body, 400));
    }

    /**
     * This text reaches an HTTP response. A provider echoing part of the
     * request must not echo a key with it.
     */
    #[Test]
    public function secrets_in_the_body_are_redacted()
    {
        $error = $this->errorFor(
            ['error' => ['message' => 'Rejected request with Bearer sk-live-abcdef123456 attached']],
            401
        );

        $this->assertStringNotContainsString('sk-live-abcdef123456', $error);
        $this->assertStringContainsString('Bearer ***', $error);
    }

    #[Test]
    public function a_body_with_nothing_useful_still_reports_the_status()
    {
        $this->assertStringContainsString('500', $this->errorFor([], 500));
    }

    /** Long provider prose must not become the whole error message. */
    #[Test]
    public function the_explanation_is_bounded()
    {
        $error = $this->errorFor(['error' => ['message' => str_repeat('verbose ', 200)]], 400);

        $this->assertLessThan(260, strlen($error));
    }
}
