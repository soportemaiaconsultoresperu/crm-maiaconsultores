<?php

namespace Tests\Feature;

use App\Models\ActivityType;
use App\Models\CampaignActionItem;
use App\Models\CampaignItemReschedule;
use App\Models\CampaignParticipant;
use App\Models\CampaignRun;
use App\Models\CampaignStep;
use App\Models\CampaignTemplate;
use App\Models\User;
use App\Policies\CampaignActionItemPolicy;
use App\Policies\CampaignRunPolicy;
use App\Policies\CampaignTemplatePolicy;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Authorization contract for the campaign module (audit findings A-1 and A-2).
 *
 * This file exists because the three original campaign HTTP suites authenticated
 * as the seeded ADMIN, whose role short-circuits every `Gate` check through the
 * `Gate::before` bypass. A test that only proves "the admin can do it" proves
 * nothing about authorization, so every rule below is exercised with a NON-admin
 * actor that either holds the mapped permission (ALLOW, asserted on the effect)
 * or does not (DENY, 403).
 *
 * It is a separate file rather than more assertions inside the smoke suites
 * because those suites assert rendering/validation, while this one asserts the
 * authorization boundary between an ability, the permission row it maps to, and
 * the effect the endpoint produces.
 */
class CampaignAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The exact 20 permission rows created and granted by
     * `database/migrations/2026_08_20_000008_seed_campaign_permissions.php`.
     * Pinned here so a rename or a dropped grant is a test failure, not a
     * silent 403 in production.
     *
     * @var list<string>
     */
    private const CAMPAIGN_PERMISSIONS = [
        'campaign_templates.view',
        'campaign_templates.create',
        'campaign_templates.update',
        'campaign_templates.duplicate',
        'campaigns.view',
        'campaigns.create',
        'campaigns.update',
        'campaigns.schedule',
        'campaigns.start',
        'campaigns.pause',
        'campaigns.complete',
        'campaigns.cancel',
        'campaigns.duplicate',
        'campaigns.add_contacts',
        'campaigns.remove_contacts',
        'campaigns.register_actions',
        'campaigns.reschedule',
        'campaigns.mark_realized',
        'campaigns.view_reports',
        'campaigns.override_completion',
    ];

    /**
     * Ability => the permission row that must exist for it to be meaningful.
     *
     * @var array<class-string, array<string, string>>
     */
    private const ABILITY_PERMISSIONS = [
        CampaignRunPolicy::class => [
            'viewAny' => 'campaigns.view',
            'schedule' => 'campaigns.schedule',
            'pause' => 'campaigns.pause',
            'start' => 'campaigns.start',
            'cancel' => 'campaigns.cancel',
            'complete' => 'campaigns.complete',
            'duplicate' => 'campaigns.duplicate',
            'reschedule' => 'campaigns.reschedule',
        ],
        CampaignTemplatePolicy::class => [
            'viewAny' => 'campaign_templates.view',
            'duplicate' => 'campaign_templates.duplicate',
        ],
        CampaignActionItemPolicy::class => [
            'viewAny' => 'campaigns.view',
            'markRealized' => 'campaigns.mark_realized',
            'cancel' => 'campaigns.reschedule',
            'reschedule' => 'campaigns.reschedule',
        ],
    ];

    private CampaignTemplate $template;
    private CampaignRun $run;
    private CampaignActionItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CatalogSeeder::class);

        $owner = User::factory()->create(['is_active' => true]);
        $owner->assignRole('admin');

        $this->template = CampaignTemplate::query()->create([
            'name' => 'Authorization template',
            'objective' => 'custom',
            'status' => CampaignTemplate::STATUS_ACTIVE,
            'owner_id' => $owner->id,
        ]);

        $this->run = CampaignRun::query()->create([
            'code' => 'CR-2026-77777',
            'name' => 'Authorization run',
            'template_id' => $this->template->id,
            'template_hash' => 'auth',
            'starts_at' => now()->addDay(),
            'owner_id' => $owner->id,
            'status' => CampaignRun::STATUS_RUNNING,
        ]);

        $step = CampaignStep::query()->create([
            'is_template' => false,
            'template_id' => null,
            'run_id' => $this->run->id,
            'source_step_id' => null,
            'order' => 1,
            'action_type_id' => ActivityType::query()->where('slug', 'llamada')->value('id'),
            'title' => 'Llamada',
            'day_offset' => 0,
            'scheduled_time' => '09:00',
            'status' => CampaignStep::STATUS_ACTIVE,
        ]);

        $participant = CampaignParticipant::query()->create([
            'run_id' => $this->run->id,
            'subject_type' => 'lead',
            'subject_id' => 1,
            'assigned_to' => $owner->id,
            'status' => CampaignParticipant::STATUS_ACTIVE,
            'display_name' => 'Autorización',
        ]);

        $this->item = CampaignActionItem::query()->create([
            'run_id' => $this->run->id,
            'step_id' => $step->id,
            'participant_id' => $participant->id,
            'status' => CampaignActionItem::STATUS_PENDING,
            'scheduled_at' => now()->addDay(),
        ]);
    }

    // ---------------------------------------------------------------- POLICIES

    public function test_every_campaign_model_resolves_a_policy(): void
    {
        // A-1: the policy class existed but its name did not match the model and
        // it was not registered, so the Gate resolved nothing and only the admin
        // role bypass let anybody through.
        $this->assertInstanceOf(
            CampaignActionItemPolicy::class,
            Gate::getPolicyFor(CampaignActionItem::class),
            'CampaignActionItem must resolve a policy by convention.'
        );
        $this->assertInstanceOf(CampaignRunPolicy::class, Gate::getPolicyFor(CampaignRun::class));
        $this->assertInstanceOf(CampaignTemplatePolicy::class, Gate::getPolicyFor(CampaignTemplate::class));

        $this->assertFalse(
            class_exists('App\\Policies\\CampaignItemPolicy'),
            'The model-mismatched CampaignItemPolicy class must not come back; it shadows nothing and resolves nothing.'
        );
    }

    public function test_every_gated_ability_is_defined_and_backed_by_an_existing_permission(): void
    {
        foreach (self::ABILITY_PERMISSIONS as $policyClass => $abilities) {
            foreach ($abilities as $ability => $permission) {
                $this->assertTrue(
                    method_exists($policyClass, $ability),
                    "{$policyClass} must define [{$ability}] (the controllers call Gate::authorize on it)."
                );
                $this->assertTrue(
                    Permission::query()->where('name', $permission)->where('guard_name', 'web')->exists(),
                    "[{$ability}] maps to [{$permission}], which must exist as a permission row."
                );
            }
        }
    }

    public function test_no_campaign_permission_row_is_orphaned(): void
    {
        foreach (self::CAMPAIGN_PERMISSIONS as $name) {
            $permission = Permission::query()->where('name', $name)->where('guard_name', 'web')->first();

            $this->assertNotNull($permission, "[{$name}] must exist as a permission row.");

            $holders = DB::table('role_has_permissions')
                ->where('permission_id', $permission->getKey())
                ->distinct()
                ->count('role_id');

            $this->assertGreaterThan(
                0,
                $holders,
                "[{$name}] has no role holder: the row exists but no role can ever pass the check."
            );
        }
    }

    public function test_admin_and_supervisor_hold_every_campaign_permission(): void
    {
        foreach (['admin', 'supervisor'] as $roleName) {
            $role = \Spatie\Permission\Models\Role::findByName($roleName);

            foreach (self::CAMPAIGN_PERMISSIONS as $name) {
                $this->assertTrue(
                    $role->hasPermissionTo($name),
                    "Role [{$roleName}] must hold [{$name}]."
                );
            }
        }
    }

    public function test_vendedor_holds_the_field_facing_campaign_permissions(): void
    {
        // Mirrors what the campaign migration intended for the salesperson: see
        // their runs, reschedule, close their own items and read the reports.
        $role = \Spatie\Permission\Models\Role::findByName('vendedor');

        foreach (['campaigns.view', 'campaigns.reschedule', 'campaigns.mark_realized', 'campaigns.view_reports'] as $name) {
            $this->assertTrue($role->hasPermissionTo($name), "Role [vendedor] must hold [{$name}].");
        }

        // ...and NOT the module-lifecycle permissions.
        foreach (['campaigns.schedule', 'campaigns.pause', 'campaigns.start', 'campaigns.cancel', 'campaigns.complete', 'campaigns.duplicate'] as $name) {
            $this->assertFalse($role->hasPermissionTo($name), "Role [vendedor] must NOT hold [{$name}].");
        }
    }

    // ------------------------------------------------- CAMPAIGN RUN LIFECYCLE

    public function test_schedule_allows_a_non_admin_holder_and_forbids_a_holderless_actor(): void
    {
        $this->actingAs($this->actorWith('campaigns.schedule'));
        $this->post(route('admin.campaign_runs.schedule', $this->run))->assertRedirect();
        $this->assertSame(CampaignRun::STATUS_SCHEDULED, $this->run->fresh()->status);

        $blocked = $this->freshRun();
        $this->actingAs($this->actorWith());
        $this->post(route('admin.campaign_runs.schedule', $blocked))->assertForbidden();
        $this->assertSame(CampaignRun::STATUS_RUNNING, $blocked->fresh()->status);
    }

    public function test_pause_allows_a_non_admin_holder_and_forbids_a_holderless_actor(): void
    {
        $this->actingAs($this->actorWith('campaigns.pause'));
        $this->post(route('admin.campaign_runs.pause', $this->run), ['reason' => 'Pausa de prueba'])
            ->assertRedirect();
        $this->assertSame(CampaignRun::STATUS_PAUSED, $this->run->fresh()->status);

        $blocked = $this->freshRun();
        $this->actingAs($this->actorWith());
        $this->post(route('admin.campaign_runs.pause', $blocked), ['reason' => 'Pausa de prueba'])
            ->assertForbidden();
        $this->assertSame(CampaignRun::STATUS_RUNNING, $blocked->fresh()->status);
    }

    public function test_resume_allows_a_non_admin_holder_and_forbids_a_holderless_actor(): void
    {
        $paused = $this->freshRun(CampaignRun::STATUS_PAUSED);

        $this->actingAs($this->actorWith('campaigns.start'));
        $this->post(route('admin.campaign_runs.resume', $paused))->assertRedirect();
        $this->assertSame(CampaignRun::STATUS_RUNNING, $paused->fresh()->status);

        $blocked = $this->freshRun(CampaignRun::STATUS_PAUSED);
        $this->actingAs($this->actorWith());
        $this->post(route('admin.campaign_runs.resume', $blocked))->assertForbidden();
        $this->assertSame(CampaignRun::STATUS_PAUSED, $blocked->fresh()->status);
    }

    public function test_cancel_allows_a_non_admin_holder_and_forbids_a_holderless_actor(): void
    {
        $this->actingAs($this->actorWith('campaigns.cancel'));
        $this->post(route('admin.campaign_runs.cancel', $this->run), ['reason' => 'Cancelada en test'])
            ->assertRedirect();
        $this->assertSame(CampaignRun::STATUS_CANCELLED, $this->run->fresh()->status);
        $this->assertSame(CampaignActionItem::STATUS_CANCELLED, $this->item->fresh()->status);

        $blocked = $this->freshRun();
        $this->actingAs($this->actorWith());
        $this->post(route('admin.campaign_runs.cancel', $blocked), ['reason' => 'Cancelada en test'])
            ->assertForbidden();
        $this->assertSame(CampaignRun::STATUS_RUNNING, $blocked->fresh()->status);
    }

    public function test_complete_allows_a_non_admin_holder_and_forbids_a_holderless_actor(): void
    {
        $this->actingAs($this->actorWith('campaigns.complete'));
        $this->post(route('admin.campaign_runs.complete', $this->run), ['reason' => 'Cierre de prueba'])
            ->assertRedirect();
        $this->assertSame(CampaignRun::STATUS_COMPLETED, $this->run->fresh()->status);

        $blocked = $this->freshRun();
        $this->actingAs($this->actorWith());
        $this->post(route('admin.campaign_runs.complete', $blocked), ['reason' => 'Cierre de prueba'])
            ->assertForbidden();
        $this->assertSame(CampaignRun::STATUS_RUNNING, $blocked->fresh()->status);
    }

    public function test_duplicate_run_allows_a_non_admin_holder_and_forbids_a_holderless_actor(): void
    {
        $before = CampaignTemplate::query()->count();

        $this->actingAs($this->actorWith('campaigns.duplicate'));
        $this->post(route('admin.campaign_runs.duplicate', $this->run), ['new_name' => 'Copia autorizada'])
            ->assertRedirect();
        $this->assertSame($before + 1, CampaignTemplate::query()->count());
        $this->assertTrue(CampaignTemplate::query()->where('name', 'Copia autorizada (ejecución)')->exists());

        $this->actingAs($this->actorWith());
        $this->post(route('admin.campaign_runs.duplicate', $this->run), ['new_name' => 'Copia prohibida'])
            ->assertForbidden();
        $this->assertSame($before + 1, CampaignTemplate::query()->count());
        $this->assertFalse(CampaignTemplate::query()->where('name', 'Copia prohibida (ejecución)')->exists());
    }

    public function test_reschedule_all_allows_a_non_admin_holder_and_forbids_a_holderless_actor(): void
    {
        $newStart = now()->addDays(10)->startOfHour();

        $this->actingAs($this->actorWith('campaigns.reschedule'));
        $this->post(route('admin.campaign_runs.reschedule-all', $this->run), [
            'new_starts_at' => $newStart->toDateTimeString(),
            'reason' => 'Reprogramación autorizada de prueba',
        ])->assertRedirect();

        $this->assertSame(
            1,
            CampaignItemReschedule::query()
                ->where('item_id', $this->item->id)
                ->where('scope', CampaignItemReschedule::SCOPE_GLOBAL)
                ->count()
        );

        // The recalculation is the effect: new start + day_offset (0), at the
        // step's scheduled time (09:00).
        $expected = \Illuminate\Support\Carbon::parse($newStart)->setTimeFromTimeString('09:00');
        $this->assertTrue($expected->equalTo($this->item->fresh()->scheduled_at));

        $this->actingAs($this->actorWith());
        $this->post(route('admin.campaign_runs.reschedule-all', $this->freshRun()), [
            'new_starts_at' => now()->addDays(20)->startOfHour()->toDateTimeString(),
            'reason' => 'Reprogramación prohibida de prueba',
        ])->assertForbidden();
        $this->assertSame(1, CampaignItemReschedule::query()->count());
    }

    // --------------------------------------------------------- CAMPAIGN ITEMS

    public function test_item_mark_realized_allows_the_assignee_holder_and_forbids_a_holderless_assignee(): void
    {
        $holder = $this->assignItemTo($this->actorWith('campaigns.mark_realized'));

        $this->actingAs($holder);
        $this->post(route('admin.campaign_items.mark-realized', $this->item), ['result' => 'Contactado'])
            ->assertRedirect();

        $item = $this->item->fresh();
        $this->assertSame(CampaignActionItem::STATUS_COMPLETED, $item->status);
        $this->assertSame($holder->id, $item->completed_by);

        $second = $this->freshItem();
        $blocked = $this->assignItemTo($this->actorWith(), $second);
        $this->actingAs($blocked);
        $this->post(route('admin.campaign_items.mark-realized', $second), ['result' => 'Contactado'])
            ->assertForbidden();
        $this->assertSame(CampaignActionItem::STATUS_PENDING, $second->fresh()->status);
    }

    public function test_item_cancel_allows_the_assignee_holder_and_forbids_a_holderless_assignee(): void
    {
        $holder = $this->assignItemTo($this->actorWith('campaigns.reschedule'));

        $this->actingAs($holder);
        $this->post(route('admin.campaign_items.cancel', $this->item), ['cancellation_reason' => 'Sin respuesta'])
            ->assertRedirect();
        $this->assertSame(CampaignActionItem::STATUS_CANCELLED, $this->item->fresh()->status);

        $second = $this->freshItem();
        $blocked = $this->assignItemTo($this->actorWith(), $second);
        $this->actingAs($blocked);
        $this->post(route('admin.campaign_items.cancel', $second), ['cancellation_reason' => 'Sin respuesta'])
            ->assertForbidden();
        $this->assertSame(CampaignActionItem::STATUS_PENDING, $second->fresh()->status);
    }

    public function test_item_reschedule_allows_the_assignee_holder_and_forbids_a_holderless_assignee(): void
    {
        $holder = $this->assignItemTo($this->actorWith('campaigns.reschedule'));
        $newDate = now()->addDays(5)->startOfHour();

        $this->actingAs($holder);
        $this->post(route('admin.campaign_items.reschedule', $this->item), [
            'new_scheduled_at' => $newDate->toDateTimeString(),
            'reason' => 'Reprogramación individual de prueba',
        ])->assertRedirect();
        $this->assertTrue($newDate->equalTo($this->item->fresh()->scheduled_at));

        $second = $this->freshItem();
        $blocked = $this->assignItemTo($this->actorWith(), $second);
        $this->actingAs($blocked);
        $this->post(route('admin.campaign_items.reschedule', $second), [
            'new_scheduled_at' => now()->addDays(6)->startOfHour()->toDateTimeString(),
            'reason' => 'Reprogramación individual prohibida',
        ])->assertForbidden();
        $this->assertSame(0, $second->fresh()->reschedule_count);
    }

    // -------------------------------------------------------- TEMPLATE MODULE

    public function test_template_duplicate_allows_a_non_admin_holder_and_forbids_a_holderless_actor(): void
    {
        $before = CampaignTemplate::query()->count();

        $this->actingAs($this->actorWith('campaign_templates.duplicate'));
        $this->post(route('admin.campaign_templates.duplicate', $this->template), ['new_name' => 'Plantilla copiada'])
            ->assertRedirect();
        $this->assertSame($before + 1, CampaignTemplate::query()->count());
        $this->assertTrue(CampaignTemplate::query()->where('name', 'Plantilla copiada')->exists());

        $this->actingAs($this->actorWith());
        $this->post(route('admin.campaign_templates.duplicate', $this->template), ['new_name' => 'Plantilla prohibida'])
            ->assertForbidden();
        $this->assertSame($before + 1, CampaignTemplate::query()->count());
        $this->assertFalse(CampaignTemplate::query()->where('name', 'Plantilla prohibida')->exists());
    }

    public function test_template_view_any_follows_the_permission_the_module_actually_creates(): void
    {
        // A-2 second divergence: ModulePolicy asks for `{module}.view.any`, but
        // the campaign module's rows are flat (`campaign_templates.view`), so the
        // inherited viewAny could never return true for a non-admin.
        $this->assertTrue(Gate::forUser($this->actorWith('campaign_templates.view'))->allows('viewAny', CampaignTemplate::class));
        $this->assertFalse(Gate::forUser($this->actorWith())->allows('viewAny', CampaignTemplate::class));
        $this->assertTrue(Gate::forUser($this->actorWith('campaigns.view'))->allows('viewAny', CampaignRun::class));
        $this->assertFalse(Gate::forUser($this->actorWith())->allows('viewAny', CampaignRun::class));
    }

    // ---------------------------------------------------------------- HELPERS

    /**
     * A NON-admin actor (no role at all) holding exactly the given permissions.
     * Using a roleless user keeps the DENY cases honest: they fail because the
     * permission is absent, not because of a role shortcut.
     */
    private function actorWith(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    private function freshRun(string $status = CampaignRun::STATUS_RUNNING): CampaignRun
    {
        return CampaignRun::query()->create([
            'code' => 'CR-2026-'.random_int(10000, 99999),
            'name' => 'Authorization run '.random_int(1, 9999),
            'template_id' => $this->template->id,
            'template_hash' => 'auth',
            'starts_at' => now()->addDay(),
            'owner_id' => $this->run->owner_id,
            'status' => $status,
        ]);
    }

    private function freshItem(): CampaignActionItem
    {
        $step = CampaignStep::query()->create([
            'is_template' => false,
            'template_id' => null,
            'run_id' => $this->run->id,
            'source_step_id' => null,
            'order' => random_int(100, 999),
            'action_type_id' => ActivityType::query()->where('slug', 'llamada')->value('id'),
            'title' => 'Llamada',
            'day_offset' => 0,
            'scheduled_time' => '09:00',
            'status' => CampaignStep::STATUS_ACTIVE,
        ]);

        $participant = CampaignParticipant::query()->create([
            'run_id' => $this->run->id,
            'subject_type' => 'lead',
            'subject_id' => random_int(1000, 9999),
            'status' => CampaignParticipant::STATUS_ACTIVE,
            'display_name' => 'Autorización 2',
        ]);

        return CampaignActionItem::query()->create([
            'run_id' => $this->run->id,
            'step_id' => $step->id,
            'participant_id' => $participant->id,
            'status' => CampaignActionItem::STATUS_PENDING,
            'scheduled_at' => now()->addDay(),
        ]);
    }

    /**
     * Item authorization is ownership-bound, so the actor must be the assignee.
     */
    private function assignItemTo(User $user, ?CampaignActionItem $item = null): User
    {
        $item ??= $this->item;
        $item->participant->update(['assigned_to' => $user->id]);

        return $user;
    }
}
