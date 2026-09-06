<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Jayanta\Jeeves\LlmProviders\ClaudeProvider;
use Jayanta\Jeeves\LlmProviders\OllamaProvider;
use Jayanta\Jeeves\LlmProviders\OpenAiProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * A model that omits a field must not crash the provider reading it.
 *
 * Three providers shared this line:
 *
 *     'order' => in_array(strtolower($parsed['order'] ?? 'desc'), [...])
 *                    ? strtolower($parsed['order'])      // unguarded
 *                    : 'desc',
 *
 * The coalesce protected the check and not the branch that used the value, so
 * a response without 'order' passed the check and then read the missing key.
 * Gemini always sends the field, so the one provider under live test never
 * triggered it -  it surfaced the first time a real DeepSeek call was made.
 *
 * The lesson is the general one: a field the contract calls optional has to be
 * optional on every path that touches it, and the only way that gets noticed
 * is exercising a provider that actually omits it.
 */
class ProviderOrderDefaultTest extends TestCase
{
    public static function providers(): array
    {
        return [
            'openai-compatible' => [OpenAiProvider::class],
            'claude' => [ClaudeProvider::class],
            'ollama' => [OllamaProvider::class],
        ];
    }

    /** normalizeIntent is protected; the behaviour is what matters, not the access. */
    private function normalize(string $class, array $parsed): array
    {
        $provider = new $class(['api_key' => 'x', 'base_url' => 'http://localhost:1/v1']);

        $method = new \ReflectionMethod($provider, 'normalizeIntent');
        $method->setAccessible(true);

        return $method->invoke($provider, $parsed, [['key' => 'orders', 'name' => 'Orders']]);
    }

    #[DataProvider('providers')]
    #[Test]
    public function a_response_without_an_order_still_normalises(string $class)
    {
        $intent = $this->normalize($class, ['dataset' => 'orders', 'metric' => 'revenue']);

        $this->assertSame('desc', $intent['order']);
    }

    #[DataProvider('providers')]
    #[Test]
    public function an_order_the_model_did_send_is_respected(string $class)
    {
        $this->assertSame('asc', $this->normalize($class, ['dataset' => 'orders', 'order' => 'ASC'])['order']);
        $this->assertSame('desc', $this->normalize($class, ['dataset' => 'orders', 'order' => 'desc'])['order']);
    }

    #[DataProvider('providers')]
    #[Test]
    public function nonsense_falls_back_rather_than_reaching_the_sql(string $class)
    {
        foreach ([null, '', 'sideways', 'DESC; DROP TABLE orders'] as $junk) {
            $this->assertSame(
                'desc',
                $this->normalize($class, ['dataset' => 'orders', 'order' => $junk])['order'],
                'accepted: ' . var_export($junk, true)
            );
        }
    }
}
