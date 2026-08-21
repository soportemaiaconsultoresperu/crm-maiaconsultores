<?php

declare(strict_types=1);

namespace App\Events\V2\TimeDriven;

use App\Contracts\Automation\AutomationTriggerEvent;
use App\Models\Customer;

/**
 * Scheduler-driven event emitted by automation:emit-customer-idle when a
 * customer has not had a completed Activity in the configured window.
 */
final class CustomerIdle implements AutomationTriggerEvent
{
    public readonly \DateTimeImmutable $occurredAt;

    public function __construct(
        public readonly Customer $customer,
        public readonly int $idleDays,
        public readonly ?\DateTimeInterface $lastCompletedActivityAt,
    ) {
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function subjectType(): ?string
    {
        return Customer::class;
    }

    public function subjectId(): ?int
    {
        return (int) $this->customer->getKey();
    }

    public function actorId(): ?int
    {
        return null;
    }

    public function payload(): array
    {
        return [
            'code' => $this->customer->code,
            'owner_id' => $this->customer->owner_id,
            'status' => $this->customer->status,
            'idle_days' => $this->idleDays,
            'last_completed_activity_at' => $this->lastCompletedActivityAt?->format('c'),
        ];
    }

    public function occurredAt(): \DateTimeInterface
    {
        return $this->occurredAt;
    }
}