<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Integrations\Services\CredentialCipher;
use Tests\TestCase;

/**
 * CredentialCipher — encrypt/decrypt round-trip and graceful handling
 * of garbage / undecryptable input.
 */
class CredentialCipherTest extends TestCase
{
    private CredentialCipher $cipher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cipher = new CredentialCipher();
    }

    public function test_encrypt_then_decrypt_returns_the_original_value(): void
    {
        $plain = 'ya29.A0AfH6SMA_very-secret-token_xyz';

        $cipher = $this->cipher->encrypt($plain);
        $this->assertNotSame($plain, $cipher);
        $this->assertSame($plain, $this->cipher->decrypt($cipher));
    }

    public function test_decrypt_of_garbage_returns_null(): void
    {
        $this->assertNull($this->cipher->decrypt('this-is-not-a-real-ciphertext'));
        $this->assertNull($this->cipher->decrypt(''));
        $this->assertNull($this->cipher->decrypt('base64:ZmFrZQ=='));
    }

    public function test_two_encryptions_of_the_same_plaintext_produce_different_ciphertexts(): void
    {
        $plain = 'identical-input';
        $first = $this->cipher->encrypt($plain);
        $second = $this->cipher->encrypt($plain);

        // Laravel's Crypt uses a random IV; the same input must produce
        // different ciphertexts across calls.
        $this->assertNotSame($first, $second);
        $this->assertSame($plain, $this->cipher->decrypt($first));
        $this->assertSame($plain, $this->cipher->decrypt($second));
    }

    public function test_decrypt_or_passthrough_returns_decrypted_value_for_cipher(): void
    {
        $plain = 'shhh';
        $cipher = $this->cipher->encrypt($plain);

        $this->assertSame($plain, $this->cipher->decryptOrPassthrough($cipher));
    }

    public function test_decrypt_or_passthrough_returns_plaintext_for_legacy_row(): void
    {
        // A row someone accidentally wrote in plaintext should still
        // be readable so admins can recover the credentials.
        $this->assertSame('legacy-plaintext', $this->cipher->decryptOrPassthrough('legacy-plaintext'));
    }

    public function test_decrypt_or_passthrough_handles_null_and_non_string(): void
    {
        $this->assertNull($this->cipher->decryptOrPassthrough(null));
        $this->assertNull($this->cipher->decryptOrPassthrough(42));
        $this->assertNull($this->cipher->decryptOrPassthrough(['array']));
    }
}