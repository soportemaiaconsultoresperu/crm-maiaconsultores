<?php

namespace Tests\Feature;

use App\Models\CampaignTemplate;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke test for the CampaignTemplate module: ensures the routes register,
 * the controller renders, and the permissions gate works.
 */
class CampaignTemplateHttpTest extends TestCase
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

    public function test_index_renders_for_admin(): void
    {
        $resp = $this->get(route('admin.campaign_templates.index'));
        $resp->assertOk();
        $resp->assertSee('Plantillas de campañas');
    }

    public function test_create_form_renders(): void
    {
        $resp = $this->get(route('admin.campaign_templates.create'));
        $resp->assertOk();
        $resp->assertSee('Datos básicos');
    }

    public function test_store_creates_template_with_steps(): void
    {
        $typeId = \App\Models\ActivityType::query()->where('slug', 'llamada')->value('id');

$resp = $this->post(route('admin.campaign_templates.store'), [
            'name' => 'Plantilla de prueba',
            'description' => 'Solo testing',
            // objective se omite: el formulario ya no lo pide y debe quedar 'custom' por default.
            'status' => CampaignTemplate::STATUS_ACTIVE,
            'steps' => [
                ['action_type_id' => $typeId, 'title' => 'Llamada inicial', 'day_offset' => 0, 'scheduled_time' => '09:00'],
                ['action_type_id' => $typeId, 'title' => 'Email', 'day_offset' => 2, 'scheduled_time' => '10:00'],
            ],
        ]);

        $resp->assertRedirect(route('admin.campaign_templates.show', CampaignTemplate::query()->first()));
        $this->assertDatabaseHas('campaign_templates', ['name' => 'Plantilla de prueba', 'objective' => 'custom']);
        $this->assertDatabaseCount('campaign_steps', 2);
    }
}
