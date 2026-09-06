<?php

namespace Jayanta\Jeeves\Contracts;

/**
 * LLM Provider Interface
 *
 * All AI providers (Gemini, OpenAI, Claude, Ollama) implement this contract.
 * This is the ONLY point where external AI services are contacted.
 *
 * PRIVACY GUARANTEE:
 * - The prompt parameter contains ONLY schema structure (table names, column names, types)
 * - Actual database values/data are NEVER included in any method call
 * - The AI generates SQL which is executed locally - data never leaves the user's system
 */
interface LlmProviderInterface
{
    /**
     * Generate SQL from a schema-aware prompt.
     *
     * The prompt contains:
     * - Database schema (table names, column names, types, relationships)
     * - LLM instructions (how to use the schema)
     * - Example queries (for few-shot learning)
     * - The user's natural language question
     *
     * The prompt NEVER contains actual data values.
     *
     * @param  string  $prompt  The complete schema-aware prompt
     * @return array {success: bool, data?: array, error?: string, refused_before_sending?: bool}
     *                                                                                            data contains parsed JSON with: sql, query_type, metric, explanation
     *                                                                                            refused_before_sending: set true when the provider decided
     *                                                                                            locally, before any request left the machine, that this
     *                                                                                            prompt cannot be answered honestly (e.g. it would not fit
     *                                                                                            the model's context window). Tells the orchestrator not to
     *                                                                                            retry with a smaller prompt -  that would answer a
     *                                                                                            narrower question, not a corrected one.
     */
    public function generateSql(string $prompt): array;

    /**
     * Parse a natural language query into structured intent.
     *
     * Used when the system first identifies what dataset/metric the user wants,
     * before generating SQL.
     *
     * @param  string  $text  User's natural language query
     * @param  array  $datasetList  Available datasets [{key, name, aliases, metrics}]
     * @return array {
     *               success: bool,
     *               dataset?: string,
     *               metric?: string,
     *               limit?: int,
     *               order?: 'asc'|'desc',
     *               district?: string,
     *               confidence?: float (0.0-1.0),
     *               needs_clarification?: bool,
     *               clarification_type?: 'dataset'|'metric'|'ambiguous',
     *               error?: string
     *               }
     */
    public function parseIntent(string $text, array $datasetList): array;

    /**
     * Check if the provider is available and configured correctly.
     *
     * @return array {status: 'ok'|'error', message?: string, model?: string}
     */
    public function healthCheck(): array;

    /**
     * Get the provider name for logging and metadata.
     *
     * @return string e.g., 'gemini', 'openai', 'claude', 'ollama', 'null'
     */
    public function getName(): string;
}
