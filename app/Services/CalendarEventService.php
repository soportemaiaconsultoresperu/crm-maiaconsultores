<?php

namespace App\Services;

use App\Enums\InternalProjects\TaskStatus;
use App\Models\Activity;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\InternalActivity;
use App\Models\InternalProjects\InternalProject;
use App\Models\InternalProjects\InternalProjectTask;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\Calendar\CalendarEventItem;
use App\Support\Content\PeruTime;
use App\Support\Content\ProjectColor;
use App\Support\DateRange;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CalendarEventService
{
    public function __construct(
        private readonly ActivityService $activities,
        private readonly DataScopeService $dataScope,
    ) {}

    /**
     * @param array<string, mixed> $filters
     * @return Collection<int, CalendarEventItem>
     */
    public function events(User $user, DateRange $range, array $filters = []): Collection
    {
        // Calendar layer (odd/tasks/internal-activities.md): '' shows commercial
        // and internal events, 'commercial' hides internal ones, 'internal'
        // shows only the organization's internal activities. 'projects'
        // (odd/tasks/internal-projects.md, S4.3) shows only internal project
        // milestones and task due dates; '' includes them too.
        $layer = (string) ($filters['layer'] ?? '');
        unset($filters['layer']);

        if ($layer === 'internal') {
            return $this->internalEvents($user, $range)->sortBy('scheduled_at')->values();
        }

        if ($layer === 'projects') {
            return $this->projectEvents($user, $range)->sortBy('scheduled_at')->values();
        }

        $commercial = $this->commercialEvents($user, $range, $filters);

        if ($layer === 'commercial' || $this->hasCommercialOnlyFilters($filters)) {
            return $commercial;
        }

        return $commercial
            ->merge($this->internalEvents($user, $range))
            ->merge($this->projectEvents($user, $range))
            ->sortBy('scheduled_at')
            ->values();
    }

    /**
     * Internal activities and project tasks carry no subject, owner, type or
     * commercial status, so any of those filters means the user is narrowing
     * commercial events only.
     *
     * @param array<string, mixed> $filters
     */
    private function hasCommercialOnlyFilters(array $filters): bool
    {
        foreach (['type_id', 'subject_type', 'owner_id', 'status'] as $key) {
            if (! empty($filters[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Collection<int, CalendarEventItem>
     */
    private function internalEvents(User $user, DateRange $range): Collection
    {
        // Recurring activities expand into one event per occurrence (T2).
        return collect(InternalActivity::query()
            ->visibleTo($user)
            ->occurringBetween($range->start(), $range->end())
            ->with('participants:id,name')
            ->orderBy('starts_at')
            ->get()
            ->flatMap(fn (InternalActivity $activity) => array_map(fn ($occurrence) => new CalendarEventItem(
                kind: 'internal',
                id: 'internal-'.$activity->id.'-'.$occurrence->format('Ymd'),
                scheduled_at: $occurrence,
                title: $activity->title,
                status: 'internal',
                typeLabel: 'Interno',
                subjectLabel: $activity->location ?: '—',
                ownerName: $activity->responsible()?->name,
                url: route('internal-activities.show', $activity),
            ), $activity->occurrencesBetween($range->start(), $range->end())))
            ->all());
    }

    /**
     * Milestones and due dates of open tasks of the projects the user may see.
     * Done tasks drop out (nothing left to watch) but milestones always show
     * so the plan stays readable. A due date is a Lima calendar day with no
     * hour, so the event sits at that day's start and renders as all-day.
     *
     * @return Collection<int, CalendarEventItem>
     */
    private function projectEvents(User $user, DateRange $range): Collection
    {
        $today = InternalProjectTask::limaToday();

        return collect(InternalProjectTask::query()
            ->whereHas('project', fn (Builder $project) => $project->visibleTo($user))
            ->whereNotNull('due_on')
            ->whereDate('due_on', '>=', $range->start()->toDateString())
            ->whereDate('due_on', '<=', $range->end()->toDateString())
            ->where(fn (Builder $open) => $open->where('is_milestone', true)
                ->orWhere('status', '!=', TaskStatus::Done->value))
            ->with(['project:id,name,code,color', 'responsible:id,name'])
            ->orderBy('due_on')
            ->orderBy('id')
            ->get()
            ->map(fn (InternalProjectTask $task) => $this->fromProjectTask($task, $today))
            ->all());
    }

    private function fromProjectTask(InternalProjectTask $task, string $today): CalendarEventItem
    {
        /** @var InternalProject $project */
        $project = $task->project;
        $dueOn = $task->due_on->toDateString();

        return new CalendarEventItem(
            kind: 'project_task',
            id: 'project-task-'.$task->id,
            scheduled_at: CarbonImmutable::parse($dueOn, PeruTime::TIMEZONE)->startOfDay(),
            title: ($task->is_milestone ? '◆ ' : '').'['.$project->code.'] '.$task->title,
            status: $task->status->value,
            typeLabel: $task->is_milestone ? 'Hito' : 'Proyecto',
            subjectLabel: $project->name,
            ownerName: $task->responsible?->name,
            url: route('internal-projects.show', $project).'#task-'.$task->id,
            allDay: true,
            style: ProjectColor::softStyle($project->color),
            overdue: ! $task->isDone() && $dueOn < $today,
        );
    }

    /**
     * @param array<string, mixed> $filters
     * @return Collection<int, CalendarEventItem>
     */
    private function commercialEvents(User $user, DateRange $range, array $filters): Collection
    {
        $activityItems = collect($this->activities
                ->calendarEvents($user, $range, $filters)
                ->map(fn (Activity $activity) => $this->fromActivity($activity))
                ->all());

        if (isset($filters['type_id']) || ! $user->can('customer-payments.view')) {
            return $activityItems->sortBy('scheduled_at')->values();
        }

        return $activityItems
            ->merge($this->invoiceEvents($user, $range, $filters))
            ->sortBy('scheduled_at')
            ->values();
    }

    /**
     * @param array<string, mixed> $filters
     * @return Collection<int, CalendarEventItem>
     */
    private function invoiceEvents(User $user, DateRange $range, array $filters): Collection
    {
        if (! empty($filters['subject_type']) && $filters['subject_type'] !== 'customer') {
            return collect();
        }

        $visibleOwnerIds = $this->dataScope->visibleOwnerIds($user);

        $query = CustomerInvoice::query()
            ->active()
            ->chargeable()
            ->dueBetween($range->start()->toDateString(), $range->end()->toDateString())
            ->with(['customer.owner:id,name', 'status:id,name,slug'])
            ->whereHas('customer', function ($customerQuery) use ($visibleOwnerIds, $filters): void {
                if ($visibleOwnerIds !== null) {
                    $customerQuery->whereIntegerInRaw('owner_id', $visibleOwnerIds);
                }

                if (! empty($filters['owner_id'])) {
                    $customerQuery->where('owner_id', (int) $filters['owner_id']);
                }
            })
            ->orderBy('due_date')
            ->orderBy('id');

        return collect($query->get()->map(fn (CustomerInvoice $invoice) => $this->fromInvoice($invoice))->all());
    }

    private function fromActivity(Activity $activity): CalendarEventItem
    {
        return new CalendarEventItem(
            kind: 'activity',
            id: $activity->id,
            scheduled_at: $activity->scheduled_at,
            title: $activity->title,
            status: $activity->status,
            typeLabel: $activity->type?->name ?? '—',
            subjectLabel: $this->activitySubjectLabel($activity),
            ownerName: $activity->owner?->name,
            url: route('activities.show', $activity),
        );
    }

    private function fromInvoice(CustomerInvoice $invoice): CalendarEventItem
    {
        $customer = $invoice->customer;
        $customerLabel = $customer instanceof Customer
            ? ($customer->trade_name ?: $customer->legal_name ?: $customer->code)
            : 'Cliente';

        return new CalendarEventItem(
            kind: 'invoice_due',
            id: 'invoice-'.$invoice->id,
            scheduled_at: $invoice->due_date->startOfDay(),
            title: 'Factura '.$invoice->invoice_number.' — '.$customerLabel,
            status: $invoice->status?->slug ?? 'invoice',
            typeLabel: 'Factura',
            subjectLabel: $customerLabel,
            ownerName: $customer?->owner?->name,
            url: route('customers.show', $customer).'#invoice-'.$invoice->id,
            amount: number_format((float) $invoice->total_amount, 2),
            requiresFinancialRead: true,
        );
    }

    private function activitySubjectLabel(Activity $activity): string
    {
        $subject = $activity->subject;

        return match (true) {
            $subject instanceof Customer => $subject->code,
            $subject instanceof Lead => $subject->code,
            $subject instanceof Opportunity => $subject->code,
            $subject instanceof SupportTicket => $subject->code,
            default => '—',
        };
    }
}
