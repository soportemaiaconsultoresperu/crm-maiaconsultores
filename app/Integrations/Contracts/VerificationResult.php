<?php

declare(strict_types=1);

namespace App\Integrations\Contracts;

/**
 * Outcome of a webhook signature verification.
 *
 * Native backed enum (string). Use {@see VerificationResult::VERIFIED} etc.
 * as return values; the scalar value is the literal stored in
 * `webhook_events.error_class` for forensics.
 */
enum VerificationResult: string
{
    case VERIFIED = 'verified';
    case INVALID_SIGNATURE = 'invalid_signature';
    case EXPIRED = 'expired';
    case MALFORMED = 'malformed';

    /**
     * Convenience for callers that want a list of every legal value
     * without instantiating each case.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}