<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Jayanta\Jeeves\Cache\NullQueryCache;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Engine\PromptBuilder;
use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Engine\QueryVerifier;
use Jayanta\Jeeves\Engine\ResponseFormatter;
use Jayanta\Jeeves\Engine\SqlBuilder;
use Jayanta\Jeeves\Schema\SchemaRegistry;
use Jayanta\Jeeves\Security\InputGuard;
use Jayanta\Jeeves\Security\SqlValidator;
use Jayanta\Jeeves\Tests\TestCase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

class RateLimitHandlingTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeOrchestrator($llm): QueryOrchestrator
    {
        $registry = new SchemaRegistry(__DIR__ . '/../Stubs/schemas');

        return new QueryOrchestrator(
            $llm,
            new NullQueryCache,
            new SqlValidator,
            $registry,
            new SqlBuilder($registry),
            Mockery::mock(PromptBuilder::class)->shouldIgnoreMissing('prompt'),
            new ResponseFormatter,
            new InputGuard,
            Mockery::mock(QueryVerifier::class)->shouldIgnoreMissing()
        );
    }

    #[Test]
    public function a_rate_limited_intent_call_fails_fast_with_an_honest_message()
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldIgnoreMissing();
        $llm->shouldReceive('getName')->andReturn('test');
        // Provider is rate limited on the first (intent) call…
        $llm->shouldReceive('parseIntent')->once()->andReturn([
            'success' => false,
            'error' => 'API rate limit exceeded. Please wait and try again.',
            'status' => 429,
        ]);
        // …and the orchestrator must NOT hammer it with more calls
        // (no sql_generation fallback, no refined-prompt retry).
        $llm->shouldReceive('generateSql')->never();

        $result = $this->makeOrchestrator($llm)->query('Top 10 customers by revenue');

        $this->assertSame('error', $result['status']);
        $this->assertSame(QueryOrchestrator::RATE_LIMIT_MESSAGE, $result['error']);
        // The misleading "could not understand" fallback must not appear
        $this->assertStringNotContainsString('Could not understand', $result['error']);
        // Internal flags never leak to API consumers
        $this->assertArrayNotHasKey('_rate_limited', $result);
        $this->assertArrayNotHasKey('_fallback_eligible', $result);
    }

    #[Test]
    public function a_non_rate_limit_intent_failure_still_falls_back()
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldIgnoreMissing();
        $llm->shouldReceive('getName')->andReturn('test');
        $llm->shouldReceive('parseIntent')->andReturn([
            'success' => false,
            'error' => 'Failed to parse intent response',
        ]);
        // Genuine comprehension failures DO fall back to SQL generation
        $llm->shouldReceive('generateSql')->atLeast()->once()->andReturn([
            'success' => false,
            'error' => 'still failing',
        ]);

        $result = $this->makeOrchestrator($llm)->query('Top 10 customers by revenue');

        $this->assertSame('error', $result['status']);
    }
}
