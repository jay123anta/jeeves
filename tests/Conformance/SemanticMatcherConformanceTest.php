<?php

namespace Jayanta\Jeeves\Tests\Conformance;

use Jayanta\Jeeves\Engine\Semantic\HttpSemanticMatcher;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Does the semantic matcher work against a REAL embedding service?
 *
 * The unit suite proves the client by feeding it a faked HTTP response, and a
 * fake is perfectly happy to return the shape the client already expects. It
 * cannot tell you that the service spells the key `scheme` while the client
 * reads `dataset`, or that the ranking arrives under a field nobody agreed on.
 * Only a live service can, and this file is the only place that asks one.
 *
 * It is SKIPPED unless a service is answering, so it costs a normal run
 * nothing and never turns CI red on a machine that has no container.
 *
 *   JEEVES_SEMANTIC_MATCH_ENDPOINT=http://127.0.0.1:8001 vendor/bin/phpunit \
 *     --filter SemanticMatcherConformanceTest
 *
 * The service this was written against is the on-prem MiniLM matcher from the
 * project this package was extracted from: sentence-transformers, model
 * all-MiniLM-L6-v2, five schemes in its corpus. Its corpus is ITS OWN - the
 * scheme keys below belong to that deployment, not to this package - which is
 * exactly why they make a good test of the allowed-list filter.
 */
class SemanticMatcherConformanceTest extends TestCase
{
    private string $endpoint;

    /** Scheme keys in the reference deployment's corpus. */
    private const CORPUS = ['basundhara_rtps', 'basundhara_3', 'pmayg', 'sbmu', 'bhumiputra'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->endpoint = env('JEEVES_SEMANTIC_MATCH_ENDPOINT', 'http://127.0.0.1:8001');

        if (!$this->serviceIsAnswering()) {
            $this->markTestSkipped(
                'No embedding service at ' . $this->endpoint . '. Start one and re-run; '
                . 'this suite is the only check that the wire format is right.'
            );
        }
    }

    private function serviceIsAnswering(): bool
    {
        $context = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);

        return @file_get_contents(rtrim($this->endpoint, '/') . '/health', false, $context) !== false;
    }

    private function matcher(float $threshold = 0.3): HttpSemanticMatcher
    {
        return new HttpSemanticMatcher(
            endpoint: $this->endpoint,
            threshold: $threshold,
            timeout: 5,
        );
    }

    /**
     * The reference service answers with `scheme`, not `dataset`, and puts its
     * ranking under `top_n`. Both are read, and this is the assertion that
     * would have caught it if they were not.
     */
    #[Test]
    public function the_live_response_shape_is_understood(): void
    {
        $result = $this->matcher()->match('toilet coverage in kamrup', self::CORPUS);

        $this->assertNotNull(
            $result->dataset,
            'a live service ranked this question and the client read nothing out of it - '
            . 'the field names it answers with are not the ones being read'
        );

        $this->assertGreaterThan(0.0, $result->score, 'a real cosine score was read as zero');
        $this->assertNull($result->error);
    }

    /**
     * THE POINT OF THE FEATURE.
     *
     * "how many houses were built" shares no word with "PMAY-G" - not the key,
     * not the name, not any alias. Keyword routing cannot place it and never
     * will. An embedding service places it correctly, and that is the entire
     * argument for this stage existing.
     */
    #[Test]
    public function a_question_sharing_no_word_with_the_dataset_is_still_placed(): void
    {
        $result = $this->matcher(threshold: 0.0)->match('how many houses were built', self::CORPUS);

        $this->assertSame(
            'pmayg',
            $result->dataset,
            'the rural housing scheme was not the closest match for a question about '
            . 'houses being built, so either the corpus or the model has changed'
        );
    }

    /**
     * A second, unrelated question, so the test above is not passing because
     * one scheme happens to win everything.
     */
    #[Test]
    public function a_different_question_reaches_a_different_dataset(): void
    {
        $result = $this->matcher(threshold: 0.0)->match('toilet coverage in kamrup', self::CORPUS);

        $this->assertSame(
            'sbmu',
            $result->dataset,
            'sanitation question did not reach the sanitation scheme - the matcher is '
            . 'returning the same answer regardless of the question'
        );
    }

    /**
     * The threshold is applied in PHP, not in the service. Retuning confidence
     * must never mean redeploying a container - so the SAME live response has
     * to read as confident or not depending only on local configuration.
     */
    #[Test]
    public function the_threshold_is_applied_on_this_side(): void
    {
        $question = 'toilet coverage in kamrup';

        $generous = $this->matcher(threshold: 0.0)->match($question, self::CORPUS);
        $strict = $this->matcher(threshold: 0.99)->match($question, self::CORPUS);

        $this->assertTrue($generous->confident);
        $this->assertFalse(
            $strict->confident,
            'a score below the configured threshold was still reported confident'
        );

        $this->assertSame(
            $generous->dataset,
            $strict->dataset,
            'the two calls disagreed about the ranking itself, which means this test is '
            . 'measuring service noise rather than the threshold'
        );

        $this->assertEqualsWithDelta(
            $generous->score,
            $strict->score,
            0.0001,
            'the same question scored differently on two calls'
        );
    }

    /**
     * Real absolute scores on this model are LOW. The shipped default of 0.3
     * exists because of exactly this, and a package that defaulted to the
     * instinctive 0.5 would reject correct routes on a working service.
     */
    #[Test]
    public function the_shipped_default_threshold_suits_the_scores_this_model_produces(): void
    {
        $best = $this->matcher(threshold: 0.0)->match('toilet coverage in kamrup', self::CORPUS)->score;

        $this->assertLessThan(
            0.5,
            $best,
            'scores are higher than the shipped default was tuned for - re-check the '
            . '0.3 default in config/jeeves.php against this deployment'
        );
    }

    /**
     * The corpus is maintained separately from any install's schema files. A
     * service that ranks a dataset this install does not register must not be
     * able to route to it, however sure it is.
     */
    #[Test]
    public function a_dataset_outside_this_install_is_discarded(): void
    {
        $result = $this->matcher(threshold: 0.0)
            ->match('how many houses were built', ['orders', 'support_tickets']);

        $this->assertNull(
            $result->dataset,
            'a live service named a scheme belonging to another application and the '
            . 'client accepted it'
        );
        $this->assertFalse($result->confident);
    }

    /** An empty allowed list means there is nothing to route to. */
    #[Test]
    public function nothing_registered_means_nothing_matched(): void
    {
        $result = $this->matcher()->match('how many houses were built', []);

        $this->assertNull($result->dataset);
        $this->assertNull($result->error, 'a request was made when there was nothing to choose from');
    }

    #[Test]
    public function candidates_come_back_ranked_best_first(): void
    {
        $candidates = $this->matcher(threshold: 0.0)
            ->match('toilet coverage in kamrup', self::CORPUS)
            ->candidates;

        $this->assertGreaterThan(1, count($candidates), 'only one candidate, so ordering proves nothing');

        $scores = array_column($candidates, 'score');
        $sorted = $scores;
        rsort($sorted);

        $this->assertSame($sorted, $scores, 'candidates were not ordered best first');
    }

    #[Test]
    public function a_reachable_service_reports_itself_available(): void
    {
        $this->assertTrue($this->matcher()->isAvailable());
    }

    /**
     * The failure path, against a real socket rather than a faked one: nothing
     * is listening on this port, and the client must return a no-match instead
     * of letting the exception reach the query path.
     */
    #[Test]
    public function an_address_with_nothing_behind_it_degrades_instead_of_throwing(): void
    {
        $matcher = new HttpSemanticMatcher(
            endpoint: 'http://127.0.0.1:1',
            threshold: 0.3,
            timeout: 2,
        );

        $result = $matcher->match('how many houses were built', self::CORPUS);

        $this->assertNull($result->dataset);
        $this->assertFalse($result->confident);
        $this->assertSame('unreachable', $result->error);
        $this->assertFalse($matcher->isAvailable());
    }
}
