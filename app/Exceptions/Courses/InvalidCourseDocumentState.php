<?php

namespace App\Exceptions\Courses;

/**
 * An academic-document lifecycle action the domain refused, tagged with the
 * reason that caused it.
 *
 * The reason travels as a stable tag — the same shape
 * {@see InvalidCourseEditionData::field()} uses — so a presentation layer can
 * pick the sentence for the case it knows without parsing the message text and
 * without reporting one reason while another was the real cause. It extends
 * \InvalidArgumentException, so every boundary that already caught one keeps
 * working unchanged.
 */
class InvalidCourseDocumentState extends \InvalidArgumentException
{
    /** The action is only legal on a document that is still vigente. */
    public const NOT_CURRENT = 'not_current';

    /** The enrollment does not meet the document's eligibility conditions yet. */
    public const NOT_ELIGIBLE = 'not_eligible';

    /** A current document already exists, so a plain generation would duplicate it. */
    public const CURRENT_ALREADY_EXISTS = 'current_already_exists';

    private function __construct(string $message, private readonly string $reason)
    {
        parent::__construct($message);
    }

    /**
     * The replacement guard refusal. Its default message stays
     * developer-facing English, like the other domain guards of the service.
     */
    public static function notCurrent(string $message = 'Only a current academic document may be regenerated.'): self
    {
        return new self($message, self::NOT_CURRENT);
    }

    /** The eligibility refusal, whose message is already the user-facing Spanish one. */
    public static function notEligible(string $message): self
    {
        return new self($message, self::NOT_ELIGIBLE);
    }

    /** The duplicate-generation refusal, whose message is already the user-facing Spanish one. */
    public static function currentAlreadyExists(string $message): self
    {
        return new self($message, self::CURRENT_ALREADY_EXISTS);
    }

    /** The stable reason, safe for presentation layers to branch on. */
    public function reason(): string
    {
        return $this->reason;
    }
}
