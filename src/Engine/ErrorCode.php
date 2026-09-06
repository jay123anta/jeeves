<?php

namespace Jayanta\Jeeves\Engine;

/**
 * Why a query failed, in a form a client can branch on.
 *
 * Every failure used to come back as `status: error` with an English sentence,
 * so a frontend deciding whether to retry, rephrase, or show a support link had
 * to match on prose that was rewritten three times in a week. These codes are
 * part of the API and will not change wording under anyone.
 *
 * The HTTP status each maps to is defined here too, so the controller cannot
 * drift from the codes and a caller that only reads the status line still gets
 * something truthful.
 */
final class ErrorCode
{
    /** The input guard refused the question before it reached a provider. */
    public const BLOCKED = 'blocked';

    /** The question was understood as a request but cannot be answered here. */
    public const NOT_UNDERSTOOD = 'not_understood';

    /** Understood, but the schema has no way to answer it. */
    public const CANNOT_ANSWER = 'cannot_answer';

    /** The provider is rate limiting. Wait and retry -  the query was fine. */
    public const RATE_LIMITED = 'rate_limited';

    /** The provider failed or returned something unusable. */
    public const PROVIDER_ERROR = 'provider_error';

    /** Generated SQL failed validation. Never executed. */
    public const UNSAFE_SQL = 'unsafe_sql';

    /** The database rejected or failed the query. */
    public const DATABASE_ERROR = 'database_error';

    /** Anything unclassified. */
    public const INTERNAL = 'internal_error';

    /**
     * HTTP status for each code.
     *
     * 4xx where the caller can do something about it, 429 where the answer is
     * simply "later", 5xx where it is ours or an upstream's fault. A rate limit
     * arriving as 400 told every client the query was malformed, so the sensible
     * response -  wait and retry -  was the one thing it could not choose.
     */
    public const HTTP_STATUS = [
        self::BLOCKED => 400,
        self::NOT_UNDERSTOOD => 422,
        self::CANNOT_ANSWER => 422,
        self::UNSAFE_SQL => 422,
        self::RATE_LIMITED => 429,
        self::PROVIDER_ERROR => 502,
        self::DATABASE_ERROR => 500,
        self::INTERNAL => 500,
    ];

    /** Is retrying the same request worth it? */
    public const RETRYABLE = [
        self::RATE_LIMITED,
        self::PROVIDER_ERROR,
    ];

    public static function httpStatus(?string $code): int
    {
        return self::HTTP_STATUS[$code] ?? 500;
    }

    public static function isRetryable(?string $code): bool
    {
        return in_array($code, self::RETRYABLE, true);
    }
}
