<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Ubigeo;
use App\Models\User;
use App\Services\LeadService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Tests\TestCase;

/**
 * RF-LEAD-001..012 (HTTP layer): routes, authorization, duplicate
 * confirmation flow (ADR-003), import/export endpoints and the ubigeo
 * JSON endpoint. Roles come from RolesAndPermissionsSeeder.
 */
class LeadHttpTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $salespersonOne;

    private User $salespersonTwo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\CatalogSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        // Explicit is_active: the in-memory model injected by actingAs does
        // not hydrate the DB default, and EnsureUserIsActive would log a
        // null out (same convention as AuthTest).
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->salespersonOne = User::factory()->create(['is_active' => true]);
        $this->salespersonOne->assignRole('vendedor');

        $this->salespersonTwo = User::factory()->create(['is_active' => true]);
        $this->salespersonTwo->assignRole('vendedor');
    }

    /**
     * @return array<string, mixed>
     */
    private function validData(array $overrides = []): array
    {
        return array_merge([
            'person_type' => 'natural',
            'first_name' => 'Juan',
            'last_name' => 'Pérez',
            'doc_type' => 'dni',
            'doc_number' => '12345678',
            'phone' => '987654321',
            'email' => 'juan.perez@example.com',
            'interest_level' => 'medio',
            'source_id' => LeadSource::query()->where('slug', 'web')->value('id'),
            'status_id' => LeadStatus::query()->where('slug', 'nuevo')->value('id'),
            'owner_id' => $this->salespersonOne->id,
        ], $overrides);
    }

    public function test_salesperson_cannot_open_another_salespersons_lead_show(): void
    {
        $lead = Lead::factory()->forOwner($this->salespersonTwo)->create();

        $this->actingAs($this->salespersonOne)
            ->get("/leads/{$lead->id}")
            ->assertForbidden();

        $this->actingAs($this->salespersonTwo)
            ->get("/leads/{$lead->id}")
            ->assertOk();
    }

    public function test_index_is_scoped_to_own_leads(): void
    {
        $mine = Lead::factory()->forOwner($this->salespersonOne)->create();
        $other = Lead::factory()->forOwner($this->salespersonTwo)->create();

        $this->actingAs($this->salespersonOne)
            ->get('/leads')
            ->assertOk()
            ->assertSee($mine->code)
            ->assertDontSee($other->code);
    }

    public function test_create_form_renders_for_salesperson(): void
    {
        $this->actingAs($this->salespersonOne)
            ->get('/leads/create')
            ->assertOk()
            ->assertSee('Nuevo prospecto');
    }

    public function test_store_creates_lead_in_nuevo_status_and_redirects_to_show(): void
    {
        $response = $this->actingAs($this->salespersonOne)
            ->post('/leads', $this->validData());

        $lead = Lead::query()->where('doc_number', '12345678')->first();

        $this->assertNotNull($lead);
        $response->assertRedirect(route('leads.show', $lead));
        $response->assertSessionHas('status');

        $this->assertSame('nuevo', $lead->status->slug);
        $this->assertSame($this->salespersonOne->id, $lead->owner_id);
    }

    public function test_store_with_duplicate_doc_bounces_without_confirmation_and_creates_with_it(): void
    {
        $existing = app(LeadService::class)->create(
            $this->validData(['owner_id' => $this->salespersonTwo->id]),
            $this->salespersonTwo,
        );

        // Without confirmation: bounce back with the warning, no new lead.
        $response = $this->actingAs($this->salespersonOne)
            ->from('/leads/create')
            ->post('/leads', $this->validData([
                'first_name' => 'Duplicado',
                'email' => 'otro@example.com',
                'phone' => '911111111',
            ]));

        $response->assertRedirect('/leads/create');
        $response->assertSessionHas('duplicates');
        $this->assertSame($existing->code, session('duplicates')['critical'][0]['code'] ?? null);
        $this->assertSame(1, Lead::query()->where('doc_number', '12345678')->count());

        // Following the redirect renders the warning with the matched code.
        $this->get('/leads/create')->assertOk()->assertSee($existing->code);

        // With confirmation: created + audited (ADR-003).
        $response = $this->actingAs($this->salespersonOne)
            ->from('/leads/create')
            ->post('/leads', $this->validData([
                'first_name' => 'Duplicado',
                'email' => 'otro@example.com',
                'phone' => '911111111',
                'confirmed_duplicate' => '1',
            ]));

        $this->assertSame(2, Lead::query()->where('doc_number', '12345678')->count());

        $newLead = Lead::query()->where('first_name', 'Duplicado')->first();

        $response->assertRedirect(route('leads.show', $newLead));

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Lead::class,
            'subject_id' => $newLead->id,
            'event' => 'duplicate-confirmed',
        ]);
    }

    public function test_update_ignores_the_lead_being_edited_in_duplicate_check(): void
    {
        $lead = app(LeadService::class)->create($this->validData(), $this->salespersonOne);

        $response = $this->actingAs($this->salespersonOne)
            ->from("/leads/{$lead->id}/edit")
            ->put("/leads/{$lead->id}", $this->validData(['first_name' => 'Juan Editado']));

        $response->assertRedirect(route('leads.show', $lead));
        $response->assertSessionHas('status');

        $this->assertSame('Juan Editado', $lead->refresh()->first_name);
    }

    public function test_import_ui_requires_leads_import_permission(): void
    {
        // Seeded vendedor has leads.export/convert but NOT leads.import.
        $this->actingAs($this->salespersonOne)
            ->get('/leads-import')
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->get('/leads-import')
            ->assertOk()
            ->assertSee('Importar');
    }

    public function test_import_process_runs_and_shows_report(): void
    {
        Storage::fake('local');
        Excel::store(new class implements FromArray, ShouldAutoSize
        {
            public function array(): array
            {
                return [
                    ['person_type', 'first_name', 'last_name', 'doc_type', 'doc_number', 'email', 'phone'],
                    ['natural', 'Importado', 'Test', 'dni', '87654321', 'importado@example.com', '966666666'],
                    ['natural', 'Sin', 'Datos', null, null, null, null],
                ];
            }
        }, 'leads-import-test.xlsx', 'local');

        $path = Storage::disk('local')->path('leads-import-test.xlsx');

        $response = $this->actingAs($this->admin)
            ->post('/leads-import', [
                'file' => new \Illuminate\Http\UploadedFile($path, 'leads.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('leads', ['doc_number' => '87654321']);
        $this->assertDatabaseMissing('leads', ['first_name' => 'Sin']);

        // The rendered report shows the summary counters (1 created,
        // 1 invalid) and the invalid row reason.
        $response->assertSee('Creados');
        $response->assertSee('Omitidos (duplicados)');
        $response->assertSee('Inválidos');
    }

    public function test_export_returns_xlsx_download(): void
    {
        Lead::factory()->forOwner($this->salespersonOne)->create();

        $response = $this->actingAs($this->admin)
            ->get('/leads-export');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_assign_changes_owner_with_audit_entry(): void
    {
        $lead = Lead::factory()->forOwner($this->salespersonOne)->create();

        $response = $this->actingAs($this->admin)
            ->post("/leads/{$lead->id}/assign", ['owner_id' => $this->salespersonTwo->id]);

        $response->assertRedirect(route('leads.show', $lead));
        $this->assertSame($this->salespersonTwo->id, $lead->refresh()->owner_id);

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Lead::class,
            'subject_id' => $lead->id,
            'event' => 'lead-reassigned',
        ]);
    }

    public function test_destroy_deactivates_with_reason(): void
    {
        $lead = Lead::factory()->forOwner($this->admin)->create();

        $response = $this->actingAs($this->admin)
            ->post("/leads/{$lead->id}", ['reason' => 'Prueba de baja controlada']);

        $response->assertRedirect(route('leads.index'));
        $response->assertSessionHas('status');

        $this->assertSoftDeleted('leads', ['id' => $lead->id]);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Lead::class,
            'subject_id' => $lead->id,
            'event' => 'lead-deactivated',
        ]);
    }

    public function test_ubigeo_endpoint_returns_children_of_a_department(): void
    {
        Ubigeo::query()->create(['code' => '150000', 'name' => 'LIMA', 'level' => 'departamento', 'parent_code' => null]);
        Ubigeo::query()->create(['code' => '150100', 'name' => 'LIMA', 'level' => 'provincia', 'parent_code' => '150000']);
        Ubigeo::query()->create(['code' => '150200', 'name' => 'HUAROCHIRI', 'level' => 'provincia', 'parent_code' => '150000']);

        $this->actingAs($this->admin)
            ->get('/leads-ubigeo/150000')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonFragment(['code' => '150100', 'name' => 'LIMA'])
            ->assertJsonFragment(['code' => '150200', 'name' => 'HUAROCHIRI']);
    }

    public function test_duplicate_check_endpoint_returns_json_matches(): void
    {
        $existing = app(LeadService::class)->create(
            $this->validData(['owner_id' => $this->salespersonTwo->id]),
            $this->salespersonTwo,
        );

        $this->actingAs($this->salespersonOne)
            ->postJson("/leads/{$existing->id}/duplicate-check", [
                'doc_number' => '12.345.678',
                'email' => 'otro@example.com',
            ])
            ->assertOk()
            ->assertJsonPath('critical.0.code', $existing->code);
    }
}
