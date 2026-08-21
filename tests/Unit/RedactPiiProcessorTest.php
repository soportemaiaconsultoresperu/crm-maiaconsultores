<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Logging\RedactPiiProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

/**
 * RedactPiiProcessor — verifies that PII (emails, phones, DNI, RUC, CE)
 * and any field whose key contains `password|token|secret|api_key` is
 * masked before the record reaches the log sink.
 */
class RedactPiiProcessorTest extends TestCase
{
    private function record(string $message, array $context = []): LogRecord
    {
        return new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'app',
            level: Level::Debug,
            message: $message,
            context: $context,
            extra: [],
        );
    }

    public function test_redacts_email_addresses_in_message(): void
    {
        $processor = new RedactPiiProcessor();
        $out = $processor($this->record('User juan.perez@example.com just signed up.'));

        $this->assertStringNotContainsString('juan.perez@example.com', $out->message);
        $this->assertStringContainsString('[REDACTED]', $out->message);
    }

    public function test_redacts_peruvian_mobile_numbers(): void
    {
        $processor = new RedactPiiProcessor();
        $out = $processor($this->record('Llamar al 987 654 321 por favor.'));

        $this->assertStringNotContainsString('987 654 321', $out->message);
        $this->assertStringContainsString('[REDACTED]', $out->message);
    }

    public function test_redacts_peruvian_dni(): void
    {
        $processor = new RedactPiiProcessor();
        $out = $processor($this->record('Cliente DNI: 12345678 registrado.'));

        $this->assertStringNotContainsString('12345678', $out->message);
        $this->assertStringContainsString('[REDACTED]', $out->message);
    }

    public function test_redacts_peruvian_ruc(): void
    {
        $processor = new RedactPiiProcessor();
        $out = $processor($this->record('Factura RUC: 20123456789 emitida.'));

        $this->assertStringNotContainsString('20123456789', $out->message);
        $this->assertStringContainsString('[REDACTED]', $out->message);
    }

    public function test_redacts_sensitive_context_keys(): void
    {
        $processor = new RedactPiiProcessor();
        $out = $processor($this->record('Auth attempt', [
            'email' => 'user@example.com',
            'password' => 'super-secret',
            'access_token' => 'ya29.abcdef',
            'client_secret' => 'shhh',
        ]));

        // email is redacted by pattern.
        $this->assertStringNotContainsString('user@example.com', $out->message);
        $this->assertStringNotContainsString('super-secret', $out->message);
        $this->assertStringNotContainsString('ya29.abcdef', $out->message);
        $this->assertStringNotContainsString('shhh', $out->message);

        // Sensitive keys present in context but masked.
        $ctx = $out->context;
        $this->assertSame('[REDACTED]', $ctx['password']);
        $this->assertSame('[REDACTED]', $ctx['access_token']);
        $this->assertSame('[REDACTED]', $ctx['client_secret']);
    }

    public function test_respects_disabled_flag(): void
    {
        $processor = new RedactPiiProcessor(['enabled' => false]);
        $out = $processor($this->record('Contact user@example.com please.'));

        $this->assertStringContainsString('user@example.com', $out->message);
        $this->assertStringNotContainsString('[REDACTED]', $out->message);
    }

    public function test_respects_custom_marker(): void
    {
        $processor = new RedactPiiProcessor(['redaction_marker' => 'XXX']);
        $out = $processor($this->record('Email user@example.com'));

        $this->assertStringContainsString('XXX', $out->message);
        $this->assertStringNotContainsString('user@example.com', $out->message);
    }

    public function test_recurses_into_nested_arrays(): void
    {
        $processor = new RedactPiiProcessor();
        $out = $processor($this->record('Nested test', [
            'data' => [
                'inner' => 'user@example.com',
                'secret' => 'something',
            ],
        ]));

        $this->assertStringNotContainsString('user@example.com', $out->context['data']['inner']);
        $this->assertSame('[REDACTED]', $out->context['data']['secret']);
    }
}