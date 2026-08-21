<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Customer;
use App\Services\ContactService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test-only contact factory. Belongs to a customer through forCustomer()
 * (or creates one lazily). Not primary by default so the single-primary
 * invariant stays under the tests' control.
 *
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $faker = fake('es_PE');
        $email = $faker->unique()->safeEmail();

        return [
            'customer_id' => fn () => Customer::factory(),
            'first_name' => $faker->firstName(),
            'last_name' => $faker->lastName(),
            'position' => null,
            'area' => null,
            'phone' => null,
            'whatsapp' => null,
            'email' => $email,
            'email_norm' => ContactService::normalizeEmail($email),
            'is_primary' => false,
            'is_active' => true,
            'observations' => null,
        ];
    }

    /**
     * Explicit customer.
     */
    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn (array $attributes): array => [
            'customer_id' => $customer->id,
        ]);
    }

    /**
     * Mark as primary (use with care: one active primary per customer).
     */
    public function primary(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_primary' => true,
        ]);
    }
}
