<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Tests\Support\RecordingProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * A rate limit during planning must not be answered with another call.
 *
 * QueryPlanner treats its own failure as non-fatal on purpose -  planning is an
 * enhancement, and a question a planner could not decompose is still a question
 * the ordinary path can answer. That is right for a malformed plan or a model
 * that returned nonsense.
 *
 * It is wrong for a 429, and the package says so in as many words:
 * docs/TROUBLESHOOTING.md promises the package "fails fast on 429 -  it
 * deliberately does not fall back or retry the whole query, since more calls
 * would extend the rate-limit window." On a comparison question the opposite
 * happened. Planning burned a call, hit the limit, dropped `$plan['error']`
 * without reading it, and the ordinary path immediately made another -  against
 * a provider that had just said stop.
 *
 * The user then saw whatever the SECOND call produced. When that one was also
 * limited, the message was at least still about rate limiting; when it happened
 * to succeed, the comparison silently became a single-part answer.
 *
 * The planner's return had no way to say which kind of failure it was -  the
 * HTTP status was dropped one level down -  so the caller could not have
 * distinguished them even if it had looked.
 */
class PlanningRateLimitDoesNotFallThroughTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/single-dataset-schemas');
        $app['config']->set('jeeves.cache.enabled', false);
        $app['config']->set('jeeves.chat.multi_step', true);
    }

    /** "compare" makes it a planning question; the planning call is refused. */
    #[Test]
    public function a_429_while_planning_is_reported_rather_than_retried_as_a_single_query()
    {
        $provider = new RecordingProvider;
        $provider->sqlResponse = ['success' => false, 'error' => 'Too Many Requests', 'status' => 429];
        $provider->intentResponse = ['success' => false, 'error' => 'Too Many Requests', 'status' => 429];
        $this->app->instance(LlmProviderInterface::class, $provider);

        $result = $this->app->make(QueryOrchestrator::class)
            ->query('compare revenue this year with last year');

        $this->assertCount(
            1,
            $provider->methodsCalled(),
            'the provider returned 429 while planning and the package called it again anyway, extending '
                . 'the rate-limit window it had just been told about: ' . json_encode($provider->methodsCalled())
        );
        $this->assertSame('error', $result['status'] ?? null, json_encode($result));
        $this->assertSame(
            'rate_limited',
            $result['error_code'] ?? null,
            'a rate limit was reported as something else, so the user is told to reword a question that '
                . 'was never the problem: ' . json_encode($result)
        );
    }

    /**
     * The guarantee it must not cost: an ordinary planning failure is still
     * non-fatal, and the question is still answered the single-query way.
     */
    #[Test]
    public function an_ordinary_planning_failure_still_falls_back_to_a_single_query()
    {
        $provider = new RecordingProvider;
        $calls = 0;
        $provider->sqlResponse = function () use (&$calls) {
            $calls++;

            // First call is the planner: unusable, but not a provider fault.
            if ($calls === 1) {
                return ['success' => true, 'data' => ['steps' => []]];
            }

            return [
                'success' => true,
                'data' => [
                    'sql' => 'SELECT SUM(revenue) AS revenue FROM nq_orders',
                    'dataset' => 'nq_orders',
                    'query_type' => 'aggregation',
                ],
            ];
        };
        $this->app->instance(LlmProviderInterface::class, $provider);

        $result = $this->app->make(QueryOrchestrator::class)
            ->query('compare revenue this year with last year');

        $this->assertGreaterThan(
            1,
            count($provider->methodsCalled()),
            'a planning failure that is not a provider fault must still leave the question answerable'
        );
        $this->assertNotSame(
            'rate_limited',
            $result['error_code'] ?? null,
            'an unusable plan was misreported as a rate limit'
        );
    }
}
