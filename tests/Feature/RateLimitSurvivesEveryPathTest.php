<?php

namespace Jayanta\Jeeves\Tests\Feature;

use Jayanta\Jeeves\Contracts\LlmProviderInterface;
use Jayanta\Jeeves\Engine\ErrorCode;
use Jayanta\Jeeves\Engine\QueryOrchestrator;
use Jayanta\Jeeves\Tests\Support\RecordingProvider;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * A 429 must reach the caller as a 429, from wherever it happens.
 *
 * The package promises this in docs/TROUBLESHOOTING.md -  it "fails fast on 429,
 * it deliberately does not fall back or retry the whole query" -  and
 * docs/API.md tells clients to branch on error_code, with rate_limited mapped
 * to HTTP 429, retryable, Retry-After set. A rate limit reported as anything
 * else is an active instruction to every SDK and queue worker NOT to back off,
 * on the one fault where backing off is the whole remedy.
 *
 * Enumerating the paths by which a provider 429 can reach the caller, because
 * fixing them one at a time is how this release has spent its entire week:
 *
 *   plan call ................. guarded (added earlier in this release)
 *   intent call ............... guarded
 *   SQL generation ............ guarded via providerFailure()
 *   refinement retry .......... guarded via providerFailure()
 *   dataset identification .... NOT GUARDED -  the response was read only for
 *                               ['dataset'], so a 429 was discarded and
 *                               generateSql called immediately afterwards
 *   step loop in runSteps ..... NOT GUARDED -  every step ran regardless, so
 *                               one rate limit became N more calls, and the
 *                               multi-step envelope carried no error_code at
 *                               all, so the controller produced HTTP 500 with
 *                               retryable:false
 *
 * The last two are the ones beside the one that was just fixed. Again.
 */
class RateLimitSurvivesEveryPathTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('jeeves.schema.config_path', __DIR__ . '/../Stubs/fuzzy-cache-isolation-schemas');
        $app['config']->set('jeeves.cache.enabled', false);
        $app['config']->set('jeeves.chat.multi_step', true);
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
    }

    private const LIMIT = ['success' => false, 'error' => 'Too Many Requests', 'status' => 429];

    private function limited(): RecordingProvider
    {
        $provider = new RecordingProvider;
        $provider->sqlResponse = self::LIMIT;
        $provider->intentResponse = self::LIMIT;
        $this->app->instance(LlmProviderInterface::class, $provider);

        return $provider;
    }

    /**
     * Planning SUCCEEDS, then the steps are rate limited.
     *
     * This is the shape that reaches the step loop, and it is the ordinary
     * shape of a per-minute quota: the small plan prompt gets through and the
     * two full-schema step prompts do not. A provider that is limited from the
     * first call never reaches the loop at all, because the plan-429 guard
     * added earlier in this release catches it -  which is exactly how the loop
     * came to be missed.
     */
    private function limitedAfterPlanning(): RecordingProvider
    {
        $provider = new RecordingProvider;
        $n = 0;
        $provider->sqlResponse = function () use (&$n) {
            $n++;

            return $n === 1
                ? ['success' => true, 'data' => ['steps' => [
                    'total revenue for nq_orders',
                    'total revenue for nq_products',
                ]]]
                : self::LIMIT;
        };
        $provider->intentResponse = self::LIMIT;
        $this->app->instance(LlmProviderInterface::class, $provider);

        return $provider;
    }

    /**
     * The dataset-identification call. Its response was consumed as
     * `$intent['dataset'] ?? null` -  a 429 has no dataset, so it read null and
     * fell through to generateSql, spending a second call on a provider that
     * had just said stop.
     */
    #[Test]
    public function a_429_identifying_the_dataset_is_reported_not_stepped_over()
    {
        config(['jeeves.query_mode' => 'sql_generation']);
        $provider = $this->limited();

        // Wording that names no dataset, so identification must ask the model.
        $result = $this->app->make(QueryOrchestrator::class)->query('give me the overall figure');

        $this->assertSame(
            ErrorCode::RATE_LIMITED,
            $result['error_code'] ?? null,
            'a rate limit while identifying the dataset was reported as something else: ' . json_encode($result)
        );
        $this->assertCount(
            1,
            $provider->methodsCalled(),
            'the provider said 429 and was called again anyway: ' . json_encode($provider->methodsCalled())
        );
    }

    /**
     * The step loop. One rate limit must stop the run, not authorise N more
     * calls, and the envelope must say rate_limited so the controller answers
     * 429 with Retry-After instead of 500 with retryable:false.
     */
    #[Test]
    public function a_429_in_a_step_stops_the_run_and_is_reported_as_a_rate_limit()
    {
        config(['jeeves.query_mode' => 'sql_generation']);
        $provider = $this->limitedAfterPlanning();

        $result = $this->app->make(QueryOrchestrator::class)
            ->query('compare revenue for nq_orders with nq_products');

        $this->assertSame(
            ErrorCode::RATE_LIMITED,
            $result['error_code'] ?? null,
            'a decomposed question hit a rate limit and reported it as something with no error_code at all, '
                . 'so the controller returns HTTP 500 retryable:false and every client is told not to back '
                . 'off: ' . json_encode($result)
        );

        // plan + step 1. The second step must not be attempted: the provider
        // has already said it is over quota, and asking again extends the
        // window that is the entire reason for backing off.
        $this->assertCount(
            2,
            $provider->methodsCalled(),
            'the run carried on making calls after a 429: ' . json_encode($provider->methodsCalled())
        );
    }

    /** Over the real HTTP route: 429, retryable, Retry-After. */
    #[Test]
    public function the_http_response_for_a_decomposed_question_is_a_429()
    {
        config(['jeeves.query_mode' => 'sql_generation']);
        $this->limitedAfterPlanning();

        $response = $this->postJson('/jeeves/text', [
            'text' => 'compare revenue for nq_orders with nq_products',
        ]);

        $response->assertStatus(429);
        $this->assertSame(ErrorCode::RATE_LIMITED, $response->json('error_code'));
        $this->assertTrue($response->json('retryable'), 'clients were told not to retry a rate limit');
        $this->assertNotEmpty($response->headers->get('Retry-After'));
    }

    /**
     * The guarantee this must not cost: a step that fails for an ordinary
     * reason still produces a multi-step answer built from whatever succeeded.
     */
    #[Test]
    public function an_ordinary_step_failure_still_yields_a_multi_step_answer()
    {
        config(['jeeves.query_mode' => 'sql_generation']);

        $provider = new RecordingProvider;
        $n = 0;
        $provider->sqlResponse = function () use (&$n) {
            $n++;

            // 1 = the plan. 2 = first step, fine. 3 = second step, ordinary fault.
            if ($n === 1) {
                return ['success' => true, 'data' => ['steps' => ['total revenue for nq_orders', 'total revenue for nq_products']]];
            }
            if ($n === 3) {
                return ['success' => false, 'error' => 'model returned nonsense'];
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
            ->query('compare revenue for nq_orders with nq_products');

        $this->assertNotSame(
            ErrorCode::RATE_LIMITED,
            $result['error_code'] ?? null,
            'an ordinary step failure was misreported as a rate limit'
        );
    }
}
