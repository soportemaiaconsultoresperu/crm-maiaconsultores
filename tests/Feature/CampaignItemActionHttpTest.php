<?php

namespace Tests\Feature;

use App\Models\CampaignActionItem;
use App\Models\CampaignParticipant;
use App\Models\CampaignRun;
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

        $this->admin = User::query()->where('email', env('ADMIN_EMAIL'))->first();
        $vendedorRole = \Spatie\Permission\Models\Role::findByName('vendedor');
        $this->vendedor = User::query()->where('email', 'vendedor@example.com')->first() ?? User::factory()->create(['email' => 'vendedor@example.com']);
        $this->vendedor->assignRole('vendedor');

        $run = CampaignRun::query()->create([
            'code' => 'CR-2026-00088',
            'name' => 'Item action test',
            'template_id' => 1,
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
        $this->item = CampaignActionItem::query()->create([
            'run_id' => $run->id,
            'step_id' => 1,
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
