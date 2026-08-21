<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\User;
use App\Notifications\OpportunityAssigned;
use App\Notifications\OpportunityStageChanged;
use App\Services\OpportunityService;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * RF-NOT-001 (partial): database-channel notifications for opportunity
 * assignment and stage change; no self-noise when actor == owner.
 */
class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    private OpportunityService $service;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->service = app(OpportunityService::class);
        $this->actor = User::factory()->create();
    }

    public function test_assignment_notification_is_persisted_for_new_owner(): void
    {
        // Do NOT fake notifications: verify the real database channel
        // writes to the notifications table.
        $owner = User::factory()->create();

        $opportunity = $this->service->create([
            'title' => 'Consultoría de mejora continua',
            'lead_id' => Lead::factory()->create()->id,
            'estimated_amount' => 9000,
            'owner_id' => $owner->id,
        ], $this->actor);

        $row = DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $owner->id)
            ->where('type', 'opportunity-assigned')
            ->first();

        $this->assertNotNull($row, 'Expected an opportunity-assigned notification row.');
        $this->assertSame($opportunity->code, $row->data['code']);
        $this->assertSame($this->actor->name, $row->data['from_user']);
        $this->assertStringContainsString($opportunity->code, $row->data['message']);
    }

    public function test_stage_change_notification_is_sent_to_owner(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $opportunity = Opportunity::factory()->forOwner($owner)->create();
        $to = \App\Models\PipelineStage::query()->where('slug', 'propuesta-enviada')->firstOrFail();

        $this->service->changeStage($opportunity, $to, $this->actor);

        Notification::assertSentTo($owner, OpportunityStageChanged::class);
        Notification::assertNotSentTo($this->actor, OpportunityStageChanged::class);
    }

    public function test_no_self_noise_when_actor_is_owner(): void
    {
        Notification::fake();

        $opportunity = Opportunity::factory()->forOwner($this->actor)->create();
        $to = \App\Models\PipelineStage::query()->where('slug', 'contacto-realizado')->firstOrFail();

        $this->service->changeStage($opportunity, $to, $this->actor);

        Notification::assertNothingSent();

        // Creation by the same actor: also silent.
        $this->service->create([
            'title' => 'Otra oportunidad propia',
            'lead_id' => Lead::factory()->create()->id,
            'estimated_amount' => 1000,
        ], $this->actor);

        Notification::assertNothingSent();
    }
}
