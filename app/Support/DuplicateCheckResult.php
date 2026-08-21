<?php

namespace App\Support;

/**
 * Result of a lead duplicate check (ADR-003).
 *
 * Matches are categorized:
 * - critical: identical normalized document number.
 * - warning: identical normalized email, phone or WhatsApp.
 *
 * Each match is a small array (not a full model) so it can be serialized
 * straight into a confirmation dialog or an import report:
 * ['id', 'code', 'full_name', 'field'] where "field" is the *_norm column
 * that matched.
 */
final class DuplicateCheckResult
{
    /**
     * @param  array<int, array{id: int, code: string, full_name: string, field: string}>  $critical
     * @param  array<int, array{id: int, code: string, full_name: string, field: string}>  $warnings
     */
    public function __construct(
        public readonly array $critical = [],
        public readonly array $warnings = [],
    ) {}

    public function hasCritical(): bool
    {
        return $this->critical !== [];
    }

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }

    public function isEmpty(): bool
    {
        return ! $this->hasCritical() && ! $this->hasWarnings();
    }
}
