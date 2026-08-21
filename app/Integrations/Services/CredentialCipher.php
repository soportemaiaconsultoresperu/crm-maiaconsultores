<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Monolog\Level;

/**
 * Thin wrapper around Laravel's {@see \Illuminate\Support\Facades\Crypt}
 * for the V2 integration layer.
 *
 * Exists so that:
 *   - call sites depend on a small named contract instead of a facade;
 *   - we can swap the algorithm without touching every adapter;
 *   - any value that fails to decrypt becomes `null` instead of throwing,
 *     because ciphertexts encrypted with a previous APP_KEY become
 *     unrecoverable after a rotation and we'd rather surface that as
 *     "no credentials" than crash the request.
 *
 * Never logs the plaintext value. The DEBUG-level audit only records
 * the caller's class and method (stack frame).
 */
class CredentialCipher
{
    /**
     * Encrypt a plaintext secret (token, refresh token, password).
     */
    public function encrypt(string $plain): string
    {
        $this->logAccess('encrypt');

        return Crypt::encryptString($plain);
    }

    /**
     * Decrypt a previously-encrypted secret.
     *
     * Returns null when the ciphertext is unrecognised (wrong key,
     * truncated payload, garbage from a backfill). Callers must treat
     * null as "no credentials" rather than as an exception.
     */
    public function decrypt(string $cipher): ?string
    {
        try {
            $this->logAccess('decrypt');

            return Crypt::decryptString($cipher);
        } catch (DecryptException $e) {
            Log::warning('CredentialCipher: decrypt failed (likely key rotation).', [
                'reason' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Best-effort safe-decrypt: when given plaintext (e.g. a credentials
     * column that someone accidentally wrote without encryption) we
     * return it as-is. Used by adapters that need to support legacy
     * rows during the B11 cut-over.
     */
    public function decryptOrPassthrough(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            return null;
        }

        $decrypted = $this->decrypt($value);

        if ($decrypted !== null) {
            return $decrypted;
        }

        // Likely already-plaintext legacy row.
        return $value;
    }

    private function logAccess(string $operation): void
    {
        if (! Log::isHandling(Level::Debug)) {
            return;
        }

        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);

        $caller = $trace[1] ?? [];
        $class = $caller['class'] ?? self::class;
        $method = $caller['function'] ?? '__construct';

        Log::debug('CredentialCipher access', [
            'operation' => $operation,
            'caller' => $class.'::'.$method,
        ]);
    }
}