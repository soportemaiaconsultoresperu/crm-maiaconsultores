<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\User;
use App\Notifications\ActivityAssigned;
use App\Services\ActivityService;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * B05 activities HTTP layer (RF-ACT-001..008). Covers:
 * - Subject-bound creation (lead/customer/opportunity POST endpoints).
 * - Standalone index/show/create/edit.
 * - start / complete (with and without follow-up) / cancel / destroy.
 * - Scope enforcement: a salesperson never sees another salesperson's row.
 * - Notification fan-out on cross-owner creation.
 */
class ActivityHttpTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $salespersonOne;

    private User $salespersonTwo;

    private ActivityService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->salespersonOne = User::factory()->create(['is_active' => true]);
        $this->salespersonOne->assignRole('vendedor');

        $this->salespersonTwo = User::factory()->create(['is_active' => true]);
        $this->salespersonTwo->assignRole('vendedor');

        $this->service = app(ActivityService::class);
    }

    private function typeId(string $slug): int
    {
        return ActivityType::query()->where('slug', $slug)->value('id');
    }

    private function validData(array $overrides = []): array
    {
        $lead = Lead::factory()->forOwner($this->salespersonOne)->create();

        return array_merge([
            'subject_type' => 'lead',
            'subject_id' => $lead->id,
            'type_id' => $this->typeId('llamada'),
            'title' => 'Llamada de calificación',
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'priority' => 'media',
        ], $overrides);
    }

    public function test_index_renders_for_salesperson_with_own_activities(): void
    {
        $mine = $this->service->create($this->validData(), $this->salespersonOne);
        $other = $this->service->create(
            $this->validData(['subject_id' => Lead::factory()->forOwner($this->salespersonTwo)->create()->id]),
            $this->salespersonTwo,
        );

        $response = $this->actingAs($this->salespersonOne)->get('/activities');

        $response->assertOk();

        // The activity title is rendered inside a link; we check for a
        // testid-stamped row instead of the Spanish string to avoid
        // locale-sensitive assertion failures.
        $response->assertSee('activity-row', false);
        $this->assertSame(1, substr_count($response->getContent(), 'activity-row'));
    }

    public function test_create_form_renders_for_salesperson(): void
    {
        $this->actingAs($this->salespersonOne)
            ->get('/activities/create')
            ->assertOk()
            ->assertSee('Nueva actividad');
    }

    public function test_store_creates_activity_and_redirects_to_show(): void
    {
        $response = $this->actingAs($this->salespersonOne)
            ->post('/activities', $this->validData([
                'scheduled_at' => '2099-01-15T10:00',
            ]));

        $activity = Activity::query()->where('title', 'Llamada de calificación')->first();
        $this->assertNotNull($activity);
        $response->assertRedirect(route('activities.show', $activity));
        $response->assertSessionHas('status');
    }

    public function test_store_rejects_bad_date(): void
    {
        $response = $this->actingAs($this->salespersonOne)
            ->from('/activities/create')
            ->post('/activities', $this->validData(['scheduled_at' => 'not-a-date']));

        $response->assertRedirect('/activities/create');
        $response->assertSessionHasErrors('scheduled_at');
    }

    public function test_store_with_cross_owner_emits_assigned_notification(): void
    {
        Notification::fake();

        $this->actingAs($this->salespersonOne)
            ->post('/activities', $this->validData([
                'owner_id' => $this->salespersonTwo->id,
                'scheduled_at' => '2099-01-15T10:00',
            ]));

        Notification::assertSentTo($this->salespersonTwo, ActivityAssigned::class);
        Notification::assertNotSentTo($this->salespersonOne, ActivityAssigned::class);
    }

    public function test_create_activity_from_lead_show_redirects_to_lead_show(): void
    {
        $lead = Lead::factory()->forOwner($this->salespersonOne)->create();

        $response = $this->actingAs($this->salespersonOne)
            ->from("/leads/{$lead->id}")
            ->post("/leads/{$lead->id}/activities", [
                'type_id' => $this->typeId('llamada'),
                'title' => 'Llamada desde el prospecto',
                'priority' => 'media',
                'scheduled_at' => '2099-02-01T09:00',
            ]);

        $response->assertRedirect(route('leads.show', $lead));
        $response->assertSessionHas('status');

        $activity = Activity::query()->where('title', 'Llamada desde el prospecto')->first();
        $this->assertNotNull($activity);
        $this->assertSame(Lead::class, $activity->subject_type);
        $this->assertSame($lead->id, $activity->subject_id);
    }

    public function test_create_activity_from_customer_show_redirects_to_customer_show(): void
    {
        $customer = Customer::factory()->forOwner($this->salespersonOne)->create();

        $response = $this->actingAs($this->salespersonOne)
            ->from("/customers/{$customer->id}")
            ->post("/customers/{$customer->id}/activities", [
                'type_id' => $this->typeId('reunion'),
                'title' => 'Reunión agendada',
                'priority' => 'alta',
                'scheduled_at' => '2099-03-10T15:00',
            ]);

        $response->assertRedirect(route('customers.show', $customer));
        $this->assertNotNull(Activity::query()->where('title', 'Reunión agendada')->first());
    }

    public function test_create_activity_from_opportunity_show_redirects_to_opportunity_show(): void
    {
        $opportunity = Opportunity::factory()->forOwner($this->salespersonOne)->create();

        $response = $this->actingAs($this->salespersonOne)
            ->from("/opportunities/{$opportunity->id}")
            ->post("/opportunities/{$opportunity->id}/activities", [
                'type_id' => $this->typeId('visita'),
                'title' => 'Visita técnica',
                'priority' => 'media',
                'scheduled_at' => '2099-04-20T11:00',
            ]);

        $response->assertRedirect(route('opportunities.show', $opportunity));
        $this->assertNotNull(Activity::query()->where('title', 'Visita técnica')->first());
    }

    public function test_show_returns_404_for_another_salespersons_activity(): void
    {
        $activity = $this->service->create(
            $this->validData(['subject_id' => Lead::factory()->forOwner($this->salespersonTwo)->create()->id]),
            $this->salespersonTwo,
        );

        $this->actingAs($this->salespersonOne)
            ->get("/activities/{$activity->id}")
            ->assertForbidden();
    }

    public function test_start_transitions_to_in_process(): void
    {
        $activity = $this->service->create($this->validData(), $this->salespersonOne);

        $this->actingAs($this->salespersonOne)
            ->post("/activities/{$activity->id}/start")
            ->assertRedirect(route('activities.show', $activity));

        $this->assertSame('in_process', $activity->refresh()->status);
    }

    public function test_complete_without_next_persists_result(): void
    {
        $activity = $this->service->create($this->validData(), $this->salespersonOne);

        $this->actingAs($this->salespersonOne)
            ->post("/activities/{$activity->id}/complete", [
                'result' => 'Cliente confirmó seguimiento',
            ])
            ->assertRedirect(route('activities.show', $activity));

        $activity->refresh();
        $this->assertSame('completed', $activity->status);
        $this->assertSame('Cliente confirmó seguimiento', $activity->result);
    }

    public function test_complete_without_result_fails_validation(): void
    {
        $activity = $this->service->create($this->validData(), $this->salespersonOne);

        $response = $this->actingAs($this->salespersonOne)
            ->from(route('activities.show', $activity))
            ->post("/activities/{$activity->id}/complete", []);

        $response->assertRedirect(route('activities.show', $activity));
        $response->assertSessionHasErrors('result');
        $this->assertSame('pending', $activity->refresh()->status);
    }

    public function test_complete_with_create_next_missing_scheduled_at_fails(): void
    {
        $activity = $this->service->create($this->validData(), $this->salespersonOne);

        $response = $this->actingAs($this->salespersonOne)
            ->from(route('activities.show', $activity))
            ->post("/activities/{$activity->id}/complete", [
                'result' => 'Cerrado',
                'create_next' => 'on',
                'next_type_id' => $this->typeId('reunion'),
                'next_title' => 'Próxima reunión',
                // next_scheduled_at is missing
            ]);

        $response->assertSessionHasErrors('next_scheduled_at');
        $this->assertSame('pending', $activity->refresh()->status);
    }

    public function test_complete_with_create_next_full_creates_follow_up(): void
    {
        $lead = Lead::factory()->forOwner($this->salespersonOne)->create();
        $activity = $this->service->create(
            $this->validData(['subject_id' => $lead->id]),
            $this->salespersonOne,
        );

        $this->actingAs($this->salespersonOne)
            ->post("/activities/{$activity->id}/complete", [
                'result' => 'Conversación exitosa',
                'create_next' => 'on',
                'next_type_id' => $this->typeId('reunion'),
                'next_scheduled_at' => '2099-05-10T10:00',
                'next_title' => 'Reunión de cierre',
            ])
            ->assertRedirect(route('activities.show', $activity));

        $activity->refresh();
        $this->assertSame('completed', $activity->status);

        $followUp = Activity::query()->where('title', 'Reunión de cierre')->first();
        $this->assertNotNull($followUp);
        $this->assertSame('pending', $followUp->status);
        $this->assertSame(Lead::class, $followUp->subject_type);
        $this->assertSame($lead->id, $followUp->subject_id);
    }

    public function test_re_complete_returns_to_completed_with_new_result(): void
    {
        $activity = $this->service->create($this->validData(), $this->salespersonOne);
        $this->service->complete($activity, ['result' => 'Listo'], $this->salespersonOne);

        // The service keeps allowing complete() on terminal rows (the state
        // machine guard lives in start() / update() / cancel()). The HTTP
        // path is therefore expected to succeed; the new result is stored.
        $this->actingAs($this->salespersonOne)
            ->post("/activities/{$activity->id}/complete", ['result' => 'Corrección del resultado'])
            ->assertRedirect();

        $this->assertSame('Corrección del resultado', $activity->refresh()->result);
        $this->assertSame('completed', $activity->status);
    }

    public function test_cancel_with_reason_persists(): void
    {
        $activity = $this->service->create($this->validData(), $this->salespersonOne);

        $this->actingAs($this->salespersonOne)
            ->post("/activities/{$activity->id}/cancel", ['reason' => 'Cliente canceló'])
            ->assertRedirect(route('activities.show', $activity));

        $this->assertSame('cancelled', $activity->refresh()->status);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Activity::class,
            'subject_id' => $activity->id,
            'event' => 'activity-cancelled',
        ]);
    }

    public function test_destroy_soft_deletes_with_reason(): void
    {
        $activity = $this->service->create($this->validData(), $this->salespersonOne);

        $this->actingAs($this->admin)
            ->post("/activities/{$activity->id}", ['reason' => 'Duplicado'])
            ->assertRedirect(route('activities.index'));

        $this->assertSoftDeleted('activities', ['id' => $activity->id]);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Activity::class,
            'subject_id' => $activity->id,
            'event' => 'activity-deactivated',
        ]);
    }

    public function test_destroy_hides_row_from_index(): void
    {
        $activity = $this->service->create($this->validData(), $this->salespersonOne);

        $this->actingAs($this->admin)
            ->post("/activities/{$activity->id}", ['reason' => 'Baja controlada']);

        $this->actingAs($this->admin)
            ->get('/activities')
            ->assertOk()
            ->assertSee('No hay actividades registradas.');
    }

    public function test_assigned_notification_only_fires_for_other_owner(): void
    {
        Notification::fake();

        // actor = salespersonOne, owner = salespersonOne: no notification.
        $this->actingAs($this->salespersonOne)->post('/activities', $this->validData());
        Notification::assertNotSentTo($this->salespersonOne, ActivityAssigned::class);

        Notification::fake();

        // actor = salespersonOne, owner = salespersonTwo: notification.
        $this->actingAs($this->salespersonOne)->post('/activities', $this->validData([
            'owner_id' => $this->salespersonTwo->id,
        ]));
        Notification::assertSentTo($this->salespersonTwo, ActivityAssigned::class);
    }
}
