<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Internal activity notice for participants (odd/tasks/internal-activities.md,
 * T3). Database channel only. Three kinds share one payload shape:
 * - invited:  the user was added as a participant.
 * - updated:  an activity the user participates in changed.
 * - upcoming: an occurrence starts within the next 24 hours (scheduler).
 * `starts_at_iso` identifies the occurrence and backs the reminder dedupe.
 */
class InternalActivityNotice extends Notification
{
    use Queueable;

    public const INVITED = 'invited';

    public const UPDATED = 'updated';

    public const UPCOMING = 'upcoming';

    public function __construct(
        public readonly string $kind,
        public readonly int $activityId,
        public readonly string $activityTitle,
        public readonly string $startsAtIso,
        public readonly ?string $actorName = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $when = \Carbon\CarbonImmutable::parse($this->startsAtIso)->format('d/m/Y H:i');
        $actor = $this->actorName ?? 'Alguien';

        $message = match ($this->kind) {
            self::INVITED => "{$actor} lo agregó a la actividad interna \"{$this->activityTitle}\" ({$when}).",
            self::UPDATED => "{$actor} modificó la actividad interna \"{$this->activityTitle}\" ({$when}).",
            default => "Recordatorio: la actividad interna \"{$this->activityTitle}\" empieza el {$when}.",
        };

        return [
            'internal_activity_id' => $this->activityId,
            'title' => $this->activityTitle,
            'starts_at_iso' => $this->startsAtIso,
            'url' => route('internal-activities.show', $this->activityId),
            'message' => $message,
        ];
    }

    public function databaseType(object $notifiable): string
    {
        return 'internal-activity-'.$this->kind;
    }
}
