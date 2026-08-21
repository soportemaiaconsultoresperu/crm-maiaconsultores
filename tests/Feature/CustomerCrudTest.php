<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Team;
use App\Models\User;
use App\Services\CustomerService;
use App\Services\DataScopeService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * RF-CLI-001..003, RF-CLI-006: customer CRUD through CustomerService
 * (codes, norms, update, deactivation) plus ADR-006 visibility on the
 * customers table.
 */
class CustomerCrudTest extends TestCase
{
    use RefreshDatabase;

    private CustomerService $service;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->service = app(CustomerService::class);
        $this->actor = User::factory()->create(['is_active' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validData(array $overrides = []): array
    {
        $base = [
            'person_type' => 'juridica',
            'legal_name' => 'Distribuidora Andina S.A.C.',
            'trade_name' => 'Disa',
            'doc_type' => 'ruc',
            'doc_number' => '20.512.345.67',
            'phone' => '+51 987 654 321',
            'whatsapp' => '987 654 321',
            'email' => '  Ventas@Andina.COM ',
            'website' => 'https://andina.example.com',
            'sector' => 'Distribución',
        ];

        return array_merge($base, $overrides);
    }

    public function test_create_generates_sequential_cli_codes_and_fills_norms(): void
    {
        $year = now()->format('Y');

        $customer = $this->service->create($this->validData(), $this->actor);

        $this->assertSame("CLI-{$year}-00001", $customer->code);
        $this->assertSame('2051234567', $customer->doc_number_norm);
        $this->assertSame('51987654321', $customer->phone_norm);
        $this->assertSame('987654321', $customer->whatsapp_norm);
        $this->assertSame('ventas@andina.com', $customer->email_norm);
        $this->assertSame('activo', $customer->status);
        $this->assertSame($this->actor->id, $customer->owner_id);
        $this->assertSame($this->actor->id, $customer->created_by);

        $second = $this->service->create($this->validData(), $this->actor);
        $this->assertSame("CLI-{$year}-00002", $second->code);
    }

    public function test_create_requires_minimum_invariants(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->create(['person_type' => 'natural'], $this->actor);
    }

    public function test_update_recomputes_norms_and_keeps_code_immutable(): void
    {
        $customer = $this->service->create($this->validData(), $this->actor);

        $customer = $this->service->update($customer, [
            'code' => 'CLI-FAKE-99999',
            'phone' => '+51 987 111 222',
            'email' => 'Nuevo@Andina.com',
        ], $this->actor);

        $this->assertNotSame('CLI-FAKE-99999', $customer->code);
        $this->assertSame('51987111222', $customer->phone_norm);
        $this->assertSame('nuevo@andina.com', $customer->email_norm);
        $this->assertSame($this->actor->id, $customer->updated_by);
    }

    public function test_deactivate_soft_deletes_and_logs_reason(): void
    {
        $customer = $this->service->create($this->validData(), $this->actor);

        $this->service->deactivate($customer, $this->actor, 'Cliente cerró operaciones');

        $this->assertSoftDeleted($customer);
        $this->assertNotNull(Customer::withTrashed()->find($customer->id));

        $log = \Spatie\Activitylog\Models\Activity::query()
            ->where('subject_type', Customer::class)
            ->where('subject_id', $customer->id)
            ->where('event', 'customer-deactivated')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($this->actor->id, $log->causer_id);
        $this->assertSame('Cliente cerró operaciones', $log->properties['reason']);
    }

    public function test_scoped_visibility_on_customers(): void
    {
        $scope = app(DataScopeService::class);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $supervisor = User::factory()->create(['is_active' => true]);
        $supervisor->assignRole('supervisor');

        $salespersonOne = User::factory()->create(['is_active' => true]);
        $salespersonOne->assignRole('vendedor');

        $salespersonTwo = User::factory()->create(['is_active' => true]);
        $salespersonTwo->assignRole('vendedor');

        $team = Team::create([
            'name' => 'Equipo Maia',
            'supervisor_id' => $supervisor->id,
            'is_active' => true,
        ]);
        $team->members()->attach($salespersonOne->id);

        $own = Customer::factory()->forOwner($salespersonOne)->create();
        $foreign = Customer::factory()->forOwner($salespersonTwo)->create();

        // Vendedor: own records only.
        $visible = $scope->appliesTo(Customer::query(), $salespersonOne)->pluck('id');
        $this->assertContains($own->id, $visible);
        $this->assertNotContains($foreign->id, $visible);

        // Supervisor: team members' records.
        $visible = $scope->appliesTo(Customer::query(), $supervisor)->pluck('id');
        $this->assertContains($own->id, $visible);
        $this->assertNotContains($foreign->id, $visible);

        // Admin: unrestricted.
        $visible = $scope->appliesTo(Customer::query(), $admin)->pluck('id');
        $this->assertContains($own->id, $visible);
        $this->assertContains($foreign->id, $visible);
    }

    public function test_customer_policy_scoped_view_checks(): void
    {
        $supervisor = User::factory()->create(['is_active' => true]);
        $supervisor->assignRole('supervisor');

        $salespersonOne = User::factory()->create(['is_active' => true]);
        $salespersonOne->assignRole('vendedor');

        $salespersonTwo = User::factory()->create(['is_active' => true]);
        $salespersonTwo->assignRole('vendedor');

        $team = Team::create([
            'name' => 'Equipo Maia',
            'supervisor_id' => $supervisor->id,
            'is_active' => true,
        ]);
        $team->members()->attach($salespersonOne->id);

        $own = Customer::factory()->forOwner($salespersonOne)->create();
        $foreign = Customer::factory()->forOwner($salespersonTwo)->create();

        $this->assertTrue(Gate::forUser($salespersonOne)->allows('view', $own));
        $this->assertFalse(Gate::forUser($salespersonOne)->allows('view', $foreign));
        $this->assertTrue(Gate::forUser($supervisor)->allows('view', $own));
        $this->assertFalse(Gate::forUser($supervisor)->allows('view', $foreign));
    }
}
