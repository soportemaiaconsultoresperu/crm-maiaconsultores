<?php

namespace App\Services;

use App\Events\V2\CustomerDeactivated;
use App\Models\Customer;
use App\Models\User;
use App\Support\NormalizesContactData;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Customer business logic (B03, Tanda A). Same patterns as LeadService:
 * codes and *_norm columns are written inside one transaction, updates
 * recompute norms, deactivation is a soft delete with a reason, and the
 * history() timeline merges CRM activities and activitylog entries from
 * both the customer and its origin lead (ADR-001).
 */
class CustomerService
{
    use NormalizesContactData;

    public function __construct(
        private readonly CodeGeneratorService $codes,
    ) {}

    /**
     * Create a customer: CLI code + normalized fields + audit columns in
     * one transaction (RF-CLI-002, ADR-002). Minimum invariants are
     * asserted here; full validation lives in CustomerStoreRequest.
     */
    public function create(array $data, User $actor): Customer
    {
        $this->assertCreatable($data);

        return DB::transaction(function () use ($data, $actor): Customer {
            $data['code'] = $this->codes->next('customer');

            $data = self::applyNormalizations($data);

            $data['status'] ??= 'activo';
            $data['owner_id'] ??= $actor->id;

            $customer = new Customer($data);
            $customer->created_by = $actor->id;
            $customer->updated_by = $actor->id;
            $customer->save();

            return $customer->refresh();
        });
    }

    /**
     * Update a customer and recompute the *_norm columns. The code is never
     * editable. Field-level changes are logged by the model's activitylog
     * configuration.
     */
    public function update(Customer $customer, array $data, User $actor): Customer
    {
        DB::transaction(function () use ($customer, $data, $actor): void {
            unset($data['code'], $data['created_by'], $data['updated_by']);

            $customer->fill($data);
            self::applyNormalizations($customer->getAttributes(), $customer);
            $customer->updated_by = $actor->id;
            $customer->save();
        });

        return $customer->refresh();
    }

    /**
     * Deactivate (soft delete) a customer with a mandatory reason. Customers
     * are never physically deleted (RF-CLI-003, RNF-DAT-001).
     */
    public function deactivate(Customer $customer, User $actor, string $reason): Customer
    {
        DB::transaction(function () use ($customer, $actor, $reason): void {
            $customer->updated_by = $actor->id;
            $customer->delete();

            activity()
                ->performedOn($customer)
                ->causedBy($actor)
                ->event('customer-deactivated')
                ->withProperties(['reason' => $reason])
->log("Cliente {$customer->code} desactivado: {$reason}");
        });

        // V2 (B12): automation engine emission after the transaction
        // commits. Never inside DB::transaction.
        event(new CustomerDeactivated($customer, $actor));

        return $customer;
    }

    /**
     * Merged customer timeline (RF-CLI-005, ADR-001): the customer's own CRM
     * activities and activitylog entries, PLUS — when the customer came from
     * a lead — the origin lead's activities and activitylog entries, so the
     * commercial history is preserved and shown on the customer record.
     *
     * Each item is an array shaped for Blade (same shape as
     * LeadService::history):
     * [
     *     'kind'   => 'activity' | 'log',
     *     'at'     => Carbon,
     *     'title'  => string,
     *     'detail' => ?string,
     *     'meta'   => [...],   // status/type/event + 'origin': customer|lead
     *     'model'  => Activity|\Spatie\Activitylog\Models\Activity|null,
     * ]
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function history(Customer $customer): Collection
    {
        $entries = collect()
            ->merge($this->activityEntries($customer, 'customer'))
            ->merge($this->logEntries($customer::class, $customer->id, 'customer'));

        $lead = $customer->convertedFromLead;

        if ($lead !== null) {
            $entries = $entries
                ->merge($this->activityEntries($lead, 'lead'))
                ->merge($this->logEntries($lead::class, $lead->id, 'lead'));
        }

        return $entries
            ->sortByDesc(fn (array $item) => $item['at']->getTimestamp())
            ->values();
    }

    /**
     * Minimum service-level invariants; exhaustive validation runs earlier
     * in CustomerStoreRequest (Tanda B wires the HTTP layer).
     *
     * @param  array<string, mixed>  $data
     */
    private function assertCreatable(array $data): void
    {
        if (! in_array($data['person_type'] ?? null, ['natural', 'juridica'], true)) {
            throw new \InvalidArgumentException(
                'person_type is required and must be "natural" or "juridica".'
            );
        }

        if (empty($data['legal_name'])) {
            throw new \InvalidArgumentException('legal_name is required.');
        }
    }

    /**
     * CRM activities of a timeline subject mapped to the shared entry shape.
     *
     * @param  Customer|\App\Models\Lead  $subject
     * @return list<array<string, mixed>>
     */
    private function activityEntries($subject, string $origin): array
    {
        return $subject->activities()
            ->with('type')
            ->orderByDesc('scheduled_at')
            ->get()
            ->map(fn (\App\Models\Activity $activity): array => [
                'kind' => 'activity',
                'at' => $activity->scheduled_at,
                'title' => $activity->title,
                'detail' => $activity->result ?? $activity->description,
                'meta' => [
                    'origin' => $origin,
                    'type' => $activity->type?->name,
                    'status' => $activity->status,
                    'priority' => $activity->priority,
                ],
                'model' => $activity,
            ])
            ->all();
    }

    /**
     * activitylog entries for a subject type/id mapped to the shared entry
     * shape.
     *
     * @return list<array<string, mixed>>
     */
    private function logEntries(string $subjectType, int $subjectId, string $origin): array
    {
        return \Spatie\Activitylog\Models\Activity::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (\Spatie\Activitylog\Models\Activity $log): array => [
                'kind' => 'log',
                'at' => $log->created_at,
                'title' => $log->description,
                'detail' => null,
                'meta' => [
                    'origin' => $origin,
                    'event' => $log->event,
                    'properties' => $log->properties->toArray(),
                ],
                'model' => $log,
            ])
            ->all();
    }
}
