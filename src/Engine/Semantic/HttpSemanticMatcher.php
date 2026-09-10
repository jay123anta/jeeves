<?php

namespace Jayanta\Jeeves\Engine\Semantic;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Jayanta\Jeeves\Contracts\SemanticMatcherInterface;
use Jayanta\Jeeves\Support\QuestionForLog;

/**
 * Asks an embedding service which dataset a question is about.
 *
 * The service is YOURS. This package ships no model and starts no container:
 * bundling a sentence-transformer would add hundreds of megabytes to a Laravel
 * package for a feature most installs will never switch on. What ships is the
 * client and the configuration, so an install that already runs such a service
 * - or wants to - can point Jeeves at it in one env var.
 *
 * WIRE FORMAT. POST {endpoint}{path} with `{"query": ..., "top_n": 3}`, and a
 * ranked list back. Candidates are read from `top_n`, `candidates` or
 * `matches`, and each candidate's key from `dataset` or `scheme`, because the
 * service this was modelled on speaks the second spelling. Cosine scores are
 * expected in 0..1; the THRESHOLD IS APPLIED HERE, not there, so retuning
 * confidence never means redeploying the service.
 *
 * Rule 2. The request carries the question text and nothing else. The allowed
 * dataset keys are filtered on THIS side deliberately - sending them would
 * disclose the shape of an install to a service that does not need it to rank,
 * and the filter has to run here anyway to catch a corpus naming a dataset
 * this install no longer registers.
 *
 * Rule 0. Every failure is a no-match, never an exception. This stage sits in
 * front of the LLM fallback, so a service that is down, slow, or returning
 * nonsense must cost the question nothing but the timeout - the answer still
 * arrives, by the same route it took before semantic matching existed.
 */
class HttpSemanticMatcher implements SemanticMatcherInterface
{
    public function __construct(
        protected string $endpoint,
        protected float $threshold = 0.3,
        protected int $timeout = 2,
        protected string $path = '/match-scheme',
    ) {
        $this->endpoint = rtrim($endpoint, '/');
        $this->path = '/' . ltrim($path, '/');
    }

    public function match(string $query, array $allowed = []): SemanticMatchResult
    {
        $query = trim($query);

        if ($query === '') {
            return SemanticMatchResult::none();
        }

        // Nothing to choose from. Asking anyway would spend the timeout to
        // learn that every answer is going to be discarded below.
        if ($allowed === []) {
            return SemanticMatchResult::none();
        }

        try {
            $response = Http::timeout($this->timeout)
                ->acceptJson()
                ->post($this->endpoint . $this->path, [
                    'query' => $query,
                    'top_n' => 3,
                ]);

            if (!$response->successful()) {
                Log::warning('[Jeeves] Semantic matcher returned an error status', [
                    'status' => $response->status(),
                ]);

                return SemanticMatchResult::none('http_' . $response->status());
            }

            $candidates = $this->readCandidates($response->json());
        } catch (\Throwable $e) {
            // Connection refused, DNS, TLS, timeout. The message can name the
            // host and port, which is operator information rather than data,
            // and it never reaches the user - only this log.
            Log::warning('[Jeeves] Semantic matcher unreachable', [
                'error' => $e->getMessage(),
                'question' => QuestionForLog::text($query),
            ]);

            return SemanticMatchResult::none('unreachable');
        }

        // A corpus is maintained separately from this install's schema files
        // and drifts from them. Ranking a dataset that is no longer registered
        // is not an error there; acting on it would be one here.
        $candidates = array_values(array_filter(
            $candidates,
            fn (array $c) => in_array($c['dataset'], $allowed, true)
        ));

        if ($candidates === []) {
            return SemanticMatchResult::none();
        }

        $best = $candidates[0];

        return new SemanticMatchResult(
            dataset: $best['dataset'],
            score: $best['score'],
            confident: $best['score'] >= $this->threshold,
            candidates: $candidates,
        );
    }

    public function isAvailable(): bool
    {
        try {
            return Http::timeout($this->timeout)->get($this->endpoint . '/health')->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Normalise whatever the service called its ranking into one shape.
     *
     * @return array<int, array{dataset: string, score: float}>
     */
    protected function readCandidates(mixed $body): array
    {
        if (!is_array($body)) {
            return [];
        }

        $raw = $body['top_n'] ?? $body['candidates'] ?? $body['matches'] ?? [];

        if (!is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $key = $candidate['dataset'] ?? $candidate['scheme'] ?? null;

            if (!is_string($key) || $key === '') {
                continue;
            }

            $out[] = ['dataset' => $key, 'score' => (float) ($candidate['score'] ?? 0.0)];
        }

        // The service is expected to rank, but a client that trusts ordering
        // it did not impose will silently route on the second-best match the
        // day a service returns insertion order instead.
        usort($out, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return $out;
    }
}
