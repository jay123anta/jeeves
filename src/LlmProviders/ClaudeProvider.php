<?php

namespace Jayanta\Jeeves\LlmProviders;

use Illuminate\Support\Facades\Http;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;

/**
 * Anthropic Claude LLM Provider
 *
 * Text-only provider. AI receives ONLY schema structure, never actual data.
 */
class ClaudeProvider extends AbstractProvider implements LlmProviderInterface
{
    protected string $apiKey;

    protected string $model;

    protected string $baseUrl = 'https://api.anthropic.com/v1';

    public function __construct(array $config = [])
    {
        parent::__construct($config);
        $this->apiKey = $config['api_key'] ?? '';
        $this->model = $config['model'] ?? 'claude-sonnet-4-20250514';
    }

    /**
     * One request shape for both calls, and two things it deliberately omits.
     *
     * The first live Claude call this package ever made returned 400 on every
     * question, for two reasons at once:
     *
     *   "`temperature` is deprecated for this model."
     *   "This model does not support assistant message prefill. The
     *    conversation must end with a user message."
     *
     * Both were sent on every request. The prefill was the older trick for
     * forcing JSON -  send a trailing assistant turn containing "{" and glue it
     * back on afterwards -  and current models refuse it outright. They also do
     * not need it: with "respond with valid JSON only" in the system prompt
     * they return clean JSON, and parseJsonResponse already strips code fences
     * and repairs the usual malformations.
     *
     * So neither is sent. Omitting both is valid on EVERY Claude model, old or
     * new, which is why there is no model sniffing here -  a version check
     * would be one more thing to get wrong the next time the API moves.
     *
     * temperature can be set explicitly for a model that still honours it;
     * null, the default, leaves it out.
     *
     * @return array<string, mixed>
     */
    protected function payload(int $maxTokens, string $system, string $prompt): array
    {
        $payload = [
            'model' => $this->model,
            'max_tokens' => $maxTokens,
            'system' => $system,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];

        $temperature = $this->config['temperature'] ?? null;

        if ($temperature !== null) {
            $payload['temperature'] = (float) $temperature;
        }

        return $payload;
    }

    public function generateSql(string $prompt): array
    {
        $payload = $this->payload(
            512,
            'You are a SQL query generator. You MUST respond with valid JSON only. No markdown, no explanation, no code blocks. Start your response with { and end with }.',
            $prompt
        );

        $response = $this->callWithRetry(
            "{$this->baseUrl}/messages",
            $payload,
            [
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
            ]
        );

        if (!$response['success']) {
            return $response;
        }

        $parsed = $this->parseJsonResponse($response['data']['content'][0]['text'] ?? '');

        if (!$parsed) {
            return ['success' => false, 'error' => 'Invalid JSON response from Claude'];
        }

        return ['success' => true, 'data' => $parsed];
    }

    public function parseIntent(string $text, array $datasetList): array
    {
        $datasetInfo = $this->buildDatasetInfo($datasetList);
        $today = $this->today();

        $prompt = <<<PROMPT
Parse this natural language query about datasets: "{$text}"

AVAILABLE DATASETS:
{$datasetInfo}

Extract: dataset (dataset key), metric, limit (default 10), order (asc/desc), group_value (or null), group_by (or null), confidence (0.0-1.0).

When the user asks HOW MANY -  "how many orders", "number of tickets", "orders by status" -  the metric is record_count. Only pick a money or quantity metric when the user names one.

query_type: "aggregation" when the user wants ONE number for the whole dataset ("total revenue", "how many orders are there") with no breakdown and no named record; "ranking" for a list, which is the usual case; "group_detail" when they named one record.

filters: EVERY narrowing that applies, as [{"column":"region","value":"East"}]. This includes one stated in the question itself -  "how many invoices are pending" is [{"column":"status","value":"pending"}], "sales in the West" is [{"column":"region","value":"West"}]. Match the value to the column it belongs to. In a conversation, also repeat the ones still in force -  a filter you leave out is switched off.

filter_column: the column that group_value belongs to, when it is NOT the column being grouped by. "quantity by customer_name where product_category is Grocery" has group_by=customer_name, group_value=Grocery, filter_column=product_category. Leave null when the filter is on the grouping column itself.

group_by is the column to break results down by when the user asks for one -  "revenue BY REGION", "orders PER STATUS". It must be one of that dataset's group_by columns; use null when no breakdown is named.

Periods are CALENDAR periods unless the user says otherwise: "this year" is 1 January to 31 December of the current year, "last year" the whole of the year before, "last month" the previous calendar month, "last quarter" the previous calendar quarter. Never a rolling window ending today -  comparing a calendar year against a trailing twelve months puts overlapping data on both sides and the difference is meaningless.
date_from / date_to: if the user named a period -  "last month", "in 2025", "since April" -  resolve it to YYYY-MM-DD dates using TODAY'S DATE: {$today}. Both null when no period is mentioned. Never invent a period the user did not ask for.

If unclear, set needs_clarification=true with clarification_type="dataset" or "metric".
Never set needs_clarification for a HOW MANY question. Every dataset has record_count, so "how many X are there" is always answerable without asking.
If the user asks for a breakdown that is NOT in that dataset's group_by list, set needs_clarification=true and clarification_type="ambiguous" -  never fall back to the default breakdown.

Return JSON only: {"dataset":"key","metric":"name","limit":10,"order":"desc","query_type":"ranking","group_value":null,"filter_column":null,"filters":[],"group_by":null,"date_from":null,"date_to":null,"confidence":0.85,"needs_clarification":false,"clarification_type":null}
PROMPT;

        $payload = $this->payload(
            256,
            'You parse natural language queries into structured intent. You MUST respond with valid JSON only. No markdown, no explanation. Start with { and end with }.',
            $prompt
        );

        $response = $this->callWithRetry(
            "{$this->baseUrl}/messages",
            $payload,
            [
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
            ]
        );

        if (!$response['success']) {
            return $this->errorResponse($response['error'], $response['status'] ?? null);
        }

        $parsed = $this->parseJsonResponse($response['data']['content'][0]['text'] ?? '');

        if (!$parsed) {
            return $this->errorResponse('Failed to parse intent response');
        }

        return $this->normalizeIntent($parsed, $datasetList);
    }

    public function healthCheck(): array
    {
        if (empty($this->apiKey)) {
            return ['status' => 'error', 'message' => 'API key not configured'];
        }

        // Claude doesn't have a simple model info endpoint, so we cache aggressively
        if (static::$healthCache !== null && static::$healthCacheTime !== null) {
            if ((time() - static::$healthCacheTime) < static::HEALTH_CACHE_TTL) {
                return static::$healthCache;
            }
        }

        try {
            // Minimal API call to verify key works
            $request = Http::timeout(10)
                ->withHeaders([
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'Content-Type' => 'application/json',
                ]);

            if ($sslOptions = $this->sslOptions()) {
                $request = $request->withOptions($sslOptions);
            }

            $response = $request->post("{$this->baseUrl}/messages", [
                'model' => $this->model,
                'max_tokens' => 10,
                'messages' => [['role' => 'user', 'content' => 'ping']],
            ]);

            $result = $response->successful()
                ? ['status' => 'ok', 'model' => $this->model]
                : ['status' => 'error', 'message' => 'API returned ' . $response->status()];

            static::$healthCache = $result;
            static::$healthCacheTime = time();

            return $result;
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $this->sanitizeError($e->getMessage())];
        }
    }

    public function getName(): string
    {
        return 'claude';
    }

    protected function normalizeIntent(array $parsed, array $datasetList): array
    {
        $dataset = $parsed['dataset'] ?? null;
        if ($dataset) {
            $validKeys = array_column($datasetList, 'key');
            if (!in_array($dataset, $validKeys)) {
                $dataset = null;
            }
        }

        $confidence = max(0.0, min(1.0, floatval($parsed['confidence'] ?? 0.5)));

        return [
            'success' => true,
            'dataset' => $dataset,
            'metric' => $parsed['metric'] ?? null,
            'limit' => min(max(intval($parsed['limit'] ?? 10), 1), config('jeeves.sql.max_limit') ?? 1000),
            // Coalesced ONCE. The old line guarded the in_array check with
            // ?? 'desc' and then re-read the key unguarded in the true branch,
            // so a model that omits 'order' -  DeepSeek does -  passed the check
            // and hit strtolower(null). Gemini always sends it, which is why
            // this survived: three providers carried it and only the tested one
            // never triggered it.
            'order' => $this->normalizeOrder($parsed['order'] ?? null),
            'group_value' => $parsed['group_value'] ?? null,
            'group_by' => $parsed['group_by'] ?? null,
            'filter_column' => $parsed['filter_column'] ?? null,
            'filters' => is_array($parsed['filters'] ?? null) ? $parsed['filters'] : [],
            'query_type' => $parsed['query_type'] ?? null,
            'date_from' => $parsed['date_from'] ?? null,
            'date_to' => $parsed['date_to'] ?? null,
            'confidence' => $confidence,
            'needs_clarification' => $parsed['needs_clarification'] ?? ($confidence < 0.7),
            'clarification_type' => $parsed['clarification_type'] ?? null,
        ];
    }
}
