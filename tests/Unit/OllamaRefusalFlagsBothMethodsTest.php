<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Illuminate\Support\Facades\Http;
use Jayanta\Jeeves\LlmProviders\OllamaProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Both methods that refuse must SAY they refused.
 *
 * `willNotFit()` guards two call sites -  generateSql() and parseIntent(). The
 * NQ-002 fix taught the orchestrator not to retry a refusal that never reached
 * the wire, keyed on a `refused_before_sending` flag, and set that flag in
 * generateSql(). parseIntent() was left returning a bare errorResponse().
 *
 * So an intent-mode context refusal still looked, to the orchestrator, exactly
 * like a model that answered badly -  and the answer to a model that answered
 * badly is retryWithRefinedPrompt(), which sends a SMALLER single-dataset
 * prompt. That prompt clears the same guard, gets answered, and the refusal the
 * user should have seen becomes a confident number from one table.
 *
 * Which is precisely the defect NQ-002 was written to close, surviving on the
 * call site NQ-002 did not visit. Three independent reviewers found it.
 *
 * The lesson is structural rather than local: the flag belongs to the REFUSAL,
 * not to the method that happens to produce it. A guard attached per-call-site
 * gives every future call site a fresh chance to forget.
 */
class OllamaRefusalFlagsBothMethodsTest extends TestCase
{
    public static function refusingCalls(): array
    {
        return [
            'generateSql' => [fn (OllamaProvider $p) => $p->generateSql(str_repeat('A', 30000))],
            'parseIntent' => [fn (OllamaProvider $p) => $p->parseIntent(
                str_repeat('A', 30000),
                [['key' => 'orders', 'name' => 'Orders']]
            )],
        ];
    }

    /**
     * Every path through willNotFit() must mark the result unretryable, or the
     * orchestrator retries it with a smaller prompt that fits.
     */
    #[DataProvider('refusingCalls')]
    #[Test]
    public function a_context_refusal_says_it_never_reached_the_wire(callable $call)
    {
        Http::fake(['*' => Http::response(['response' => '{"dataset":"orders"}'], 200)]);

        $result = $call(new OllamaProvider(['model' => 'llama3', 'num_ctx' => 2048]));

        Http::assertNothingSent();

        $this->assertFalse($result['success'] ?? true, 'the oversized prompt was not refused at all');
        $this->assertTrue(
            $result['refused_before_sending'] ?? false,
            'this refusal is indistinguishable from a bad model answer, so the orchestrator will retry it '
                . 'with a smaller prompt that fits -  which is the wrong answer NQ-002 exists to prevent'
        );
    }

    /**
     * And the message must still name the lever, on both paths.
     */
    #[DataProvider('refusingCalls')]
    #[Test]
    public function the_refusal_still_names_the_setting(callable $call)
    {
        Http::fake(['*' => Http::response(['response' => '{}'], 200)]);

        $result = $call(new OllamaProvider(['model' => 'llama3', 'num_ctx' => 2048]));

        $this->assertStringContainsString('num_ctx', $result['error'] ?? '');
    }
}
