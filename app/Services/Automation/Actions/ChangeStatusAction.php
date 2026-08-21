<?php

declare(strict_types=1);

namespace App\Services\Automation\Actions;

use App\Contracts\Automation\ActionContract;
use App\Models\AutomationExecutionStep;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Change a generic status field on the event subject.
 *
 * Adapts to subject type:
 *  - Lead: status_id (LeadStatus catalog)
 *  - Customer: status ENUM ('activo'|'inactivo')
 *  - Opportunity: stage_id (PipelineStage catalog)
 *
 * Payload:
 *  - value: required — either a catalog id or the literal string.
 *  - column: optional override (status_id|status|stage_id). When omitted
 *            the column is derived from subject type.
 */
class ChangeStatusAction implements ActionContract
{
    public function execute(array $payload, AutomationExecutionStep $step): void
    {
        $execution = $step->execution()->first();

        if ($execution === null) {
            return;
        }

        $subject = $this->resolveSubject($execution->subject_type, (int) $execution->subject_id);

        if ($subject === null) {
            return;
        }

        $column = $payload['column'] ?? $this->defaultColumn($execution->subject_type);
        $value = $payload['value'] ?? null;

        if ($value === null || $value === '') {
            throw new InvalidArgumentException('ChangeStatusAction: value is required.');
        }

        $actor = \App\Models\User::query()->first();

        if ($actor === null) {
            throw new \RuntimeException('ChangeStatusAction: no user available to act as actor.');
        }

        DB::transaction(function () use ($subject, $column, $value, $actor): void {
            $subject->{$column} = $value;
            $subject->updated_by = $actor->id;
            $subject->save();
        });

        $step->response_json = array_merge((array) ($step->response_json ?? []), [
            'column' => $column,
            'value' => $value,
        ]);
        $step->save();
    }

    public function simulate(array $payload): array
    {
        return [
            'would_change_status' => true,
            'payload' => $payload,
        ];
    }

    private function resolveSubject(string $subjectType, int $subjectId): ?object
    {
        return match ($subjectType) {
            \App\Models\Lead::class => \App\Models\Lead::query()->find($subjectId),
            \App\Models\Customer::class => \App\Models\Customer::query()->find($subjectId),
            \App\Models\Opportunity::class => \App\Models\Opportunity::query()->find($subjectId),
            default => null,
        };
    }

    private function defaultColumn(string $subjectType): string
    {
        return match ($subjectType) {
            \App\Models\Lead::class => 'status_id',
            \App\Models\Customer::class => 'status',
            \App\Models\Opportunity::class => 'stage_id',
            default => 'status',
        };
    }
}