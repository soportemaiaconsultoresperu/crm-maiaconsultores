<?php

namespace Database\Factories;

use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\PipelineStage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test-only opportunity factory (no fake business data outside tests).
 *
 * The default state is an OPEN opportunity for a lead (the pipeline flow
 * is exercised through the service); customer-based, won and lost states
 * are opt-in. Stage rows are lazily created with the seeder values so the
 * factory works with or without a seeded database.
 *
 * @extends Factory<Opportunity>
 */
class OpportunityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => sprintf('OPP-%s-%05d', now()->format('Y'), random_int(1, 99999)),
            'title' => fake('es_PE')->sentence(3),
            'lead_id' => fn () => Lead::factory(),
            'customer_id' => null,
            'contact_id' => null,
            'owner_id' => fn () => User::factory(),
            'stage_id' => fn () => PipelineStage::query()->firstOrCreate(
                ['slug' => 'nueva-oportunidad'],
                ['name' => 'Nueva oportunidad', 'stage_type' => 'open', 'default_probability' => 10, 'sort' => 1, 'is_active' => true],
            )->id,
            'estimated_amount' => 10000,
            'currency_code' => 'PEN',
            'probability' => 10,
            'expected_close_at' => now()->addMonth(),
            'source_id' => null,
            'priority' => 'media',
            'description' => null,
            'loss_reason_id' => null,
            'closed_at' => null,
            'final_amount' => null,
        ];
    }

    /**
     * Explicit owner (typical for visibility tests).
     */
    public function forOwner(User $owner): static
    {
        return $this->state(fn (array $attributes): array => [
            'owner_id' => $owner->id,
        ]);
    }

    /**
     * Opportunity attached to a lead instead of a customer.
     */
    public function forLead(Lead $lead): static
    {
        return $this->state(fn (array $attributes): array => [
            'lead_id' => $lead->id,
            'customer_id' => null,
        ]);
    }

    /**
     * Opportunity attached to a customer instead of a lead.
     */
    public function forCustomer(\App\Models\Customer $customer): static
    {
        return $this->state(fn (array $attributes): array => [
            'lead_id' => null,
            'customer_id' => $customer->id,
        ]);
    }

    /**
     * Won opportunity (stage ganada + closed_at + final_amount).
     */
    public function won(): static
    {
        return $this->state(fn (array $attributes): array => [
            'stage_id' => PipelineStage::query()->firstOrCreate(
                ['slug' => 'ganada'],
                ['name' => 'Ganada', 'stage_type' => 'won', 'default_probability' => 100, 'sort' => 6, 'is_active' => true],
            )->id,
            'probability' => 100,
            'closed_at' => now(),
            'final_amount' => $attributes['estimated_amount'] ?? 10000,
        ]);
    }

    /**
     * Lost opportunity (stage perdida + loss reason + closed_at).
     */
    public function lost(): static
    {
        return $this->state(fn (array $attributes): array => [
            'stage_id' => PipelineStage::query()->firstOrCreate(
                ['slug' => 'perdida'],
                ['name' => 'Perdida', 'stage_type' => 'lost', 'default_probability' => 0, 'sort' => 7, 'is_active' => true],
            )->id,
            'probability' => 0,
            'loss_reason_id' => \App\Models\LossReason::query()->firstOrCreate(
                ['slug' => 'precio'],
                ['name' => 'Precio', 'sort' => 1, 'is_active' => true],
            )->id,
            'closed_at' => now(),
        ]);
    }
}
