<?php

namespace Jayanta\Jeeves\LlmProviders;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Jayanta\Jeeves\Contracts\ReportsUsage;
use Jayanta\Jeeves\Schema\DatasetCatalog;

/**
 * Abstract LLM Provider
 *
 * Common functionality shared across all AI providers:
 * - Retry logic with exponential backoff
 * - Response JSON parsing (strips markdown code blocks)
 * - Error sanitization (removes API keys from messages)
 * - Health check caching
 */
abstract class AbstractProvider implements ReportsUsage
{
    protected array $config;

    /** Running token counts for this request. @var array<string, int> */
    protected array $usage = [];

    protected int $maxRetries;

    protected int $timeout;

    /** @var array|null Cached health check result */
    protected static ?array $healthCache = null;

    protected static ?int $healthCacheTime = null;

    protected const HEALTH_CACHE_TTL = 60;

    /**
     * Fallback retry policy, used when config/jeeves.php has no 'retry'
     * block (e.g. an app published the config before this feature existed).
     * Deliberately conservative: a web request must not hang on a struggling
     * provider -  PHP-FPM workers are a finite pool.
     */
    protected const RETRY_DEFAULTS = [
        'base_delay_ms' => 250,
        'max_delay_ms' => 2000,
        'total_budget_ms' => 4000,
        'respect_retry_after' => true,
        'jitter' => true,
    ];

    /** @var int Milliseconds slept so far within the current callWithRetry() */
    protected int $retryWaitedMs = 0;

    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->maxRetries = $config['max_retries'] ?? 3;
        $this->timeout = $config['timeout'] ?? 30;
    }

    /**
     * Make an API call, retrying transient failures with capped backoff.
     *
     * Waiting is bounded on THREE axes so a provider outage can never pin a
     * worker: per-wait cap (max_delay_ms), cumulative cap (total_budget_ms),
     * and attempt count (the provider's max_retries). When the budget cannot
     * cover the next wait, the call fails immediately rather than sleeping.
     */
    protected function callWithRetry(string $url, array $payload, array $headers = []): array
    {
        $lastError = null;
        $lastStatus = null;
        $this->retryWaitedMs = 0;

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            try {
                $request = Http::timeout($this->timeout)
                    ->withHeaders(array_merge(['Content-Type' => 'application/json'], $headers));

                // SSL: system CA bundle by default. On setups without one
                // (e.g. XAMPP), point ssl_verify at a cacert.pem path instead
                // of disabling verification -  false enables MITM attacks.
                if ($sslOptions = $this->sslOptions()) {
                    $request = $request->withOptions($sslOptions);
                }

                $response = $request->post($url, $payload);

                if ($response->successful()) {
                    $body = $response->json();
                    $this->recordUsage(is_array($body) ? $body : []);

                    return ['success' => true, 'data' => $body];
                }

                $status = $response->status();

                // Rate limited: honour Retry-After when the budget allows it,
                // otherwise fall back to capped exponential backoff.
                if ($status === 429) {
                    $retryAfter = $this->parseRetryAfter($response->header('Retry-After'));
                    $waited = $this->waitBeforeRetry($attempt, $retryAfter);

                    if ($waited !== null) {
                        Log::warning("[Jeeves:{$this->getName()}] Rate limited (429), attempt {$attempt}/{$this->maxRetries}, waited {$waited}ms");

                        continue;
                    }

                    return [
                        'success' => false,
                        'error' => 'API rate limit exceeded. Please wait and try again.',
                        'status' => 429,
                    ];
                }

                // 5xx responses are usually transient (provider hiccup, upstream
                // timeout) -  worth one bounded retry. 4xx are not: a bad key or
                // malformed request will fail identically every time.
                if ($status >= 500 && ($waited = $this->waitBeforeRetry($attempt)) !== null) {
                    Log::warning("[Jeeves:{$this->getName()}] HTTP {$status}, attempt {$attempt}/{$this->maxRetries}, waited {$waited}ms");
                    $lastError = "API error: HTTP {$status}";
                    $lastStatus = $status;

                    continue;
                }

                // The provider almost always says WHY in the body, and
                // discarding it left "API error: HTTP 403" -  a number, and
                // nothing a user can act on. The same 403 meant "your team has
                // no credits yet", which is a five-minute fix once you can read
                // it. Same for a rejected key, a retired model, a malformed
                // request: all of them explain themselves and none of it was
                // being shown.
                return [
                    'success' => false,
                    'error' => "API error: HTTP {$status}" . $this->explain($response),
                    'status' => $status,
                ];

            } catch (\Exception $e) {
                // Redact secrets BEFORE logging -  exception messages can embed
                // the request URL, which for some providers carries the API key.
                $lastError = $this->redactSecrets($e->getMessage());
                Log::error("[Jeeves:{$this->getName()}] API exception attempt {$attempt}", ['error' => $lastError]);

                if ($this->waitBeforeRetry($attempt) === null) {
                    break;
                }
            }
        }

        $result = ['success' => false, 'error' => $this->sanitizeError('Service error: ' . ($lastError ?? 'Unknown error'))];

        // Preserve the HTTP status when the failure came from a response
        // (not an exception) so callers can still branch on it.
        if ($lastStatus !== null) {
            $result['status'] = $lastStatus;
        }

        return $result;
    }

    /**
     * Sleep before the next attempt, if another attempt is allowed and the
     * wait fits the remaining budget.
     *
     * @param  int  $attempt  The attempt that just failed (1-based)
     * @param  int|null  $retryAfterSeconds  Provider's Retry-After hint, if sent
     * @return int|null Milliseconds slept, or NULL when the caller should stop
     *                  retrying (last attempt, or budget cannot cover the wait)
     */
    protected function waitBeforeRetry(int $attempt, ?int $retryAfterSeconds = null): ?int
    {
        // Never sleep after the final attempt -  nothing follows it.
        if ($attempt >= $this->maxRetries) {
            return null;
        }

        $delay = $this->backoffDelayMs($attempt, $retryAfterSeconds);
        $budget = (int) $this->retryConfig()['total_budget_ms'];

        // Fail fast rather than half-waiting: if the provider says "come back
        // in 30s" and the budget is 4s, sleeping 4s helps nobody.
        if ($budget > 0 && ($this->retryWaitedMs + $delay) > $budget) {
            Log::debug("[Jeeves:{$this->getName()}] Retry budget exhausted", [
                'waited_ms' => $this->retryWaitedMs,
                'next_delay_ms' => $delay,
                'budget_ms' => $budget,
            ]);

            return null;
        }

        $this->sleepMs($delay);
        $this->retryWaitedMs += $delay;

        return $delay;
    }

    /**
     * Compute the backoff delay in milliseconds for a failed attempt.
     *
     * Exponential (base * 2^(attempt-1)), capped at max_delay_ms, with
     * half-range jitter to stop concurrent workers retrying in lockstep.
     * A Retry-After hint overrides the computed value when configured to.
     */
    protected function backoffDelayMs(int $attempt, ?int $retryAfterSeconds = null): int
    {
        $config = $this->retryConfig();

        if ($retryAfterSeconds !== null && $config['respect_retry_after']) {
            return max(0, $retryAfterSeconds) * 1000;
        }

        $delay = (int) $config['base_delay_ms'] * (2 ** max(0, $attempt - 1));
        $delay = (int) min($delay, (int) $config['max_delay_ms']);

        if ($config['jitter'] && $delay > 1) {
            // Half-range jitter: keeps a growing floor (unlike full jitter,
            // which can pick ~0 and hammer the provider again immediately).
            $delay = random_int((int) ceil($delay / 2), $delay);
        }

        return $delay;
    }

    /**
     * Parse a Retry-After header value (delta-seconds or HTTP-date).
     */
    protected function parseRetryAfter($header): ?int
    {
        if (is_array($header)) {
            $header = $header[0] ?? null;
        }

        if ($header === null || $header === '') {
            return null;
        }

        $header = trim((string) $header);

        if (is_numeric($header)) {
            return max(0, (int) $header);
        }

        $timestamp = strtotime($header);

        return $timestamp === false ? null : max(0, $timestamp - time());
    }

    /**
     * Resolve the retry policy, falling back to safe built-in defaults.
     */
    protected function retryConfig(): array
    {
        $configured = config('jeeves.retry', []);

        return array_merge(static::RETRY_DEFAULTS, is_array($configured) ? $configured : []);
    }

    /**
     * Blocking sleep, isolated so tests can assert the schedule without
     * actually waiting.
     */
    protected function sleepMs(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }

    /**
     * Parse JSON from an LLM response text.
     *
     * LLMs are unreliable with JSON. This method handles ALL known failure patterns:
     * 1. Markdown code blocks wrapping JSON
     * 2. Trailing text after JSON
     * 3. Leading text before JSON
     * 4. Multiple code block formats
     * 5. HTML entities in JSON
     * 6. Trailing commas (invalid JSON but common from LLMs)
     * 7. Single quotes instead of double quotes
     * 8. Comments in JSON
     * 9. Completely non-JSON responses
     */
    protected function parseJsonResponse(string $text): ?array
    {
        $original = $text;

        // Step 1: Strip markdown code blocks (```json ... ```, ``` ... ```)
        $text = preg_replace('/```(?:json|JSON|sql|SQL)?\s*\n?/i', '', $text);
        $text = trim($text);

        // Step 2: Try direct parse first (fastest path)
        $parsed = json_decode($text, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
            return $parsed;
        }

        // Step 3: Extract JSON object -  find first { and last matching }
        $jsonStr = $this->extractJsonObject($text);
        if ($jsonStr) {
            $parsed = json_decode($jsonStr, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
                return $parsed;
            }

            // Step 4: Fix common JSON issues and retry
            $fixed = $this->fixMalformedJson($jsonStr);
            $parsed = json_decode($fixed, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
                Log::debug("[Jeeves:{$this->getName()}] JSON parsed after fixing");

                return $parsed;
            }
        }

        // Step 5: Last resort -  try to find any JSON array
        if (preg_match('/\[[\s\S]*\]/', $text, $matches)) {
            $parsed = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
                return $parsed;
            }
        }

        Log::warning("[Jeeves:{$this->getName()}] JSON parse failed after all attempts", [
            'text' => substr($original, 0, 500),
            'json_error' => json_last_error_msg(),
        ]);

        return null;
    }

    /**
     * Extract a JSON object from text that may have surrounding content.
     * Handles nested braces correctly.
     */
    protected function extractJsonObject(string $text): ?string
    {
        $start = strpos($text, '{');
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escape = false;
        $len = strlen($text);

        for ($i = $start; $i < $len; $i++) {
            $char = $text[$i];

            if ($escape) {
                $escape = false;

                continue;
            }

            if ($char === '\\') {
                $escape = true;

                continue;
            }

            if ($char === '"') {
                $inString = !$inString;

                continue;
            }

            if ($inString) {
                continue;
            }

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }

        // Unbalanced braces -  return from first { to end
        return substr($text, $start);
    }

    /**
     * Fix common JSON malformation from LLMs.
     */
    protected function fixMalformedJson(string $json): string
    {
        // Remove single-line comments (// ...)
        $json = preg_replace('/\/\/[^\n]*/', '', $json);

        // Remove block comments (/* ... */)
        $json = preg_replace('/\/\*[\s\S]*?\*\//', '', $json);

        // Remove trailing commas before } or ]
        $json = preg_replace('/,\s*([\]}])/', '$1', $json);

        // Fix single quotes to double quotes (only outside existing double-quoted strings)
        // This is risky so only do it if no double quotes exist
        if (strpos($json, '"') === false) {
            $json = str_replace("'", '"', $json);
        }

        // Decode HTML entities
        $json = html_entity_decode($json, ENT_QUOTES, 'UTF-8');

        // Remove BOM
        $json = preg_replace('/^\x{FEFF}/u', '', $json);

        return trim($json);
    }

    /**
     * The provider's own explanation for a failed call, if it gave one.
     *
     * Every provider nests it somewhere slightly different -  OpenAI and its
     * imitators use error.message, Anthropic the same, Google error.message on
     * a differently-shaped envelope -  so several shapes are tried before
     * falling back to the raw body.
     *
     * Truncated and stripped of secrets: this text reaches an HTTP response,
     * and a provider echoing part of the request must not echo a key with it.
     *
     * @param  Response  $response
     */
    protected function explain($response): string
    {
        try {
            $body = $response->json();
        } catch (\Throwable $e) {
            $body = null;
        }

        $message = null;

        if (is_array($body)) {
            $message = $body['error']['message']
                ?? $body['error']
                ?? $body['message']
                ?? $body['detail']
                ?? null;
        }

        if (!is_string($message) || trim($message) === '') {
            $message = (string) $response->body();
        }

        $message = trim(preg_replace('/\s+/', ' ', $message));

        if ($message === '') {
            return '';
        }

        return ' -  ' . $this->redactSecrets(mb_substr($message, 0, 180));
    }

    /**
     * 'asc' or 'desc', from whatever the model sent or did not send.
     *
     * Shared because three providers had the same defect: the null-coalesce
     * guarded the validity check and not the branch that used the value, so a
     * model omitting the field passed the check and then read the missing key.
     * Gemini always sends it, so the only provider under live test never
     * triggered it.
     */
    protected function normalizeOrder(mixed $order): string
    {
        $normalized = strtolower(trim((string) ($order ?? '')));

        return in_array($normalized, ['asc', 'desc'], true) ? $normalized : 'desc';
    }

    /**
     * `query_type` is deliberately NOT normalised here.
     *
     * It is normalised in QueryOrchestrator::normalizeQueryType(), which every
     * intent passes through whatever produced it. Doing it per provider would
     * leave third-party implementations of LlmProviderInterface — the
     * documented way to add a provider, and what this package's own test double
     * is — on the unnormalised path.
     *
     * `normalizeOrder()` above has that weakness and is left alone for now
     * because a wrong sort direction is visible, while a wrong `query_type`
     * silently answers a different question.
     */

    /**
     * Add the usage block from one response to this instance's running total.
     *
     * Accumulated rather than replaced, because answering one question can
     * take several calls -  intent parsing, a fallback to SQL generation, a
     * retry, or the steps of a decomposed question. Reporting only the last
     * call would understate exactly the questions that cost the most.
     *
     * Both dialects are read: Gemini's usageMetadata and OpenAI's usage, which
     * between them cover every provider here and every OpenAI-compatible
     * server. A provider that returns neither simply reports nothing.
     *
     * @param  array<string, mixed>  $body  Decoded response body
     */
    protected function recordUsage(array $body): void
    {
        $gemini = $body['usageMetadata'] ?? null;
        $openai = $body['usage'] ?? null;

        $prompt = $gemini['promptTokenCount'] ?? $openai['prompt_tokens'] ?? null;
        $completion = $gemini['candidatesTokenCount'] ?? $openai['completion_tokens'] ?? null;
        $total = $gemini['totalTokenCount'] ?? $openai['total_tokens'] ?? null;

        // Gemini bills thinking tokens and reports them separately from the
        // candidate tokens, so a total built by adding the two visible numbers
        // is short by exactly the amount hardest to predict.
        $thinking = $gemini['thoughtsTokenCount'] ?? null;

        if ($prompt === null && $completion === null && $total === null) {
            return;
        }

        $this->usage['calls'] = ($this->usage['calls'] ?? 0) + 1;

        foreach ([
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'thinking_tokens' => $thinking,
            'total_tokens' => $total,
        ] as $key => $value) {
            if (is_numeric($value)) {
                $this->usage[$key] = ($this->usage[$key] ?? 0) + (int) $value;
            }
        }
    }

    /**
     * Token counts for the calls this instance has made.
     *
     * @return array<string, int>
     */
    public function lastUsage(): array
    {
        return $this->usage;
    }

    /**
     * Start counting again.
     *
     * The provider is a singleton for the life of the request, so without this
     * a second question in the same process reports the first one's tokens as
     * well -  which turns per-question cost attribution into a running total
     * that nobody asked for.
     */
    public function resetUsage(): void
    {
        $this->usage = [];
    }

    /**
     * Resolve HTTP client SSL options from the ssl_verify config value.
     *
     * Accepted values:
     *   true / "true" / "1"    → verify against the system CA bundle (default; no override)
     *   false / "false" / "0"  → disable verification (NOT recommended)
     *   "/path/to/cacert.pem"  → verify against this CA bundle file -  the fix
     *                            for local stacks (XAMPP/WAMP) whose PHP has no
     *                            CA store, without touching php.ini
     *
     * @return array Guzzle options to merge in, or [] for default behavior
     */
    protected function sslOptions(): array
    {
        $verify = config('jeeves.ssl_verify', true);

        if ($verify === false) {
            return ['verify' => false];
        }

        if (is_string($verify)) {
            $normalized = strtolower(trim($verify));
            if (in_array($normalized, ['', '1', 'true', 'on', 'yes'], true)) {
                return [];
            }
            if (in_array($normalized, ['0', 'false', 'off', 'no'], true)) {
                return ['verify' => false];
            }

            // Anything else is treated as a CA bundle path. Passed through
            // as-is: a bad path fails loudly with a clear Guzzle error rather
            // than being silently ignored.
            return ['verify' => $verify];
        }

        return [];
    }

    /**
     * Redact API keys and tokens from a string, keeping the rest intact.
     * Used for log messages, where diagnostic detail must survive.
     */
    protected function redactSecrets(string $message): string
    {
        $redacted = preg_replace('/key=[A-Za-z0-9_-]+/', 'key=***', $message);
        $redacted = preg_replace('/Bearer\s+[A-Za-z0-9_.-]+/i', 'Bearer ***', $redacted);
        $redacted = preg_replace('/x-api-key:\s*[A-Za-z0-9_.-]+/i', 'x-api-key: ***', $redacted);

        return $redacted;
    }

    /**
     * Remove API keys, URLs, and other sensitive data from error messages.
     */
    protected function sanitizeError(string $message): string
    {
        // Remove API keys and tokens
        $sanitized = $this->redactSecrets($message);

        // Remove API URLs
        $sanitized = preg_replace('/https?:\/\/[^\s]+generativelanguage\.googleapis\.com[^\s]*/i', '[Gemini API]', $sanitized);
        $sanitized = preg_replace('/https?:\/\/[^\s]+api\.openai\.com[^\s]*/i', '[OpenAI API]', $sanitized);
        $sanitized = preg_replace('/https?:\/\/[^\s]+api\.anthropic\.com[^\s]*/i', '[Claude API]', $sanitized);

        // Generic connection errors
        if (stripos($sanitized, 'cURL error') !== false || stripos($sanitized, 'Could not resolve') !== false) {
            return 'Unable to connect to AI service. Please check your network connection and try again.';
        }

        return $sanitized;
    }

    /**
     * Build a standard error response.
     *
     * @param  string  $message  Error description (sanitized before returning)
     * @param  int|null  $status  HTTP status of the failed call, when known -
     *                            preserved so callers can react to e.g. 429
     *                            (rate limit) without string-matching messages
     */
    /**
     * Today, for resolving relative periods.
     *
     * A model has no reliable idea what day it is, so "last month" cannot be
     * turned into dates without being told. Sent on every intent prompt: it is
     * a date, not data.
     */
    protected function today(): string
    {
        return date('Y-m-d');
    }

    /**
     * Render the dataset list for an intent-parsing prompt.
     *
     * Shared by every provider so the intent contract is described one way.
     * The line format itself lives in `DatasetCatalog`, extracted so that the
     * next place needing this list borrows it rather than growing a near-copy
     * -  `QueryPlanner` already had one, and it had drifted.
     *
     * Pinned byte-for-byte by `tests/Unit/DatasetInfoRenderingTest.php`
     * before this delegated, so the move is provably behaviour-identical.
     * That matters more than it sounds: this feeds the intent prompt on every
     * provider, on every question.
     */
    protected function buildDatasetInfo(array $datasetList): string
    {
        return DatasetCatalog::render($datasetList);
    }

    protected function errorResponse(string $message, ?int $status = null): array
    {
        $response = [
            'success' => false,
            'error' => $this->sanitizeError($message),
            'dataset' => null,
            'metric' => null,
            'limit' => 10,
            'order' => 'desc',
            'group_value' => null,
            'group_by' => null,
            'date_from' => null,
            'date_to' => null,
            'confidence' => 0.0,
            'needs_clarification' => true,
            'clarification_type' => 'error',
        ];

        if ($status !== null) {
            $response['status'] = $status;
        }

        return $response;
    }

    /**
     * Get the provider name - must be implemented by each provider.
     */
    abstract public function getName(): string;
}
