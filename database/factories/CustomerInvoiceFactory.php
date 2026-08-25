<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\InvoiceStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerInvoice>
 */
class CustomerInvoiceFactory extends Factory
{
    protected $model = CustomerInvoice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => fn () => Customer::factory(),
            'invoice_number' => 'FAC-'.$this->faker->unique()->numerify('#####'),
            'due_date' => $this->faker->dateTimeBetween('now', '+60 days')->format('Y-m-d'),
            'total_amount' => $this->faker->randomFloat(2, 1, 99999),
            'status_id' => fn () => InvoiceStatus::query()->where('slug', InvoiceStatus::SLUG_IN_PROCESS)->value('id')
                ?? InvoiceStatus::query()->create([
                    'name' => 'En proceso',
                    'slug' => InvoiceStatus::SLUG_IN_PROCESS,
                    'sort' => 3,
                    'is_active' => true,
                ])->id,
            'notes' => null,
            'retired_at' => null,
            'retired_by' => null,
            'retire_reason' => null,
        ];
    }

    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn (array $attributes): array => [
            'customer_id' => $customer->id,
        ]);
    }

    public function forStatus(InvoiceStatus $status): static
    {
        return $this->state(fn (array $attributes): array => [
            'status_id' => $status->id,
        ]);
    }
}
