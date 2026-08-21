<?php

namespace App\Imports;

use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\User;
use App\Services\LeadDuplicateFinder;
use App\Services\LeadService;
use App\Support\ImportResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Lead Excel import (RF-LEAD-007, ADR-003).
 *
 * Behavior:
 * - Validates every row (person_type + first_name required, at least one
 *   contact datum, document format per doc_type).
 * - Duplicate rows (doc/email/phone/whatsapp norms) are SKIPPED and
 *   reported with the matched lead code — never auto-updated.
 * - Invalid rows are reported with a Spanish reason.
 * - Valid rows are created in chunks inside a per-chunk transaction via
 *   LeadService (codes + norms + audit).
 *
 * Import template headers match the English attribute names
 * (person_type, first_name, last_name, ...); they do NOT need to match the
 * export headings. The importing user is the actor for every created row.
 *
 * @implements ToCollection<array-key, Collection<int, mixed>>
 */
class LeadsImport implements ToCollection, WithHeadingRow
{
    private const CHUNK_SIZE = 100;

    /**
     * Translate every supported row header to the internal snake_case
     * column name used downstream by the validator, payload and
     * LeadDuplicateFinder. Both the new human-readable Spanish headers
     * ("Nombre", "Razón social", …) and the legacy snake_case headers
     * ("first_name", "company_name", …) are accepted so files generated
     * before the rename keep working.
     *
     * Must stay in sync with LeadsTemplateExport::COLUMNS.
     *
     * @var array<string, string>
     */
    private const HEADER_TO_FIELD = [
        // New human-readable headers, NORMALIZED to snake-case ASCII because
        // maatwebsite/excel's WithHeadingRow converts every header to that
        // form before handing the row to us. The mapHeaders() helper applies
        // the same normalization to the incoming keys before lookup.
        'tipo_de_persona'                => 'person_type',
        'nombre'                         => 'first_name',
        'apellidos'                      => 'last_name',
        'razon_social'                   => 'company_name',
        'cargo'                          => 'position',
        'tipo_de_documento'              => 'doc_type',
        'numero_de_documento'            => 'doc_number',
        'telefono'                       => 'phone',
        'whatsapp'                       => 'whatsapp',
        'correo_electronico'             => 'email',
        'direccion'                      => 'address',
        'codigo_de_distrito_ubigeo'      => 'ubigeo_code',
        'codigo_de_distrito'             => 'ubigeo_code',
        'nivel_de_interes'               => 'interest_level',
        'observaciones'                  => 'observations',
        // Legacy snake_case headers (kept as a fallback).
        'person_type'                    => 'person_type',
        'first_name'                     => 'first_name',
        'last_name'                      => 'last_name',
        'company_name'                   => 'company_name',
        'position'                       => 'position',
        'doc_type'                       => 'doc_type',
        'doc_number'                     => 'doc_number',
        'phone'                          => 'phone',
        'whatsapp'                       => 'whatsapp',
        'email'                          => 'email',
        'address'                        => 'address',
        'ubigeo_code'                    => 'ubigeo_code',
        'interest_level'                 => 'interest_level',
        'observations'                   => 'observations',
    ];

    private LeadService $leads;

    private LeadDuplicateFinder $duplicates;

    public readonly ImportResult $result;

    public function __construct(
        private readonly User $actor,
    ) {
        $this->leads = app(LeadService::class);
        $this->duplicates = new LeadDuplicateFinder();
        $this->result = new ImportResult();
    }

    /**
     * @param  Collection<int, Collection<int, mixed>>  $collection
     */
    public function collection(Collection $collection): void
    {
        $pending = [];

                foreach ($collection as $index => $row) {
                    // Excel returns numeric cells as int/float; cast everything to
                    // string so validation rules (string|max) behave consistently.
                    $data = $row->map(
                        fn ($value) => is_int($value) || is_float($value) ? (string) $value : $value
                    )->toArray();

                    // Translate the (possibly friendly) headers to the internal
                    // snake_case keys the rest of this importer uses.
                    $data = $this->mapHeaders($data);

            // Heading row is 1, data rows start at 2.
            $rowNumber = $index + 2;

            if ($this->isEmptyRow($data)) {
                continue;
            }

            $this->result->total++;

            $validator = Validator::make($data, $this->rules($data));

            if ($validator->fails()) {
                $this->result->markInvalid(
                    $rowNumber,
                    $validator->errors()->first()
                );

                continue;
            }

            if ($match = $this->duplicates->findInRow($data)) {
                $this->result->markSkipped(
                    $rowNumber,
                    "Posible duplicado del lead {$match->code} (documento, correo o teléfono coinciden).",
                    $match->code
                );

                continue;
            }

            $pending[] = $this->payload($data);
        }

        foreach (array_chunk($pending, self::CHUNK_SIZE) as $chunk) {
            DB::transaction(function () use ($chunk): void {
                foreach ($chunk as $attributes) {
                    $this->leads->create($attributes, $this->actor);
                    $this->result->markCreated();
                }
            });
        }
    }

        /**
         * Validation rules for a single import row.
         *
         * @param  array<string, mixed>  $data
         * @return array<string, mixed>
         */
        private function rules(array $data): array
        {
            $docRules = ['nullable', 'max:20', 'required_without_all:email,phone,whatsapp'];

            if (($data['doc_type'] ?? null) === 'dni') {
                $docRules[] = 'digits:8';
            } elseif (($data['doc_type'] ?? null) === 'ruc') {
                $docRules[] = 'digits:11';
            }

            // `first_name` is required only for natural prospects — for juridica
            // the company name lives in `company_name` and the field can come in empty.
            $isNatural = ($data['person_type'] ?? null) === 'natural';

            return [
                'person_type' => ['required', 'in:natural,juridica'],
                'first_name' => [
                    Rule::requiredIf($isNatural),
                    'nullable',
                    'string',
                    'max:100',
                ],
                'last_name' => ['nullable', 'string', 'max:100'],
                'company_name' => ['nullable', 'string', 'max:150'],
                'position' => ['nullable', 'string', 'max:100'],
                'doc_type' => ['nullable', 'in:dni,ruc,ce,pasaporte,otro'],
                'doc_number' => $docRules,
                'phone' => ['nullable', 'string', 'max:30'],
                'whatsapp' => ['nullable', 'string', 'max:30'],
                'email' => ['nullable', 'email', 'max:150'],
                'address' => ['nullable', 'string', 'max:255'],
                'ubigeo_code' => ['nullable', 'digits:6', 'exists:ubigeo,code'],
                'interest_level' => ['nullable', 'in:bajo,medio,alto'],
                'observations' => ['nullable', 'string'],
            ];
        }

    /**
     * Row attributes ready for LeadService::create, applying sensible
     * defaults for omitted catalog columns.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function payload(array $data): array
    {
        return [
            ...$data,
            'last_name' => trim((string) ($data['last_name'] ?? '')),
            'status_id' => $this->defaultStatusId(),
            'source_id' => $this->defaultSourceId(),
            'owner_id' => $this->actor->id,
        ];
    }

    private function defaultStatusId(): int
    {
        return (int) LeadStatus::query()
            ->where('slug', 'nuevo')
            ->value('id');
    }

    private function defaultSourceId(): int
    {
        return (int) LeadSource::query()
            ->where('slug', 'otro')
            ->value('id');
    }

        /**
         * Translate row headers via HEADER_TO_FIELD. Both sides are
         * normalized (lowercase + strip accents + non-alphanumerics → "_")
         * so the operator-facing Spanish header ("Tipo de persona") matches
         * the lookup key ("tipo_de_persona") that WithHeadingRow produces.
         * Unknown keys pass through unchanged.
         *
         * @param  array<string, mixed>  $data
         * @return array<string, mixed>
         */
        private function mapHeaders(array $data): array
        {
            $mapped = [];
            foreach ($data as $key => $value) {
                $normalized = self::normalizeHeader((string) $key);
                $internalKey = self::HEADER_TO_FIELD[$normalized] ?? (string) $key;
                $mapped[$internalKey] = $value;
            }
            return $mapped;
        }

        /**
         * Lowercase + strip Spanish accents + collapse every non-alphanumeric
         * run to a single underscore. Mirrors the normalization that
         * maatwebsite/excel applies to heading rows.
         */
        private static function normalizeHeader(string $key): string
        {
            $key = mb_strtolower($key);
            $key = strtr($key, [
                'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
                'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
                'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u',
                'ñ' => 'n',
            ]);
            $key = preg_replace('/[^a-z0-9_]+/', '_', $key);
            return trim($key, '_');
        }

        /**
         * Decide whether a row should be skipped silently (not counted as
         * `total`, not reported as `invalid`). A row is treated as blank when:
         *
         *  - every cell is empty/null/whitespace (the common operator mistake
         *    of trailing empty rows under the data); OR
         *  - the row lacks the `person_type` column — the most fundamental
         *    identifier of any prospect. This catches the Excel artifacts
         *    where only a stray phone or email survived from a copy-paste.
         *
         * @param  array<string, mixed>  $data
         */
        private function isEmptyRow(array $data): bool
        {
            $hasAnyValue = collect($data)
                ->contains(fn ($value) => $value !== null && trim((string) $value) !== '');

            if (! $hasAnyValue) {
                return true;
            }

            return ! isset($data['person_type']) || trim((string) $data['person_type']) === '';
        }
}
