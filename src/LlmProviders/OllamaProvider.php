<?php

namespace Jayanta\Jeeves\LlmProviders;

use Illuminate\Support\Facades\Http;
use Jayanta\Jeeves\Contracts\LlmProviderInterface;

/**
 * Ollama LLM Provider (local/self-hosted models)
 *
 * Runs entirely locally - no data ever leaves the user's machine.
 * Supports any model available in Ollama (llama3, mistral, codellama, etc.)
 */
class OllamaProvider extends AbstractProvider implements LlmProviderInterface
{
    /**
     * Bytes per token, for deciding whether a prompt fits.
     *
     * Three, not the usual four, and the difference is the safety margin. Two
     * reasons, both pointing the same way:
     *
     * English prose runs about four characters per token, but a schema prompt
     * is mostly short identifiers, types and punctuation, which tokenize worse
     * -  nearer three and a half. Assuming four would leave no headroom on
     * exactly the content this prompt is made of.
     *
     * And this counts BYTES, not characters. A Devanagari or CJK character in
     * a schema description is three bytes and still costs roughly one token,
     * so bytes/4 would under-count it by about a third. At bytes/3 that case
     * lands near one token per character, which is about right.
     *
     * Erring low here means sending a prompt that gets silently truncated,
     * which is the bug this guard exists to stop. Erring high means refusing a
     * prompt that would have fitted, and saying exactly which setting to
     * raise. The second is the one to prefer.
     */
    private const BYTES_PER_TOKEN = 3;

    /**
     * Context size when the config does not say.
     *
     * Ollama's own default is 4096 in current builds and 2048 in older ones.
     * Both are too small for an ordinary multi-table Laravel app once the
     * schema block is included, so leaving it to the server means silent
     * truncation on a normal install. 8192 covers a realistic schema with room
     * for the answer and is still modest for the KV cache.
     */
    private const DEFAULT_NUM_CTX = 8192;

    /**
     * Smallest context worth attempting. Below this nothing useful fits, and a
     * value this low is a misconfiguration rather than a choice.
     */
    private const MIN_NUM_CTX = 1024;

    protected string $baseUrl;

    protected string $model;

    protected int $numCtx;

    public function __construct(array $config = [])
    {
        parent::__construct($config);
        $this->baseUrl = rtrim($config['base_url'] ?? 'http://localhost:11434', '/');
        $this->model = $config['model'] ?? 'llama3';
        // ?? alone is not enough. `OLLAMA_NUM_CTX=` with nothing after it is a
        // present-but-empty env value, Laravel hands back '', and (int) '' is
        // 0 -  which would refuse every question ever asked, with an error
        // naming the setting the user had just edited. Anything unusable falls
        // back to the default rather than bricking the driver.
        $configured = (int) ($config['num_ctx'] ?? 0);
        $this->numCtx = $configured >= self::MIN_NUM_CTX ? $configured : self::DEFAULT_NUM_CTX;
    }

    /**
     * Why a prompt cannot be sent, or null if it can.
     *
     * Ollama does not reject an oversized prompt. It drops the beginning and
     * answers from the remainder -  and the beginning is where the schema is.
     * The result is a confident answer built on table definitions the model
     * never saw, which is the failure this package exists to prevent, arriving
     * with nothing on the wire to show it happened.
     *
     * So the check happens here, before the call, and refuses.
     */
    private function willNotFit(string $prompt, int $numPredict): ?string
    {
        $needed = (int) ceil(strlen($prompt) / self::BYTES_PER_TOKEN) + $numPredict;

        if ($needed <= $this->numCtx) {
            return null;
        }

        return sprintf(
            'This question needs about %s tokens of context but num_ctx is %d, so Ollama '
            . 'would silently discard the start of the prompt -  the schema -  and answer from '
            . 'what was left. Raise num_ctx (llm.providers.ollama.num_ctx, or '
            . 'OLLAMA_NUM_CTX) if the machine has the memory, use a model with a larger '
            . 'context, or describe fewer tables in config/jeeves-schemas.',
            number_format($needed),
            $this->numCtx
        );
    }

    /**
     * Mark a response as one that never reached the wire.
     *
     * Nothing was sent, so a retry with a smaller prompt would not be
     * correcting a bad answer -  it would be asking, and answering, a
     * different question. QueryOrchestrator reads this to decline retrying.
     *
     * It exists as a helper because the flag belongs to the REFUSAL, not to
     * whichever method produced it. It was first added inline in generateSql()
     * and parseIntent() was missed, so an intent-mode context refusal still
     * looked like a model answering badly and was retried into exactly the
     * confident wrong number the flag was introduced to prevent. Two call
     * sites, one of them forgotten, within a single change.
     */
    private function refused(array $response): array
    {
        $response['refused_before_sending'] = true;

        return $response;
    }

    public function generateSql(string $prompt): array
    {
        if ($tooBig = $this->willNotFit($prompt, 512)) {
            return $this->refused(['success' => false, 'error' => $tooBig]);
        }

        $payload = [
            'model' => $this->model,
            'prompt' => $prompt,
            'stream' => false,
            'format' => 'json',
            'options' => [
                'temperature' => 0.1,
                'num_predict' => 512,
                'num_ctx' => $this->numCtx,
            ],
        ];

        $response = $this->callWithRetry("{$this->baseUrl}/api/generate", $payload);

        if (!$response['success']) {
            return $response;
        }

        $text = $response['data']['response'] ?? '';
        $parsed = $this->parseJsonResponse($text);

        if (!$parsed) {
            return ['success' => false, 'error' => 'Invalid JSON response from Ollama'];
        }

        return ['success' => true, 'data' => $parsed];
    }

    public function parseIntent(string $text, array $datasetList): array
    {
        $datasetInfo = $this->buildDatasetInfo($datasetList);
        $today = $this->today();

        $prompt = <<<PROMPT
Parse this query: "{$text}"

DATASETS:
{$datasetInfo}

query_type = "aggregation" for one number over the whole dataset ("total revenue", "how many orders"), else "ranking".
filters = EVERY narrowing, as [{"column":..,"value":..}] -  including one stated in the question: "how many invoices are pending" is [{"column":"status","value":"pending"}]. In a conversation repeat the ones still in force; omitting one switches it off.
filter_column = the column group_value belongs to when it is not the grouping column ("by customer_name where product_category is Grocery").
group_by = the column to break results down by if the user asked ("revenue BY REGION"), from that dataset's group_by list, else null.
Never ask for clarification on a HOW MANY question -  record_count always exists.
metric = record_count when the user asks HOW MANY ("how many orders", "orders by status"), otherwise the named measure.
Periods are CALENDAR periods unless the user says otherwise: "this year" is 1 January to 31 December of the current year, "last year" the whole of the year before, "last month" the previous calendar month, "last quarter" the previous calendar quarter. Never a rolling window ending today -  comparing a calendar year against a trailing twelve months puts overlapping data on both sides and the difference is meaningless.
date_from / date_to = YYYY-MM-DD dates if the user named a period ("last month", "in 2025"), else null. TODAY IS {$today}.

Return JSON: {"dataset":"key","metric":"name","limit":10,"order":"desc","query_type":"ranking","group_value":null,"filter_column":null,"filters":[],"group_by":null,"date_from":null,"date_to":null,"confidence":0.85,"needs_clarification":false,"clarification_type":null}
PROMPT;

        if ($tooBig = $this->willNotFit($prompt, 256)) {
            return $this->refused($this->errorResponse($tooBig));
        }

        $payload = [
            'model' => $this->model,
            'prompt' => $prompt,
            'stream' => false,
            'format' => 'json',
            'options' => [
                'temperature' => 0.1,
                'num_predict' => 256,
                'num_ctx' => $this->numCtx,
            ],
        ];

        $response = $this->callWithRetry("{$this->baseUrl}/api/generate", $payload);

        if (!$response['success']) {
            return $this->errorResponse($response['error'], $response['status'] ?? null);
        }

        $responseText = $response['data']['response'] ?? '';
        $parsed = $this->parseJsonResponse($responseText);

        if (!$parsed) {
            return $this->errorResponse('Failed to parse intent response');
        }

        return $this->normalizeIntent($parsed, $datasetList);
    }

    public function healthCheck(): array
    {
        if (static::$healthCache !== null && static::$healthCacheTime !== null) {
            if ((time() - static::$healthCacheTime) < static::HEALTH_CACHE_TTL) {
                return static::$healthCache;
            }
        }

        try {
            $response = Http::timeout(5)->get("{$this->baseUrl}/api/tags");

            $result = $response->successful()
                ? ['status' => 'ok', 'model' => $this->model]
                : ['status' => 'error', 'message' => 'Ollama not reachable'];

            static::$healthCache = $result;
            static::$healthCacheTime = time();

            return $result;
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => 'Ollama not running at ' . $this->baseUrl];
        }
    }

    public function getName(): string
    {
        return 'ollama';
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
