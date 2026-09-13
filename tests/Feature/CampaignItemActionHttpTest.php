<?php

namespace Tests\Feature;

use App\Models\ActivityType;
use App\Models\CampaignActionItem;
use App\Models\CampaignParticipant;
use App\Models\CampaignRun;
use App\Models\CampaignStep;
use App\Models\CampaignTemplate;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature test for per-row actions on campaign items (start, mark realized,
 * cancel, mark not applicable, reschedule, reopen).
 */
class CampaignItemActionHttpTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $vendedor;
    private CampaignActionItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CatalogSeeder::class);

        // Resolve the admin the way the working suites do: create the user and
        // assign the role explicitly. Reading `env('ADMIN_EMAIL')` returns null
        // under a cached config, which made this whole class ERROR in setUp and
        // hid the real 403 the vendedor scenarios below were about to hit.
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->vendedor = User::factory()->create(['email' => 'vendedor@example.com', 'is_active' => true]);
        $this->vendedor->assignRole('vendedor');

        $template = CampaignTemplate::query()->create([
            'name' => 'Item action template',
            'objective' => 'custom',
            'status' => CampaignTemplate::STATUS_ACTIVE,
            'owner_id' => $this->vendedor->id,
        ]);

        $run = CampaignRun::query()->create([
            'code' => 'CR-2026-00088',
            'name' => 'Item action test',
            'template_id' => $template->id,
            'template_hash' => 'x',
            'starts_at' => now(),
            'owner_id' => $this->vendedor->id,
            'status' => CampaignRun::STATUS_RUNNING,
        ]);
        $participant = CampaignParticipant::query()->create([
            'run_id' => $run->id,
            'subject_type' => 'lead',
            'subject_id' => 1,
            'assigned_to' => $this->vendedor->id,
            'status' => CampaignParticipant::STATUS_ACTIVE,
            'display_name' => 'Test',
        ]);
        $step = CampaignStep::query()->create([
            'is_template' => false,
            'template_id' => null,
            'run_id' => $run->id,
            'source_step_id' => null,
            'order' => 1,
            'action_type_id' => ActivityType::query()->where('slug', 'llamada')->value('id'),
            'title' => 'Llamada',
            'day_offset' => 0,
            'scheduled_time' => '09:00',
            'status' => CampaignStep::STATUS_ACTIVE,
        ]);

        $this->item = CampaignActionItem::query()->create([
            'run_id' => $run->id,
            'step_id' => $step->id,
            'participant_id' => $participant->id,
            'status' => CampaignActionItem::STATUS_PENDING,
            'scheduled_at' => now(),
        ]);
    }

    public function test_mark_realized_requires_result(): void
    {
        $this->actingAs($this->vendedor);
        $resp = $this->post(route('admin.campaign_items.mark-realized', $this->item), [
            'result' => '', // vacio — debe fallar
        ]);
        $resp->assertSessionHasErrors('result');
        $this->assertSame(CampaignActionItem::STATUS_PENDING, $this->item->fresh()->status);
    }

    public function test_mark_realized_succeeds(): void
    {
        $this->actingAs($this->vendedor);
        $resp = $this->post(route('admin.campaign_items.mark-realized', $this->item), [
            'result' => 'Cliente confirmó interés',
            'contact_response' => 'Llamó el martes',
        ]);
        $resp->assertRedirect();
        $item = $this->item->fresh();
        $this->assertSame(CampaignActionItem::STATUS_COMPLETED, $item->status);
        $this->assertSame('Cliente confirmó interés', $item->result);
    }

    public function test_reschedule_requires_future_date(): void
    {
        $this->actingAs($this->vendedor);
        $resp = $this->post(route('admin.campaign_items.reschedule', $this->item), [
            'new_scheduled_at' => '2020-01-01 10:00', // pasada
            'reason' => 'Cliente pidió reagendar',
        ]);
        $resp->assertSessionHasErrors('new_scheduled_at');
    }
}
