<?php

namespace Database\Factories;

use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\User;
use App\Services\LeadService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test-only lead factory (no fake business data outside tests).
 *
 * Catalog rows (status/source) are lazily created with the seeder values
 * so the factory works with or without a seeded database. Raw values come
 * in "user-typed" formats and the *_norm columns are filled directly via
 * the LeadService normalizers, mirroring what the service would write.
 *
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $faker = fake('es_PE');

        $docNumber = $faker->numerify('########'); // DNI-like, 8 digits
        $phone = '+51 9'.$faker->numerify('########'); // 9xx xxx xxx
        $email = $faker->unique()->safeEmail();

        return [
            'code' => sprintf('LEAD-%s-%05d', now()->format('Y'), random_int(1, 99999)),
            'person_type' => 'natural',
            'first_name' => $faker->firstName(),
            'last_name' => $faker->lastName(),
            'company_name' => null,
            'position' => null,
            'doc_type' => 'dni',
            'doc_number' => $docNumber,
            'doc_number_norm' => LeadService::normalizeDoc($docNumber),
            'phone' => $phone,
            'phone_norm' => LeadService::normalizePhone($phone),
            'whatsapp' => null,
            'whatsapp_norm' => null,
            'email' => $email,
            'email_norm' => LeadService::normalizeEmail($email),
            'address' => $faker->address(),
            'ubigeo_code' => null,
            'source_id' => fn () => LeadSource::query()->firstOrCreate(
                ['slug' => 'web'],
                ['name' => 'Web', 'sort' => 1, 'is_active' => true],
            )->id,
            'status_id' => fn () => LeadStatus::query()->firstOrCreate(
                ['slug' => 'nuevo'],
                ['name' => 'Nuevo', 'sort' => 1, 'is_final' => false, 'is_active' => true],
            )->id,
            'interest_level' => 'medio',
            'owner_id' => fn () => User::factory(),
            'entered_at' => now(),
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
     * Juridica person with a company name.
     */
    public function juridica(): static
    {
        return $this->state(fn (array $attributes): array => [
            'person_type' => 'juridica',
            'company_name' => fake('es_PE')->company(),
        ]);
    }
}
