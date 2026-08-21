<?php

declare(strict_types=1);

namespace App\Integrations\Dto;

/**
 * Reference to a calendar event as returned by the provider.
 *
 * `externalEventId` is the provider-assigned opaque ID (Google event id,
 * Outlook Graph event id) — the only handle we keep to perform update
 * or delete operations later. `etag` is the provider's optimistic-
 * concurrency token used to detect lost updates.
 */
final readonly class CalendarEventRef
{
    public function __construct(
        public string $externalEventId,
        public ?string $etag,
        public string $status,
        public ?int $responseCode,
        public array $rawResponse = [],
    ) {
    }

    public static function created(string $externalEventId, ?string $etag, ?int $responseCode = 201, array $rawResponse = []): self
    {
        return new self(
            externalEventId: $externalEventId,
            etag: $etag,
            status: 'created',
            responseCode: $responseCode,
            rawResponse: $rawResponse,
        );
    }

    public static function updated(string $externalEventId, ?string $etag, ?int $responseCode = 200, array $rawResponse = []): self
    {
        return new self(
            externalEventId: $externalEventId,
            etag: $etag,
            status: 'updated',
            responseCode: $responseCode,
            rawResponse: $rawResponse,
        );
    }

    public static function rejected(string $status, ?int $responseCode = null, array $rawResponse = []): self
    {
        return new self(
            externalEventId: '',
            etag: null,
            status: $status,
            responseCode: $responseCode,
            rawResponse: $rawResponse,
        );
    }
}