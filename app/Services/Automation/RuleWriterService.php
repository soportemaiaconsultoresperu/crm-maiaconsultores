<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Models\AutomationAction;
use App\Models\AutomationCondition;
use App\Models\AutomationConditionGroup;
use App\Models\AutomationRule;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * B12-UI — PR 3 / Stage 3A — Server-side persistence for the rule editor.
 *
 * Encapsulates the transactional write of an AutomationRule together with its
 * children (condition groups, conditions, actions). The service is intentionally
 * stateful on a single transaction so partial writes can never leave the schema
 * half-built; it also exposes a clone() method used by the controller's clone
 * action.
 *
 * Out of scope for v1:
 *  - Idempotency: this runs on direct UI submits; idempotency is owned by the
 *    engine listener (DispatchAutomationRule) on the engine trigger path.
 *  - Audit: Spatie Activitylog hooks fire on the model save() events.
 */
class RuleWriterService
{
    /**
     * Create a new rule + groups + conditions + actions in one transaction.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): AutomationRule
    {
        return DB::transaction(function () use ($data, $actor): AutomationRule {
            $rule = AutomationRule::query()->create($this->ruleAttributes($data, $actor));

            $this->persistGroups($rule, $data['groups'] ?? []);
            $this->persistActions($rule, $data['actions'] ?? []);

            return $rule->fresh(['conditionGroups.conditions', 'actions']);
        });
    }

    /**
     * Update an existing rule + replace its groups/conditions/actions.
     *
     * Children are deleted and re-created — simpler than diff'ing nested rows
     * and safe inside a transaction.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(AutomationRule $rule, array $data): AutomationRule
    {
        return DB::transaction(function () use ($rule, $data): AutomationRule {
            $rule->update($this->ruleAttributes($data, ruleCreator: $rule->created_by));

            $rule->conditionGroups()->delete();
            $this->persistGroups($rule, $data['groups'] ?? []);

            $rule->actions()->delete();
            $this->persistActions($rule, $data['actions'] ?? []);

            return $rule->fresh(['conditionGroups.conditions', 'actions']);
        });
    }

    /**
     * Replicate a rule + its groups + its conditions + its actions into a new
     * rule. The clone is created with `is_active=false`, `mode='test'`, and
     * a suffix on the name; the creator is the actor of the request.
     */
    public function clone(AutomationRule $source, User $actor): AutomationRule
    {
        return DB::transaction(function () use ($source, $actor): AutomationRule {
            $clone = $source->replicate(['executions_count']);
            $clone->name = $source->name.' (copia)';
            $clone->is_active = false;
            $clone->mode = 'test';
            $clone->created_by = $actor->id;
            $clone->owner_id = null;
            $clone->save();

            foreach ($source->conditionGroups as $group) {
                $newGroup = $group->replicate();
                $newGroup->rule_id = $clone->id;
                $newGroup->save();

                foreach ($group->conditions as $condition) {
                    $newCondition = $condition->replicate();
                    $newCondition->rule_id = $clone->id;
                    $newCondition->group_id = $newGroup->id;
                    $newCondition->save();
                }
            }

            foreach ($source->actions as $action) {
                $newAction = $action->replicate();
                $newAction->rule_id = $clone->id;
                $newAction->save();
            }

            return $clone->fresh(['conditionGroups.conditions', 'actions']);
        });
    }

    /**
     * Persist a reorder sequence. The `kind` discriminator accepts `rules`
     * (top-level), `conditions` (inside a group), or `actions`. The
     * `order` array carries the IDs in their new order; we walk it and
     * persist `$i + 1` as the new `order` value.
     *
     * @param  array<int>  $order
     */
    public function reorder(string $kind, array $order, ?AutomationRule $ruleScope = null): void
    {
        DB::transaction(function () use ($kind, $order, $ruleScope): void {
            $position = 1;
            foreach ($order as $id) {
                $id = (int) $id;
                match ($kind) {
                    'rules' => AutomationRule::query()->where('id', $id)->update(['order' => $position]),
                    'conditions' => AutomationCondition::query()
                        ->where('id', $id)
                        ->where('rule_id', $ruleScope?->id ?? $id) // best effort when no ruleScope
                        ->update(['position' => $position]),
                    'actions' => AutomationAction::query()
                        ->where('id', $id)
                        ->where('rule_id', $ruleScope?->id ?? $id)
                        ->update(['position' => $position]),
                    default => null,
                };
                $position++;
            }
        });
    }

    /**
     * Common attribute setter for create + update.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function ruleAttributes(array $data, ?User $actor = null, ?int $ruleCreator = null): array
    {
        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'trigger_event' => $data['trigger_event'],
            'is_active' => (bool) ($data['is_active'] ?? true),
            'order' => (int) ($data['order'] ?? 0),
            'mode' => $data['mode'] ?? 'live',
            'created_by' => $ruleCreator ?? $actor?->id,
            'owner_id' => $data['owner_id'] ?? null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     */
    private function persistGroups(AutomationRule $rule, array $groups): void
    {
        foreach ($groups as $groupData) {
            /** @var AutomationConditionGroup $group */
            $group = $rule->conditionGroups()->create([
                'logical_operator' => $groupData['logical_operator'] ?? 'AND',
                'position' => (int) ($groupData['position'] ?? 0),
            ]);

            foreach ($groupData['conditions'] ?? [] as $conditionData) {
                $group->conditions()->create([
                    'rule_id' => $rule->id,
                    'field' => $conditionData['field'],
                    'operator' => $conditionData['operator'],
                    'value' => $conditionData['value'] ?? null,
                    'value_type' => $conditionData['value_type'] ?? 'string',
                    'position' => (int) ($conditionData['position'] ?? 0),
                ]);
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $actions
     */
    private function persistActions(AutomationRule $rule, array $actions): void
    {
        foreach ($actions as $actionData) {
            $rule->actions()->create([
                'type' => $actionData['type'],
                'position' => (int) ($actionData['position'] ?? 0),
                'channel' => $actionData['channel'] ?? null,
                'recipient_strategy' => $actionData['recipient_strategy'] ?? null,
                'payload_json' => $actionData['payload_json'] ?? null,
                'retry_policy_json' => $actionData['retry_policy_json'] ?? null,
                'is_active' => (bool) ($actionData['is_active'] ?? true),
            ]);
        }
    }
}
