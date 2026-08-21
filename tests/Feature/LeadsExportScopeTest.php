<?php

namespace Tests\Feature;

use App\Models\LeadSource;
use App\Models\User;
use App\Services\LeadService;
use App\Exports\LeadsExport;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * Regression: lead exports MUST respect the requesting user's data scope
 * (ADR-006 / RF-LEAD-012). A vendedor with leads.export must never export
 * another salesperson's leads.
 */
class LeadsExportScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendedor_export_contains_only_own_leads(): void
    {
        $this->seed(CatalogSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $vendedor = User::factory()->create(['is_active' => true])->assignRole('vendedor');
        $otro = User::factory()->create(['is_active' => true])->assignRole('vendedor');

        $source = LeadSource::where('slug', 'web')->firstOrFail();
        $svc = app(LeadService::class);

        $own = $svc->create([
            'person_type' => 'natural', 'first_name' => 'Propio', 'last_name' => 'Uno',
            'doc_type' => 'dni', 'doc_number' => '11111111',
            'email' => 'own@example.com', 'source_id' => $source->id,
            'owner_id' => $vendedor->id,
        ], $vendedor);

        $foreign = $svc->create([
            'person_type' => 'natural', 'first_name' => 'Ajeno', 'last_name' => 'Dos',
            'doc_type' => 'dni', 'doc_number' => '22222222',
            'email' => 'foreign@example.com', 'source_id' => $source->id,
            'owner_id' => $otro->id,
        ], $otro);

        $query = (new LeadsExport([], $vendedor))->query();
        $codes = $query->pluck('code');

        $this->assertTrue($codes->contains($own->code));
        $this->assertFalse($codes->contains($foreign->code));
    }

    public function test_admin_export_is_unrestricted(): void
    {
        $this->seed(CatalogSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = User::factory()->create(['is_active' => true])->assignRole('admin');
        $vendedor = User::factory()->create(['is_active' => true])->assignRole('vendedor');

        $source = LeadSource::where('slug', 'web')->firstOrFail();
        $svc = app(LeadService::class);

        $lead = $svc->create([
            'person_type' => 'natural', 'first_name' => 'De', 'last_name' => 'Vendedor',
            'doc_type' => 'dni', 'doc_number' => '33333333',
            'email' => 'de@example.com', 'source_id' => $source->id,
            'owner_id' => $vendedor->id,
        ], $vendedor);

        $codes = (new LeadsExport([], $admin))->query()->pluck('code');

        $this->assertTrue($codes->contains($lead->code));
    }
}
