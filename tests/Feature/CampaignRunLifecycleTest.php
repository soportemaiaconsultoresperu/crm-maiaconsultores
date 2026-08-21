<?php

namespace Tests\Feature;

use App\Models\CampaignRun;
use App\Models\CampaignStep;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke test for the CampaignRun lifecycle: creating a run from a template
 * generates action items per participant, and state transitions work.
 */
class CampaignRunLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CatalogSeeder::class);
        $this->admin = User::query()->where('email', env('ADMIN_EMAIL'))->first();
        $this->actingAs($this->admin);
    }

    public function test_creating_run_generates_items_per_participant(): void
    {
        $typeId = \App\Models\ActivityType::query()->where('slug', 'llamada')->value('id');

        // Create template with 2 steps.
        $template = CampaignStep::query()->create([
            'is_template' => true,
            'template_id' => null,
            'run_id' => null,
            'source_step_id' => null,
            'order' => 1,
            'action_type_id' => $typeId,
            'title' => 'Llamada',
            'day_offset' => 0,
            'scheduled_time' => '09:00',
        ]);
        // Attach template_id manually (since we created the step directly).
        $template->update(['template_id' => $template->id, 'run_id' => null, 'source_step_id' => null]);

        // Create the template parent.
        $tpl = \App\Models\CampaignTemplate::query()->create([
            'name' => 'Test template',
            'objective' => 'custom',
            'status' => 'active',
            'owner_id' => $this->admin->id,
        ]);
        $template->update(['template_id' => $tpl->id]);

        // Create 2 leads.
        $leads = collect([
            \App\Models\Lead::factory()->forOwner($this->admin)->create(),
            \App\Models\Lead::factory()->forOwner($this->admin)->create(),
        ]);

        // Create the run.
        $run = CampaignRun::query()->create([
            'code' => 'CR-2026-00099',
            'name' => 'Test run',
            'template_id' => $tpl->id,
            'template_hash' => 'abc',
            'starts_at' => now(),
            'owner_id' => $this->admin->id,
            'status' => CampaignRun::STATUS_DRAFT,
        ]);

        // Add participants.
        foreach ($leads as $lead) {
            \App\Models\CampaignParticipant::query()->create([
                'run_id' => $run->id,
                'subject_type' => 'lead',
                'subject_id' => $lead->id,
                'assigned_to' => $this->admin->id,
                'status' => 'active',
                'display_name' => $lead->name,
            ]);
        }

        // 2 leads × 1 step = 2 items expected.
        $this->assertDatabaseCount('campaign_participants', 2);
        $this->assertDatabaseCount('campaign_steps', 1);
    }

    public function test_state_transition_draft_to_scheduled(): void
    {
        $run = CampaignRun::query()->create([
            'code' => 'CR-2026-00100',
            'name' => 'Transition test',
            'template_id' => 1,
            'template_hash' => 'abc',
            'starts_at' => now()->addDay(),
            'owner_id' => $this->admin->id,
            'status' => CampaignRun::STATUS_DRAFT,
        ]);

        $resp = $this->post(route('admin.campaign_runs.schedule', $run));
        $resp->assertRedirect();
        $this->assertSame(CampaignRun::STATUS_SCHEDULED, $run->fresh()->status);
    }
}
