<?php

declare(strict_types=1);

namespace App\Services\WhatsApp\Exceptions;

use RuntimeException;

/**
 * B14 Pasada B-1 — Typed exception raised by stub-mode WhatsApp adapters
 * to signal that a real Meta WhatsApp Cloud API call would be required but
 * the credentials are not configured (A5 pending).
 *
 * The exception class name is captured verbatim into the
 * `error_class` field of the canonical envelope, so the UI / automation
 * engine can branch on it without parsing free-text messages.
 */
class NotImplementedException extends RuntimeException
{
    public static function credentialsNotConfigured(): self
    {
        return new self(
            'Meta WhatsApp Cloud API credentials not configured (A5 pending). '
            .'Configure whatsapp_accounts.business_id and access_token to enable.'
        );
    }
}