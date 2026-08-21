<?php

declare(strict_types=1);

namespace App\Services\Automation\Actions;

use App\Contracts\Automation\ActionContract;
use App\Models\AutomationExecutionStep;
use App\Models\User;
use App\Notifications\Automation\AutomationNotification;
use InvalidArgumentException;

/**
 * Send a database notification to a user.
 *
 * Payload:
 *  - user_id (int, optional — falls back to subject's owner or first admin)
 *  - title (string, required)
 *  - body (string, required)
 *  - level (info|warning|error, optional)
 */
class SendNotificationAction implements ActionContract
{
    public function execute(array $payload, AutomationExecutionStep $step): void
    {
        $execution = $step->execution()->first();

        if ($execution === null) {
            return;
        }

        $title = (string) ($payload['title'] ?? '');
        $body = (string) ($payload['body'] ?? '');

        if ($title === '' || $body === '') {
            throw new InvalidArgumentException('SendNotificationAction: title and body are required.');
        }

        $user = $this->resolveUser($payload, $execution->subject_type, (int) $execution->subject_id);

        if ($user === null) {
            throw new InvalidArgumentException('SendNotificationAction: no recipient user could be resolved.');
        }

        $user->notify(new AutomationNotification(
            title: $title,
            body: $body,
            level: (string) ($payload['level'] ?? 'info'),
        ));

        $step->response_json = array_merge((array) ($step->response_json ?? []), [
            'user_id' => $user->id,
            'title' => $title,
        ]);
        $step->save();
    }

    public function simulate(array $payload): array
    {
        return [
            'would_send_notification' => true,
            'payload' => $payload,
        ];
    }

    private function resolveUser(array $payload, string $subjectType, int $subjectId): ?User
    {
        $id = (int) ($payload['user_id'] ?? 0);

        if ($id > 0) {
            return User::query()->find($id);
        }

        $ownerId = match ($subjectType) {
            \App\Models\Lead::class => \App\Models\Lead::query()->whereKey($subjectId)->value('owner_id'),
            \App\Models\Opportunity::class => \App\Models\Opportunity::query()->whereKey($subjectId)->value('owner_id'),
            \App\Models\Customer::class => \App\Models\Customer::query()->whereKey($subjectId)->value('owner_id'),
            default => null,
        };

        if ($ownerId !== null) {
            return User::query()->find((int) $ownerId);
        }

        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('name', 'admin'))
            ->first();
    }
}