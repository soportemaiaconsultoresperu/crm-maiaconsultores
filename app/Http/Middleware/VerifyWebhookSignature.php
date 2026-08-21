<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\Integrations\WebhookRejectedException;
use App\Integrations\Contracts\VerificationResult;
use App\Integrations\Contracts\WebhookVerifier;
use App\Integrations\Services\AdapterFactory;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verify the signature of an inbound webhook request before any
 * controller / listener sees it.
 *
 * Usage from a route group:
 *
 *     Route::middleware('signed.webhook')->group(function () {
 *         Route::post('/webhooks/{provider}', WebhookController::class);
 *     });
 *
 * Behaviour:
 *   - resolves the verifier for the `{provider}` route parameter through
 *     {@see AdapterFactory::webhookVerifier()};
 *   - reads the configured signature header from `config('integrations.webhook.signature_header')`;
 *   - reads the raw body bytes (NOT the parsed input) for HMAC;
 *   - reads the optional timestamp header and enforces the configured
 *     timestamp window (replay-attack guard).
 *
 * On failure: throws {@see WebhookRejectedException} with a 400 status.
 * The exception's `previous` carries the {@see VerificationResult}
 * reason for forensics.
 */
class VerifyWebhookSignature
{
    public function __construct(private readonly AdapterFactory $factory)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $provider = (string) $request->route('provider');

        if ($provider === '') {
            throw new WebhookRejectedException('Missing provider route parameter.');
        }

        $verifier = $this->factory->webhookVerifier($provider);

        // Outlook has no header-based signature; the controller handles
        // its `clientState` token-based check itself.
        if ($verifier === null) {
            return $next($request);
        }

        $headerName = (string) (config("integrations.webhook.signature_header.{$provider}") ?? '');

        if ($headerName === '') {
            throw new WebhookRejectedException("No signature header configured for provider {$provider}.");
        }

        $signature = (string) $request->header($headerName, '');

        if ($signature === '') {
            throw new WebhookRejectedException(
                "Missing signature header {$headerName} for provider {$provider}.",
            );
        }

        // Reject oversized payloads before reading the body.
        $maxBytes = (int) config('integrations.webhook.max_payload_bytes', 1048576);
        $body = $request->getContent();
        if ($body === false) {
            throw new WebhookRejectedException('Could not read request body.');
        }
        if (strlen($body) > $maxBytes) {
            throw new WebhookRejectedException("Webhook body exceeds {$maxBytes} bytes.");
        }

        $timestamp = $this->extractTimestamp($request, $provider);

        if ($timestamp !== null) {
            $window = (int) config('integrations.webhook.timestamp_window_seconds', 300);
            $skew = abs(time() - $timestamp);
            if ($skew > $window) {
                throw new WebhookRejectedException(
                    "Webhook timestamp outside {$window}s window (skew={$skew}s).",
                );
            }
        }

        $result = $verifier->verify($signature, $body, $timestamp);

        if ($result !== VerificationResult::VERIFIED) {
            throw new WebhookRejectedException(
                "Webhook signature {$result->value} for provider {$provider}.",
            );
        }

        return $next($request);
    }

    private function extractTimestamp(Request $request, string $provider): ?int
    {
        // Convention: <provider>-timestamp header, e.g. "meta-timestamp".
        $names = [
            'meta' => 'X-Hub-Timestamp',
            'google' => 'X-Goog-Channel-Token',
            'outlook' => null,
        ];

        $header = $names[$provider] ?? null;

        if ($header === null) {
            return null;
        }

        $value = $request->header($header);

        if ($value === null || $value === '') {
            return null;
        }

        // X-Hub-Timestamp is a Unix timestamp as a string.
        if (ctype_digit($value)) {
            return (int) $value;
        }

        // Google uses opaque channel tokens; only used as freshness marker here.
        return null;
    }
}