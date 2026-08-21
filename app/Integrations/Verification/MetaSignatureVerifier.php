<?php

declare(strict_types=1);

namespace App\Integrations\Verification;

use App\Integrations\Contracts\VerificationResult;
use App\Integrations\Contracts\WebhookVerifier;

/**
 * Meta (WhatsApp Cloud API / Facebook Graph) webhook signature verifier.
 *
 * Meta signs deliveries with HMAC-SHA256 over the raw body using the
 * App Secret as the key. The header value is `sha256=<hex>`.
 *
 * The verifier is constant-time via {@see hash_equals()} and treats a
 * missing / malformed signature header as MALFORMED rather than throwing.
 */
class MetaSignatureVerifier implements WebhookVerifier
{
    public function __construct(private readonly string $appSecret)
    {
    }

    public function verify(string $signature, string $body, ?int $timestamp): VerificationResult
    {
        if ($signature === '' || $this->appSecret === '') {
            return VerificationResult::MALFORMED;
        }

        if (! str_starts_with($signature, 'sha256=')) {
            return VerificationResult::MALFORMED;
        }

        $expected = 'sha256='.hash_hmac('sha256', $body, $this->appSecret);

        if (! hash_equals($expected, $signature)) {
            return VerificationResult::INVALID_SIGNATURE;
        }

        return VerificationResult::VERIFIED;
    }
}