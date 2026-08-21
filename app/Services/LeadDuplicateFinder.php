<?php

namespace App\Services;

use App\Models\Lead;
use App\Support\DuplicateCheckResult;

/**
 * Detects duplicate leads by normalized document/email/phone values
 * (ADR-003).
 *
 * Duplicate detection is deliberately NOT scoped by data visibility:
 * administrators need global duplicate detection, and a salesperson must
 * be warned before creating a lead that already exists in someone else's
 * pipeline. Scope filters belong in list queries, not here.
 *
 * This finder only DETECTS. Blocking a duplicate creation is a UI-level
 * decision with explicit confirmation; the import skips duplicates and
 * reports them instead of updating (ADR-003).
 */
class LeadDuplicateFinder
{
    /**
     * Check candidate data against all ACTIVE leads (soft-deleted leads
     * are ignored).
     *
     * @param  array<string, mixed>  $data  Raw lead input (doc_number,
     *                                      email, phone, whatsapp keys).
     * @param  Lead|null  $ignore  Lead excluded from matching (e.g. the
     *                             lead being edited).
     */
    public function check(array $data, ?Lead $ignore = null): DuplicateCheckResult
    {
        $critical = [];
        $warnings = [];

        $docNumber = LeadService::normalizeDoc($data['doc_number'] ?? null);

        if ($docNumber !== null) {
            $critical = $this->matchOn('doc_number_norm', $docNumber, $ignore);
        }

        $email = LeadService::normalizeEmail($data['email'] ?? null);

        if ($email !== null) {
            $warnings = [
                ...$warnings,
                ...$this->matchOn('email_norm', $email, $ignore),
            ];
        }

        foreach (['phone' => 'phone_norm', 'whatsapp' => 'whatsapp_norm'] as $raw => $column) {
            $number = LeadService::normalizePhone($data[$raw] ?? null);

            if ($number !== null) {
                $warnings = [
                    ...$warnings,
                    ...$this->matchOn($column, $number, $ignore),
                ];
            }
        }

        return new DuplicateCheckResult($critical, $warnings);
    }

    /**
     * First lead matching a row by doc/email/phone/whatsapp norms (in that
     * priority order), or null. Used by the Excel import to skip duplicate
     * rows; the matched lead code goes into the error report (RF-LEAD-007).
     *
     * @param  array<string, mixed>  $row
     */
    public function findInRow(array $row): ?Lead
    {
        $docNumber = LeadService::normalizeDoc($row['doc_number'] ?? null);

        if ($docNumber !== null) {
            $lead = Lead::query()->where('doc_number_norm', $docNumber)->first();

            if ($lead !== null) {
                return $lead;
            }
        }

        $email = LeadService::normalizeEmail($row['email'] ?? null);

        if ($email !== null) {
            $lead = Lead::query()->where('email_norm', $email)->first();

            if ($lead !== null) {
                return $lead;
            }
        }

        foreach (['phone', 'whatsapp'] as $raw) {
            $number = LeadService::normalizePhone($row[$raw] ?? null);

            if ($number !== null) {
                $lead = Lead::query()->where("{$raw}_norm", $number)->first();

                if ($lead !== null) {
                    return $lead;
                }
            }
        }

        return null;
    }

    /**
     * Active leads whose $column equals $value, reduced to the fields the
     * UI needs for a duplicate warning.
     *
     * @return array<int, array{id: int, code: string, full_name: string, field: string}>
     */
    private function matchOn(string $column, string $value, ?Lead $ignore): array
    {
        return Lead::query()
            ->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->where($column, $value)
            ->orderBy('code')
            ->get(['id', 'code', 'first_name', 'last_name'])
            ->map(fn (Lead $lead): array => [
                'id' => $lead->id,
                'code' => $lead->code,
                'full_name' => trim("{$lead->first_name} {$lead->last_name}"),
                'field' => $column,
            ])
            ->all();
    }
}
