<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\User;
use App\Services\LeadService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test-only customer factory (no fake business data outside tests). Raw
 * values come in "user-typed" formats and the *_norm columns are filled
 * with the shared normalizers, mirroring what CustomerService would write.
 *
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $faker = fake('es_PE');

        $ruc = $faker->unique()->numerify('10#########'); // 11 digits
        $phone = '+51 9'.$faker->numerify('########');
        $email = $faker->unique()->safeEmail();

        return [
            'code' => sprintf('CLI-%s-%05d', now()->format('Y'), random_int(1, 99999)),
            'person_type' => 'juridica',
            'legal_name' => $faker->unique()->company().' S.A.C.',
            'trade_name' => $faker->company(),
            'doc_type' => 'ruc',
            'doc_number' => $ruc,
            'doc_number_norm' => LeadService::normalizeDoc($ruc),
            'phone' => $phone,
            'phone_norm' => LeadService::normalizePhone($phone),
            'whatsapp' => null,
            'whatsapp_norm' => null,
            'email' => $email,
            'email_norm' => LeadService::normalizeEmail($email),
            'website' => null,
            'fiscal_address' => $faker->address(),
            'ubigeo_code' => null,
            'sector' => null,
            'owner_id' => fn () => User::factory(),
            'status' => 'activo',
            'converted_from_lead_id' => null,
            'converted_at' => null,
            'observations' => null,
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
     * Natural person with a full name as legal_name.
     */
    public function natural(): static
    {
        return $this->state(function (array $attributes): array {
            $faker = fake('es_PE');
            $dni = $faker->unique()->numerify('########'); // 8 digits

            return [
                'person_type' => 'natural',
                'legal_name' => $faker->name(),
                'trade_name' => null,
                'doc_type' => 'dni',
                'doc_number' => $dni,
                'doc_number_norm' => LeadService::normalizeDoc($dni),
            ];
        });
    }
}
