<?php

namespace App\Services\Courses;

use App\Enums\Courses\CommercialDocumentType;
use App\Models\Courses\CourseCommercialDocument;
use App\Models\Courses\CourseEnrollment;
use App\Models\Courses\CourseEnrollmentGroup;
use App\Models\User;
use App\Services\DocumentService;
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

    /** @param array<string, mixed> $attributes */
    public function register(CommercialDocumentType $type, array $attributes, User $actor): CourseCommercialDocument
    {
        Gate::forUser($actor)->authorize('manage', CourseCommercialDocument::class);

        $enrollmentId = $attributes['course_enrollment_id'] ?? null;
        $groupId = $attributes['course_enrollment_group_id'] ?? null;

        if (($enrollmentId === null && $groupId === null) || ($enrollmentId !== null && $groupId !== null)) {
            throw new InvalidArgumentException('Seleccione exactamente una matrícula o grupo de matrícula.');
        }

        $enrollment = $enrollmentId === null ? null : CourseEnrollment::query()->find($enrollmentId);
        if ($enrollmentId !== null && $enrollment === null) {
            throw new InvalidArgumentException('La matrícula seleccionada no existe.');
        }

        if ($groupId !== null && ! CourseEnrollmentGroup::query()->whereKey($groupId)->exists()) {
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

        $money = isset($attributes['subtotal_amount'])
            ? $this->calculate($type, (string) $attributes['subtotal_amount'])
            : $this->calculateCharges(
                $type,
                (string) ($attributes['activity_price_amount'] ?? $enrollment?->activity_price_amount ?? '0'),
                (string) ($attributes['certificate_charge_amount'] ?? $enrollment?->certificate_charge_amount ?? '0'),
                (string) ($attributes['discount_amount'] ?? $enrollment?->discount_amount ?? '0'),
            );

        return CourseCommercialDocument::query()->create([
            'course_enrollment_id' => $enrollmentId,
            'course_enrollment_group_id' => $groupId,
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
