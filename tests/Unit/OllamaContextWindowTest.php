<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Illuminate\Support\Facades\Http;
use Jayanta\Jeeves\LlmProviders\OllamaProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The context window Ollama is told to use, and what happens when a prompt
 * will not fit inside it.
 *
 * The driver set `num_predict` -  how many tokens to write -  and never
 * `num_ctx`, how many it may read. So every install ran on Ollama's server
 * default, which is 4096 in current builds and 2048 in older ones, and neither
 * is a number this package chose or documented.
 *
 * Ollama does not reject a prompt that exceeds it. It silently discards the
 * beginning and answers from what is left. The schema block is at the top, so
 * what gets thrown away is the table definitions, and what survives is the
 * question -  producing a confident answer built on a schema the model never
 * fully saw.
 *
 * The measured prompt for a twelve-table, fourteen-column app is about 4,285
 * tokens. That is a small Laravel application, already over the default, on
 * exactly the local-first stack this package exists to serve.
 *
 * These pin the wire shape and the refusal, not the answer -  a canned response
 * is perfectly happy to reply to a prompt the model only half received.
 */
class OllamaContextWindowTest extends TestCase
{
    private function capture(callable $call, array $config = []): array
    {
        Http::fake(['*' => Http::response(['response' => '{"dataset":"orders","confidence":0.9}'], 200)]);

        $call(new OllamaProvider($config + ['model' => 'llama3']));

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
            'parseIntent' => [fn (OllamaProvider $p) => $p->parseIntent('total revenue', [['key' => 'orders', 'name' => 'Orders']])],
            'generateSql' => [fn (OllamaProvider $p) => $p->generateSql('write some sql')],
        ];
    }

    /**
     * Without this the context size is whatever the server happens to default
     * to, which differs between Ollama versions and is invisible from here.
     */
    #[DataProvider('calls')]
    #[Test]
    public function the_context_window_is_stated_explicitly(callable $call)
    {
        $sent = $this->capture($call);

        $this->assertArrayHasKey(
            'num_ctx',
            $sent['options'] ?? [],
            'num_ctx is absent, so Ollama silently uses its own default and truncates the schema'
        );
    }

    #[DataProvider('calls')]
    #[Test]
    public function the_configured_context_window_is_the_one_sent(callable $call)
    {
        $sent = $this->capture($call, ['num_ctx' => 16384]);

        $this->assertSame(16384, $sent['options']['num_ctx'] ?? null);
    }

    /**
     * A machine that cannot afford a large KV cache needs to be able to say so,
     * and a large one needs to be able to use what it has.
     */
    #[Test]
    public function the_default_leaves_room_for_a_realistic_schema()
    {
        $sent = $this->capture(fn (OllamaProvider $p) => $p->generateSql('x'));

        $this->assertGreaterThanOrEqual(
            8192,
            $sent['options']['num_ctx'] ?? 0,
            'the default must clear a twelve-table schema at ~4,285 tokens with room for the answer'
        );
    }

    /**
     * The important one. A prompt that cannot fit must not be sent, because
     * sending it produces an answer rather than an error, and the answer looks
     * exactly like a correct one.
     */
    #[Test]
    public function a_prompt_too_large_for_the_window_is_refused_rather_than_truncated()
    {
        Http::fake(['*' => Http::response(['response' => '{"sql":"SELECT 1"}'], 200)]);

        $result = (new OllamaProvider(['model' => 'llama3', 'num_ctx' => 2048]))
            ->generateSql(str_repeat('A', 30000));

        Http::assertNothingSent();

        $this->assertFalse($result['success'], 'an unfittable prompt was sent anyway');
    }

    /**
     * "HTTP 400" would send someone to their network stack. The fix is a
     * setting, so the message has to name it and the two numbers that decide
     * it -  otherwise the only visible symptom is a wrong answer.
     *
     * The arithmetic is meant to be checkable by hand: 30,000 bytes at three
     * bytes per token is 10,000, plus the 512 reserved for the answer, is
     * 10,512. Pinning the figure pins the ratio, which is the part that
     * decides whether a prompt is refused a little early or a little late -
     * and late means truncated.
     */
    #[Test]
    public function the_refusal_names_the_setting_and_the_numbers()
    {
        Http::fake(['*' => Http::response(['response' => '{}'], 200)]);

        $result = (new OllamaProvider(['model' => 'llama3', 'num_ctx' => 2048]))
            ->generateSql(str_repeat('A', 30000));

        $error = $result['error'] ?? '';

        $this->assertStringContainsString('num_ctx', $error);
        $this->assertStringContainsString('2048', $error);
        $this->assertStringContainsString('10,512', $error, 'the required size is not stated');
    }

    /**
     * A multibyte schema description costs about one token per character while
     * occupying three bytes, so a byte count divided by four would under-read
     * it by roughly a third -  and under-reading is the direction that sends a
     * prompt to be truncated.
     */
    #[Test]
    public function a_multibyte_prompt_is_not_under_counted()
    {
        Http::fake(['*' => Http::response(['response' => '{}'], 200)]);

        // 2,000 Devanagari characters -  6,000 bytes, and roughly 2,000 tokens.
        $result = (new OllamaProvider(['model' => 'llama3', 'num_ctx' => 2048]))
            ->generateSql(str_repeat('क', 2000));

        Http::assertNothingSent();
        $this->assertFalse($result['success']);
    }

    /**
     * `OLLAMA_NUM_CTX=` with nothing after it reaches config as '' and casts to
     * 0. Without a floor that refuses every question ever asked -  and the
     * error names the setting the user has just edited, so it reads as though
     * editing it caused the problem.
     */
    #[DataProvider('unusableValues')]
    #[Test]
    public function an_unusable_configured_value_falls_back_to_the_default(mixed $configured)
    {
        $sent = $this->capture(
            fn (OllamaProvider $p) => $p->generateSql('how many orders'),
            ['num_ctx' => $configured]
        );

        $this->assertSame(8192, $sent['options']['num_ctx'] ?? null);
    }

    public static function unusableValues(): array
    {
        return [
            'empty env' => [(int) ''],
            'zero' => [0],
            'negative' => [-1],
            'absurdly small' => [16],
        ];
    }

    /**
     * The guard must not fire on ordinary questions.
     */
    #[Test]
    public function a_normal_prompt_is_sent_untouched()
    {
        $sent = $this->capture(fn (OllamaProvider $p) => $p->generateSql('SELECT revenue by region'));

        $this->assertSame('SELECT revenue by region', $sent['prompt'] ?? null);
    }
}
