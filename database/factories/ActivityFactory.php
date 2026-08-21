<?php

namespace Database\Factories;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * Test-only activity factory (B05 / RF-ACT-006). Use forSubject(),
 * forLead(), forCustomer() or forOpportunity() to attach the activity to a
 * polymorphic subject. The scheduled_at/status/priority states cover the
 * next-action scenarios (ADR-012) and the scheduler tests.
 *
 * @extends Factory<Activity>
 */
class ActivityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type_id' => fn () => ActivityType::query()->firstOrCreate(
                ['slug' => 'llamada'],
                ['name' => 'Llamada', 'sort' => 1, 'is_active' => true],
            )->id,
            'subject_type' => null,
            'subject_id' => null,
            'owner_id' => fn () => User::factory(),
            'title' => 'Llamada de seguimiento',
            'description' => null,
            'scheduled_at' => now()->addDay(),
            'executed_at' => null,
            'result' => null,
            'status' => 'pending',
            'priority' => 'media',
            'reminder_at' => null,
        ];
    }

    /**
     * Attach the activity to its polymorphic subject.
     */
    public function forSubject(Model $subject): static
    {
        return $this->state(fn (array $attributes): array => [
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
        ]);
    }

    /**
     * Shorthand for a Lead subject (B05 / RF-ACT-006).
     */
    public function forLead(Lead $lead): static
    {
        return $this->forSubject($lead);
    }

    /**
     * Shorthand for a Customer subject (B05 / RF-ACT-006).
     */
    public function forCustomer(Customer $customer): static
    {
        return $this->forSubject($customer);
    }

    /**
     * Shorthand for an Opportunity subject (B05 / RF-ACT-006).
     */
    public function forOpportunity(Opportunity $opportunity): static
    {
        return $this->forSubject($opportunity);
    }

    /**
     * Explicit owner.
     */
    public function forOwner(User $owner): static
    {
        return $this->state(fn (array $attributes): array => [
            'owner_id' => $owner->id,
        ]);
    }

    /**
     * Completed activity: status + executed_at + result.
     */
    public function completed(?string $result = 'Resultado de prueba'): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'completed',
            'executed_at' => $attributes['executed_at'] ?? now(),
            'result' => $result,
        ]);
    }

    /**
     * Overdue activity: pending in the past (the scheduler has not yet
     * processed this row).
     */
    public function overdue(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'pending',
            'scheduled_at' => now()->subDay(),
        ]);
    }

    /**
     * Activity scheduled within the upcoming reminder window
     * (now < scheduled_at <= now + 24h).
     */
    public function upcoming(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'pending',
            'scheduled_at' => now()->addHours(2),
            'reminder_at' => null,
        ]);
    }
}