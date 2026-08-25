<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\LossReason;
use App\Models\Opportunity;
use App\Models\PipelineStage;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RF-OPP-001..010 (HTTP layer): Kanban scoping/rendering, stage moves via
 * POST (drag&drop endpoint + no-JS fallback), win/lose flows, closed-record
 * immutability, notifications bell/list and export permissions.
 */
class OpportunityHttpTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $salespersonOne;

    private User $salespersonTwo;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->salespersonOne = User::factory()->create(['is_active' => true]);
        $this->salespersonOne->assignRole('vendedor');

        $this->salespersonTwo = User::factory()->create(['is_active' => true]);
        $this->salespersonTwo->assignRole('vendedor');

        $this->supervisor = User::factory()->create(['is_active' => true]);
        $this->supervisor->assignRole('supervisor');

        $team = Team::create([
            'name' => 'Equipo Oportunidades',
            'supervisor_id' => $this->supervisor->id,
            'is_active' => true,
        ]);
        $team->members()->attach($this->salespersonOne->id);
    }

    private function stage(string $slug): PipelineStage
    {
        return PipelineStage::query()->where('slug', $slug)->firstOrFail();
    }

    public function test_kanban_is_scoped_vendedor_sees_only_own_cards(): void
    {
        $mine = Opportunity::factory()->forOwner($this->salespersonOne)->create();
        $other = Opportunity::factory()->forOwner($this->salespersonTwo)->create();

        $this->actingAs($this->salespersonOne)
            ->get('/opportunities-kanban')
            ->assertOk()
            ->assertSee($mine->code)
            ->assertDontSee($other->code);
    }

    public function test_kanban_shows_stage_columns_for_supervisor_and_team_cards(): void
    {
        $memberOpportunity = Opportunity::factory()->forOwner($this->salespersonOne)->create();
        $outsiderOpportunity = Opportunity::factory()->forOwner($this->salespersonTwo)->create();

        $this->actingAs($this->supervisor)
            ->get('/opportunities-kanban')
            ->assertOk()
            ->assertSee('Nueva oportunidad')
            ->assertSee('Contacto realizado')
            ->assertSee('Negociación')
            ->assertSee($memberOpportunity->code)
            ->assertDontSee($outsiderOpportunity->code);
    }

    public function test_opportunity_create_and_edit_forms_use_sweetalert_loading(): void
    {
        $this->actingAs($this->admin)
            ->get('/opportunities/create')
            ->assertOk()
            ->assertSee('data-testid="opportunity-form"', false)
            ->assertSee('data-swal-loading', false);

        $opportunity = Opportunity::factory()->forOwner($this->salespersonOne)->create();
        $content = $this->actingAs($this->admin)
            ->get("/opportunities/{$opportunity->id}/edit")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-testid="opportunity-form"', $content);
        $this->assertStringContainsString('data-swal-loading', $content);
        $this->assertStringContainsString('name="_method" value="PUT"', $content);
    }

    public function test_opportunity_detail_actions_use_sweetalert_confirmation_and_loading(): void
    {
        $opportunity = Opportunity::factory()->forOwner($this->salespersonOne)->create();

        $content = $this->actingAs($this->admin)
            ->get("/opportunities/{$opportunity->id}")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-testid="win-form" data-swal-loading', $content);
        $this->assertStringContainsString('data-swal-title="Marcar oportunidad ganada"', $content);
        $this->assertStringContainsString('data-testid="lose-form" data-swal-loading', $content);
        $this->assertStringContainsString('data-swal-title="Marcar oportunidad perdida"', $content);
        $this->assertStringContainsString('data-testid="deactivate-form" data-swal-loading', $content);
        $this->assertStringContainsString('data-swal-title="Desactivar oportunidad"', $content);
        $this->assertSame(3, substr_count($content, 'data-swal-confirm'));
    }

    public function test_opportunity_kanban_fallback_move_uses_sweetalert_confirmation_and_loading(): void
    {
        $opportunity = Opportunity::factory()->forOwner($this->salespersonOne)->create();

        $content = $this->actingAs($this->salespersonOne)
            ->get('/opportunities-kanban')
            ->assertOk()
            ->assertSee($opportunity->code)
            ->getContent();

        $this->assertStringContainsString('data-testid="move-form-'.$opportunity->code.'" data-swal-loading', $content);
        $this->assertStringContainsString('data-swal-title="Mover oportunidad"', $content);
        $this->assertStringContainsString('data-swal-text="La oportunidad cambiará a la etapa seleccionada."', $content);
        $this->assertStringContainsString("window.Swal.fire", $content);
        $this->assertStringContainsString("alert(message);", $content);
    }

    public function test_stage_post_moves_stage_and_writes_history(): void
    {
        $opportunity = Opportunity::factory()->forOwner($this->salespersonOne)->create();
        $target = $this->stage('contacto-realizado');

        $this->actingAs($this->salespersonOne)
            ->post("/opportunities/{$opportunity->id}/stage", [
                'stage_id' => $target->id,
                'note' => 'Cliente respondió el correo',
            ])
            ->assertRedirect();

        $this->assertSame($target->id, $opportunity->fresh()->stage_id);
        $this->assertDatabaseHas('opportunity_stage_histories', [
            'opportunity_id' => $opportunity->id,
            'to_stage_id' => $target->id,
            'note' => 'Cliente respondió el correo',
        ]);
    }

    public function test_stage_post_targeting_ganada_is_rejected_with_no_history(): void
    {
        $opportunity = Opportunity::factory()->forOwner($this->salespersonOne)->create();
        $won = $this->stage('ganada');
        $historyCount = $opportunity->stageHistories()->count();

        $response = $this->actingAs($this->salespersonOne)
            ->from("/opportunities/{$opportunity->id}")
            ->post("/opportunities/{$opportunity->id}/stage", ['stage_id' => $won->id]);

        $response->assertRedirect("/opportunities/{$opportunity->id}");
        $response->assertSessionHas('error');

        $this->assertSame($this->stage('nueva-oportunidad')->id, $opportunity->fresh()->stage_id);
        $this->assertSame($historyCount, $opportunity->stageHistories()->count());
    }

    public function test_stage_post_on_closed_opportunity_fails(): void
    {
        $opportunity = Opportunity::factory()->won()->forOwner($this->salespersonOne)->create();
        $target = $this->stage('negociacion');
        $stageId = $opportunity->stage_id;

        $response = $this->actingAs($this->salespersonOne)
            ->post("/opportunities/{$opportunity->id}/stage", ['stage_id' => $target->id]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame($stageId, $opportunity->fresh()->stage_id);
    }

    public function test_win_without_final_amount_bounces_back_with_error(): void
    {
        $opportunity = Opportunity::factory()->forOwner($this->salespersonOne)->create();

        $this->actingAs($this->salespersonOne)
            ->post("/opportunities/{$opportunity->id}/win", [])
            ->assertRedirect()
            ->assertSessionHasErrors('final_amount');

        $this->assertSame($this->stage('nueva-oportunidad')->id, $opportunity->fresh()->stage_id);
    }

    public function test_win_with_final_amount_closes_opportunity_and_shows_banner(): void
    {
        $opportunity = Opportunity::factory()->forOwner($this->salespersonOne)->create();

        $this->actingAs($this->salespersonOne)
            ->post("/opportunities/{$opportunity->id}/win", [
                'final_amount' => 15400.50,
                'closed_at' => '2026-09-15',
            ])
            ->assertRedirect();

        $fresh = $opportunity->fresh();
        $this->assertSame($this->stage('ganada')->id, $fresh->stage_id);
        $this->assertEquals(15400.50, (float) $fresh->final_amount);
        $this->assertNotNull($fresh->closed_at);

        $this->actingAs($this->salespersonOne)
            ->get("/opportunities/{$opportunity->id}")
            ->assertOk()
            ->assertSee('Oportunidad ganada')
            ->assertSee('15,400.50');
    }

    public function test_lose_without_reason_bounces_back_with_error(): void
    {
        $opportunity = Opportunity::factory()->forOwner($this->salespersonOne)->create();

        $this->actingAs($this->salespersonOne)
            ->post("/opportunities/{$opportunity->id}/lose", ['note' => 'sin motivo'])
            ->assertRedirect()
            ->assertSessionHasErrors('loss_reason_id');

        $this->assertSame($this->stage('nueva-oportunidad')->id, $opportunity->fresh()->stage_id);
    }

    public function test_lose_with_reason_closes_opportunity(): void
    {
        $opportunity = Opportunity::factory()->forOwner($this->salespersonOne)->create();
        $reason = LossReason::query()->where('slug', 'precio')->firstOrFail();

        $this->actingAs($this->salespersonOne)
            ->post("/opportunities/{$opportunity->id}/lose", [
                'loss_reason_id' => $reason->id,
                'note' => 'El cliente eligió a otro proveedor',
            ])
            ->assertRedirect();

        $fresh = $opportunity->fresh();
        $this->assertSame($this->stage('perdida')->id, $fresh->stage_id);
        $this->assertSame($reason->id, $fresh->loss_reason_id);
        $this->assertNotNull($fresh->closed_at);

        $this->actingAs($this->salespersonOne)
            ->get("/opportunities/{$opportunity->id}")
            ->assertOk()
            ->assertSee('Oportunidad perdida')
            ->assertSee($reason->name);
    }

    public function test_create_minimal_customer_opportunity_assigns_code_and_defaults(): void
    {
        $customer = Customer::factory()->forOwner($this->salespersonOne)->create();

        $response = $this->actingAs($this->salespersonOne)
            ->post('/opportunities', [
                'title' => 'Consultoría de mejora de procesos',
                'customer_id' => $customer->id,
                'estimated_amount' => 12000,
            ]);

        $opportunity = Opportunity::query()->where('title', 'Consultoría de mejora de procesos')->first();

        $this->assertNotNull($opportunity);
        $this->assertMatchesRegularExpression('/^OPP-\d{4}-\d{5}$/', $opportunity->code);
        $this->assertSame($this->stage('nueva-oportunidad')->id, $opportunity->stage_id);
        $this->assertSame('PEN', $opportunity->currency_code);
        $this->assertSame($this->salespersonOne->id, $opportunity->owner_id);

        $response->assertRedirect(route('opportunities.show', $opportunity));
    }

    public function test_update_on_closed_opportunity_redirects_with_error(): void
    {
        $opportunity = Opportunity::factory()->lost()->forOwner($this->salespersonOne)->create();
        $originalTitle = $opportunity->title;

        $this->actingAs($this->salespersonOne)
            ->put("/opportunities/{$opportunity->id}", ['title' => 'Nuevo título'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame($originalTitle, $opportunity->fresh()->title);
    }

    public function test_owner_gets_unread_notification_when_another_user_moves_stage(): void
    {
        $opportunity = Opportunity::factory()->forOwner($this->salespersonOne)->create();

        $this->actingAs($this->admin)
            ->post("/opportunities/{$opportunity->id}/stage", [
                'stage_id' => $this->stage('reunion-programada')->id,
            ])
            ->assertRedirect();

        // Navbar bell shows the unread count.
        $this->actingAs($this->salespersonOne)
            ->get('/opportunities-kanban')
            ->assertOk()
            ->assertSee('data-testid="nav-unread-count"', false);

        $this->actingAs($this->salespersonOne)
            ->get('/notifications')
            ->assertOk()
            ->assertSee($opportunity->code);

        $unread = $this->salespersonOne->unreadNotifications;
        $this->assertCount(1, $unread);

        $this->actingAs($this->salespersonOne)
            ->post('/notifications/mark-read', ['id' => $unread->first()->id])
            ->assertRedirect();

        $this->assertCount(0, $this->salespersonOne->fresh()->unreadNotifications);
    }

    public function test_no_notification_when_owner_moves_own_opportunity(): void
    {
        $opportunity = Opportunity::factory()->forOwner($this->salespersonOne)->create();

        $this->actingAs($this->salespersonOne)
            ->post("/opportunities/{$opportunity->id}/stage", [
                'stage_id' => $this->stage('negociacion')->id,
            ])
            ->assertRedirect();

        $this->assertCount(0, $this->salespersonOne->fresh()->unreadNotifications);
    }

    public function test_export_requires_permission(): void
    {
        Opportunity::factory()->forOwner($this->salespersonOne)->create();

        $this->actingAs($this->salespersonTwo)
            ->get('/opportunities-export')
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->get('/opportunities-export')
            ->assertOk()
            ->assertDownload();
    }

    public function test_vendedor_cannot_open_another_users_opportunity(): void
    {
        $opportunity = Opportunity::factory()->forOwner($this->salespersonTwo)->create();

        $this->actingAs($this->salespersonOne)
            ->get("/opportunities/{$opportunity->id}")
            ->assertForbidden();
    }
}
