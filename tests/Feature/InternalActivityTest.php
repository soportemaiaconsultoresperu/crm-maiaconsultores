<?php

namespace Tests\Feature;

use App\Models\InternalActivity;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Internal activities (odd/tasks/internal-activities.md, T1: S2-S4, S8).
 * Organization-wide activities with an audience (all | roles | users),
 * participants, CRUD for any active user, and an "Interno" calendar layer.
 */
class InternalActivityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $seller;

    private User $supervisor;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole('vendedor');
        $this->supervisor = User::factory()->create(['is_active' => true]);
        $this->supervisor->assignRole('supervisor');
        $this->outsider = User::factory()->create(['is_active' => true]);
        $this->outsider->assignRole('vendedor');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Capacitación interna',
            'description' => 'Uso del CRM',
            'location' => 'Sala 1',
            'starts_at' => '2026-10-21 09:00',
            'ends_at' => '2026-10-21 11:00',
            'audience_type' => 'all',
        ], $overrides);
    }

    private function createAs(User $user, array $overrides = []): InternalActivity
    {
        $this->actingAs($user)->post(route('internal-activities.store'), $this->payload($overrides))
            ->assertRedirect();

        return InternalActivity::query()->latest('id')->firstOrFail();
    }

    public function test_any_active_user_can_create_with_participants_and_responsible(): void
    {
        $activity = $this->createAs($this->seller, [
            'participant_ids' => [$this->supervisor->id, $this->outsider->id],
            'responsible_id' => $this->supervisor->id,
        ]);

        $this->assertSame('Capacitación interna', $activity->title);
        $this->assertSame($this->seller->id, $activity->created_by);
        $this->assertSame('all', $activity->audience_type);
        $this->assertEqualsCanonicalizing(
            [$this->supervisor->id, $this->outsider->id],
            $activity->participants->pluck('id')->all(),
        );
        $this->assertSame($this->supervisor->id, $activity->responsible()?->id);
    }

    public function test_responsible_is_added_as_participant(): void
    {
        $activity = $this->createAs($this->seller, ['responsible_id' => $this->supervisor->id]);

        $this->assertSame([$this->supervisor->id], $activity->participants->pluck('id')->all());
    }

    public function test_store_validates_required_fields_and_audience_targets(): void
    {
        $before = InternalActivity::query()->count();

        $this->actingAs($this->seller)
            ->post(route('internal-activities.store'), ['audience_type' => 'roles'])
            ->assertSessionHasErrors(['title', 'starts_at', 'role_ids']);

        $this->actingAs($this->seller)
            ->post(route('internal-activities.store'), $this->payload(['audience_type' => 'users']))
            ->assertSessionHasErrors(['user_ids']);

        $this->actingAs($this->seller)
            ->post(route('internal-activities.store'), $this->payload(['ends_at' => '2026-10-21 08:00']))
            ->assertSessionHasErrors(['ends_at']);

        $this->assertSame($before, InternalActivity::query()->count());
    }

    public function test_audience_all_is_visible_to_everyone(): void
    {
        $activity = $this->createAs($this->seller);

        $this->actingAs($this->outsider)->get(route('internal-activities.show', $activity))->assertOk();
        $this->actingAs($this->outsider)->get(route('internal-activities.index'))->assertSee('Capacitación interna');
    }

    public function test_audience_roles_limits_visibility(): void
    {
        $activity = $this->createAs($this->seller, [
            'audience_type' => 'roles',
            'role_ids' => [Role::findByName('supervisor')->id],
        ]);

        $this->actingAs($this->supervisor)->get(route('internal-activities.show', $activity))->assertOk();
        $this->actingAs($this->outsider)->get(route('internal-activities.show', $activity))->assertForbidden();
        $this->actingAs($this->outsider)->get(route('internal-activities.index'))->assertDontSee('Capacitación interna');
        $this->actingAs($this->seller)->get(route('internal-activities.show', $activity))->assertOk();
        $this->actingAs($this->admin)->get(route('internal-activities.show', $activity))->assertOk();
    }

    public function test_audience_users_and_participants_see_it(): void
    {
        $activity = $this->createAs($this->seller, [
            'audience_type' => 'users',
            'user_ids' => [$this->supervisor->id],
            'participant_ids' => [$this->admin->id],
        ]);

        $this->actingAs($this->supervisor)->get(route('internal-activities.show', $activity))->assertOk();
        $this->actingAs($this->outsider)->get(route('internal-activities.show', $activity))->assertForbidden();

        $participantOnly = $this->createAs($this->seller, [
            'title' => 'Solo participante',
            'audience_type' => 'users',
            'user_ids' => [$this->supervisor->id],
            'participant_ids' => [$this->outsider->id],
        ]);
        $this->actingAs($this->outsider)->get(route('internal-activities.show', $participantOnly))->assertOk();
    }

    public function test_only_creator_or_admin_can_update_and_delete(): void
    {
        $activity = $this->createAs($this->seller);

        $this->actingAs($this->outsider)
            ->put(route('internal-activities.update', $activity), $this->payload(['title' => 'Hackeado']))
            ->assertForbidden();
        $this->actingAs($this->outsider)
            ->delete(route('internal-activities.destroy', $activity))
            ->assertForbidden();
        $this->assertSame('Capacitación interna', $activity->fresh()->title);

        $this->actingAs($this->seller)
            ->put(route('internal-activities.update', $activity), $this->payload(['title' => 'Editada']))
            ->assertRedirect(route('internal-activities.show', $activity));
        $this->assertSame('Editada', $activity->fresh()->title);

        $this->actingAs($this->admin)
            ->delete(route('internal-activities.destroy', $activity))
            ->assertRedirect(route('internal-activities.index'));
        $this->assertSoftDeleted('internal_activities', ['id' => $activity->id]);
    }

    public function test_calendar_shows_internal_layer_to_audience_only(): void
    {
        $this->createAs($this->seller, [
            'audience_type' => 'roles',
            'role_ids' => [Role::findByName('supervisor')->id],
        ]);

        $this->actingAs($this->supervisor)
            ->get(route('calendar.index', ['view' => 'list', 'anchor' => '2026-10-21']))
            ->assertOk()
            ->assertSee('Capacitación interna')
            ->assertSee('Interno');

        $this->actingAs($this->outsider)
            ->get(route('calendar.index', ['view' => 'list', 'anchor' => '2026-10-21']))
            ->assertOk()
            ->assertDontSee('Capacitación interna');
    }

    public function test_calendar_layer_filter_hides_or_isolates_internal_events(): void
    {
        $this->createAs($this->seller);

        $this->actingAs($this->seller)
            ->get(route('calendar.index', ['view' => 'list', 'anchor' => '2026-10-21', 'layer' => 'commercial']))
            ->assertOk()
            ->assertDontSee('Capacitación interna');

        $this->actingAs($this->seller)
            ->get(route('calendar.index', ['view' => 'month', 'anchor' => '2026-10-21', 'layer' => 'internal']))
            ->assertOk()
            ->assertSee('Capacitación interna')
            ->assertSee('calendar-event-internal', false);
    }

    public function test_calendar_without_layer_param_still_lists_commercial_activities(): void
    {
        $this->actingAs($this->seller)
            ->get(route('calendar.index', ['view' => 'month']))
            ->assertOk()
            ->assertSee('data-testid="calendar-grid"', false);
    }
}
