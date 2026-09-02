<?php

namespace App\Services;

use App\Events\V2\LeadAssigned;
use App\Events\V2\LeadCreated;
use App\Events\V2\LeadDeactivated;
use App\Events\V2\LeadStatusChanged;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\User;
use App\Support\NormalizesContactData;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lead business logic (B02). Controllers and Livewire components stay
 * thin; everything that mutates a lead goes through this service.
 *
 * - Codes are generated inside the same transaction as the insert
 *   (ADR-002).
 * - The *_norm columns are maintained here (ADR-003) and are the only
 *   values used for duplicate matching.
 * - Duplicates are NEVER blocked at this layer: blocking is a UI-level
 *   decision with explicit confirmation (ADR-003). Detection lives in
 *   LeadDuplicateFinder.
 * - The next action is always derived from activities (ADR-012).
 */
class LeadService
{
    use NormalizesContactData;

    public function __construct(
        private readonly CodeGeneratorService $codes,
    ) {}

    /**
     * Create a lead: code + normalized fields + audit columns in one
     * transaction.
     */
public function create(array $data, User $actor): Lead
    {
        $lead = DB::transaction(function () use ($data, $actor): Lead {
                $primaryContact = $data['primary_contact'] ?? null;
                unset($data['primary_contact']);

                $data['code'] = $this->codes->next('lead');

                $data = self::applyNormalizations($data);

                $data['entered_at'] ??= now();

                // A new lead always starts in the initial status (slug "nuevo").
                if (empty($data['status_id'])) {
                    $data['status_id'] = LeadStatus::where('slug', 'nuevo')->value('id')
                        ?? throw new \RuntimeException('Initial lead status "nuevo" is not seeded.');
                }

                // The creating user is the initial responsible salesperson until
                // an assignment changes it.
                $data['owner_id'] ??= $actor->id;

            $lead = new Lead($data);
            $lead->created_by = $actor->id;
            $lead->updated_by = $actor->id;
            $lead->save();

            if ($lead->person_type === 'juridica' && $primaryContact !== null) {
$this->storePrimaryContact($lead, $primaryContact, $actor);
            }

            return $lead->refresh();
        });

        // V2 (B12): automation engine emission after the transaction
        // commits. Never inside DB::transaction.
        event(new LeadCreated($lead, $actor));

        return $lead;
    }

    /**
     * Update a lead and recompute the *_norm columns. The code is never
     * editable. Field-level changes are logged by the model's activitylog
     * configuration.
     */
public function update(Lead $lead, array $data, User $actor): Lead
    {
        $previousStatusId = $lead->status_id;

        DB::transaction(function () use ($lead, $data, $actor): void {
            $primaryContact = $data['primary_contact'] ?? null;
            unset($data['code'], $data['created_by'], $data['updated_by'], $data['primary_contact']);

            $lead->fill($data);
            self::applyNormalizations($lead->getAttributes(), $lead);
                $lead->updated_by = $actor->id;
                $lead->save();

                if ($lead->person_type === 'juridica' && $primaryContact !== null) {
                    $this->storePrimaryContact($lead, $primaryContact, $actor);
                } elseif ($lead->person_type === 'natural') {
                    $lead->primaryContact()->delete();
                }
            });

        $lead->refresh();

        // V2 (B12): emit LeadStatusChanged after the transaction commits
        // when the status was actually changed.
        if (array_key_exists('status_id', $data) && (int) $previousStatusId !== (int) $lead->status_id) {
            event(new LeadStatusChanged($lead, (int) $previousStatusId, $actor));
        }

        return $lead;
    }

        /**
         * Persist the legal prospect's single related primary contact.
         *
         * @param array<string, mixed> $data
         */
        private function storePrimaryContact(Lead $lead, array $data, User $actor): void
        {
            $data['email_norm'] = self::normalizeEmail($data['email'] ?? null);

            $contact = $lead->primaryContact()->firstOrNew();
            $contact->fill($data);
            $contact->created_by ??= $actor->id;
            $contact->updated_by = $actor->id;
            $contact->save();
        }

        /**
         * Reassign the responsible salesperson with a dedicated audit entry
     * (RF-LEAD-003).
     */
public function assign(Lead $lead, User $newOwner, User $actor, ?string $note = null): Lead
    {
        $previousOwnerId = $lead->owner_id;

        DB::transaction(function () use ($lead, $newOwner, $actor, $note): void {
            $oldOwnerId = $lead->owner_id;

            $lead->owner_id = $newOwner->id;
            $lead->updated_by = $actor->id;
            $lead->save();

            activity()
                ->performedOn($lead)
                ->causedBy($actor)
                ->event('lead-reassigned')
                ->withProperties([
                    'old_owner_id' => $oldOwnerId,
                    'new_owner_id' => $newOwner->id,
                    'note' => $note,
                ])
                ->log("Lead {$lead->code} reasignado a {$newOwner->name}");
        });

        $lead->refresh();

        // V2 (B12): automation engine emission after the transaction
        // commits. Never inside DB::transaction.
        event(new LeadAssigned($lead, $previousOwnerId, $actor));

        return $lead;
    }

    /**
     * Deactivate (soft delete) a lead with a mandatory reason. Leads are
     * never physically deleted (RF-LEAD-011, RNF-DAT-001). Reactivation is
     * out of scope for B02.
     */
public function deactivate(Lead $lead, User $actor, string $reason): Lead
    {
        DB::transaction(function () use ($lead, $actor, $reason): void {
            $lead->updated_by = $actor->id;
            $lead->delete();

            activity()
                ->performedOn($lead)
                ->causedBy($actor)
                ->event('lead-deactivated')
                ->withProperties(['reason' => $reason])
                ->log("Lead {$lead->code} desactivado: {$reason}");
        });

        // V2 (B12): automation engine emission after the transaction
        // commits. Never inside DB::transaction.
        event(new LeadDeactivated($lead, $actor));

        return $lead;
    }

    /**
     * Most proximate future PENDING activity of this lead (ADR-012).
     * "Sin próximo seguimiento" when null.
     */
    public function nextAction(Lead $lead): ?Activity
    {
        return $lead->activities()
            ->where('status', 'pending')
            ->where('scheduled_at', '>=', now())
            ->orderBy('scheduled_at')
            ->first();
    }

    /**
     * Merged lead timeline (RF-LEAD-005): CRM activities (any status,
     * eager-loaded type) plus activitylog entries for this lead (field
     * changes, reassignments, deactivations), newest first.
     *
     * Each item is an array shaped for Blade:
     * [
     *     'kind'   => 'activity' | 'log',
     *     'at'     => Carbon,
     *     'title'  => string,
     *     'detail' => ?string,
     *     'meta'   => [...],   // status/type for activities, event for logs
     *     'model'  => Activity|\Spatie\Activitylog\Models\Activity|null,
     * ]
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function history(Lead $lead): Collection
    {
        $crmActivities = $lead->activities()
            ->with('type')
            ->orderByDesc('scheduled_at')
            ->get()
            ->map(fn (Activity $activity): array => [
                'kind' => 'activity',
                'at' => $activity->scheduled_at,
                'title' => $activity->title,
                'detail' => $activity->result ?? $activity->description,
                'meta' => [
                    'type' => $activity->type?->name,
                    'status' => $activity->status,
                    'priority' => $activity->priority,
                ],
                'model' => $activity,
            ]);

        $logEntries = \Spatie\Activitylog\Models\Activity::query()
            ->where('subject_type', Lead::class)
            ->where('subject_id', $lead->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (\Spatie\Activitylog\Models\Activity $log): array => [
                'kind' => 'log',
                'at' => $log->created_at,
                'title' => $log->description,
                'detail' => null,
                'meta' => [
                    'event' => $log->event,
                    'properties' => $log->properties->toArray(),
                ],
                'model' => $log,
            ]);

        // toBase(): the items are plain arrays, not models — merging an
        // array payload into an Eloquent collection would call getKey()
        // on arrays and crash.
        return $crmActivities
            ->toBase()
            ->merge($logEntries)
            ->sortByDesc(fn (array $item) => $item['at']->getTimestamp())
            ->values();
    }
}
