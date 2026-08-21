<?php

declare(strict_types=1);

namespace App\Integrations\Contracts;

/**
 * Strategy interface every webhook signature verifier implements.
 *
 * Concrete verifiers (one per provider) live in
 * {@see App\Integrations\Verification\*} and are wired through
 * {@see \App\Integrations\Services\AdapterFactory}.
 *
 * The middleware does NOT depend on a specific provider class; it asks
 * the factory for the verifier associated with the route parameter and
 * delegates to it.
 */
interface WebhookVerifier
{
    /**
     * Verify the HMAC/signature of an inbound webhook delivery.
     *
     * @param  string  $signature  raw header value as received (e.g. "sha256=...")
     * @param  string  $body  raw request body, exactly as received on the wire
     * @param  int|null  $timestamp  Unix timestamp from the X-...-Timestamp
     *                              header if present; null when missing
     */
    public function verify(string $signature, string $body, ?int $timestamp): VerificationResult;
}