<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Providers\AutomationServiceProvider;
use App\Support\Permissions\PermissionCatalog;
use Database\Seeders\AdditionalConsentPermissionsSeeder;
use Database\Seeders\AdditionalEmailPermissionsSeeder;
use Database\Seeders\AdditionalNotificationPermissionsSeeder;
use Database\Seeders\AdditionalPermissionsSeeder;
use Database\Seeders\AdditionalWhatsAppPermissionsSeeder;
use Database\Seeders\ContentPermissionsSeeder;
use Database\Seeders\CoursePermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SupportPermissionsSeeder;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Spanish permission catalog + role editor UX (odd/tasks/roles-permissions-ux.md, S2–S6).
 */
class RolePermissionsUxTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolesAndPermissionsSeeder::class,
            AdditionalPermissionsSeeder::class,
            AdditionalConsentPermissionsSeeder::class,
            AdditionalEmailPermissionsSeeder::class,
            AdditionalNotificationPermissionsSeeder::class,
            AdditionalWhatsAppPermissionsSeeder::class,
            SupportPermissionsSeeder::class,
            ContentPermissionsSeeder::class,
            CoursePermissionsSeeder::class,
        ]);
        (new AutomationServiceProvider($this->app))->registerAutomationPermissions();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = User::factory()->create(['is_active' => true, 'name' => 'Ana Administradora']);
        $this->admin->assignRole('admin');
    }

    public function test_catalog_covers_every_seeded_permission_without_fallback(): void
    {
        $names = Permission::query()->pluck('name');

        $this->assertGreaterThanOrEqual(170, $names->count());

        $missing = $names->reject(fn (string $name) => PermissionCatalog::has($name))->values()->all();
        $this->assertSame([], $missing, 'Permisos sin entrada en el catálogo: '.implode(', ', $missing));

        foreach ($names as $name) {
            $this->assertNotSame('otros', PermissionCatalog::module($name)['key'], $name);
            $this->assertStringNotContainsString('.', PermissionCatalog::label($name), $name);
            $this->assertNotSame('', PermissionCatalog::describe($name), $name);
        }
    }

    public function test_catalog_labels_and_scope_groups(): void
    {
        $this->assertSame('Ver todos los prospectos', PermissionCatalog::label('leads.view.any'));
        $this->assertSame('Prospectos', PermissionCatalog::module('leads.create')['name']);
        $this->assertTrue(PermissionCatalog::isSensitive('leads.export'));
        $this->assertFalse(PermissionCatalog::isSensitive('leads.create'));

        $scopes = PermissionCatalog::scopeGroups(Permission::query()->pluck('name'));
        $this->assertSame(
            ['own' => 'leads.view.own', 'team' => 'leads.view.team', 'any' => 'leads.view.any'],
            $scopes['leads'],
        );
        $this->assertArrayHasKey('support', $scopes);
        $this->assertArrayNotHasKey('calendar', $scopes);
    }

    public function test_unknown_permission_falls_back_to_otros_with_readable_label(): void
    {
        Permission::create(['name' => 'widgets.export_all', 'guard_name' => 'web']);

        $this->assertFalse(PermissionCatalog::has('widgets.export_all'));
        $this->assertSame('otros', PermissionCatalog::module('widgets.export_all')['key']);
        $this->assertSame('Otros', PermissionCatalog::module('widgets.export_all')['name']);
        $this->assertStringNotContainsString('.', PermissionCatalog::label('widgets.export_all'));

        $this->actingAs($this->admin)
            ->get(route('admin.roles.edit', Role::findByName('vendedor')))
            ->assertOk()
            ->assertSee('Otros')
            ->assertSee(PermissionCatalog::label('widgets.export_all'));
    }

    public function test_edit_page_shows_spanish_labels_and_no_raw_names_as_text(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.roles.edit', Role::findByName('supervisor')))
            ->assertOk()
            ->assertSee('Exportar prospectos')
            ->assertSee('Descarga el listado de prospectos a un archivo.')
            ->assertSee('Sensible')
            ->assertSee('¿Qué registros puede ver?')
            ->assertSee('Solo los suyos')
            ->assertSee('Los de su equipo')
            ->assertSee('Marcar todo')
            ->assertSee('Prospectos');

        $html = $response->getContent();
        $xpath = $this->xpath($html);

        foreach ($xpath->query('//label | //h2 | //h3 | //h4 | //h5 | //small | //span') as $node) {
            foreach (Permission::query()->pluck('name') as $name) {
                $this->assertStringNotContainsString($name, trim($node->textContent), "Texto crudo «{$name}» visible");
            }
        }
    }

    public function test_saving_existing_roles_without_changes_keeps_exactly_the_same_permissions(): void
    {
        foreach (['supervisor', 'vendedor'] as $roleName) {
            $role = Role::findByName($roleName);
            $before = $role->permissions()->pluck('name')->sort()->values()->all();

            $html = $this->actingAs($this->admin)->get(route('admin.roles.edit', $role))->assertOk()->getContent();

            $this->actingAs($this->admin)
                ->put(route('admin.roles.update', $role), $this->formPayload($html))
                ->assertRedirect(route('admin.roles.edit', $role))
                ->assertSessionHasNoErrors();

            $after = $role->fresh()->permissions()->pluck('name')->sort()->values()->all();
            $this->assertSame($before, $after, "El rol {$roleName} cambió sus permisos al guardar sin cambios");
        }
    }

    public function test_scope_radio_maps_to_the_single_matching_permission(): void
    {
        $role = Role::create(['name' => 'coordinador', 'guard_name' => 'web']);
        $role->syncPermissions(['leads.view.any', 'leads.view.own', 'leads.create']);

        $this->actingAs($this->admin)
            ->get(route('admin.roles.edit', $role))
            ->assertOk()
            ->assertSee('value="any" checked', false);

        $this->actingAs($this->admin)->put(route('admin.roles.update', $role), [
            'name' => 'coordinador',
            'permissions' => ['leads.create', 'leads.export'],
            'scope' => ['leads' => 'team', 'customers' => 'none'],
        ])->assertSessionHasNoErrors();

        $names = $role->fresh()->permissions()->pluck('name')->sort()->values()->all();
        $this->assertSame(['leads.create', 'leads.export', 'leads.view.team'], $names);
    }

    public function test_store_maps_scope_and_rejects_unknown_permissions(): void
    {
        $this->actingAs($this->admin)->post(route('admin.roles.store'), [
            'name' => 'analista',
            'permissions' => ['reports.view'],
            'scope' => ['customers' => 'own'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            ['customers.view.own', 'reports.view'],
            Role::findByName('analista')->permissions()->pluck('name')->sort()->values()->all(),
        );

        $this->actingAs($this->admin)->post(route('admin.roles.store'), [
            'name' => 'invalido',
            'permissions' => ['no.existe'],
        ])->assertSessionHasErrors('permissions.0');

        $this->actingAs($this->admin)->post(route('admin.roles.store'), [
            'name' => 'invalido',
            'scope' => ['leads' => 'galaxy'],
        ])->assertSessionHasErrors('scope.leads');
    }

    public function test_copy_from_prefills_the_create_form(): void
    {
        $vendedor = Role::findByName('vendedor');

        $html = $this->actingAs($this->admin)
            ->get(route('admin.roles.create', ['copy_from' => $vendedor->id]))
            ->assertOk()
            ->assertSee('Copiar permisos de otro rol')
            ->getContent();

        $payload = $this->formPayload($html);
        $this->assertSame('own', $payload['scope']['leads']);
        $this->assertContains('leads.export', $payload['permissions']);

        $payload['name'] = 'vendedor-junior';
        $this->actingAs($this->admin)->post(route('admin.roles.store'), $payload)->assertSessionHasNoErrors();

        $this->assertSame(
            $vendedor->permissions()->pluck('name')->sort()->values()->all(),
            Role::findByName('vendedor-junior')->permissions()->pluck('name')->sort()->values()->all(),
        );
    }

    public function test_admin_role_shows_full_access_and_saving_keeps_its_permissions(): void
    {
        $role = Role::findByName('admin');
        $before = $role->permissions()->pluck('name')->sort()->values()->all();

        $html = $this->actingAs($this->admin)
            ->get(route('admin.roles.edit', $role))
            ->assertOk()
            ->assertSee('Acceso total')
            ->assertSee('el administrador puede todo, siempre')
            ->getContent();

        $this->assertStringNotContainsString('name="permissions[]"', $html);

        $this->actingAs($this->admin)->put(route('admin.roles.update', $role), [
            'name' => 'admin',
            'permissions' => ['leads.create'],
            'scope' => ['leads' => 'own'],
        ])->assertSessionHasNoErrors();

        $this->assertSame($before, $role->fresh()->permissions()->pluck('name')->sort()->values()->all());
    }

    public function test_system_roles_cannot_be_renamed(): void
    {
        foreach (['admin', 'supervisor', 'vendedor'] as $name) {
            $role = Role::findByName($name);

            $this->actingAs($this->admin)
                ->get(route('admin.roles.edit', $role))
                ->assertOk()
                ->assertSee('El nombre de los roles del sistema no se puede cambiar');

            $this->actingAs($this->admin)
                ->put(route('admin.roles.update', $role), ['name' => $name.'-renombrado'])
                ->assertSessionHasErrors(['name']);

            $this->assertSame($name, $role->fresh()->name);
        }
    }

    public function test_index_and_show_render_readable_summaries(): void
    {
        $supervisorUser = User::factory()->create(['is_active' => true, 'name' => 'Sofía Supervisora']);
        $supervisorUser->assignRole('supervisor');

        $this->actingAs($this->admin)
            ->get(route('admin.roles.index'))
            ->assertOk()
            ->assertSee('Acceso total')
            ->assertSee('Supervisor')
            ->assertSee('Prospectos');

        $summary = PermissionCatalog::summaryFor(Role::findByName('supervisor'));
        $leads = collect($summary)->firstWhere('key', 'leads');
        $this->assertSame('ver los de su equipo, crear, editar', $leads['actions']);

        $this->actingAs($this->admin)
            ->get(route('admin.roles.show', Role::findByName('supervisor')))
            ->assertOk()
            ->assertSee('ver los de su equipo, crear, editar')
            ->assertSee('Sofía Supervisora');
    }

    public function test_user_form_renders_role_summaries(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.users.create'))
            ->assertOk()
            ->assertSee('data-role-summary="supervisor"', false)
            ->assertSee('data-role-summary="admin"', false)
            ->assertSee('Supervisor');
    }

    public function test_users_without_roles_manage_get_403_as_today(): void
    {
        $vendedor = User::factory()->create(['is_active' => true]);
        $vendedor->assignRole('vendedor');
        $role = Role::findByName('supervisor');

        $this->actingAs($vendedor)->get(route('admin.roles.index'))->assertForbidden();
        $this->actingAs($vendedor)->get(route('admin.roles.create'))->assertForbidden();
        $this->actingAs($vendedor)->get(route('admin.roles.edit', $role))->assertForbidden();
        $this->actingAs($vendedor)->put(route('admin.roles.update', $role), ['name' => 'x'])->assertForbidden();

        $supervisor = User::factory()->create(['is_active' => true]);
        $supervisor->assignRole('supervisor');
        $this->actingAs($supervisor)->get(route('admin.roles.index'))->assertOk();
        $this->actingAs($supervisor)->get(route('admin.roles.edit', $role))->assertForbidden();
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    /**
     * Rebuild what the browser would submit from the rendered role form.
     *
     * @return array{name:string, permissions:list<string>, scope:array<string,string>}
     */
    private function formPayload(string $html): array
    {
        $xpath = $this->xpath($html);
        $form = $xpath->query('//form[@id="role-form"]')->item(0);
        $this->assertNotNull($form, 'No se encontró el formulario del rol');

        $payload = ['name' => '', 'permissions' => [], 'scope' => []];

        foreach ($xpath->query('.//input', $form) as $input) {
            if (! $input instanceof DOMElement) {
                continue;
            }
            $name = $input->getAttribute('name');
            $checked = $input->hasAttribute('checked');

            if ($name === 'name') {
                $payload['name'] = $input->getAttribute('value');
            } elseif ($name === 'permissions[]' && $checked) {
                $payload['permissions'][] = $input->getAttribute('value');
            } elseif (preg_match('/^scope\[(.+)\]$/', $name, $m) && $checked) {
                $payload['scope'][$m[1]] = $input->getAttribute('value');
            }
        }

        return $payload;
    }
}
