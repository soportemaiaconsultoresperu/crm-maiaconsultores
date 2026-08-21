<?php

namespace App\Imports;

use App\Models\Currency;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Tax;
use App\Models\User;
use App\Support\ImportResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Product catalog Excel import (RF-PROD-001..003).
 *
 * Mirrors LeadsImport's structure: read with `WithHeadingRow`, validate every
 * row, report issues per row in Spanish, and create valid rows in chunks
 * inside a per-chunk transaction.
 *
 * Template headers (Spanish, must match the export):
 *   Código, Tipo, Nombre, Categoría, Descripción, Precio, Moneda, Impuesto, Activo
 *
 * - `Código` is unique within a year (handled by the schema and CodeGenerator).
 *   Rows whose `Código` already exists are reported as duplicates and skipped.
 * - `Tipo` must be `producto` or `servicio`.
 * - `Categoría` is resolved by name (case-insensitive). Unknown names fail validation.
 * - `Moneda` is resolved by ISO 4217 code.
 * - `Impuesto` is resolved by name.
 * - `Activo` accepts `Sí` / `No` / `1` / `0` / `true` / `false`. Defaults to true
 *   when omitted.
 *
 * @implements ToCollection<array-key, Collection<int, mixed>>
 */
class ProductsImport implements ToCollection, WithHeadingRow
{
    private const CHUNK_SIZE = 100;

    public readonly ImportResult $result;

    /**
     * Codes seen while reading the current file. Used to skip duplicates that
     * appear within the same upload (the per-row DB query only catches codes
     * that already exist in the database, not codes repeated later in the file).
     *
     * @var list<string>
     */
    private array $seenCodesInFile = [];

    public function __construct(
        private readonly User $actor,
    ) {
        $this->result = new ImportResult();
    }

    /**
     * @param  Collection<int, Collection<int, mixed>>  $collection
     */
    public function collection(Collection $collection): void
    {
        $pending = [];

        foreach ($collection as $index => $row) {
            // Excel returns numeric cells as int/float; cast to string so
            // validation rules (string|max) behave consistently.
            $data = $row->map(
                fn ($value) => is_int($value) || is_float($value) ? (string) $value : $value
            )->toArray();

            // Heading row is 1, data rows start at 2.
            $rowNumber = $index + 2;

            if ($this->isEmptyRow($data)) {
                continue;
            }

            $this->result->total++;

            // Resolve category / currency / tax by human-readable keys so the
            // validator can apply exists: rules.
            $resolved = $this->resolveLookupFields($data, $rowNumber);

            if ($resolved === null) {
                // resolveLookupFields() already pushed the precise reason into
                // the result via markInvalid(); we skip the row.
                continue;
            }

            $payload = [...$resolved];

            $validator = Validator::make($payload, $this->rules());

            if ($validator->fails()) {
                $this->result->markInvalid($rowNumber, $validator->errors()->first());
                continue;
            }

            // Duplicate check (database, including soft-deleted rows): the
            // product code is UNIQUE at the SQL level, so a soft-deleted row
            // still blocks the INSERT even though the default Product query
            // hides it. We use withTrashed() to be safe.
            if (Product::query()->withTrashed()->where('code', $payload['code'])->exists()) {
                $this->result->markSkipped(
                    $rowNumber,
                    "Ya existe un producto con el código \"{$payload['code']}\".",
                    $payload['code'],
                );
                continue;
            }

            // Duplicate check (within the same file): repeated codes in this
            // upload would otherwise pass the DB check (not yet persisted) and
            // then blow up the chunk with a UniqueConstraintViolationException.
            if (in_array($payload['code'], $this->seenCodesInFile, true)) {
                $this->result->markSkipped(
                    $rowNumber,
                    "Código duplicado \"{$payload['code']}\" dentro del archivo (se conserva la primera ocurrencia).",
                    $payload['code'],
                );
                continue;
            }
            $this->seenCodesInFile[] = $payload['code'];

            $pending[] = $this->payload($payload);
        }

        foreach (array_chunk($pending, self::CHUNK_SIZE) as $chunk) {
            DB::transaction(function () use ($chunk): void {
                foreach ($chunk as $attributes) {
                    Product::query()->create($attributes);
                    $this->result->markCreated();
                }
            });
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'code'         => ['required', 'string', 'max:50'],
            'product_type' => ['required', 'in:producto,servicio'],
            'name'         => ['required', 'string', 'max:150'],
            'category_id'  => ['nullable', 'integer', 'exists:product_categories,id'],
            'description'  => ['nullable', 'string', 'max:1000'],
            'price'        => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'currency_code'=> ['required', 'string', 'size:3', 'exists:currencies,code'],
            'tax_id'       => ['nullable', 'integer', 'exists:taxes,id'],
            'is_active'    => ['nullable', 'boolean'],
        ];
    }

    /**
     * Translate the Spanish-looking template columns (`Categoría`, `Moneda`,
     * `Impuesto`, `Activo`) into the database-friendly columns the validator
     * expects (`category_id`, `currency_code`, `tax_id`, `is_active`).
     *
     * Returns null when any lookup cannot be resolved (the reason is already
     * recorded on $this->result so callers should just `continue`).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function resolveLookupFields(array $data, int $rowNumber): ?array
    {
        // maatwebsite/excel with WithHeadingRow normalises headers to
        // snake-case ASCII ("Código" -> "codigo", "Descripción" -> "descripcion"),
        // so we read the snake-case keys the library actually produces.
        $categoryName = trim((string) ($data['categoria'] ?? $data['category_id'] ?? ''));
        if ($categoryName !== '' && ! is_numeric($categoryName)) {
            $category = ProductCategory::query()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($categoryName)])
                ->first();
            if ($category === null) {
                $this->result->markInvalid($rowNumber, "La categoría \"{$categoryName}\" no existe.");
                return null;
            }
            $data['category_id'] = $category->id;
        } elseif (is_numeric($categoryName)) {
            $data['category_id'] = (int) $categoryName;
        }
        unset($data['categoria']);

        $currencyCode = strtoupper(trim((string) ($data['moneda'] ?? $data['currency_code'] ?? '')));
        if ($currencyCode !== '') {
            if (! Currency::query()->where('code', $currencyCode)->exists()) {
                $this->result->markInvalid($rowNumber, "La moneda \"{$currencyCode}\" no existe.");
                return null;
            }
            $data['currency_code'] = $currencyCode;
        }
        unset($data['moneda']);

        $taxName = trim((string) ($data['impuesto'] ?? $data['tax_id'] ?? ''));
        if ($taxName !== '' && ! is_numeric($taxName)) {
            $tax = Tax::query()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($taxName)])
                ->first();
            if ($tax === null) {
                $this->result->markInvalid($rowNumber, "El impuesto \"{$taxName}\" no existe.");
                return null;
            }
            $data['tax_id'] = $tax->id;
        } elseif ($taxName === '') {
            $data['tax_id'] = null;
        } elseif (is_numeric($taxName)) {
            $data['tax_id'] = (int) $taxName;
        }
        unset($data['impuesto']);

        // Activo: accept Sí/No/1/0/true/false (Spanish operators usually write
        // "Sí"/"No" — filter_var() alone does not, so we normalise first).
        $activoRaw = $data['activo'] ?? $data['is_active'] ?? null;
        unset($data['activo']);
        if ($activoRaw === null || trim((string) $activoRaw) === '') {
            $data['is_active'] = true;
        } else {
            $normalised = mb_strtolower(trim((string) $activoRaw));
            if (in_array($normalised, ['sí', 'si', '1', 'true', 'yes', 'on'], true)) {
                $data['is_active'] = true;
            } elseif (in_array($normalised, ['no', '0', 'false', 'off'], true)) {
                $data['is_active'] = false;
            } else {
                $data['is_active'] = filter_var($activoRaw, FILTER_VALIDATE_BOOLEAN);
            }
        }

        // Tipo
        $tipo = $data['tipo'] ?? $data['product_type'] ?? null;
        unset($data['tipo']);
        if ($tipo !== null) {
            $data['product_type'] = mb_strtolower(trim((string) $tipo));
        }

        // Código
        $code = $data['codigo'] ?? $data['code'] ?? null;
        unset($data['codigo']);
        if ($code !== null) {
            $data['code'] = trim((string) $code);
        }

        // Nombre
        $name = $data['nombre'] ?? $data['name'] ?? null;
        unset($data['nombre']);
        if ($name !== null) {
            $data['name'] = trim((string) $name);
        }

        // Descripción
        $desc = $data['descripcion'] ?? $data['description'] ?? null;
        unset($data['descripcion']);
        if ($desc !== null && $desc !== '') {
            $data['description'] = trim((string) $desc);
        } else {
            $data['description'] = null;
        }

        // Precio
        $price = $data['precio'] ?? $data['price'] ?? null;
        unset($data['precio']);
        if ($price !== null && $price !== '') {
            $data['price'] = (float) $price;
        }

        return $data;
    }

    /**
     * Final attributes ready for Product::create(), applying safe defaults
     * for fields the operator may have omitted.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function payload(array $data): array
    {
        return [
            'code'          => (string) $data['code'],
            'product_type'  => (string) $data['product_type'],
            'name'          => (string) $data['name'],
            'category_id'   => $data['category_id'] ?? null,
            'description'   => $data['description'] ?? null,
            'price'         => (float) $data['price'],
            'currency_code' => (string) $data['currency_code'],
            'tax_id'        => $data['tax_id'] ?? null,
            'is_active'     => (bool) ($data['is_active'] ?? true),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function isEmptyRow(array $data): bool
    {
        return collect($data)
            ->filter(fn ($value) => $value !== null && trim((string) $value) !== '')
            ->isEmpty();
    }
}
