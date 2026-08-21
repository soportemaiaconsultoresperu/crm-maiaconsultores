<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LeadDuplicateFinder;
use App\Services\LeadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadCrudTest extends TestCase
{
    use RefreshDatabase;

    private LeadService $service;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(LeadService::class);
        $this->seed(\Database\Seeders\CatalogSeeder::class);
        $this->actor = User::factory()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function validData(array $overrides = []): array
    {
        $source = \App\Models\LeadSource::firstOrCreate(
            ['slug' => 'web'],
            ['name' => 'Web', 'sort' => 1],
        );

        return [
            'person_type' => 'natural',
            'first_name' => 'Juan',
            'last_name' => 'Pérez',
            'doc_type' => 'dni',
            'doc_number' => '12.345.678',
            'phone' => '+51 987 654 321',
            'whatsapp' => '987 654 321',
            'email' => '  Juan.Perez@Example.COM ',
            'interest_level' => 'alto',
            'source_id' => $source->id,
        ] + $overrides;
    }

    public function test_create_generates_sequential_codes_and_fills_norms(): void
    {
        $year = now()->format('Y');

        $lead = $this->service->create($this->validData(), $this->actor);

        $this->assertSame("LEAD-{$year}-00001", $lead->code);
        $this->assertSame('12345678', $lead->doc_number_norm);
        $this->assertSame('51987654321', $lead->phone_norm);
        $this->assertSame('987654321', $lead->whatsapp_norm);
        $this->assertSame('juan.perez@example.com', $lead->email_norm);
        $this->assertNotNull($lead->entered_at);
        $this->assertSame($this->actor->id, $lead->created_by);

        $second = $this->service->create($this->validData(), $this->actor);
        $this->assertSame("LEAD-{$year}-00002", $second->code);
    }

    public function test_update_recomputes_norms(): void
    {
        $lead = $this->service->create($this->validData(), $this->actor);

        $lead = $this->service->update($lead, [
            'phone' => '+51 987 111 222',
            'email' => 'Juan.Nuevo@Example.com',
            'doc_number' => ' 87.654.321 ',
        ], $this->actor);

        $this->assertSame('51987111222', $lead->phone_norm);
        $this->assertSame('juan.nuevo@example.com', $lead->email_norm);
        $this->assertSame('87654321', $lead->doc_number_norm);
        $this->assertSame($this->actor->id, $lead->updated_by);
    }

    public function test_update_does_not_allow_changing_the_code(): void
    {
        $lead = $this->service->create($this->validData(), $this->actor);

        $lead = $this->service->update($lead, ['code' => 'LEAD-FAKE-99999', 'first_name' => 'Ana'], $this->actor);

        $this->assertNotSame('LEAD-FAKE-99999', $lead->code);
        $this->assertSame('Ana', $lead->first_name);
    }

    public function test_assign_changes_owner_and_logs_reassignment_activity(): void
    {
        $lead = $this->service->create($this->validData(), $this->actor);
        $oldOwnerId = $lead->owner_id;
        $newOwner = User::factory()->create();

        $lead = $this->service->assign($lead, $newOwner, $this->actor, 'Rebalanceo de cartera');

        $this->assertSame($newOwner->id, $lead->owner_id);

        $log = \Spatie\Activitylog\Models\Activity::query()
            ->where('subject_type', \App\Models\Lead::class)
            ->where('subject_id', $lead->id)
            ->where('event', 'lead-reassigned')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($this->actor->id, $log->causer_id);
        $this->assertSame($oldOwnerId, (int) $log->properties['old_owner_id']);
        $this->assertSame($newOwner->id, (int) $log->properties['new_owner_id']);
        $this->assertSame('Rebalanceo de cartera', $log->properties['note']);
    }

    public function test_deactivate_soft_deletes_and_logs_reason(): void
    {
        $lead = $this->service->create($this->validData(), $this->actor);

        $lead = $this->service->deactivate($lead, $this->actor, 'Datos falsos');

        $this->assertSoftDeleted($lead);

        $log = \Spatie\Activitylog\Models\Activity::query()
            ->where('subject_type', \App\Models\Lead::class)
            ->where('subject_id', $lead->id)
            ->where('event', 'lead-deactivated')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($this->actor->id, $log->causer_id);
        $this->assertSame('Datos falsos', $log->properties['reason']);
    }

    public function test_duplicate_creation_is_not_blocked_by_the_service(): void
    {
        // ADR-003: blocking is a UI-level decision; the service only
        // exposes the duplicate finder.
        $first = $this->service->create($this->validData(), $this->actor);
        $second = $this->service->create($this->validData(), $this->actor);

        $this->assertNotNull($second->id);
        $this->assertNotSame($first->id, $second->id);

        $check = app(LeadDuplicateFinder::class)->check($this->validData());
        $this->assertTrue($check->hasCritical());
    }

    public function test_history_merges_crm_activities_and_field_changes(): void
    {
        $lead = $this->service->create($this->validData(), $this->actor);
        $newOwner = User::factory()->create();
        $this->service->assign($lead, $newOwner, $this->actor);

        \App\Models\Activity::create([
            'type_id' => \App\Models\ActivityType::query()->firstOrCreate(
                ['slug' => 'llamada'],
                ['name' => 'Llamada', 'sort' => 1, 'is_active' => true],
            )->id,
            'subject_type' => \App\Models\Lead::class,
            'subject_id' => $lead->id,
            'owner_id' => $this->actor->id,
            'title' => 'Llamada de seguimiento',
            'scheduled_at' => now()->addDay(),
            'status' => 'pending',
            'priority' => 'media',
        ]);

        $history = $this->service->history($lead);

        $kinds = $history->pluck('kind')->values();
        $this->assertContains('activity', $kinds->all());
        $this->assertTrue(
            $kinds->filter(fn (string $kind) => $kind === 'log')->count() >= 2,
            'Expected the created log entry and the reassignment log entry.'
        );

        // Newest first ordering.
        $timestamps = $history->map(fn (array $item) => $item['at']->getTimestamp());
        $this->assertEquals($timestamps->sortDesc()->values()->all(), $timestamps->all());
    }
}
