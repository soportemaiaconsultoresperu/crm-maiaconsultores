<?php

declare(strict_types=1);

namespace App\Events\V2;

/**
 * B17 / D-21a — Mandatory trigger: an integration account's outbound call has
 * failed permanently (max retries exhausted). Emitted by the integration
 * sub-system when an outbound HTTP / API call returns a non-retryable error
 * after the integration sub-system's retry policy gives up.
 *
 * Listeners (the parent orchestrator / admin notification subscriber) decide
 * what to do with the event (default: email all admin users; v1 does not
 * ship a UI listener, only the typed event contract).
 */
final class IntegrationFailedPermanently
{
    public function __construct(
        public readonly int $accountId,
        public readonly string $errorClass,
        public readonly string $errorMessage,
    ) {
    }
}
