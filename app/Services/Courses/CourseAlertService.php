<?php

declare(strict_types=1);

namespace App\Services\Courses;

use App\Enums\Courses\AcademicDocumentStatus;
use App\Enums\Courses\DeliveryStatus;
use App\Models\Courses\CourseAcademicDocument;
use App\Models\Courses\CourseCommercialDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * The delivery follow-up alert domain for Cursos y charlas.
 *
 * Every alert rule lives here: which documents still demand the operator's
 * action, when one of them becomes overdue, and what closing the follow-up
 * means. Dashboards, controllers and views read these queries; they never
 * recompute a rule.
 *
 * The read side is side-effect free on purpose: `pendingCount()` and friends
 * write nothing, so a dashboard may call them on every page load.
 */
final class CourseAlertService
{
    /**
     * The delivery snapshot values that still need an operator action. `sent`
     * closed the follow-up by sending it and `discarded` closed it explicitly;
     * `failed` did NOT close anything — a failed send is precisely the case the
     * operator has to act on again.
     */
    private const OUTSTANDING_DELIVERY_STATUSES = [
        DeliveryStatus::Pending->value,
        DeliveryStatus::Failed->value,
    ];

    /**
     * The commercial document statuses whose document can still be served, the
     * same pair the delivery channel itself accepts for streaming.
     */
    private const SERVABLE_COMMERCIAL_STATUSES = ['registered', 'sent'];

    /**
     * Outstanding academic follow-ups: the certificate is usable (current, QR
     * live) and its delivery has not been closed by a send.
     *
     * `pending_generation` and `failed` never produced a document, and an
     * annulled or replaced certificate — or one whose QR was revoked — can no
     * longer be served to the recipient, so demanding a delivery follow-up for
     * them would ask the operator for something the delivery domain itself
     * refuses (see `hasDeliverableAcademicDocument()`).
     *
     * @return Builder<CourseAcademicDocument>
     */
    public function pendingAcademicDocuments(): Builder
    {
        return CourseAcademicDocument::query()
            ->where('status', AcademicDocumentStatus::Current->value)
            ->whereNull('qr_token_revoked_at')
            ->whereIn('delivery_status', self::OUTSTANDING_DELIVERY_STATUSES);
    }

    /**
     * Outstanding commercial follow-ups: the comprobante is servable (its
     * private attachment was uploaded) and its delivery has not been closed.
     *
     * `pending_file` has no attachment to send yet and `discarded` was closed
     * at document level; neither is a delivery follow-up.
     *
     * @return Builder<CourseCommercialDocument>
     */
    public function pendingCommercialDocuments(): Builder
    {
        return CourseCommercialDocument::query()
            ->whereIn('status', self::SERVABLE_COMMERCIAL_STATUSES)
            ->whereIn('delivery_status', self::OUTSTANDING_DELIVERY_STATUSES);
    }

    /**
     * The overdue slice of the pending follow-ups: the same documents, once the
     * configured calendar days have elapsed since their follow-up started.
     *
     * @return Builder<CourseAcademicDocument>
     */
    public function overdueAcademicDocuments(): Builder
    {
        return $this->overdue($this->pendingAcademicDocuments());
    }

    /** @return Builder<CourseCommercialDocument> */
    public function overdueCommercialDocuments(): Builder
    {
        return $this->overdue($this->pendingCommercialDocuments());
    }

    public function pendingCount(): int
    {
        return $this->count($this->pendingAcademicDocuments(), $this->pendingCommercialDocuments());
    }

    /**
     * Overdue is a subset of pending: it answers "how many of the pending
     * follow-ups have waited too long", not "how many are late and no longer
     * pending".
     */
    public function overdueCount(): int
    {
        return $this->count($this->overdueAcademicDocuments(), $this->overdueCommercialDocuments());
    }

    /**
     * Close a delivery follow-up by discarding it, without touching the document
     * itself.
     *
     * A discard records WHY (a required non-empty reason) and WHO (the actor,
     * both on the audit entry and, for the reason, on the document's own
     * `delivery_discard_reason`), and appends the audit entry. It changes only
     * the delivery follow-up: the certificate keeps its `current` status, its
     * QR token hash and its private file, and the comprobante keeps its amount
     * and attachment — the certificate or comprobante stays usable after its
     * follow-up was discarded (spec: discarding changes only the delivery
     * follow-up, not document validity).
     *
     * A follow-up already closed by a successful send is refused: overwriting
     * that snapshot with `discarded` would claim it was never sent.
     */
    public function discard(
        CourseAcademicDocument|CourseCommercialDocument $document,
        string $reason,
        User $actor,
    ): CourseAcademicDocument|CourseCommercialDocument {
        Gate::forUser($actor)->authorize('send', $document);

        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Descartar la alerta requiere un motivo.');
        }

        $previous = $document->delivery_status;
        if ($previous === DeliveryStatus::Sent) {
            throw new InvalidArgumentException('Una entrega enviada no se puede descartar.');
        }

        $document->forceFill([
            'delivery_status' => DeliveryStatus::Discarded,
            'delivery_discard_reason' => $reason,
        ])->save();

        activity()
            ->performedOn($document)
            ->causedBy($actor)
            ->event('course-delivery-alert-discarded')
            ->withProperties([
                'reason' => $reason,
                'previous_delivery_status' => $previous?->value,
            ])
            ->log('Alerta de entrega descartada con motivo');

        return $document->fresh();
    }

    /**
     * The overdue comparison is a pure calendar-day comparison: the follow-up
     * is overdue when its anchor day is strictly BEFORE `today - due_days`. A
     * document issued exactly N calendar days ago is therefore still pending,
     * and becomes overdue on the following calendar day; a document issued at
     * 23:00 is not pushed over by the passage of an hour, only by the passage
     * of a calendar day.
     *
     * Writing it as a cutoff date instead of SQL date arithmetic keeps the same
     * query on every driver and leaves the comparison to the database.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function overdue(Builder $query): Builder
    {
        return $query->whereRaw('COALESCE(issue_date, DATE(created_at)) < ?', [$this->overdueCutoff()]);
    }

    private function overdueCutoff(): string
    {
        $dueDays = max(0, (int) config('courses.delivery_due_days', 1));

        return now()->startOfDay()->subDays($dueDays)->toDateString();
    }

    /**
     * Both channels are counted through the same expression, so a dashboard's
     * total can never drift from the per-channel lists it links to.
     *
     * @param  Builder<Model>  ...$queries
     */
    private function count(Builder ...$queries): int
    {
        return array_sum(array_map(static fn (Builder $query): int => $query->count(), $queries));
    }
}
