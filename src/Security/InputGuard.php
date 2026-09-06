<?php

namespace Jayanta\Jeeves\Security;

use Illuminate\Support\Facades\Log;
use Jayanta\Jeeves\Support\QuestionForLog;

/**
 * Input Guard
 *
 * Sanitizes and validates user input BEFORE it reaches the AI prompt.
 *
 * AUTO-DETECTS the jayanta/laravel-ai-guard package:
 * - If ai-guard is installed → uses its deeper scoring-based detection (prompt injection,
 *   jailbreak, bot detection) AND runs built-in checks as fallback
 * - If ai-guard is NOT installed → uses built-in regex checks only
 *
 * This makes ai-guard an OPTIONAL companion -  not a required dependency.
 *
 * An ai-guard detection is enforced according to ai-guard's OWN configuration
 * (its `mode` and `confidence_threshold`), so installing it never silently
 * changes what gets blocked; see `privacy.ai_guard` in config/jeeves.php.
 *
 * Defends against:
 *
 * 1. PROMPT INJECTION -  attempts to override AI instructions
 *    "Ignore previous instructions", "You are now a...", "System: ..."
 *
 * 2. SQL INJECTION VIA PROMPT -  SQL embedded in natural language
 *    "Show me data; DROP TABLE users", "' OR 1=1 --"
 *
 * 3. DATA EXFILTRATION -  attempts to extract schema/config info
 *    "Show me all table names", "What is your system prompt"
 *
 * 4. RESOURCE ABUSE -  extremely long queries, repeated special chars
 *
 * This guard runs BEFORE the query reaches the LLM.
 * The SqlValidator runs AFTER the LLM generates SQL.
 * Together they provide defense-in-depth.
 */
class InputGuard
{
    /**
     * Validate and sanitize user input.
     *
     * @param  string  $query  Raw user query
     * @return array {safe: bool, query: string (sanitized), blocked_reason: string|null}
     */
    public function validate(string $query): array
    {
        $original = $query;

        // Step 1: Basic sanitization + Unicode normalization
        $query = $this->sanitize($query);

        // Step 2: Check length
        $maxLength = config('jeeves.privacy.max_query_length', 1000);
        if (strlen($query) > $maxLength) {
            return $this->block("Query too long. Maximum {$maxLength} characters.", $original);
        }

        if (strlen(trim($query)) < 3) {
            return $this->block('Query too short.', $original);
        }

        // Step 3: Use ai-guard if installed (deeper scoring-based detection)
        $aiGuardResult = $this->checkWithAiGuard($query);
        if ($aiGuardResult) {
            Log::warning('[Jeeves:InputGuard] Blocked by ai-guard', [
                'threat_type' => $aiGuardResult['threat_type'],
                'confidence_score' => $aiGuardResult['confidence_score'],
                'query' => QuestionForLog::text($original),
            ]);

            return $this->block('Query blocked: ' . $aiGuardResult['threat_type'], $original);
        }

        // Step 4: Built-in prompt injection detection (fallback / additional layer)
        $injectionResult = $this->detectPromptInjection($query);
        if ($injectionResult) {
            Log::warning('[Jeeves:InputGuard] Prompt injection blocked', [
                'pattern' => $injectionResult,
                'query' => QuestionForLog::text($original),
            ]);

            return $this->block('Query contains disallowed patterns.', $original);
        }

        // Step 4: Detect SQL injection in query text
        $sqlInjectionResult = $this->detectSqlInQuery($query);
        if ($sqlInjectionResult) {
            Log::warning('[Jeeves:InputGuard] SQL injection in query blocked', [
                'pattern' => $sqlInjectionResult,
                'query' => QuestionForLog::text($original),
            ]);

            return $this->block('Query contains disallowed SQL patterns.', $original);
        }

        // Step 5: Detect data exfiltration attempts
        $exfilResult = $this->detectExfiltration($query);
        if ($exfilResult) {
            Log::warning('[Jeeves:InputGuard] Exfiltration attempt blocked', [
                'pattern' => $exfilResult,
                'query' => QuestionForLog::text($original),
            ]);

            return $this->block('Query contains disallowed patterns.', $original);
        }

        return ['safe' => true, 'query' => $query, 'blocked_reason' => null];
    }

    /**
     * Basic input sanitization with Unicode normalization.
     */
    protected function sanitize(string $query): string
    {
        // Remove null bytes
        $query = str_replace("\0", '', $query);

        // Remove zero-width characters (used to bypass keyword detection)
        $query = preg_replace('/[\x{200B}\x{200C}\x{200D}\x{FEFF}\x{00AD}]/u', '', $query);

        // Normalize fullwidth characters to ASCII (ＤＲＯＰ → DROP)
        if (preg_match('/[\x{FF01}-\x{FF5E}]/u', $query)) {
            $query = mb_convert_kana($query, 'a', 'UTF-8');
        }

        // Normalize whitespace (but keep single spaces)
        $query = preg_replace('/\s+/', ' ', $query);

        // Remove control characters (keep printable + common unicode)
        $query = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $query);

        // Remove BOM
        $query = preg_replace('/^\x{FEFF}/u', '', $query);

        return trim($query);
    }

    /**
     * Detect prompt injection attempts.
     *
     * These are patterns where the user tries to override the AI's instructions.
     */
    protected function detectPromptInjection(string $query): ?string
    {
        $patterns = [
            // Direct instruction override
            '/ignore\s+(all\s+)?(previous|above|prior)\s+(instructions|rules|prompts)/i' => 'instruction_override',
            '/disregard\s+(all\s+)?(previous|above|prior)/i' => 'instruction_override',
            '/forget\s+(all\s+)?(previous|your)\s+(instructions|rules)/i' => 'instruction_override',

            // Role hijacking
            '/you\s+are\s+now\s+(a|an)\s/i' => 'role_hijack',
            '/act\s+as\s+(a|an)\s+(different|new)/i' => 'role_hijack',
            '/pretend\s+(you\s+are|to\s+be)\s/i' => 'role_hijack',
            '/switch\s+to\s+(a\s+)?new\s+role/i' => 'role_hijack',

            // System prompt extraction
            '/what\s+(is|are)\s+your\s+(system\s+)?prompt/i' => 'prompt_extraction',
            '/show\s+(me\s+)?(your|the)\s+(system\s+)?prompt/i' => 'prompt_extraction',
            '/repeat\s+(your|the)\s+(system\s+)?(instructions|prompt|rules)/i' => 'prompt_extraction',
            '/print\s+(your|the)\s+(system\s+)?(instructions|prompt)/i' => 'prompt_extraction',
            '/reveal\s+(your|the)\s+(system|hidden)/i' => 'prompt_extraction',

            // Delimiter injection (trying to break out of the user query section)
            '/\bsystem\s*:/i' => 'delimiter_injection',
            '/\bassistant\s*:/i' => 'delimiter_injection',
            '/\b(IMPORTANT|CRITICAL)\s*:\s*(ignore|override|forget|new\s+instructions)/i' => 'delimiter_injection',

            // Encoded injection attempts
            '/&#\d+;/' => 'encoded_injection',
            '/%[0-9a-f]{2}/i' => 'encoded_injection',
        ];

        foreach ($patterns as $pattern => $label) {
            if (preg_match($pattern, $query)) {
                return $label;
            }
        }

        return null;
    }

    /**
     * Detect SQL injection patterns embedded in the query text.
     *
     * Users might try to sneak SQL into their natural language query,
     * hoping the AI passes it through.
     */
    protected function detectSqlInQuery(string $query): ?string
    {
        $patterns = [
            // Direct SQL statements
            '/;\s*(DROP|DELETE|UPDATE|INSERT|ALTER|CREATE|TRUNCATE)\s/i' => 'direct_sql',
            '/\bDROP\s+TABLE\b/i' => 'drop_table',
            '/\bDELETE\s+FROM\b/i' => 'delete_from',
            '/\bUPDATE\s+\w+\s+SET\b/i' => 'update_set',
            '/\bINSERT\s+INTO\b/i' => 'insert_into',
            '/\bALTER\s+TABLE\b/i' => 'alter_table',
            '/\bCREATE\s+(TABLE|INDEX|VIEW|FUNCTION)\b/i' => 'create_object',
            '/\bTRUNCATE\s+TABLE\b/i' => 'truncate_table',
            '/\bGRANT\s+(ALL|SELECT|INSERT|UPDATE|DELETE)\b/i' => 'grant',

            // Classic SQL injection
            '/\'\s*OR\s+\'\d+\'\s*=\s*\'\d+/i' => 'classic_sqli',
            '/\'\s*OR\s+1\s*=\s*1/i' => 'classic_sqli',
            '/\bUNION\s+(ALL\s+)?SELECT\b/i' => 'union_select',

            // Comment-based injection
            '/--\s*$/' => 'comment_injection',
            '/\/\*.*\*\//' => 'comment_injection',

            // System catalog probing
            '/\binformation_schema\b/i' => 'catalog_probe',
            '/\bpg_catalog\b/i' => 'catalog_probe',
            '/\bpg_tables\b/i' => 'catalog_probe',
            '/\bsysobjects\b/i' => 'catalog_probe',

            // File operations
            '/\bLOAD_FILE\b/i' => 'file_operation',
            '/\bINTO\s+(OUT|DUMP)FILE\b/i' => 'file_operation',
            '/\bCOPY\s+(TO|FROM)\b/i' => 'file_operation',

            // Command execution
            '/\bEXEC\s*\(/i' => 'exec_attempt',
            '/\bxp_cmdshell\b/i' => 'exec_attempt',
        ];

        foreach ($patterns as $pattern => $label) {
            if (preg_match($pattern, $query)) {
                return $label;
            }
        }

        return null;
    }

    /**
     * Detect data exfiltration attempts.
     *
     * Users trying to extract metadata, schema structure, or
     * information about the AI system itself.
     */
    protected function detectExfiltration(string $query): ?string
    {
        $patterns = [
            // Schema enumeration
            '/\blist\s+all\s+tables\b/i' => 'schema_enum',
            '/\bshow\s+(all\s+)?tables\b/i' => 'schema_enum',
            '/\bshow\s+(all\s+)?databases\b/i' => 'schema_enum',
            '/\bdescribe\s+table\b/i' => 'schema_enum',
            '/\bshow\s+columns\s+from\b/i' => 'schema_enum',

            // System info extraction
            '/\bversion\s*\(\s*\)/i' => 'system_info',
            '/\bcurrent_user\b/i' => 'system_info',
            '/\bpg_password\b/i' => 'system_info',

            // API key extraction
            '/\b(api|secret)\s*key\b/i' => 'key_extraction',
            '/\bcredentials?\b/i' => 'key_extraction',
            '/\bpassword\b/i' => 'key_extraction',
        ];

        foreach ($patterns as $pattern => $label) {
            if (preg_match($pattern, $query)) {
                return $label;
            }
        }

        return null;
    }

    /**
     * Fully-qualified ai-guard facade. Case matters: Composer's PSR-4 lookup is
     * case-sensitive, so a mis-cased name silently fails to autoload and the
     * integration would never fire.
     */
    // A plain string, not ::class -  ai-guard is an optional dependency, and a
    // ::class reference to an uninstalled package trips static analysis in
    // adopters' projects.
    public const AI_GUARD_FACADE = 'JayAnta\\AiGuard\\Facades\\AiGuard';

    /**
     * Check with the ai-guard package if installed.
     *
     * Auto-detects jayanta/laravel-ai-guard -  returns threat info when the
     * detection should BLOCK, null when ai-guard is absent, disabled, saw
     * nothing, or is not configured to act on what it saw.
     *
     * ai-guard owns the enforcement decision: it only blocks in its 'block'
     * mode, at or above its own confidence_threshold. Its shipped default is
     * 'log_only', so installing it adds visibility without changing which
     * queries are refused. Set `privacy.ai_guard.enforce` to 'always' to block
     * on any above-threshold detection regardless of ai-guard's mode.
     *
     * This makes ai-guard an optional companion, not a hard dependency.
     *
     * @return array{threat_type: string, confidence_score: int, matched_pattern: string|null}|null
     */
    protected function checkWithAiGuard(string $query): ?array
    {
        if (!$this->hasAiGuard()) {
            return null; // Not installed or switched off -  use built-in checks
        }

        if (!$this->aiGuardSupportsTextScan()) {
            // Say so once per request rather than throwing on every question
            // and swallowing it. doctor reports the same thing with the fix.
            Log::warning(
                '[Jeeves:InputGuard] ai-guard is installed but its version has no detectText(); '
                . 'skipping it and using the built-in checks. Upgrade jayanta/laravel-ai-guard.'
            );

            return null;
        }

        try {
            $result = $this->callAiGuard($query);

            if (($result['detected'] ?? false) !== true) {
                return null;
            }

            // ai-guard reports confidence as 'confidence_score' (0-100).
            $threat = [
                'threat_type' => $result['threat_type'] ?? 'unknown',
                'confidence_score' => (int) ($result['confidence_score'] ?? 0),
                'matched_pattern' => $result['matched_pattern'] ?? null,
            ];

            $threshold = (int) config('ai-guard.confidence_threshold', 70);
            if ($threat['confidence_score'] < $threshold) {
                $this->logAiGuardDetection("below ai-guard threshold ({$threshold})", $threat, $query);

                return null;
            }

            $enforce = config('jeeves.privacy.ai_guard.enforce', 'auto');
            $mode = (string) config('ai-guard.mode', 'log_only');
            if ($enforce !== 'always' && $mode !== 'block') {
                $this->logAiGuardDetection("not enforced (ai-guard mode: {$mode})", $threat, $query);

                return null;
            }

            return $threat;
        } catch (\Throwable $e) {
            // ai-guard failed -  log but don't block (graceful degradation)
            Log::debug('[Jeeves:InputGuard] ai-guard check failed, using built-in', [
                'error' => $e->getMessage(),
            ]);
        }

        return null; // Safe or ai-guard unavailable
    }

    /**
     * Invoke ai-guard's text detector. Isolated so tests can stub it.
     */
    protected function callAiGuard(string $query): array
    {
        $facade = static::AI_GUARD_FACADE;

        return $facade::detectText($query);
    }

    /**
     * Record an ai-guard detection that did not block, so the signal is not lost.
     */
    protected function logAiGuardDetection(string $why, array $threat, string $query): void
    {
        Log::info('[Jeeves:InputGuard] ai-guard detection not blocked -  ' . $why, [
            'threat_type' => $threat['threat_type'],
            'confidence_score' => $threat['confidence_score'],
            'query' => QuestionForLog::text($query),
        ]);
    }

    /**
     * Check whether ai-guard is installed AND enabled for Jeeves.
     */
    public function hasAiGuard(): bool
    {
        if (!config('jeeves.privacy.ai_guard.enabled', true)) {
            return false;
        }

        return $this->aiGuardInstalled();
    }

    /**
     * Whether the ai-guard package is present. Isolated so tests can stub it.
     */
    protected function aiGuardInstalled(): bool
    {
        return class_exists(static::AI_GUARD_FACADE);
    }

    /**
     * Does the installed ai-guard expose the text scanner we need?
     *
     * ai-guard v2.0.0 ships `detect(Request)` but not `detectText(string)`,
     * which was added later. We have a question string, not a request, so on
     * v2.0.0 every call throws. That is caught and the built-in checks carry
     * on, but the feature the user believes they installed does nothing -
     * silently, which is worse than not having it. `jeeves:doctor`
     * reports this so it is visible rather than merely survivable.
     */
    public function aiGuardSupportsTextScan(): bool
    {
        if (!$this->hasAiGuard()) {
            return false;
        }

        try {
            $facade = static::AI_GUARD_FACADE;
            $root = $facade::getFacadeRoot();

            return $root !== null && method_exists($root, 'detectText');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Return a blocked result.
     */
    protected function block(string $reason, string $original): array
    {
        return [
            'safe' => false,
            'query' => $original,
            'blocked_reason' => $reason,
        ];
    }
}
