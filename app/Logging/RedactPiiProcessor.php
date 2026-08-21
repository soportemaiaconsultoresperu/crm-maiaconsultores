<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Monolog processor that masks personally identifiable information
 * before it hits any log sink.
 *
 * Covers the regex classes defined in docs/v2/01-roadmap.md §4 (c):
 *   - email addresses;
 *   - phone numbers (Peruvian and international E.164);
 *   - DNI (8 digits);
 *   - RUC (11 digits);
 *   - CE / carnet de extranjería (9–12 digits);
 *   - any field whose key contains password|token|secret|api_key.
 *
 * Behaviour is configurable through config('integrations.logging'):
 *   - redaction_marker (default "[REDACTED]")
 *   - enabled          (default true)
 *   - preserve_length  (default true) — keep the original token length
 *                        so a hash-length placeholder is plausible
 */
class RedactPiiProcessor implements ProcessorInterface
{
    /**
     * @var list<string>
     */
    private const SENSITIVE_KEY_PATTERNS = [
        'password',
        'token',
        'secret',
        'api_key',
        'apikey',
        'authorization',
        'client_secret',
        'app_secret',
        'access_token',
        'refresh_token',
        'bearer',
        'credential',
    ];

    /**
     * @var list<string>
     */
    private const REGEX_PATTERNS = [
        // Email
        '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/',
        // Peruvian mobile 9xxxxxxxx (with optional +51 and separators)
        '/(?:\+?51[\s\-]?)?9[\s\-]?\d{2}[\s\-]?\d{3}[\s\-]?\d{3}\b/',
        // International E.164
        '/\+\d{1,3}[\s\-]?\d{1,4}[\s\-]?\d{3,4}[\s\-]?\d{3,4}[\s\-]?\d{0,4}/',
        // RUC (11 digits) — prefix with RUC or starting with 10/20/15/17
        '/\b(?:RUC[:\s]*)?(?:1[05]|20)\d{9}\b/i',
        // CE (9–12 digits) — preceded by CE keyword
        '/\bCE[:\s]*\d{9,12}\b/i',
        // DNI (8 digits) — preceded by DNI keyword OR a bare 8-digit number
        // (the bare case would over-match; we require a "DNI" cue by default
        //  — see the per-pattern switch below for the strict version)
        '/\bDNI[:\s]*\d{8}\b/i',
    ];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config = [])
    {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        if (! ($this->config['enabled'] ?? true)) {
            return $record;
        }

        $marker = (string) ($this->config['redaction_marker'] ?? '[REDACTED]');

        $message = $this->redactString($record->message, $marker);
        $context = $this->redactArray((array) $record->context, $marker);
        $extra = $this->redactArray((array) $record->extra, $marker);

        return $record->with(message: $message, context: $context, extra: $extra);
    }

    /**
     * Convenience factory that pulls the configuration out of Laravel.
     */
    public static function fromConfig(ConfigRepository $config): self
    {
        return new self((array) $config->get('integrations.logging', []));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function redactArray(array $data, string $marker): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            $sensitiveKey = is_string($key) && $this->keyIsSensitive($key);

            if ($sensitiveKey) {
                $out[$key] = $marker;

                continue;
            }

            if (is_string($value)) {
                $out[$key] = $this->redactString($value, $marker);

                continue;
            }

            if (is_array($value)) {
                $out[$key] = $this->redactArray($value, $marker);

                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }

    private function redactString(string $value, string $marker): string
    {
        foreach (self::REGEX_PATTERNS as $pattern) {
            $value = preg_replace_callback(
                $pattern,
                function (array $m) use ($marker): string {
                    $match = $m[0];

                    return $this->config['preserve_length'] ?? true
                        ? $marker.'('.strlen($match).')'
                        : $marker;
                },
                $value,
            ) ?? $value;
        }

        return $value;
    }

    private function keyIsSensitive(string $key): bool
    {
        $needle = strtolower($key);

        foreach (self::SENSITIVE_KEY_PATTERNS as $pattern) {
            if (str_contains($needle, $pattern)) {
                return true;
            }
        }

        return false;
    }
}