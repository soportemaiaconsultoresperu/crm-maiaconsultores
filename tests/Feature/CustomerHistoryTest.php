<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use App\Services\CustomerService;
use App\Services\LeadConversionService;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RF-CLI-005 / ADR-001: the customer timeline merges the customer's own
 * activities + activitylog AND the origin lead's activities + activitylog.
 */
class CustomerHistoryTest extends TestCase
{
    use RefreshDatabase;

    private CustomerService $customers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->customers = app(CustomerService::class);
    }

    public function test_history_merges_lead_and_customer_streams_ordered_desc(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $lead = Lead::factory()->forOwner($actor)->create();

        // Lead-origin CRM activity (oldest).
        $leadActivity = Activity::factory()
            ->forSubject($lead)
            ->create(['title' => 'Primera llamada comercial', 'scheduled_at' => now()->subDays(3)]);

        $customer = app(LeadConversionService::class)->convert(
            $lead,
            [
                'person_type' => 'juridica',
                'legal_name' => 'Corporación Maia S.A.C.',
                'doc_type' => 'ruc',
                'doc_number' => '20.512.345.67',
            ],
            $actor,
        );

        // Customer-side CRM activity (newest).
        $customerActivity = Activity::factory()
            ->forSubject($customer)
            ->create(['title' => 'Reunión de kickoff', 'scheduled_at' => now()->subDay()]);

        $history = $this->customers->history($customer);

        // Customer CRM activity present with origin=customer.
        $customerEntries = $history->where('kind', 'activity')->where('meta.origin', 'customer');
        $this->assertTrue($customerEntries->pluck('title')->contains('Reunión de kickoff'));

        // Lead-origin CRM activity still reachable with origin=lead.
        $leadEntries = $history->where('kind', 'activity')->where('meta.origin', 'lead');
        $this->assertTrue($leadEntries->pluck('title')->contains('Primera llamada comercial'));

        // Both activitylog streams: lead-converted (subject Lead) and
        // customer-created-from-lead (subject Customer).
        $logEvents = $history->where('kind', 'log')->pluck('meta.event');
        $this->assertTrue($logEvents->contains('lead-converted'));
        $this->assertTrue($logEvents->contains('customer-created-from-lead'));

        // Sorted newest first.
        $timestamps = $history->pluck('at')->map(fn ($at) => $at->getTimestamp())->values();
        $sorted = $timestamps->sortDesc()->values();
        $this->assertSame(
            $sorted->all(),
            $timestamps->all(),
            'history() entries must be ordered newest first.',
        );

        // Shared entry shape for Blade (same as LeadService::history).
        $history->each(function (array $entry): void {
            $this->assertSame(
                ['kind', 'at', 'title', 'detail', 'meta', 'model'],
                array_keys($entry),
            );
        });
    }

    public function test_history_of_direct_customer_excludes_other_records(): void
    {
        $actor = User::factory()->create(['is_active' => true]);

        $direct = $this->customers->create(
            [
                'person_type' => 'juridica',
                'legal_name' => 'Cliente Directo S.A.C.',
                'doc_type' => 'ruc',
                'doc_number' => '20.999.888.77',
            ],
            $actor,
        );

        Activity::factory()->forSubject($direct)->create([
            'title' => 'Visita comercial',
            'scheduled_at' => now()->subDays(2),
        ]);

        $other = Lead::factory()->forOwner($actor)->create();
        Activity::factory()->forSubject($other)->create([
            'title' => 'Actividad de otro lead',
            'scheduled_at' => now()->subDay(),
        ]);

        $history = $this->customers->history($direct);

        $titles = $history->pluck('title');
        $this->assertTrue($titles->contains('Visita comercial'));
        $this->assertFalse($titles->contains('Actividad de otro lead'));
        $this->assertFalse($history->contains(fn (array $entry) => $entry['meta']['origin'] === 'lead'));
    }
}
