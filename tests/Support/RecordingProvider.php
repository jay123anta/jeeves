<?php

namespace Jayanta\Jeeves\Tests\Support;

use Jayanta\Jeeves\Contracts\LlmProviderInterface;

/**
 * A provider that records everything that would go over the wire.
 *
 * This is the wire tap for PrivacyWallTest: LlmProviderInterface is documented
 * as "the ONLY point where external AI services are contacted", so anything
 * that leaves the server passes through one of these methods. Every argument of
 * every call is captured verbatim, which is what the privacy assertions search.
 */
class RecordingProvider implements LlmProviderInterface
{
    /** @var array<int, array{method: string, args: array}> */
    public array $calls = [];

    /** Canned response for generateSql(), or a callable receiving the prompt. */
    public $sqlResponse;

    /** Canned response for parseIntent(), or a callable receiving the text. */
    public $intentResponse;

    public function generateSql(string $prompt): array
    {
        $this->calls[] = ['method' => 'generateSql', 'args' => ['prompt' => $prompt]];

        return $this->resolve($this->sqlResponse, $prompt) ?? [
            'success' => true,
            'data' => [
                'sql' => 'SELECT buyer, total FROM nq_privacy_orders ORDER BY total DESC LIMIT 10',
                'dataset' => 'privacy_orders',
                'metric' => 'total',
                'query_type' => 'ranking',
                'explanation' => 'Orders ranked by total',
            ],
        ];
    }

    public function parseIntent(string $text, array $datasetList): array
    {
        $this->calls[] = ['method' => 'parseIntent', 'args' => ['text' => $text, 'datasetList' => $datasetList]];

        return $this->resolve($this->intentResponse, $text) ?? [
            'success' => true,
            'dataset' => 'privacy_orders',
            'metric' => 'total',
            'limit' => 10,
            'order' => 'desc',
            'group_value' => null,
            'confidence' => 0.95,
            'needs_clarification' => false,
        ];
    }

    public function healthCheck(): array
    {
        return ['status' => 'ok', 'model' => 'recording-fake'];
    }

    public function getName(): string
    {
        return 'recording';
    }

    /**
     * Everything this provider was asked to transmit, as one searchable string.
     */
    public function transmitted(): string
    {
        return json_encode($this->calls, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<int, string> */
    public function methodsCalled(): array
    {
        return array_column($this->calls, 'method');
    }

    public function reset(): void
    {
        $this->calls = [];
    }

    private function resolve($response, string $input): ?array
    {
        if ($response === null) {
            return null;
        }

        return is_callable($response) ? $response($input) : $response;
    }
}
