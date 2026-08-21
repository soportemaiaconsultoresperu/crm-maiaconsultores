<?php

declare(strict_types=1);

namespace App\Integrations\Dto;

/**
 * Provider-side message reference returned by WhatsApp send methods.
 *
 * `wamid` is the canonical Meta WhatsApp Cloud API message identifier.
 * `status` is the immediate acknowledgement status reported by the
 * provider (typically `accepted`, `queued` or `failed`).
 */
final readonly class ProviderMessageRef
{
    public function __construct(
        public ?string $wamid,
        public string $status,
        public ?int $responseCode,
        public array $rawResponse = [],
    ) {
    }

    public static function accepted(string $wamid, ?int $responseCode = 200, array $rawResponse = []): self
    {
        return new self(
            wamid: $wamid,
            status: 'accepted',
            responseCode: $responseCode,
            rawResponse: $rawResponse,
        );
    }

    public static function rejected(string $status, ?int $responseCode = null, array $rawResponse = []): self
    {
        return new self(
            wamid: null,
            status: $status,
            responseCode: $responseCode,
            rawResponse: $rawResponse,
        );
    }
}