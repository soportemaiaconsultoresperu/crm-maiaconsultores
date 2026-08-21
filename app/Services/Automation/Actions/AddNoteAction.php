<?php

declare(strict_types=1);

namespace App\Services\Automation\Actions;

use App\Contracts\Automation\ActionContract;
use App\Models\ActivityType;
use App\Models\AutomationExecutionStep;
use App\Services\ActivityService;
use InvalidArgumentException;

/**
 * Create an Activity that is semantically a free-form note on the subject.
 *
 * Backs onto ActivityService::create() with the ActivityType slug 'nota'.
 * The activity type row is created on demand when the catalog does not yet
 * have it (test environments only — production seeding is expected).
 */
class AddNoteAction implements ActionContract
{
    public function __construct(private readonly ActivityService $activities)
    {
    }

    public function execute(array $payload, AutomationExecutionStep $step): void
    {
        $execution = $step->execution()->first();

        if ($execution === null) {
            return;
        }

        $subjectKey = match ($execution->subject_type) {
            \App\Models\Lead::class => 'lead',
            \App\Models\Customer::class => 'customer',
            \App\Models\Opportunity::class => 'opportunity',
            default => null,
        };

        if ($subjectKey === null) {
            return;
        }

        $body = (string) ($payload['body'] ?? '');

        if ($body === '') {
            throw new InvalidArgumentException('AddNoteAction: body is required.');
        }

        $type = ActivityType::query()->where('slug', 'nota')->first();

        if ($type === null) {
            $type = ActivityType::query()->create([
                'name' => 'Nota',
                'slug' => 'nota',
                'sort' => 99,
                'is_active' => true,
            ]);
        }

        $actor = \App\Models\User::query()->first();

        if ($actor === null) {
            throw new \RuntimeException('AddNoteAction: no user available to act as actor.');
        }

        $activity = $this->activities->create([
            'type_id' => $type->id,
            'subject_type' => $subjectKey,
            'subject_id' => (int) $execution->subject_id,
            'title' => substr($body, 0, 80) ?: 'Nota',
            'description' => $body,
            'priority' => $payload['priority'] ?? 'baja',
            'status' => 'completed',
            'executed_at' => now(),
            'result' => $body,
            'owner_id' => (int) ($payload['owner_id'] ?? $actor->id),
        ], $actor);

        $step->response_json = array_merge((array) ($step->response_json ?? []), [
            'created_activity_id' => $activity->id,
            'type_slug' => 'nota',
        ]);
        $step->save();
    }

    public function simulate(array $payload): array
    {
        return [
            'would_add_note' => true,
            'payload' => $payload,
        ];
    }
}