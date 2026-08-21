<?php

declare(strict_types=1);

namespace App\Events\V2;

use App\Contracts\Automation\AutomationTriggerEvent;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\Quotation;
use App\Models\User;

/**
 * Base abstract for every V2 domain event.
 *
 * Subclasses override `payload()` and may carry extra public readonly
 * fields. The listener reads `subjectType()`, `subjectId()` and `actorId()`
 * through the AutomationTriggerEvent contract — the rest is opaque to the
 * engine.
 */
abstract class DomainEvent implements AutomationTriggerEvent
{
    public readonly \DateTimeImmutable $occurredAt;

    public function __construct(public readonly ?int $actorId = null)
    {
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function actorId(): ?int
    {
        return $this->actorId;
    }

    public function occurredAt(): \DateTimeInterface
    {
        return $this->occurredAt;
    }

    /**
     * Map a model class to the column used to extract the subject id when
     * the caller passes an Eloquent model. Used by subclasses that accept a
     * model in their constructor.
     */
    protected static function morphClassFor(object $subject): string
    {
        return match (true) {
            $subject instanceof Lead => Lead::class,
            $subject instanceof Customer => Customer::class,
            $subject instanceof Contact => Contact::class,
            $subject instanceof Opportunity => Opportunity::class,
            $subject instanceof Quotation => Quotation::class,
            $subject instanceof User => User::class,
            default => $subject::class,
        };
    }
}