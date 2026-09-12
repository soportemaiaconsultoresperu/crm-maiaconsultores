<?php

namespace App\Services\Courses;

use App\Enums\Courses\CommercialDocumentType;
use App\Enums\Courses\CourseEnrollmentState;
use App\Models\Courses\CourseCommercialDocument;
use App\Models\Courses\CourseEnrollment;
use App\Models\Courses\CourseEnrollmentGroup;
use App\Models\User;
use App\Services\DocumentService;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class CourseCommercialDocumentService
{
    public function __construct(private readonly DocumentService $documents) {}

    /** @return array{subtotal_amount:string,igv_rate:string,igv_amount:string,total_amount:string} */
    public function calculate(CommercialDocumentType $type, string $subtotal): array
    {
        $subtotalCents = $this->cents($subtotal, 'subtotal');
        $rate = $type === CommercialDocumentType::Recibo ? '0' : (string) config('courses.igv_rate');
        $rateBasisPoints = $this->rateBasisPoints($rate);
        $igvCents = intdiv(($subtotalCents * $rateBasisPoints) + 5_000, 10_000);

        return [
            'subtotal_amount' => $this->format($subtotalCents),
            'igv_rate' => $this->formatRate($rateBasisPoints),
            'igv_amount' => $this->format($igvCents),
            'total_amount' => $this->format($subtotalCents + $igvCents),
        ];
    }

    /** @return array{subtotal_amount:string,igv_rate:string,igv_amount:string,total_amount:string} */
    public function calculateCharges(
        CommercialDocumentType $type,
        string $activityPrice,
        string $certificateCharge,
        string $discount,
    ): array {
        $subtotalCents = $this->cents($activityPrice, 'activity price')
            + $this->cents($certificateCharge, 'certificate charge')
            - $this->cents($discount, 'discount');

        if ($subtotalCents < 0) {
            throw new InvalidArgumentException('El subtotal no puede ser negativo.');
        }

        return $this->calculate($type, $this->format($subtotalCents));
    }

    /**
     * The breakdown of a group purchase: it sums the charges of the group's
     * billable enrollments and delegates the tax math to calculateCharges(), so
     * a group's money always comes from its own enrollments and never from a
     * fallback to zero.
     *
     * Enrollments in the terminal `withdrawn` and `no_show` states are left out
     * (design.md declares them terminal for eligibility): those participants no
     * longer receive the service or its certificate, so their charges are not
     * billable. Every other state is summed.
     *
     * @return array{subtotal_amount:string,igv_rate:string,igv_amount:string,total_amount:string}
     *
     * @throws InvalidArgumentException when the group has no billable enrollment or its aggregated subtotal is zero, so a zero-value document is never written silently
     */
    public function calculateGroupCharges(CommercialDocumentType $type, CourseEnrollmentGroup $group): array
    {
        $enrollments = $group->enrollments()
            ->whereNotIn('state', [
                CourseEnrollmentState::Withdrawn->value,
                CourseEnrollmentState::NoShow->value,
            ])
            ->get();

        if ($enrollments->isEmpty()) {
            throw new InvalidArgumentException('El grupo no tiene matrículas facturables: no es posible registrar un comprobante con total cero.');
        }

        $activityCents = 0;
        $certificateCents = 0;
        $discountCents = 0;

        foreach ($enrollments as $enrollment) {
            $activityCents += $this->cents((string) $enrollment->activity_price_amount, 'activity price');
            $certificateCents += $this->cents((string) $enrollment->certificate_charge_amount, 'certificate charge');
            $discountCents += $this->cents((string) $enrollment->discount_amount, 'discount');
        }

        if ($activityCents + $certificateCents - $discountCents === 0) {
            throw new InvalidArgumentException('El subtotal del grupo es cero: no es posible registrar un comprobante con total cero.');
        }

        return $this->calculateCharges(
            $type,
            $this->format($activityCents),
            $this->format($certificateCents),
            $this->format($discountCents),
        );
    }

    /** @param array<string, mixed> $attributes */
    public function register(CommercialDocumentType $type, array $attributes, User $actor): CourseCommercialDocument
    {
        Gate::forUser($actor)->authorize('manage', CourseCommercialDocument::class);

        // The writer owns the key rule: the column is CHAR(64), so a longer key
        // would not survive persistence and would silently break idempotency.
        // The request mirrors the same bound only so the user reads a field
        // error instead of a domain rejection.
        $operationKey = trim((string) ($attributes['operation_key'] ?? ''));
        if (strlen($operationKey) > 64) {
            throw new InvalidArgumentException('La clave de operación no puede superar los 64 caracteres.');
        }

        // A replay of one operation returns the document that operation already
        // registered instead of writing a second factura with the same total.
        // Without a key nothing changes, so a registration coming from an
        // external or legacy path keeps working exactly as before.
        if ($operationKey !== '') {
            $existing = $this->existingForOperationKey($operationKey);
            if ($existing !== null) {
                return $existing;
            }
        }

        $enrollmentId = $attributes['course_enrollment_id'] ?? null;
        $groupId = $attributes['course_enrollment_group_id'] ?? null;

        if (($enrollmentId === null && $groupId === null) || ($enrollmentId !== null && $groupId !== null)) {
            throw new InvalidArgumentException('Seleccione exactamente una matrícula o grupo de matrícula.');
        }

        $enrollment = $enrollmentId === null ? null : CourseEnrollment::query()->find($enrollmentId);
        if ($enrollmentId !== null && $enrollment === null) {
            throw new InvalidArgumentException('La matrícula seleccionada no existe.');
        }

        $group = $groupId === null ? null : CourseEnrollmentGroup::query()->find($groupId);
        if ($groupId !== null && $group === null) {
            throw new InvalidArgumentException('El grupo seleccionado no existe.');
        }

        $status = (string) ($attributes['status'] ?? 'pending_file');
        if ($status !== 'pending_file') {
            throw new InvalidArgumentException('Un comprobante sin archivo debe permanecer pendiente de archivo.');
        }

        $payerName = trim((string) ($attributes['payer_name'] ?? ''));
        if ($payerName === '') {
            throw new InvalidArgumentException('El pagador es obligatorio.');
        }

        // A group target has no single payer's charges to inherit, so its money
        // comes from the group's own enrollments: the aggregation refuses a zero
        // result instead of writing a zero-value document, and the payload can
        // never fabricate the group's charges. An enrollment target keeps the
        // Slice 4 behavior, and an explicit `subtotal_amount` still wins for both.
        $money = match (true) {
            isset($attributes['subtotal_amount']) => $this->calculate($type, (string) $attributes['subtotal_amount']),
            $group !== null => $this->calculateGroupCharges($type, $group),
            default => $this->calculateCharges(
                $type,
                (string) ($attributes['activity_price_amount'] ?? $enrollment?->activity_price_amount ?? '0'),
                (string) ($attributes['certificate_charge_amount'] ?? $enrollment?->certificate_charge_amount ?? '0'),
                (string) ($attributes['discount_amount'] ?? $enrollment?->discount_amount ?? '0'),
            ),
        };

        try {
            return CourseCommercialDocument::query()->create([
                'course_enrollment_id' => $enrollmentId,
                'course_enrollment_group_id' => $groupId,
                'idempotency_key' => $operationKey === '' ? null : $operationKey,
                'type' => $type,
                'series' => $attributes['series'] ?? null,
                'number' => $attributes['number'] ?? null,
                'issue_date' => $attributes['issue_date'] ?? null,
                'currency' => $attributes['currency'] ?? config('courses.default_currency'),
                'subtotal_amount' => $money['subtotal_amount'],
                'igv_rate' => $money['igv_rate'],
                'igv_amount' => $money['igv_amount'],
                'total_amount' => $money['total_amount'],
                'payer_name' => $payerName,
                'payer_document_type' => $attributes['payer_document_type'] ?? null,
                'payer_document_number' => $attributes['payer_document_number'] ?? null,
                'observations' => $attributes['observations'] ?? null,
                'status' => $status,
            ]);
        } catch (QueryException $exception) {
            // The two requests of a double submit can both miss the replay
            // lookup above and both try to insert; the unique index then lets
            // only one of them win. The loser resolves to the document the
            // winner registered, exactly like the delivery ledger does, instead
            // of duplicating money or surfacing an error.
            if ($operationKey === '') {
                throw $exception;
            }

            $existing = $this->existingForOperationKey($operationKey);
            if ($existing === null) {
                throw $exception;
            }

            return $existing;
        }
    }

    private function existingForOperationKey(string $operationKey): ?CourseCommercialDocument
    {
        return CourseCommercialDocument::query()->where('idempotency_key', $operationKey)->first();
    }

    public function upload(CourseCommercialDocument $commercial, UploadedFile $file, User $actor): \App\Models\Document
    {
        Gate::forUser($actor)->authorize('manage', $commercial);

        $previousDocumentId = $commercial->document_id;
        $document = $this->documents->upload($commercial, $file, $actor);

        $commercial->forceFill([
            'document_id' => $document->id,
            'status' => 'registered',
        ])->save();

        activity()
            ->performedOn($commercial)
            ->causedBy($actor)
            ->event($previousDocumentId === null ? 'course-commercial-document-attached' : 'course-commercial-document-replaced')
            ->withProperties([
                'previous_document_id' => $previousDocumentId,
                'document_id' => $document->id,
            ])
            ->log('Archivo de comprobante comercial adjuntado');

        return $document;
    }

    private function cents(string $amount, string $label): int
    {
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $amount)) {
            throw new InvalidArgumentException("Ingrese {$label} no negativo con hasta dos decimales.");
        }

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private function rateBasisPoints(string $rate): int
    {
        if (! preg_match('/^\d+(?:\.\d{1,4})?$/', $rate)) {
            throw new InvalidArgumentException('La tasa IGV configurada no es válida.');
        }

        [$whole, $fraction] = array_pad(explode('.', $rate, 2), 2, '');

        return ((int) $whole * 10_000) + (int) str_pad($fraction, 4, '0');
    }

    private function format(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    private function formatRate(int $basisPoints): string
    {
        return sprintf('%d.%04d', intdiv($basisPoints, 10_000), $basisPoints % 10_000);
    }
}
