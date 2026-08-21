<?php

declare(strict_types=1);

namespace App\Services\Automation\Actions;

use App\Contracts\Automation\ActionContract;
use App\Models\Activity;
use App\Models\AutomationExecutionStep;
use App\Services\ActivityService;
use InvalidArgumentException;

/**
 * Create an Activity linked to the event's subject.
 *
 * Payload:
 *  - type_id (int, required) — ActivityType id
 *  - title (string, required)
 *  - description (string, optional)
 *  - scheduled_at (Carbon, optional — defaults to now())
 *  - priority (lowercase string, optional — defaults to 'media')
 *  - owner_id (int, optional — defaults to step actor or automation owner)
 */
class CreateActivityAction implements ActionContract
{
    public function __construct(private readonly ActivityService $activities)
    {
    }

    public function execute(array $payload, AutomationExecutionStep $step): void
    {
        $context = $this->contextFor($step);

        $data = [
            'type_id' => (int) ($payload['type_id'] ?? 0),
            'subject_type' => $context['subject_key'],
            'subject_id' => $context['subject_id'],
            'title' => (string) ($payload['title'] ?? ''),
            'description' => $payload['description'] ?? null,
            'scheduled_at' => $payload['scheduled_at'] ?? now()->toDateTimeString(),
            'priority' => $payload['priority'] ?? 'media',
        ];

        if (! empty($payload['owner_id'])) {
            $data['owner_id'] = (int) $payload['owner_id'];
        }

        if (empty($data['type_id']) || $data['title'] === '') {
            throw new InvalidArgumentException('CreateActivityAction: type_id and title are required.');
        }

        $actor = $context['actor'];

        if (! isset($data['owner_id'])) {
            $data['owner_id'] = $actor->id;
        }

        $activity = $this->activities->create($data, $actor);

        $step->response_json = array_merge((array) ($step->response_json ?? []), [
            'created_activity_id' => $activity->id,
            'subject_type' => $data['subject_type'],
            'subject_id' => $data['subject_id'],
        ]);
        $step->save();
    }

    public function simulate(array $payload): array
    {
        return [
            'would_create_activity' => true,
            'payload' => $payload,
        ];
    }

    /**
     * @return array{subject_key:string, subject_id:int, actor:\App\Models\User}
     */
    private function contextFor(AutomationExecutionStep $step): array
    {
        $execution = $step->execution()->first();
        $subjectClass = $execution?->subject_type ?? '';
        $subjectId = (int) ($execution?->subject_id ?? 0);

        $subjectKey = match ($subjectClass) {
            \App\Models\Lead::class => 'lead',
            \App\Models\Customer::class => 'customer',
            \App\Models\Opportunity::class => 'opportunity',
            default => 'lead',
        };

        $actor = \App\Models\User::query()->find($execution?->actor_id) ?? \App\Models\User::query()->first();

        if ($actor === null) {
            throw new \RuntimeException('CreateActivityAction: no user available to act as actor.');
        }

        return [
            'subject_key' => $subjectKey,
            'subject_id' => $subjectId,
            'actor' => $actor,
        ];
    }
}