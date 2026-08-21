<?php

declare(strict_types=1);

namespace App\Contracts\Automation;

/**
 * Marker interface for any class that may drive an AutomationRule.
 *
 * Kept under App\Contracts\Automation (rather than App\Integrations\Contracts)
 * to avoid mixing the integration contract namespace with the automation
 * trigger surface. Domain events and time-driven events implement this so
 * the listener can iterate over any trigger type via a single interface.
 *
 * Implementing classes must expose:
 *  - subjectType(): string (the morph class string, e.g. App\Models\Lead)
 *  - subjectId(): int|null (the row id; null only for events without a row)
 *  - actorId(): int|null (the user who triggered it, if any)
 *  - payload(): array (the data the condition evaluator reads)
 */
interface AutomationTriggerEvent
{
    /**
     * FQCN of the subject (morph class) this event refers to.
     */
    public function subjectType(): ?string;

    /**
     * Database id of the subject; null when the event has no row anchor.
     */
    public function subjectId(): ?int;

    /**
     * User id of the actor that caused the event; null for scheduler events.
     */
    public function actorId(): ?int;

    /**
     * Arbitrary payload that the ConditionEvaluator reads (`field` lookups).
     *
     * @return array<string, mixed>
     */
    public function payload(): array;

    /**
     * ISO-8601 timestamp of when the event happened.
     */
    public function occurredAt(): \DateTimeInterface;
}