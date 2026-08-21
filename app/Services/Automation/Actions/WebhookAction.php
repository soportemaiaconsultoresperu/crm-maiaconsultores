<?php

declare(strict_types=1);

namespace App\Services\Automation\Actions;

use App\Contracts\Automation\ActionContract;
use App\Models\AutomationExecutionStep;
use App\Services\Automation\Exceptions\WebhookNotAuthorizedException;
use InvalidArgumentException;

/**
 * Webhook action — STUB for B12.
 *
 * Validates the destination against
 * `config('integrations.webhooks.allowed_destinations')` (HTTPS, full
 * URL match) and throws WebhookNotAuthorizedException when the URL is not
 * in the list. The real HTTP call is deferred to B14 / per-roadmap §3.8.
 *
 * Payload:
 *  - url (string, required — must be in allowed_destinations)
 *  - method (GET|POST|PATCH, optional — default POST)
 *  - body (array, optional)
 *  - headers (array, optional)
 */
class WebhookAction implements ActionContract
{
    public function execute(array $payload, AutomationExecutionStep $step): void
    {
        $url = (string) ($payload['url'] ?? '');

        if ($url === '') {
            throw new InvalidArgumentException('WebhookAction: url is required.');
        }

        if (! $this->isAuthorized($url)) {
            throw new WebhookNotAuthorizedException(
                "WebhookAction: destination {$url} is not in the allowed list."
            );
        }

        // Stub: do not actually fire. The full HTTP call (signature, SSRF
        // block, response capture) is delivered in B14. We only record the
        // would-be call metadata so the admin can audit.
        $step->response_json = array_merge((array) ($step->response_json ?? []), [
            'would_dispatch_webhook' => true,
            'method' => strtoupper((string) ($payload['method'] ?? 'POST')),
            'url' => $url,
            'body_keys' => array_keys((array) ($payload['body'] ?? [])),
            'note' => 'Real HTTP call deferred to B14.',
        ]);
        $step->save();
    }

    public function simulate(array $payload): array
    {
        $url = (string) ($payload['url'] ?? '');

        return [
            'would_dispatch_webhook' => true,
            'url' => $url,
            'authorized' => $url !== '' && $this->isAuthorized($url),
        ];
    }

    private function isAuthorized(string $url): bool
    {
        $allowed = (array) config('integrations.webhooks.allowed_destinations', []);

        if (empty($allowed)) {
            // Empty allow-list = deny by default; explicit feature flag.
            return false;
        }

        foreach ($allowed as $candidate) {
            if (is_string($candidate) && strcasecmp($candidate, $url) === 0) {
                return true;
            }
        }

        return false;
    }
}