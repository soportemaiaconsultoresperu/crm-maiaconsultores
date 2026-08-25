<?php

namespace App\Services;

use App\Models\CodeSequence;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Generates per-entity, per-year correlative codes (ADR-002).
 *
 * Format: PREFIX-YEAR-NNNNN (e.g. LEAD-2026-00001). Uses a transaction
 * with SELECT ... FOR UPDATE on code_sequences to prevent duplicates
 * under concurrency. The year row is created lazily with defaults taken
 * from settings (seq.{entity}.prefix, seq.{entity}.pad_length) when
 * missing; seq.pad_length stays as a global fallback.
 */
class CodeGeneratorService
{
    /**
     * Entities that can be coded by this service.
     */
    private const ENTITIES = ['lead', 'customer', 'opportunity', 'quotation', 'product', 'support_ticket'];

    /**
     * Default prefixes when no setting overrides them (ADR-002).
     */
    private const DEFAULT_PREFIXES = [
        'lead' => 'LEAD',
        'customer' => 'CLI',
        'opportunity' => 'OPP',
        'quotation' => 'COT',
        'product' => 'PROD',
        'support_ticket' => 'SUP',
    ];

    /**
     * Default padding width when no setting overrides it.
     */
    private const DEFAULT_PAD_LENGTH = 5;

    /**
     * Generate the next code for the given entity and the current year.
     *
     * @throws \InvalidArgumentException When the entity is unknown.
     */
    public function next(string $entity): string
    {
        if (! in_array($entity, self::ENTITIES, true)) {
            throw new \InvalidArgumentException(
                "Unknown code entity [{$entity}]. Expected one of: ".implode(', ', self::ENTITIES).'.'
            );
        }

        return DB::transaction(function () use ($entity) {
            $year = (int) now()->format('Y');

            $sequence = CodeSequence::query()
                ->where('entity', $entity)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                $sequence = CodeSequence::create([
                    'entity' => $entity,
                    'year' => $year,
                    'prefix' => $this->setting("seq.{$entity}.prefix") ?? self::DEFAULT_PREFIXES[$entity],
                    'next_number' => 1,
                    'pad_length' => (int) ($this->setting("seq.{$entity}.pad_length")
                        ?? $this->setting('seq.pad_length')
                        ?? self::DEFAULT_PAD_LENGTH),
                ]);
            }

            $number = $sequence->next_number;

            $sequence->increment('next_number');

            return sprintf(
                '%s-%d-%s',
                $sequence->prefix,
                $year,
                str_pad((string) $number, $sequence->pad_length, '0', STR_PAD_LEFT)
            );
        });
    }

    /**
     * Read a setting value by key (null when absent).
     */
    private function setting(string $key): ?string
    {
        $value = Setting::query()->where('key', $key)->value('value');

        return $value === null ? null : (string) $value;
    }
}
