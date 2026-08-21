<?php

namespace App\Imports;

use App\Models\Contact;
use App\Models\Customer;
use App\Models\User;
use App\Services\ContactService;
use App\Support\NormalizesContactData;
use App\Support\ImportResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Contact Excel import (mirrors the leads import pattern, RF-LEAD-007/ADR-003).
 *
 * Behavior:
 * - Validates every row: first_name + last_name required, email format,
 *   is_primary accepts si/no/1/0/true/false, customer resolved by
 *   customer_doc_number against the customers table (doc_number_norm).
 * - Duplicate rows (email_norm or phone already existing for that customer)
 *   are SKIPPED and reported — never auto-updated.
 * - Unknown customer_doc_number → invalid row with a Spanish reason.
 * - Valid rows are created in chunks inside a per-chunk transaction via
 *   ContactService (norms + audit + single-primary guarantee).
 *
 * Template headings: customer_doc_number, first_name, last_name, position,
 * area, phone, whatsapp, email, is_primary, observations.
 *
 * @implements ToCollection<array-key, Collection<int, mixed>>
 */
class ContactsImport implements ToCollection, WithHeadingRow
{
    private const CHUNK_SIZE = 100;

    use NormalizesContactData;

    private ContactService $contacts;

    public readonly ImportResult $result;

    public function __construct(
        private readonly User $actor,
    ) {
        $this->contacts = app(ContactService::class);
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
            // string so validation rules behave consistently.
            $data = $row->map(
                fn ($value) => is_int($value) || is_float($value) ? (string) $value : $value
            )->toArray();

            // Heading row is 1, data rows start at 2.
            $rowNumber = $index + 2;

            if ($this->isEmptyRow($data)) {
                continue;
            }

            $this->result->total++;

            $validator = Validator::make($data, $this->rules());

            if ($validator->fails()) {
                $this->result->markInvalid(
                    $rowNumber,
                    $validator->errors()->first()
                );

                continue;
            }

            $customer = $this->resolveCustomer((string) ($data['customer_doc_number'] ?? ''));

            if ($customer === null) {
                $this->result->markInvalid(
                    $rowNumber,
                    "Cliente con documento «{$data['customer_doc_number']}» no encontrado."
                );

                continue;
            }

            $data = $this->normalizeRow($data);

            if ($this->isDuplicate($customer, $data)) {
                $this->result->markSkipped(
                    $rowNumber,
                    "Posible duplicado: ya existe un contacto del cliente {$customer->code} con ese correo o teléfono."
                );

                continue;
            }

            $pending[] = [$customer, $this->payload($data)];
        }

        foreach (array_chunk($pending, self::CHUNK_SIZE) as $chunk) {
            DB::transaction(function () use ($chunk): void {
                foreach ($chunk as [$customer, $attributes]) {
                    $this->contacts->create($customer, $attributes, $this->actor);
                    $this->result->markCreated();
                }
            });
        }
    }

    /**
     * Validation rules for a single import row.
     *
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'customer_doc_number' => ['required', 'string', 'max:20'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'position' => ['nullable', 'string', 'max:100'],
            'area' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'whatsapp' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'is_primary' => ['nullable', 'in:si,no,1,0,true,false,verdadero,falso'],
            'observations' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Resolve the parent customer by document number (normalized: dots,
     * spaces and case are ignored — same rule as duplicate matching).
     */
    private function resolveCustomer(string $docNumber): ?Customer
    {
        $norm = self::normalizeDoc($docNumber);

        if ($norm === null) {
            return null;
        }

        return Customer::query()
            ->where('doc_number_norm', $norm)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeRow(array $data): array
    {
        $data = array_map(
            fn ($value) => is_string($value) ? trim($value) : $value,
            $data
        );

        foreach (['position', 'area', 'phone', 'whatsapp', 'email', 'observations'] as $field) {
            if (($data[$field] ?? null) === '') {
                $data[$field] = null;
            }
        }

        return $data;
    }

    /**
     * Duplicate check (ADR-003): email_norm already exists for the target
     * customer, or phone/whatsapp already matches it. Contacts only carry an
     * email_norm column, so phones are matched against both the raw value
     * and its normalized digits (same normalization rule as leads).
     *
     * @param  array<string, mixed>  $data
     */
    private function isDuplicate(Customer $customer, array $data): bool
    {
        $emailNorm = self::normalizeEmail($data['email'] ?? null);
        $rawPhone = isset($data['phone']) && $data['phone'] !== null
            ? trim((string) $data['phone'])
            : null;
        $phoneNorm = self::normalizePhone($data['phone'] ?? null);

        return Contact::query()
            ->where('customer_id', $customer->id)
            ->where(function ($q) use ($emailNorm, $rawPhone, $phoneNorm): void {
                if ($emailNorm !== null) {
                    $q->orWhere('email_norm', $emailNorm);
                }

                foreach (array_filter([$rawPhone, $phoneNorm]) as $phone) {
                    $q->orWhere('phone', $phone)->orWhere('whatsapp', $phone);
                }
            })
            ->exists();
    }

    /**
     * Row attributes ready for ContactService::create.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function payload(array $data): array
    {
        return [
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'position' => $data['position'] ?? null,
            'area' => $data['area'] ?? null,
            'phone' => $data['phone'] ?? null,
            'whatsapp' => $data['whatsapp'] ?? null,
            'email' => $data['email'] ?? null,
            'is_primary' => $this->toBoolean($data['is_primary'] ?? null),
            'observations' => $data['observations'] ?? null,
        ];
    }

    /**
     * Coerce the is_primary cell (si/no/1/0/true/false) to a boolean.
     */
    private function toBoolean(mixed $value): bool
    {
        return in_array(
            mb_strtolower(trim((string) $value)),
            ['si', '1', 'true', 'verdadero'],
            true
        );
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
