<?php

declare(strict_types=1);

namespace App\Services\Automation\Actions;

use App\Contracts\Automation\ActionContract;
use App\Models\AutomationExecutionStep;
use App\Models\Opportunity;
use App\Services\ActivityService;
use InvalidArgumentException;

/**
 * Schedule a follow-up activity on the event subject.
 *
 * Differs from CreateActivityAction in that it requires `next_scheduled_at`
 * explicitly and reuses the same subject_type/subject_id from the event.
 *
 * Payload:
 *  - type_id (int, required)
 *  - title (string, required)
 *  - next_scheduled_at (Carbon, required)
 *  - owner_id (int, optional)
 *  - description (string, optional)
 *  - priority (string, optional)
 */
class CreateFollowUpActivityAction implements ActionContract
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
            Opportunity::class => 'opportunity',
            default => null,
        };

        if ($subjectKey === null) {
            return;
        }

        if (empty($payload['next_scheduled_at'])) {
            throw new InvalidArgumentException('CreateFollowUpActivityAction: next_scheduled_at is required.');
        }

        $data = [
            'type_id' => (int) ($payload['type_id'] ?? 0),
            'subject_type' => $subjectKey,
            'subject_id' => (int) $execution->subject_id,
            'title' => (string) ($payload['title'] ?? ''),
            'description' => $payload['description'] ?? null,
            'scheduled_at' => $payload['next_scheduled_at'],
            'priority' => $payload['priority'] ?? 'media',
        ];

        if (! empty($payload['owner_id'])) {
            $data['owner_id'] = (int) $payload['owner_id'];
        }

        $actor = \App\Models\User::query()->first();

        if ($actor === null) {
            throw new \RuntimeException('CreateFollowUpActivityAction: no user available to act as actor.');
        }

        $activity = $this->activities->create($data, $actor);

        $step->response_json = array_merge((array) ($step->response_json ?? []), [
            'created_activity_id' => $activity->id,
            'scheduled_at' => $activity->scheduled_at?->toIso8601String(),
        ]);
        $step->save();
    }

    public function simulate(array $payload): array
    {
        return [
            'would_create_follow_up' => true,
            'payload' => $payload,
        ];
    }
}