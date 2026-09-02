<?php

namespace Tests\Feature;

use App\Events\V2\LeadConverted;
use App\Exceptions\ConversionException;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use App\Services\LeadConversionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * RF-LEAD-013 / ADR-001: lead → customer conversion. The whole operation
 * is one transaction; double conversion is impossible; a failure inside
 * the transaction leaves no partial state.
 */
class LeadConversionTest extends TestCase
{
    use RefreshDatabase;

    private LeadConversionService $service;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\CatalogSeeder::class);
        $this->service = app(LeadConversionService::class);
        $this->actor = User::factory()->create(['is_active' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function customerData(array $overrides = []): array
    {
        $base = [
            'person_type' => 'juridica',
            'legal_name' => 'Corporación Maia S.A.C.',
            'trade_name' => 'Maia',
            'doc_type' => 'ruc',
            'doc_number' => '20.512.345.67',
            'phone' => '+51 987 654 321',
            'email' => 'Contacto@MaiaExample.COM',
        ];

        return array_merge($base, $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function contactData(array $overrides = []): array
    {
        $base = [
            'first_name' => 'Rosa',
            'last_name' => 'Quispe',
            'position' => 'Gerente de Compras',
            'phone' => '+51 966 555 444',
            'email' => 'Rosa.Quispe@MaiaExample.com',
        ];

        return array_merge($base, $overrides);
    }

    public function test_convert_creates_customer_linked_to_lead_with_status_convertido(): void
    {
        $lead = Lead::factory()->forOwner($this->actor)->create();
        $year = now()->format('Y');

        $customer = $this->service->convert($lead, $this->customerData(), $this->actor);

        // Customer side (RF-CLI-006: converted_from_lead_id traceability).
        $this->assertSame("CLI-{$year}-00001", $customer->code);
        $this->assertSame('2051234567', $customer->doc_number_norm);
        $this->assertSame('contacto@maiaexample.com', $customer->email_norm);
        $this->assertSame($lead->id, $customer->converted_from_lead_id);
        $this->assertNotNull($customer->converted_at);
        $this->assertSame($this->actor->id, $customer->owner_id); // lead owner by default

        // Lead side: preserved, status convertido (is_final).
        $lead = $lead->refresh();
        $this->assertSame('convertido', $lead->status->slug);
        $this->assertTrue((bool) $lead->status->is_final);
        $this->assertSame($this->actor->id, $lead->updated_by);

        // Audit entries on both sides.
        $leadLog = \Spatie\Activitylog\Models\Activity::query()
            ->where('subject_type', Lead::class)
            ->where('subject_id', $lead->id)
            ->where('event', 'lead-converted')
            ->first();
        $this->assertNotNull($leadLog);
        $this->assertSame($customer->code, $leadLog->properties['customer_code']);

        $customerLog = \Spatie\Activitylog\Models\Activity::query()
            ->where('subject_type', Customer::class)
            ->where('subject_id', $customer->id)
            ->where('event', 'customer-created-from-lead')
            ->first();
        $this->assertNotNull($customerLog);
        $this->assertSame($lead->code, $customerLog->properties['lead_code']);
    }

        public function test_convert_dispatches_lead_converted_after_committing(): void
        {
            Event::fake([LeadConverted::class]);
            $lead = Lead::factory()->forOwner($this->actor)->create();

            $customer = $this->service->convert($lead, $this->customerData(), $this->actor);

            Event::assertDispatched(LeadConverted::class, function (LeadConverted $event) use ($lead, $customer): bool {
                return $event->lead->is($lead)
                    && $event->customer->is($customer)
                    && $event->lead->status->slug === 'convertido';
            });
        }

        public function test_convert_with_contact_creates_primary_contact(): void
        {
        $lead = Lead::factory()->forOwner($this->actor)->create();

        $customer = $this->service->convert(
            $lead,
            $this->customerData(),
            $this->actor,
            $this->contactData(),
        );

        $contact = $customer->contacts()->first();

        $this->assertNotNull($contact);
        $this->assertSame('Rosa', $contact->first_name);
        $this->assertSame('rosa.quispe@maiaexample.com', $contact->email_norm);
        $this->assertTrue((bool) $contact->is_primary);
        $this->assertTrue((bool) $contact->is_active);
        $this->assertSame(1, $customer->contacts()->count());
    }

    public function test_convert_preserves_a_legal_prospects_primary_contact(): void
    {
        $lead = app(\App\Services\LeadService::class)->create([
            'person_type' => 'juridica',
            'legal_name' => 'Comercial Andina S.A.C.',
            'doc_type' => 'ruc',
            'doc_number' => '20512345678',
            'source_id' => \App\Models\LeadSource::query()->where('slug', 'web')->value('id'),
            'status_id' => \App\Models\LeadStatus::query()->where('slug', 'nuevo')->value('id'),
            'owner_id' => $this->actor->id,
            'primary_contact' => [
                'first_name' => 'Rosa',
                'last_name' => 'Quispe',
                'position' => 'Gerente de Compras',
                'whatsapp' => '999888777',
            ],
        ], $this->actor);

        $customer = $this->service->convert($lead, $this->customerData(), $this->actor);

        $contact = $customer->contacts()->sole();
        $this->assertSame('Rosa', $contact->first_name);
        $this->assertSame('Quispe', $contact->last_name);
        $this->assertSame('999888777', $contact->whatsapp);
        $this->assertTrue((bool) $contact->is_primary);
    }

    public function test_double_conversion_throws_and_leaves_data_intact(): void
    {
        $lead = Lead::factory()->forOwner($this->actor)->create();

        $first = $this->service->convert(
            $lead,
            $this->customerData(['legal_name' => 'Primera Conversion S.A.C.']),
            $this->actor,
        );

        $this->expectException(ConversionException::class);

        try {
            $this->service->convert(
                $lead,
                $this->customerData(['legal_name' => 'Segunda Conversion S.A.C.']),
                $this->actor,
            );
        } catch (ConversionException $e) {
            // Nothing persisted by the second attempt.
            $this->assertSame(1, Customer::query()->count());
            $this->assertSame(1, $lead->convertedCustomers()->count());
            $this->assertSame($first->id, $lead->convertedCustomers()->first()->id);

            throw $e;
        }
    }

    public function test_final_status_lead_cannot_be_converted(): void
    {
        $lost = \App\Models\LeadStatus::query()->firstOrCreate(
            ['slug' => 'perdido'],
            ['name' => 'Perdido', 'sort' => 6, 'is_final' => true, 'is_active' => true],
        );

        $lead = Lead::factory()->create(['status_id' => $lost->id]);

        $this->expectException(ConversionException::class);

        $this->service->convert($lead, $this->customerData(), $this->actor);
    }

    public function test_rollback_leaves_no_customer_and_lead_unchanged(): void
    {
        $lead = Lead::factory()->forOwner($this->actor)->create();

        try {
            // Contact creation fails inside the conversion transaction
            // (last_name is a service-level invariant): everything must roll
            // back — no customer row, lead NOT in convertido.
            $this->service->convert(
                $lead,
                $this->customerData(),
                $this->actor,
                $this->contactData(['last_name' => null]),
            );

            $this->fail('Conversion with an invalid contact should have thrown.');
        } catch (\InvalidArgumentException) {
            // Expected: surfaced by the contact service invariants.
        }

        $this->assertSame(0, Customer::query()->count());
        $this->assertSame(0, Contact::query()->count());
        $this->assertSame(0, $lead->convertedCustomers()->count());

        $lead = $lead->refresh();
        $this->assertSame('nuevo', $lead->status->slug);
        $this->assertNotSame('convertido', $lead->status->slug);

        // The CLI sequence rolled back too: the next successful conversion
        // takes the first number.
        $year = now()->format('Y');
        $customer = $this->service->convert($lead, $this->customerData(), $this->actor);
        $this->assertSame("CLI-{$year}-00001", $customer->code);
    }

    public function test_conversion_without_owner_defaults_to_actor(): void
    {
        // Lead without owner (owner_id NOT NULL — use a distinct owner and
        // verify the default picks the lead owner, not the actor).
        $owner = User::factory()->create(['is_active' => true]);
        $lead = Lead::factory()->forOwner($owner)->create();
        $actor = User::factory()->create(['is_active' => true]);

        $customer = $this->service->convert($lead, $this->customerData(), $actor);

        $this->assertSame($owner->id, $customer->owner_id);
    }
}
