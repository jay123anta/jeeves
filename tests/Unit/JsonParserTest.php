<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Jayanta\Jeeves\LlmProviders\GeminiProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class JsonParserTest extends TestCase
{
    private GeminiProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new GeminiProvider(['api_key' => 'test']);
    }

    /**
     * Access the protected parseJsonResponse via reflection.
     */
    private function parse(string $text): ?array
    {
        $method = new \ReflectionMethod($this->provider, 'parseJsonResponse');
        $method->setAccessible(true);

        return $method->invoke($this->provider, $text);
    }

    #[Test]
    public function it_parses_clean_json()
    {
        $result = $this->parse('{"sql": "SELECT 1", "dataset": "test"}');
        $this->assertNotNull($result);
        $this->assertEquals('SELECT 1', $result['sql']);
    }

    #[Test]
    public function it_strips_markdown_code_blocks()
    {
        $result = $this->parse("```json\n{\"sql\": \"SELECT 1\"}\n```");
        $this->assertNotNull($result);
        $this->assertEquals('SELECT 1', $result['sql']);
    }

    #[Test]
    public function it_extracts_json_from_surrounding_text()
    {
        $result = $this->parse('Here is the query: {"sql": "SELECT 1"} Hope this helps!');
        $this->assertNotNull($result);
        $this->assertEquals('SELECT 1', $result['sql']);
    }

    #[Test]
    public function it_handles_trailing_commas()
    {
        $result = $this->parse('{"sql": "SELECT 1", "dataset": "test",}');
        $this->assertNotNull($result);
    }

    #[Test]
    public function it_returns_null_for_non_json()
    {
        $result = $this->parse('This is not JSON at all');
        $this->assertNull($result);
    }

    #[Test]
    public function it_handles_nested_json()
    {
        $result = $this->parse('{"sql": "SELECT 1", "meta": {"key": "value"}}');
        $this->assertNotNull($result);
        $this->assertEquals('value', $result['meta']['key']);
    }
}
