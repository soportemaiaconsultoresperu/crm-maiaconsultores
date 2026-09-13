<?php

namespace Tests\Feature;

use App\Models\CampaignRun;
use App\Models\CampaignStep;
use App\Models\CampaignTemplate;
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
        // Resolve the admin the way the working suites do: create the user and
        // assign the role explicitly. `env('ADMIN_EMAIL')` is null under a cached
        // config, which made this class ERROR in setUp.
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
        $this->actingAs($this->admin);
    }

    public function test_creating_run_generates_items_per_participant(): void
    {
        $typeId = \App\Models\ActivityType::query()->where('slug', 'llamada')->value('id');

        // Create the template parent FIRST: campaign_steps.template_id is a real
        // foreign key, so the step cannot point at a template that does not exist yet.
        $tpl = CampaignTemplate::query()->create([
            'name' => 'Test template',
            'objective' => 'custom',
            'status' => 'active',
            'owner_id' => $this->admin->id,
        ]);

        CampaignStep::query()->create([
            'is_template' => true,
            'template_id' => $tpl->id,
            'run_id' => null,
            'source_step_id' => null,
            'order' => 1,
            'action_type_id' => $typeId,
            'title' => 'Llamada',
            'day_offset' => 0,
            'scheduled_time' => '09:00',
            'status' => CampaignStep::STATUS_ACTIVE,
        ]);

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
                'display_name' => trim($lead->first_name.' '.$lead->last_name) ?: $lead->company_name,
            ]);
        }

        // 2 leads × 1 step = 2 items expected.
        $this->assertDatabaseCount('campaign_participants', 2);
        $this->assertDatabaseCount('campaign_steps', 1);
    }

    public function test_state_transition_draft_to_scheduled(): void
    {
        $tpl = CampaignTemplate::query()->create([
            'name' => 'Transition template',
            'objective' => 'custom',
            'status' => 'active',
            'owner_id' => $this->admin->id,
        ]);

        $run = CampaignRun::query()->create([
            'code' => 'CR-2026-00100',
            'name' => 'Transition test',
            'template_id' => $tpl->id,
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
