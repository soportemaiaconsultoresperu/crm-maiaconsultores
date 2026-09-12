<?php

declare(strict_types=1);

namespace App\Http\Controllers\CourseTalks;

use App\Enums\Courses\AcademicDocumentType;
use App\Enums\Courses\CommercialDocumentType;
use App\Enums\Courses\DeliveryStatus;
use App\Http\Controllers\Controller;
use App\Models\Courses\CourseAcademicDocument;
use App\Models\Courses\CourseActivity;
use App\Models\Courses\CourseCommercialDocument;
use App\Models\Courses\CourseEdition;
use App\Models\Courses\CourseParticipant;
use App\Models\Notification\OutboundDelivery;
use App\Models\User;
use App\Services\Courses\CourseAlertService;
use App\Services\Courses\CourseDocumentDeliveryService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * The delivery alert screen of Cursos y charlas (Slice 7 unit 7.b): the
 * outstanding academic and commercial follow-ups in one filterable list, plus
 * the discard action 7.a introduced.
 *
 * Thin by design. Which documents are outstanding, which of them are overdue and
 * what closing a follow-up means all stay in {@see CourseAlertService}; whether a
 * document can still be delivered stays in
 * {@see CourseDocumentDeliveryService::hasDeliverableAcademicDocument()} and
 * {@see CourseDocumentDeliveryService::hasStreamableCommercialDocument()}. This
 * controller only narrows to the subset the operator asked for, assembles the
 * rows the view renders (which is where the filesystem-backed deliverability
 * verdict is read, never in Blade) and turns a domain rejection into a visible
 * message instead of an HTTP 500.
 */
class CourseAlertController extends Controller
{
    /**
     * The filter keys this screen accepts. Anything else in the query string is
     * ignored, so an unknown parameter can neither reach a query nor fail a
     * request.
     */
    private const FILTER_KEYS = [
        'activity_type',
        'edition_id',
        'participant_id',
        'responsible_user_id',
        'document_type',
        'delivery_status',
        'channel',
        'date_from',
        'date_to',
    ];

    /** The keys whose value must be a plain number. */
    private const ID_FILTER_KEYS = ['edition_id', 'participant_id', 'responsible_user_id'];

    private const DATE_FILTER_KEYS = ['date_from', 'date_to'];

    private const DATE_SHAPE = '/^\d{4}-\d{2}-\d{2}$/';

    /**
     * The relations the assembled rows read. Only what is rendered, so the screen
     * costs a fixed number of queries instead of one per row.
     */
    private const ACADEMIC_RELATIONS = [
        'enrollment.participant',
        'enrollment.edition.activity',
        'enrollment.edition.responsible',
    ];

    private const COMMERCIAL_RELATIONS = [
        'enrollment.participant',
        'enrollment.edition.activity',
        'enrollment.edition.responsible',
        'group.edition.activity',
        'group.edition.responsible',
    ];

    public function __construct(private readonly CourseAlertService $alerts) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', CourseActivity::class);

        $viewer = $request->user();
        [$filters, $invalidFilters] = $this->filters($request);

        // The entity filters offer the values the whole outstanding set contains,
        // not the values the narrowed result contains, so an active filter can
        // always be changed back. The rows come from the filtered query.
        $allAcademic = $this->alerts->outstandingAcademicDocuments()->with(self::ACADEMIC_RELATIONS)->get();
        $allCommercial = $this->alerts->outstandingCommercialDocuments()->with(self::COMMERCIAL_RELATIONS)->get();

        $academic = $filters === []
            ? $allAcademic
            : $this->alerts->outstandingAcademicDocuments($filters)->with(self::ACADEMIC_RELATIONS)->get();

        $commercial = $filters === []
            ? $allCommercial
            : $this->alerts->outstandingCommercialDocuments($filters)->with(self::COMMERCIAL_RELATIONS)->get();

        // The overdue rule is the domain's, never re-derived here: the rows that
        // are overdue are exactly the ones its own query returns.
        $overdue = [
            'academico' => array_flip($this->alerts->overdueAcademicDocuments()->pluck('id')->map(intval(...))->all()),
            'comercial' => array_flip($this->alerts->overdueCommercialDocuments()->pluck('id')->map(intval(...))->all()),
        ];

        $deliveries = $this->deliveryHistory($academic, $commercial);

        // The read-only face of the delivery service: only its side-effect-free
        // deliverability predicates are used, and they stay the single owner of
        // the rule. The mail closure is the same unused placeholder the other read
        // surfaces pass.
        $deliveryService = new CourseDocumentDeliveryService(static fn (): bool => true);

        $rows = [];

        foreach ($academic as $document) {
            $rows[] = $this->academicRow($document, $deliveries, $overdue, $deliveryService, $viewer);
        }

        foreach ($commercial as $document) {
            $rows[] = $this->commercialRow($document, $deliveries, $overdue, $deliveryService, $viewer);
        }

        usort(
            $rows,
            static fn (array $left, array $right): int => [$left['anchor'], $left['kind'], $left['id']]
                <=> [$right['anchor'], $right['kind'], $right['id']],
        );

        return view('course-talks.alerts.index', [
            'rows' => $rows,
            // The counters are the aggregate numbers the alert domain computes,
            // never a count of the rendered rows: the screen shows the module's
            // workload even while the list is filtered.
            'pendingCount' => $this->alerts->pendingCount(),
            'overdueCount' => $this->alerts->overdueCount(),
            'filters' => $filters,
            'invalidFilters' => $invalidFilters,
            'editionOptions' => $this->editionOptions($allAcademic, $allCommercial),
            'participantOptions' => $this->participantOptions($allAcademic, $allCommercial),
            'responsibleOptions' => $this->responsibleOptions($allAcademic, $allCommercial),
        ]);
    }

    /**
     * Close an academic follow-up without touching the certificate. The ability is
     * the same one 7.a enforces inside the domain (`send`); this boundary check
     * only turns it into a 403 before anything is attempted.
     */
    public function discardAcademic(Request $request, CourseAcademicDocument $academicDocument): RedirectResponse
    {
        return $this->discard($request, $academicDocument);
    }

    /**
     * Close a commercial follow-up without touching the comprobante. Both routes
     * need their own model binding: Laravel resolves one model class per route
     * parameter, so a single `{document}` parameter could only be resolved by
     * hand and would lose route-model binding and its 404.
     */
    public function discardCommercial(Request $request, CourseCommercialDocument $commercialDocument): RedirectResponse
    {
        return $this->discard($request, $commercialDocument);
    }

    private function discard(Request $request, CourseAcademicDocument|CourseCommercialDocument $document): RedirectResponse
    {
        Gate::authorize('send', $document);

        // A scalar is required: a reason posted as an array must not reach a string
        // cast, which would fail the request instead of reporting a missing reason.
        $reason = $request->input('reason');
        $reason = is_scalar($reason) ? (string) $reason : '';

        try {
            $this->alerts->discard($document, $reason, $request->user());
        } catch (InvalidArgumentException $exception) {
            // The domain owns the rule (a blank reason, a follow-up already closed
            // by a send) and reports it in Spanish, so its own sentence is what the
            // operator reads.
            return $this->backToList()->withInput()->withErrors(['alerts' => $exception->getMessage()]);
        }

        return $this->backToList()->with('status', 'Alerta de entrega descartada. El documento sigue vigente.');
    }

    private function backToList(): RedirectResponse
    {
        return redirect()->route('course-talks.alerts.index');
    }

    /**
     * The filter payload and the keys the operator asked for with a value this
     * screen does not know.
     *
     * A value that is not a scalar is dropped entirely: it is not a filter, and it
     * must never reach a query. A scalar value is passed through even when it is
     * unknown, so an unknown value narrows the list to nothing (an observable
     * result) while the screen also reports it instead of failing.
     *
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function filters(Request $request): array
    {
        $filters = [];
        $invalid = [];

        foreach (self::FILTER_KEYS as $key) {
            $raw = $request->query($key);

            if (! is_scalar($raw)) {
                if ($raw !== null) {
                    $invalid[] = $key;
                }

                continue;
            }

            $value = trim((string) $raw);

            if ($value === '') {
                continue;
            }

            $filters[$key] = $this->castFilterValue($key, $value);

            if (! $this->isKnownFilterValue($key, $value)) {
                $invalid[] = $key;
            }
        }

        return [$filters, $invalid];
    }

    /**
     * Ids travel as integers so no driver has to compare a numeric column against
     * a non-numeric string.
     */
    private function castFilterValue(string $key, string $value): int|string
    {
        return in_array($key, self::ID_FILTER_KEYS, true) && ctype_digit($value)
            ? (int) $value
            : $value;
    }

    /**
     * Whether a value belongs to the vocabulary the screen documents. Only used to
     * report the value back to the operator: an unknown value still narrows the
     * list, it is never silently treated as if no filter had been sent.
     */
    private function isKnownFilterValue(string $key, string $value): bool
    {
        return match ($key) {
            'edition_id', 'participant_id', 'responsible_user_id' => ctype_digit($value) && (int) $value > 0,
            'activity_type' => in_array($value, ['course', 'talk'], true),
            'document_type' => $this->isKnownDocumentType($value),
            'delivery_status' => in_array($value, array_column(DeliveryStatus::cases(), 'value'), true),
            'channel' => in_array($value, [OutboundDelivery::CHANNEL_MAIL, OutboundDelivery::CHANNEL_WHATSAPP], true),
            'date_from', 'date_to' => preg_match(self::DATE_SHAPE, $value) === 1,
            default => false,
        };
    }

    private function isKnownDocumentType(string $value): bool
    {
        $academic = array_column(AcademicDocumentType::cases(), 'value');
        $commercial = array_column(CommercialDocumentType::cases(), 'value');

        return in_array($value, [...$academic, ...$commercial], true);
    }

    /**
     * The delivery history of every listed document, read once. The append-only
     * ledger is the only source of delivery truth; this surface never writes it.
     *
     * @param  Collection<int, CourseAcademicDocument>  $academic
     * @param  Collection<int, CourseCommercialDocument>  $commercial
     * @return Collection<string, Collection<int, OutboundDelivery>>
     */
    private function deliveryHistory(Collection $academic, Collection $commercial): Collection
    {
        return OutboundDelivery::query()
            ->where(function (Builder $query) use ($academic, $commercial): void {
                $query->where(function (Builder $inner) use ($academic): void {
                    $inner->where('related_entity_type', CourseAcademicDocument::class)
                        ->whereIn('related_entity_id', $academic->pluck('id'));
                })->orWhere(function (Builder $inner) use ($commercial): void {
                    $inner->where('related_entity_type', CourseCommercialDocument::class)
                        ->whereIn('related_entity_id', $commercial->pluck('id'));
                });
            })
            ->orderBy('id')
            ->get()
            ->groupBy(static fn (OutboundDelivery $delivery): string => $delivery->related_entity_type.':'.$delivery->related_entity_id);
    }

    /**
     * @param  Collection<string, Collection<int, OutboundDelivery>>  $deliveries
     * @param  array<string, array<int, int>>  $overdue
     * @return array<string, mixed>
     */
    private function academicRow(
        CourseAcademicDocument $document,
        Collection $deliveries,
        array $overdue,
        CourseDocumentDeliveryService $deliveryService,
        User $viewer,
    ): array {
        $edition = $this->editionOf($document);
        $participant = $document->enrollment?->participant;
        $history = $deliveries->get(CourseAcademicDocument::class.':'.$document->id, collect());

        return [
            'kind' => 'academico',
            'id' => (int) $document->id,
            'reference' => (string) $document->code,
            'document_type' => $document->type->value,
            'activity' => $edition?->activity?->name,
            'activity_type' => $edition?->activity?->type?->value,
            'edition_id' => $edition?->id,
            'edition_label' => $this->editionLabel($edition),
            'participant_id' => $participant?->id,
            'participant_label' => $this->participantLabel($participant),
            'responsible_user_id' => $edition?->responsible?->id,
            'responsible_label' => $edition?->responsible?->name,
            'delivery_status' => $document->delivery_status->value,
            'anchor' => $this->anchorOf($document->issue_date?->toDateString() ?? $document->created_at?->toDateString()),
            'overdue' => isset($overdue['academico'][(int) $document->id]),
            // The verdict of the delivery domain's own predicate, read here (never
            // in Blade): this is what tells "waiting to be sent" apart from "cannot
            // be sent until the private file is restored".
            'deliverable' => $deliveryService->hasDeliverableAcademicDocument($document),
            'can_discard' => Gate::forUser($viewer)->allows('send', $document),
            'discard_url' => route('course-talks.alerts.academic-discard', $document),
            ...$this->historySummary($history),
        ];
    }

    /**
     * @param  Collection<string, Collection<int, OutboundDelivery>>  $deliveries
     * @param  array<string, array<int, int>>  $overdue
     * @return array<string, mixed>
     */
    private function commercialRow(
        CourseCommercialDocument $document,
        Collection $deliveries,
        array $overdue,
        CourseDocumentDeliveryService $deliveryService,
        User $viewer,
    ): array {
        $edition = $this->editionOf($document);
        $participant = $document->enrollment?->participant;
        $history = $deliveries->get(CourseCommercialDocument::class.':'.$document->id, collect());

        return [
            'kind' => 'comercial',
            'id' => (int) $document->id,
            'reference' => $this->commercialReference($document),
            'document_type' => $document->type->value,
            'activity' => $edition?->activity?->name,
            'activity_type' => $edition?->activity?->type?->value,
            'edition_id' => $edition?->id,
            'edition_label' => $this->editionLabel($edition),
            'participant_id' => $participant?->id,
            // A group comprobante has no participant: the payer is who the operator
            // has to reach, and the payer name is the field the commercial screens
            // already show.
            'participant_label' => $participant !== null
                ? $this->participantLabel($participant)
                : ($document->payer_name ?: 'Comprobante de grupo'),
            'responsible_user_id' => $edition?->responsible?->id,
            'responsible_label' => $edition?->responsible?->name,
            'delivery_status' => $document->delivery_status->value,
            'anchor' => $this->anchorOf($document->issue_date?->toDateString() ?? $document->created_at?->toDateString()),
            'overdue' => isset($overdue['comercial'][(int) $document->id]),
            'deliverable' => $deliveryService->hasStreamableCommercialDocument($document),
            'can_discard' => Gate::forUser($viewer)->allows('send', $document),
            'discard_url' => route('course-talks.alerts.commercial-discard', $document),
            ...$this->historySummary($history),
        ];
    }

    /**
     * @param  Collection<int, OutboundDelivery>  $history
     * @return array<string, mixed>
     */
    private function historySummary(Collection $history): array
    {
        $latest = $history->last();

        return [
            'history_count' => $history->count(),
            'channel' => $latest?->channel,
            'last_attempt_status' => $latest?->status,
            'last_attempt_at' => $latest?->updated_at?->format('d/m/Y H:i'),
            'last_error' => $latest?->last_error,
        ];
    }

    /**
     * A comprobante reaches its edition through its enrollment or through its group
     * purchase; both are tried so a group comprobante is never shown as orphaned.
     */
    private function editionOf(CourseAcademicDocument|CourseCommercialDocument $document): ?CourseEdition
    {
        if ($document instanceof CourseAcademicDocument) {
            return $document->enrollment?->edition;
        }

        return $document->enrollment?->edition ?? $document->group?->edition;
    }

    private function editionLabel(?CourseEdition $edition): ?string
    {
        if ($edition === null) {
            return null;
        }

        return ($edition->activity?->name ?? 'Actividad sin nombre').' · '.($edition->code ?: 'Edición #'.$edition->id);
    }

    private function participantLabel(?CourseParticipant $participant): ?string
    {
        if ($participant === null) {
            return null;
        }

        return trim($participant->last_name.', '.$participant->first_name, ' ,');
    }

    private function commercialReference(CourseCommercialDocument $document): string
    {
        $series = trim((string) $document->series);
        $number = trim((string) $document->number);

        return ($series === '' || $number === '') ? 'Comprobante #'.$document->id : $series.'-'.$number;
    }

    private function anchorOf(?string $anchor): string
    {
        return $anchor ?? '—';
    }

    /**
     * The editions, participants and responsibles the filters may offer, read from
     * the whole outstanding set so no filter offers a value that matches nothing.
     *
     * @param  Collection<int, CourseAcademicDocument>  $academic
     * @param  Collection<int, CourseCommercialDocument>  $commercial
     * @return array<int, string>
     */
    private function editionOptions(Collection $academic, Collection $commercial): array
    {
        $options = [];

        foreach ([...$academic->all(), ...$commercial->all()] as $document) {
            $edition = $this->editionOf($document);

            if ($edition !== null) {
                $options[(int) $edition->id] = $this->editionLabel($edition) ?? 'Edición #'.$edition->id;
            }
        }

        ksort($options);

        return $options;
    }

    /**
     * @param  Collection<int, CourseAcademicDocument>  $academic
     * @param  Collection<int, CourseCommercialDocument>  $commercial
     * @return array<int, string>
     */
    private function participantOptions(Collection $academic, Collection $commercial): array
    {
        $options = [];

        foreach ([...$academic->all(), ...$commercial->all()] as $document) {
            $participant = $document->enrollment?->participant;

            if ($participant !== null) {
                $options[(int) $participant->id] = $this->participantLabel($participant) ?? 'Participante #'.$participant->id;
            }
        }

        ksort($options);

        return $options;
    }

    /**
     * @param  Collection<int, CourseAcademicDocument>  $academic
     * @param  Collection<int, CourseCommercialDocument>  $commercial
     * @return array<int, string>
     */
    private function responsibleOptions(Collection $academic, Collection $commercial): array
    {
        $options = [];

        foreach ([...$academic->all(), ...$commercial->all()] as $document) {
            $responsible = $this->editionOf($document)?->responsible;

            if ($responsible !== null) {
                $options[(int) $responsible->id] = $responsible->name;
            }
        }

        ksort($options);

        return $options;
    }
}
