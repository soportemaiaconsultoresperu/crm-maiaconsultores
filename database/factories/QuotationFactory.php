<?php

namespace Database\Factories;

use App\Models\Currency;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\Quotation;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test-only quotation factory (no fake business data outside tests).
 *
 * The default state is a draft quotation attached to a freshly-created
 * lead. Lines are NOT created here — they are produced by
 * QuotationService::create() so the historical tax snapshot and
 * totals stay consistent with what the service would write.
 *
 * @extends Factory<Quotation>
 */
class QuotationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => sprintf('COT-%s-%05d', now()->format('Y'), random_int(1, 99999)),
            'lead_id' => fn () => Lead::factory(),
            'customer_id' => null,
            'contact_id' => null,
            'opportunity_id' => null,
            'owner_id' => fn () => User::factory(),
            'issued_at' => now()->toDateString(),
            'expires_at' => now()->addDays(15)->toDateString(),
            'currency_code' => fn () => Currency::query()->firstOrCreate(
                ['code' => 'PEN'],
                ['name' => 'Sol Peruano', 'symbol' => 'S/', 'decimals' => 2, 'is_active' => true],
            )->code,
            'terms' => null,
            'observations' => null,
            'status' => 'draft',
            'subtotal' => 0,
            'discount_total' => 0,
            'tax_total' => 0,
            'total' => 0,
            'accepted_at' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (): array => ['status' => 'draft', 'accepted_at' => null]);
    }

    public function sent(): static
    {
        return $this->state(fn (): array => ['status' => 'sent', 'accepted_at' => null]);
    }

    public function accepted(): static
    {
        return $this->state(fn (): array => ['status' => 'accepted', 'accepted_at' => now()]);
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => ['status' => 'rejected', 'accepted_at' => null]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['status' => 'expired', 'accepted_at' => null]);
    }

    public function voided(): static
    {
        return $this->state(fn (): array => ['status' => 'voided', 'accepted_at' => null]);
    }

    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn (): array => [
            'lead_id' => null,
            'customer_id' => $customer->id,
        ]);
    }

    public function forLead(Lead $lead): static
    {
        return $this->state(fn (): array => [
            'lead_id' => $lead->id,
            'customer_id' => null,
        ]);
    }

    public function forOpportunity(Opportunity $opportunity): static
    {
        return $this->state(fn (): array => [
            'opportunity_id' => $opportunity->id,
            'lead_id' => $opportunity->lead_id,
            'customer_id' => $opportunity->customer_id,
        ]);
    }

    public function forOwner(User $owner): static
    {
        return $this->state(fn (): array => ['owner_id' => $owner->id]);
    }

    public function inCurrency(string $code): static
    {
        return $this->state(function () use ($code): array {
            Currency::query()->firstOrCreate(
                ['code' => $code],
                ['name' => $code, 'symbol' => $code, 'decimals' => 2, 'is_active' => true],
            );

            return ['currency_code' => $code];
        });
    }

    /**
     * Pre-create a default Tax row (Gravado IGV) — convenience for tests
     * that need the catalog present.
     */
    public function withTaxes(): static
    {
        return $this->afterMaking(function (Quotation $quotation): void {
            Tax::query()->firstOrCreate(
                ['slug' => 'gravado-igv'],
                ['name' => 'Gravado IGV', 'rate' => 18, 'sort' => 1, 'is_active' => true],
            );
        });
    }
}
