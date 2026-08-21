<?php

namespace App\Services;

use App\Models\CampaignActionItem;
use App\Models\CampaignParticipant;
use App\Models\CampaignRun;
use App\Models\CampaignStep;
use App\Models\CampaignTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CampaignRunService
{
public function createFromTemplate(CampaignTemplate $template, array $data, User $actor): CampaignRun
    {
        return DB::transaction(function () use ($template, $data, $actor) {
            $run = CampaignRun::query()->create([
                'code' => $this->nextCode(),
                'name' => $data['name'],
                'template_id' => $template->id,
                'template_hash' => hash('sha256', $template->getKey() . '|' . $template->updated_at),
                'starts_at' => $data['starts_at'],
                'ends_at_estimated' => $data['ends_at_estimated'] ?? null,
                'owner_id' => $data['owner_id'] ?? $actor->id,
                'team_id' => $data['team_id'] ?? null,
                'status' => CampaignRun::STATUS_DRAFT,
            ]);

            foreach ($template->steps as $tplStep) {
                $runStep = CampaignStep::query()->create([
                    'is_template' => false,
                    'template_id' => null,
                    'run_id' => $run->id,
                    'source_step_id' => $tplStep->id,
                    'order' => $tplStep->order,
                    'action_type_id' => $tplStep->action_type_id,
                    'title' => $tplStep->title,
                    'day_offset' => $tplStep->day_offset,
                    'scheduled_time' => $tplStep->scheduled_time,
                    'instructions' => $tplStep->instructions,
                    'is_required' => $tplStep->is_required,
                    'is_advertising' => $tplStep->is_advertising,
                    'status' => CampaignStep::STATUS_ACTIVE,
                ]);

                foreach (($data['participants'] ?? []) as $participantData) {
                    $this->createParticipantAndItem($run, $runStep, $participantData, $actor);
                }
            }

            return $run->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $participantData
     */
    private function createParticipantAndItem(CampaignRun $run, CampaignStep $step, array $participantData, User $actor): void
    {
        $subjectType = $participantData['subject_type'];
        $subjectId = (int) $participantData['subject_id'];
        $subject = $this->resolveSubject($subjectType, $subjectId);

        $assignedTo = $subject->owner_id ?? $run->owner_id;

        $participant = CampaignParticipant::query()->updateOrCreate(
            [
                'run_id' => $run->id,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
            ],
            [
                'assigned_to' => $assignedTo,
                'status' => CampaignParticipant::STATUS_ACTIVE,
                'included_at' => now(),
'display_name' => $this->displayNameFor($subject, $subjectType),
                'company_name' => $subject->company_name_for_campaign ?? null,
                'document_number_masked' => $subject->document_number_masked_for_campaign ?? null,
                'email' => $subject->email_for_campaign ?? null,
                'phone' => $subject->phone_for_campaign ?? null,
                'added_by' => $actor->id,
            ]
        );

        $scheduledAt = \Carbon\CarbonImmutable::parse($run->starts_at)
            ->addDays($step->day_offset)
            ->setTimeFromTimeString($step->scheduled_time ?? '09:00:00');

        CampaignActionItem::query()->create([
            'run_id' => $run->id,
            'step_id' => $step->id,
            'participant_id' => $participant->id,
            'status' => CampaignActionItem::STATUS_PENDING,
            'scheduled_at' => $scheduledAt,
        ]);
    }

    /**
     * Compute a human-readable display name for a subject (lead/customer/contact).
     * Each model has different identifying columns; this centralises the logic
     * so the participant snapshot never falls back to the ugly "lead #N" string.
     */
    private function displayNameFor(\Illuminate\Database\Eloquent\Model $subject, string $type): string
    {
        if ($type === 'contact') {
            $full = trim(($subject->first_name ?? '') . ' ' . ($subject->last_name ?? ''));
            return $full !== '' ? $full : sprintf('%s #%d', $type, $subject->getKey());
        }

        if ($type === 'customer') {
            return $subject->legal_name
                ?: ($subject->trade_name ?: sprintf('%s #%d', $type, $subject->getKey()));
        }

        // lead
        $full = trim(($subject->first_name ?? '') . ' ' . ($subject->last_name ?? ''));
        if ($full !== '') {
            return $full;
        }
        if (! empty($subject->company_name)) {
            return $subject->company_name;
        }
        return sprintf('%s #%d', $type, $subject->getKey());
    }

    /**
     * Add participants to an existing run (e.g. after creation).
     *
     * @param  array<int, array<string, mixed>>  $participants
     */
    public function addParticipants(CampaignRun $run, array $participants, User $actor): int
    {
        return DB::transaction(function () use ($run, $participants, $actor) {
            $count = 0;
            foreach ($run->steps as $step) {
                foreach ($participants as $pd) {
                    $this->createParticipantAndItem($run, $step, $pd, $actor);
                    $count++;
                }
            }
            return $count;
        });
    }

    /**
     * Remove participants from a run (soft: mark as excluded, keep history).
     *
     * @param  array<int, int>  $participantIds
     */
    public function removeParticipants(CampaignRun $run, array $participantIds, string $reason, User $actor): int
    {
        return DB::transaction(function () use ($run, $participantIds, $reason, $actor) {
            $count = CampaignParticipant::query()
                ->where('run_id', $run->id)
                ->whereIn('id', $participantIds)
                ->update([
                    'status' => CampaignParticipant::STATUS_EXCLUDED,
                    'excluded_at' => now(),
                    'exclusion_reason' => $reason,
                    'removed_by' => $actor->id,
                ]);

            // Their items become cancelled with the same reason.
            CampaignActionItem::query()
                ->whereIn('participant_id', $participantIds)
                ->whereIn('status', [
                    CampaignActionItem::STATUS_PENDING,
                    CampaignActionItem::STATUS_IN_PROCESS,
                    CampaignActionItem::STATUS_OVERDUE,
                ])
                ->update([
                    'status' => CampaignActionItem::STATUS_CANCELLED,
                    'cancellation_reason' => $reason,
                ]);
            return $count;
        });
    }

    public function changeStatus(CampaignRun $run, string $newStatus, ?string $reason, User $actor): CampaignRun
    {
        $run->update([
            'status' => $newStatus,
            'status_changed_at' => now(),
            'status_changed_by' => $actor->id,
            'status_reason' => $reason,
        ]);
        return $run->fresh();
    }

    public function schedule(CampaignRun $run, User $actor): CampaignRun
    {
        return $this->changeStatus($run, CampaignRun::STATUS_SCHEDULED, null, $actor);
    }

    public function pause(CampaignRun $run, string $reason, User $actor): CampaignRun
    {
        return $this->changeStatus($run, CampaignRun::STATUS_PAUSED, $reason, $actor);
    }

    public function resume(CampaignRun $run, User $actor): CampaignRun
    {
        return $this->changeStatus($run, CampaignRun::STATUS_RUNNING, null, $actor);
    }

    public function cancel(CampaignRun $run, string $reason, User $actor): CampaignRun
    {
        return DB::transaction(function () use ($run, $reason, $actor) {
            $run->update([
                'status' => CampaignRun::STATUS_CANCELLED,
                'status_changed_at' => now(),
                'status_changed_by' => $actor->id,
                'status_reason' => $reason,
            ]);
            CampaignActionItem::query()
                ->where('run_id', $run->id)
                ->whereIn('status', [
                    CampaignActionItem::STATUS_PENDING,
                    CampaignActionItem::STATUS_IN_PROCESS,
                    CampaignActionItem::STATUS_OVERDUE,
                ])
                ->update([
                    'status' => CampaignActionItem::STATUS_CANCELLED,
                    'cancellation_reason' => $reason,
                ]);
            return $run->fresh();
        });
    }

    public function complete(CampaignRun $run, ?string $reason, User $actor): CampaignRun
    {
        return $this->changeStatus($run, CampaignRun::STATUS_COMPLETED, $reason, $actor);
    }

    /**
     * @param  array<string, mixed>  $data  Must include `subject_type` and `subject_id`.
     */
    private function resolveSubject(string $type, int $id): \Illuminate\Database\Eloquent\Model
    {
        $map = [
            'lead' => \App\Models\Lead::class,
            'customer' => \App\Models\Customer::class,
            'contact' => \App\Models\Contact::class,
            'opportunity' => \App\Models\Opportunity::class,
        ];
        $class = $map[$type] ?? throw new InvalidArgumentException("Tipo de sujeto no soportado: {$type}");
        $model = $class::query()->withTrashed()->findOrFail($id);
        return $model;
    }

    private function nextCode(): string
    {
        $year = now()->year;
        $count = CampaignRun::query()
            ->where('code', 'like', "CR-{$year}-%")
            ->withTrashed()
            ->count();
        return sprintf('CR-%d-%05d', $year, $count + 1);
    }
}
