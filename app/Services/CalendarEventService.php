<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\Calendar\CalendarEventItem;
use App\Support\DateRange;
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
