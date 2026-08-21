<?php

namespace App\Services;

use App\Events\V2\OpportunityCreated;
use App\Events\V2\OpportunityLost;
use App\Events\V2\OpportunityStageChanged;
use App\Events\V2\OpportunityWon;
use App\Exceptions\InvalidOperationException;
use App\Models\Activity;
use App\Models\LossReason;
use App\Models\Opportunity;
use App\Models\OpportunityStageHistory;
use App\Models\PipelineStage;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\OpportunityAssigned;
use App\Notifications\OpportunityStageChanged as OpportunityStageChangedNotification;
use App\Support\NextActionQuery;
use App\Traits\NotifiesOwner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Opportunity business logic (B04 / RF-OPP-001..010).
 *
 * - Codes OPP-YYYY-NNNNN generated in the same transaction as the insert
 *   (ADR-002).
 * - Exactly one of lead_id/customer_id (validated here and in the
 *   FormRequest; MySQL cannot cross-table CHECK).
 * - Currency per opportunity, PEN by default (ADR-004).
 * - Stage transitions are append-only history + activitylog entries
 *   (RF-OPP-005); won/lost are terminal until reopening exists (ADR-007
 *   spirit: explicit, never silent).
 * - No next-action columns: activities are the single source (ADR-012).
 */
class OpportunityService
{
    use NotifiesOwner;

    public function __construct(
        private readonly CodeGeneratorService $codes,
        private readonly DataScopeService $dataScope,
    ) {}

    /**
     * Create an opportunity: code + defaults in one transaction.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws \InvalidArgumentException When both or neither of
     *                                   lead_id/customer_id is set.
     */
    public function create(array $data, User $actor): Opportunity
    {
        return DB::transaction(function () use ($data, $actor): Opportunity {
            $data['code'] = $this->codes->next('opportunity');

            // Exactly one of lead/customer (docs §3.4).
            $hasLead = ! empty($data['lead_id']);
            $hasCustomer = ! empty($data['customer_id']);
            if ($hasLead === $hasCustomer) {
                throw new \InvalidArgumentException(
                    'La oportunidad debe indicar exactamente uno de lead o cliente.'
                );
            }

            $data['owner_id'] ??= $actor->id;

            if (empty($data['stage_id'])) {
                $data['stage_id'] = $this->firstOpenStageId();
            }

            if (empty($data['currency_code'])) {
                $data['currency_code'] = $this->defaultCurrency()
                    ?? throw new \RuntimeException('Setting currency_default is not configured.');
            }

            $data['probability'] ??= PipelineStage::query()
                ->whereKey($data['stage_id'])
                ->value('default_probability');

            $opportunity = new Opportunity($data);
            $opportunity->created_by = $actor->id;
            $opportunity->updated_by = $actor->id;
            $opportunity->save();

            activity()
                ->performedOn($opportunity)
                ->causedBy($actor)
                ->event('opportunity-created')
                ->withProperties(['code' => $opportunity->code])
                ->log("Oportunidad {$opportunity->code} creada");

            // Initial stage history row (from_stage_id is NULL on
            // creation, docs §3.4): the opportunity "entered" its first
            // stage.
            OpportunityStageHistory::create([
                'opportunity_id' => $opportunity->id,
                'from_stage_id' => null,
                'to_stage_id' => $opportunity->stage_id,
                'user_id' => $actor->id,
                'changed_at' => now(),
                'note' => null,
            ]);

            // RF-NOT-001 (partial): notify the new owner when someone else
            // created/assigned the opportunity.
            $owner = $opportunity->owner()->first();
            if ($owner !== null) {
                self::notifyOwnerUnlessActor(
                    $owner,
                    $actor,
                    new OpportunityAssigned($opportunity->code, $opportunity->title, $actor->name),
                );
            }

return $opportunity->refresh();
        });

        // V2 (B12): automation engine emission after the transaction
        // commits. Never inside DB::transaction.
        event(new OpportunityCreated($opportunity, $actor));

        return $opportunity;
    }

    /**
     * Update editable fields of an OPEN opportunity. Stage changes never
     * go through here (use changeStage); closed (won/lost) opportunities
     * are immutable until reopening exists.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidOperationException When the opportunity is already
     *                                   won or lost.
     */
    public function update(Opportunity $opportunity, array $data, User $actor): Opportunity
    {
        $this->assertOpen($opportunity);

        DB::transaction(function () use ($opportunity, $data, $actor): void {
            unset($data['code'], $data['stage_id'], $data['created_by'], $data['updated_by']);

            $opportunity->fill($data);
            $opportunity->updated_by = $actor->id;
            $opportunity->save();
        });

        return $opportunity->refresh();
    }

    /**
     * Move an open opportunity to another stage: append-only history row
     * + stage update + activitylog + owner notification, all in ONE
     * transaction (RF-OPP-004/005).
     *
     * @throws InvalidOperationException When the opportunity is already
     *                                   closed (won/lost) — reopening is a deliberate future
     *                                   feature, out of scope.
     */
    public function changeStage(Opportunity $opportunity, PipelineStage $to, User $actor, ?string $note = null): Opportunity
    {
        $this->assertOpen($opportunity);

        // V2 (B12): capture $from outside the closure so the post-commit
        // event emission (which must NOT be inside DB::transaction per
        // C-01) can reference the previous stage.
        $from = $opportunity->stage;

        DB::transaction(function () use ($opportunity, $to, $actor, $note, $from): void {
            OpportunityStageHistory::create([
                'opportunity_id' => $opportunity->id,
                'from_stage_id' => $from?->id,
                'to_stage_id' => $to->id,
                'user_id' => $actor->id,
                'changed_at' => now(),
                'note' => $note,
            ]);

            // Probability is NOT auto-overwritten on stage change: only
            // explicit user data sets it.
            $opportunity->stage_id = $to->id;
            $opportunity->updated_by = $actor->id;
            $opportunity->save();

            activity()
                ->performedOn($opportunity)
                ->causedBy($actor)
                ->event('opportunity-stage-changed')
                ->withProperties([
                    'from_stage' => $from?->slug,
                    'to_stage' => $to->slug,
                    'note' => $note,
                ])
                ->log("Oportunidad {$opportunity->code}: etapa {$from?->slug} → {$to->slug}");

$owner = $opportunity->owner()->first();
            if ($owner !== null) {
self::notifyOwnerUnlessActor(
                    $owner,
                    $actor,
                    new OpportunityStageChangedNotification(
                        $opportunity->code,
                        $opportunity->title,
                        (string) $from?->name,
                        (string) $to->name,
                    ),
                );
            }
        });

        $opportunity->refresh();

        // V2 (B12): automation engine emission after the transaction
        // commits. Never inside DB::transaction.
        event(new OpportunityStageChanged($opportunity, $from?->id, $actor));

        return $opportunity;
    }

    /**
     * Mark the opportunity as WON (RF-OPP-006 / ADR-007): explicit final
     * amount (in the opportunity currency) and close date, terminal stage
     * change with history, all in one transaction.
     *
     * @param  array{final_amount?: mixed, closed_at?: mixed}  $data
     *
     * @throws \InvalidArgumentException When final_amount is missing or
     *                                   not a positive number.
     * @throws InvalidOperationException When the opportunity is already
     *                                   won or lost.
     */
    public function markWon(Opportunity $opportunity, array $data, User $actor): Opportunity
    {
        $this->assertOpen($opportunity);

        $finalAmount = $data['final_amount'] ?? null;
        if (! is_numeric($finalAmount) || (float) $finalAmount <= 0) {
            throw new \InvalidArgumentException(
                'El monto final debe ser un número mayor a cero para marcar la oportunidad como ganada.'
            );
        }

        $wonStage = $this->stageBySlug('ganada');

        // V2 (B12): capture the transaction result so the post-commit
        // event emission can run (the original V1 returned the
        // transaction directly, which made any subsequent event()
        // unreachable).
        $result = DB::transaction(function () use ($opportunity, $data, $actor, $finalAmount, $wonStage): Opportunity {
            $from = $opportunity->stage;

            OpportunityStageHistory::create([
                'opportunity_id' => $opportunity->id,
                'from_stage_id' => $from?->id,
                'to_stage_id' => $wonStage->id,
                'user_id' => $actor->id,
                'changed_at' => now(),
                'note' => 'Oportunidad ganada',
            ]);

            $opportunity->stage_id = $wonStage->id;
            $opportunity->final_amount = $finalAmount;
            $opportunity->closed_at = $data['closed_at'] ?? now();
            $opportunity->updated_by = $actor->id;
            $opportunity->save();

            activity()
                ->performedOn($opportunity)
                ->causedBy($actor)
                ->event('opportunity-won')
                ->withProperties([
                    'final_amount' => (float) $finalAmount,
                    'currency_code' => $opportunity->currency_code,
                    'estimated_amount' => (float) $opportunity->estimated_amount,
                    'closed_at' => $opportunity->closed_at->toIso8601String(),
                ])
->log("Oportunidad {$opportunity->code} ganada por {$finalAmount} {$opportunity->currency_code}");

            return $opportunity->refresh();
        });

        $opportunity->refresh();

        // V2 (B12): automation engine emission after the transaction
        // commits. Never inside DB::transaction.
        event(new OpportunityWon($opportunity, $actor));

        return $result;
    }

    /**
     * Mark the opportunity as LOST (RF-OPP-007): a loss reason is
     * mandatory; terminal stage change with history, one transaction.
     *
     *
     * @throws \InvalidArgumentException When no loss reason is provided.
     * @throws InvalidOperationException When the opportunity is already
     *                                   won or lost.
     */
    public function markLost(Opportunity $opportunity, LossReason|int $lossReason, User $actor, ?string $note = null): Opportunity
    {
        $this->assertOpen($opportunity);

        if ($lossReason instanceof LossReason) {
            $reason = $lossReason;
        } elseif ((int) $lossReason > 0) {
            $reason = LossReason::query()->findOrFail((int) $lossReason);
        } else {
            throw new \InvalidArgumentException(
                'El motivo de pérdida es obligatorio para marcar la oportunidad como perdida.'
            );
        }

        $lostStage = $this->stageBySlug('perdida');

        // V2 (B12): capture the transaction result so the post-commit
        // event emission can run.
        $result = DB::transaction(function () use ($opportunity, $actor, $note, $reason, $lostStage): Opportunity {
            $from = $opportunity->stage;

            OpportunityStageHistory::create([
                'opportunity_id' => $opportunity->id,
                'from_stage_id' => $from?->id,
                'to_stage_id' => $lostStage->id,
                'user_id' => $actor->id,
                'changed_at' => now(),
                'note' => $note,
            ]);

            $opportunity->stage_id = $lostStage->id;
            $opportunity->loss_reason_id = $reason->id;
            $opportunity->closed_at = now();
            $opportunity->updated_by = $actor->id;
            $opportunity->save();

            activity()
                ->performedOn($opportunity)
                ->causedBy($actor)
                ->event('opportunity-lost')
                ->withProperties([
                    'loss_reason_id' => $reason->id,
                    'loss_reason' => $reason->name,
                    'note' => $note,
                ])
->log("Oportunidad {$opportunity->code} perdida: {$reason->name}");

            return $opportunity->refresh();
        });

        $opportunity->refresh();

        // V2 (B12): automation engine emission after the transaction
        // commits. Never inside DB::transaction.
        event(new OpportunityLost($opportunity, $actor, $reason->name));

        return $result;
    }

    /**
     * Soft-delete deactivation with a mandatory reason (RF-OPP-001,
     * RNF-DAT-001): POST destroy in the UI, never a physical delete.
     */
    public function deactivate(Opportunity $opportunity, User $actor, string $reason): Opportunity
    {
        DB::transaction(function () use ($opportunity, $actor, $reason): void {
            $opportunity->updated_by = $actor->id;
            $opportunity->delete();

            activity()
                ->performedOn($opportunity)
                ->causedBy($actor)
                ->event('opportunity-deactivated')
                ->withProperties(['reason' => $reason])
                ->log("Oportunidad {$opportunity->code} desactivada: {$reason}");
        });

        return $opportunity;
    }

    /**
     * Most proximate future PENDING activity of this opportunity
     * (ADR-012). "Sin próximo seguimiento" when null.
     */
    public function nextAction(Opportunity $opportunity): ?Activity
    {
        return NextActionQuery::forSubject(Opportunity::class, (int) $opportunity->getKey());
    }

    /**
     * Map of opportunity_id => next Activity for list pages (one query).
     *
     * @param  array<int, int>  $opportunityIds
     * @return Collection<int, Activity> keyed by opportunity id
     */
    public function nextActions(array $opportunityIds): Collection
    {
        return NextActionQuery::forSubjects(Opportunity::class, $opportunityIds);
    }

    /**
     * Merged opportunity timeline: CRM activities, stage histories and
     * activitylog entries, newest first (same shape as LeadService::
     * history, plus a "stage" kind for stage transitions).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function history(Opportunity $opportunity): Collection
    {
        $crmActivities = $opportunity->activities()
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

        $stageHistories = $opportunity->stageHistories()
            ->with(['fromStage', 'toStage', 'user'])
            ->orderByDesc('changed_at')
            ->get()
            ->map(fn (OpportunityStageHistory $history): array => [
                'kind' => 'stage',
                'at' => $history->changed_at,
                'title' => $history->fromStage === null
                    ? "Creada en {$history->toStage?->name}"
                    : "{$history->fromStage?->name} → {$history->toStage?->name}",
                'detail' => $history->note,
                'meta' => [
                    'from_stage' => $history->fromStage?->slug,
                    'to_stage' => $history->toStage?->slug,
                    'user' => $history->user?->name,
                ],
                'model' => $history,
            ]);

        $logEntries = \Spatie\Activitylog\Models\Activity::query()
            ->where('subject_type', Opportunity::class)
            ->where('subject_id', $opportunity->id)
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

        // toBase(): plain-array payloads must not live in an Eloquent
        // collection (merge would call getKey() on arrays).
        return $crmActivities
            ->toBase()
            ->merge($stageHistories->toBase())
            ->merge($logEntries->toBase())
            ->sortByDesc(fn (array $item) => $item['at']->getTimestamp())
            ->values();
    }

    /**
     * Owner-scoped query for list pages (RF-OPP-010 / ADR-006).
     *
     * @return Builder<Opportunity>
     */
    public function scopeQuery(User $user): Builder
    {
        return $this->dataScope->appliesTo(Opportunity::query(), $user, 'owner_id');
    }

    /**
     * True when the opportunity is not in a won/lost stage.
     */
    private function assertOpen(Opportunity $opportunity): void
    {
        $stageType = $opportunity->stage?->stage_type;

        if ($stageType === 'won' || $stageType === 'lost') {
            throw new InvalidOperationException(
                "La oportunidad {$opportunity->code} ya está cerrada ({$stageType}) y no admite más cambios."
            );
        }
    }

    /**
     * Id of the first active open stage by sort (creation default).
     */
    private function firstOpenStageId(): int
    {
        return PipelineStage::query()
            ->where('stage_type', 'open')
            ->where('is_active', true)
            ->orderBy('sort')
            ->value('id')
            ?? throw new \RuntimeException('No open pipeline stage is seeded.');
    }

    /**
     * Stage by slug ("ganada" / "perdida").
     */
    private function stageBySlug(string $slug): PipelineStage
    {
        return PipelineStage::query()
            ->where('slug', $slug)
            ->first()
            ?? throw new \RuntimeException("Pipeline stage \"{$slug}\" is not seeded.");
    }

    /**
     * Default currency code from settings (ADR-004).
     */
    private function defaultCurrency(): ?string
    {
        $value = Setting::query()->where('key', 'currency_default')->value('value');

        return $value === null ? null : (string) $value;
    }
}
