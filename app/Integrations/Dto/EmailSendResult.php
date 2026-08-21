<?php

declare(strict_types=1);

namespace App\Integrations\Dto;

/**
 * Immutable result returned by {@see \App\Integrations\Contracts\EmailProvider::send()}.
 *
 * Mirrors the contract surface needed by callers (Jobs, listeners, UI):
 * they need to know whether the provider accepted the message, the
 * provider-assigned ID for correlation, and the raw response for logging.
 */
final readonly class EmailSendResult
{
    public function __construct(
        public bool $accepted,
        public ?string $providerMessageId,
        public string $status,
        public ?string $threadId,
        public ?int $responseCode,
        public array $rawResponse = [],
    ) {
    }

    public static function accepted(
        string $providerMessageId,
        ?string $threadId = null,
        ?int $responseCode = 202,
        array $rawResponse = [],
    ): self {
        return new self(
            accepted: true,
            providerMessageId: $providerMessageId,
            status: 'queued',
            threadId: $threadId,
            responseCode: $responseCode,
            rawResponse: $rawResponse,
        );
    }

    public static function rejected(
        string $status,
        ?int $responseCode = null,
        array $rawResponse = [],
    ): self {
        return new self(
            accepted: false,
            providerMessageId: null,
            status: $status,
            threadId: null,
            responseCode: $responseCode,
            rawResponse: $rawResponse,
        );
    }
}