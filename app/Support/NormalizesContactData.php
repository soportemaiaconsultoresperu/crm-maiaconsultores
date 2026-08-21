<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Shared *_norm normalizers for contact-like data (ADR-003).
 *
 * Used by LeadService, CustomerService and ContactService so leads,
 * customers and contacts normalize documents, phones and emails with
 * exactly the same rules. The *_norm columns are the only values used
 * for duplicate matching.
 */
trait NormalizesContactData
{
    /**
     * Normalize a phone/WhatsApp number for duplicate matching.
     *
     * Digits only; when there are more than 11 digits (country-code
     * prefixes, extra trunk digits) the LAST 11 digits are kept. A leading
     * "+" is intentionally dropped so "+51 987 654 321" and "51987654321"
     * normalize to the same value. Blank input normalizes to null.
     */
    public static function normalizePhone(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', trim($value));

        if ($digits === '') {
            return null;
        }

        if (strlen($digits) > 11) {
            $digits = substr($digits, -11);
        }

        return $digits;
    }

    /**
     * Normalize an email for duplicate matching: lowercase + trimmed.
     */
    public static function normalizeEmail(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = mb_strtolower(trim($value));

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Normalize a document number for duplicate matching: uppercase,
     * spaces and dots stripped ("12.345.678" == "12345678").
     */
    public static function normalizeDoc(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = str_replace([' ', '.', "\t"], '', mb_strtoupper(trim($value)));

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Compute the *_norm values for the given raw input and write them back
     * (either onto the data array or directly onto the model).
     *
     * @param  array<string, mixed>  $data  Raw input keyed by attribute.
     * @param  Model|null  $model  When given, norms are set on the model
     *                            (used by update, where partial input must
     *                            be merged with existing attributes).
     * @param  array<string, string>|null  $targets  raw attribute => norm
     *                                               attribute mapping;
     *                                               defaults to the shared
     *                                               doc/phone/whatsapp/email
     *                                               mapping.
     * @return array<string, mixed> The data array with norms applied
     *                              (only meaningful when $model is null).
     */
    public static function applyNormalizations(
        array $data,
        ?Model $model = null,
        ?array $targets = null,
    ): array {
        $targets ??= [
            'doc_number' => 'doc_number_norm',
            'phone' => 'phone_norm',
            'whatsapp' => 'whatsapp_norm',
            'email' => 'email_norm',
        ];

        foreach ($targets as $raw => $norm) {
            $value = array_key_exists($raw, $data) ? $data[$raw] : null;

            if ($model !== null && ! array_key_exists($raw, $data)) {
                continue; // untouched on update
            }

            $normalized = match ($raw) {
                'doc_number' => self::normalizeDoc($value),
                'phone', 'whatsapp' => self::normalizePhone($value),
                'email' => self::normalizeEmail($value),
                default => null,
            };

            if ($model !== null) {
                $model->setAttribute($norm, $normalized);
            } else {
                $data[$norm] = $normalized;
            }
        }

        return $data;
    }
}
