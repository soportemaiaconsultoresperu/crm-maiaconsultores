<?php

declare(strict_types=1);

namespace App\Events\V2\TimeDriven;

use App\Contracts\Automation\AutomationTriggerEvent;
use App\Models\Quotation;

/**
 * Scheduler-driven event emitted by automation:emit-quotation-will-expire.
 *
 * Carries the `lookAheadDays` (how many days ahead the scheduler scanned)
 * and the `daysUntilExpiry` so conditions can react to the urgency window.
 */
final class QuotationWillExpire implements AutomationTriggerEvent
{
    public readonly \DateTimeImmutable $occurredAt;

    public function __construct(
        public readonly Quotation $quotation,
        public readonly int $lookAheadDays,
        public readonly int $daysUntilExpiry,
    ) {
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function subjectType(): ?string
    {
        return Quotation::class;
    }

    public function subjectId(): ?int
    {
        return (int) $this->quotation->getKey();
    }

    public function actorId(): ?int
    {
        return null;
    }

    public function payload(): array
    {
        return [
            'number' => $this->quotation->number,
            'status' => $this->quotation->status,
            'owner_id' => $this->quotation->owner_id,
            'lead_id' => $this->quotation->lead_id,
            'customer_id' => $this->quotation->customer_id,
            'opportunity_id' => $this->quotation->opportunity_id,
            'total' => $this->quotation->total,
            'currency_code' => $this->quotation->currency_code,
            'expires_at' => optional($this->quotation->expires_at)->toDateString(),
            'look_ahead_days' => $this->lookAheadDays,
            'days_until_expiry' => $this->daysUntilExpiry,
        ];
    }

    public function occurredAt(): \DateTimeInterface
    {
        return $this->occurredAt;
    }
}