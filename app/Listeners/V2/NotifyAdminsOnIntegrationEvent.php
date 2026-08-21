<?php

declare(strict_types=1);

namespace App\Listeners\V2;

use App\Events\V2\AutomationCycleDetected;
use App\Events\V2\IntegrationAccountDisconnected;
use App\Events\V2\IntegrationFailedPermanently;
use App\Models\User;
use App\Services\Notification\NotificationService;

/**
 * B17 / D-21a..c — Mandatory-trigger listener.
 *
 * Wires the 3 of the 4 mandatory notification triggers:
 *   - D-21a  IntegrationFailedPermanently    → mail all admin users
 *   - D-21b  IntegrationAccountDisconnected   → mail all admin users
 *   - D-21c  AutomationCycleDetected         → mail all admin users
 *
 * D-21d (NotificationFailedPermanently) is emitted by `SendOutboundDelivery::failed()`
 * but its listener is deferred to a follow-up B17.x change (the operator can
 * already read the failed deliveries view in Pasada B-2 scope).
 *
 * For each event, the listener dispatches one `OutboundDelivery` per admin
 * user (mail channel). The recipients list is the canonical 'admin' role
 * membership at the time the event fires — fresh permission grants are
 * reflected on the next event.
 */
class NotifyAdminsOnIntegrationEvent
{
    public function __construct(private readonly NotificationService $service)
    {
    }

    public function handleIntegrationFailedPermanently(IntegrationFailedPermanently $event): void
    {
        $this->dispatchToAdmins(
            channel: 'mail',
            subject: 'Integration failed permanently',
            body: sprintf(
                'Account #%d failed permanently. Error: %s — %s',
                $event->accountId,
                $event->errorClass,
                $event->errorMessage,
            ),
            relatedEntityType: 'IntegrationAccount',
            relatedEntityId: $event->accountId,
            bucket: 'D-21a',
        );
    }

    public function handleIntegrationAccountDisconnected(IntegrationAccountDisconnected $event): void
    {
        $this->dispatchToAdmins(
            channel: 'mail',
            subject: 'Integration account disconnected',
            body: sprintf(
                'Account #%d was disconnected%s.',
                $event->accountId,
                $event->reason !== null ? ' (reason: '.$event->reason.')' : '',
            ),
            relatedEntityType: 'IntegrationAccount',
            relatedEntityId: $event->accountId,
            bucket: 'D-21b',
        );
    }

    public function handleAutomationCycleDetected(AutomationCycleDetected $event): void
    {
        $this->dispatchToAdmins(
            channel: 'mail',
            subject: 'Automation rule cycle detected',
            body: sprintf(
                'Rule #%d cycle detected (%d break(s) recorded).',
                $event->ruleId,
                $event->cycleBreakCount,
            ),
            relatedEntityType: 'AutomationRule',
            relatedEntityId: $event->ruleId,
            bucket: 'D-21c',
        );
    }

    /**
     * @param  string  $relatedEntityType
     * @param  int|null  $relatedEntityId
     */
    private function dispatchToAdmins(
        string $channel,
        string $subject,
        string $body,
        string $relatedEntityType,
        ?int $relatedEntityId,
        string $bucket,
    ): void {
        $adminEmails = User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('name', 'admin'))
            ->pluck('email')
            ->all();

        foreach ($adminEmails as $email) {
            $this->service->dispatch([
                'channel' => $channel,
                'recipient_ref' => $email,
                'related_entity_type' => $relatedEntityType,
                'related_entity_id' => $relatedEntityId,
                'account_id' => null,
                'payload' => [
                    'subject' => $subject,
                    'body' => $body,
                ],
                'bucket' => $bucket,
            ]);
        }
    }
}
