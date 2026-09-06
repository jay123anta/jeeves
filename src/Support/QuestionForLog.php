<?php

namespace Jayanta\Jeeves\Support;

/**
 * NQ-010. The one place that decides how a question reaches the log.
 *
 * A question carries whatever the person typed into it - a customer's name, an
 * account number, a diagnosis - and it was written to the application log at
 * nine sites across four files, with four different answers to "how much":
 *
 *   InputGuard, five sites            200 characters
 *   QueryOrchestrator, guard block    100 characters
 *   QueryOrchestrator, two others     the whole thing
 *   TwoTierQueryCache, cache miss     the whole thing
 *
 * Nobody chose that. It accumulated one call site at a time, which is this
 * project's signature defect in another costume: a policy spread across call
 * sites is not a policy, it is a collection of accidents, and the next site
 * somebody adds will invent a fifth answer.
 *
 * **Questions are still logged by default, and that is deliberate.** When
 * somebody reports a wrong answer, the question is the single most useful line
 * in the log. Defaulting to silence would cost every adopter that in exchange
 * for a benefit only some of them need - and an install that cannot log
 * questions at all now has one switch rather than nine edits.
 *
 * This is NOT the privacy wall. Rule 2 is about what reaches an LLM provider,
 * and nothing here does. This is the same principle one hop later: the log is a
 * different audience, with a different retention policy, read by people who
 * were never granted access to the data.
 */
class QuestionForLog
{
    /**
     * The default cut. 200 rather than 100 because five of the nine sites
     * already used it, and because the sites that matter most - InputGuard
     * recording a blocked query - are the ones where the extra context is
     * evidence of what somebody tried.
     */
    public const DEFAULT_MAX_CHARS = 200;

    /** What the log says instead of the question when logging is off. */
    public const WITHHELD = '(question withheld: jeeves.logging.log_question)';

    /**
     * The loggable form of a question.
     *
     * Never returns the untruncated text, whatever the configuration says.
     * `log_question` chooses between "bounded" and "nothing", not between
     * "bounded" and "everything" - a setting that could switch the bound off
     * would eventually be switched off.
     */
    public static function text(?string $question): string
    {
        if (!config('jeeves.logging.log_question', true)) {
            return self::WITHHELD;
        }

        $question = trim((string) $question);

        if ($question === '') {
            return '(empty)';
        }

        $max = config('jeeves.logging.question_max_chars', self::DEFAULT_MAX_CHARS);
        $max = is_numeric($max) && (int) $max > 0 ? (int) $max : self::DEFAULT_MAX_CHARS;

        // mb_* because a question is user text and cutting a multi-byte
        // character in half produces a log line that is not valid UTF-8, which
        // some log shippers drop silently - losing the entry this exists for.
        if (mb_strlen($question) <= $max) {
            return $question;
        }

        return mb_substr($question, 0, $max) . '… (truncated)';
    }
}
