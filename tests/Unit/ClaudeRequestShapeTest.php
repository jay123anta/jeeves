<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Illuminate\Support\Facades\Http;
use Jayanta\Jeeves\LlmProviders\ClaudeProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * What the Claude driver puts on the wire.
 *
 * The first live Claude call this package ever made returned 400 on every
 * single question, for two reasons at once:
 *
 *     "`temperature` is deprecated for this model."
 *     "This model does not support assistant message prefill. The conversation
 *      must end with a user message."
 *
 * Both were sent on every request. Unit tests could not catch it -  they assert
 * on the parsed result, and a canned response is happy to answer a request the
 * real API would refuse. Only a live call could, and Claude was the last
 * provider never to have had one.
 *
 * These pin the shape rather than the outcome, which is the part that was
 * wrong.
 */
class ClaudeRequestShapeTest extends TestCase
{
    private function capture(callable $call): array
    {
        Http::fake(['*' => Http::response([
            'content' => [['type' => 'text', 'text' => '{"dataset":"orders","metric":"revenue","confidence":0.9}']],
        ], 200)]);

        $call(new ClaudeProvider(['api_key' => 'k', 'model' => 'claude-sonnet-5']));

        $sent = null;
        Http::assertSent(function ($request) use (&$sent) {
            $sent = $request->data();

            return true;
        });

        return $sent ?? [];
    }

    public static function calls(): array
    {
        return [
            'parseIntent' => [fn (ClaudeProvider $p) => $p->parseIntent('total revenue', [['key' => 'orders', 'name' => 'Orders']])],
            'generateSql' => [fn (ClaudeProvider $p) => $p->generateSql('write some sql')],
        ];
    }

    /**
     * Current models refuse a trailing assistant turn outright. They also do
     * not need one: the system prompt asks for JSON and they return JSON.
     */
    #[DataProvider('calls')]
    #[Test]
    public function the_conversation_ends_with_a_user_message(callable $call)
    {
        $sent = $this->capture($call);

        $roles = array_column($sent['messages'] ?? [], 'role');

        $this->assertNotEmpty($roles);
        $this->assertSame('user', end($roles), 'an assistant prefill is back; the API rejects it');
        $this->assertNotContains('assistant', $roles);
    }

    #[DataProvider('calls')]
    #[Test]
    public function temperature_is_not_sent_unless_asked_for(callable $call)
    {
        $this->assertArrayNotHasKey('temperature', $this->capture($call));
    }

    /**
     * Omitting it is valid on every Claude model; sending it is not. But an
     * older model that honours it can still be given one deliberately.
     */
    #[Test]
    public function temperature_can_still_be_set_deliberately()
    {
        Http::fake(['*' => Http::response(['content' => [['type' => 'text', 'text' => '{"ok":true}']]], 200)]);

        (new ClaudeProvider(['api_key' => 'k', 'model' => 'claude-sonnet-4-5-20250929', 'temperature' => 0.1]))
            ->generateSql('x');

        Http::assertSent(fn ($request) => ($request->data()['temperature'] ?? null) === 0.1);
    }

    /**
     * The prefill used to be glued back on with '{' . $text. With no prefill
     * the model returns the whole object, and prepending would corrupt it.
     */
    #[Test]
    public function the_response_is_parsed_as_sent_not_reassembled()
    {
        Http::fake(['*' => Http::response([
            'content' => [['type' => 'text', 'text' => '{"dataset":"orders","metric":"revenue","confidence":0.9}']],
        ], 200)]);

        $intent = (new ClaudeProvider(['api_key' => 'k']))
            ->parseIntent('total revenue', [['key' => 'orders', 'name' => 'Orders']]);

        $this->assertTrue($intent['success']);
        $this->assertSame('orders', $intent['dataset']);
    }
}
