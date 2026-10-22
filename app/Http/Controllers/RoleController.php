<?php

namespace App\Http\Controllers;

use App\Support\Permissions\PermissionCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Admin roles UI (B08 / RF-USR-008).
 *
 * Roles are owned by Spatie; the controller exposes a thin CRUD surface
 * gated by `roles.manage` / `roles.view`. Roles are never deleted when they
 * still have users assigned: we soft-deactivate via the `is_active` flag
 * we attach to role rows? No — Spatie roles don't carry is_active. We
 * therefore remove users from the role before deleting it (the destroy
 * form forces an explicit confirmation). For our scope a simple delete
 * matches the documented RF-USR-008 behavior.
 *
 * Note: the system ships with three seeded roles (admin/supervisor/
 * vendedor). The admin can create additional roles to test permission
 * combinations; the seeded roles are flagged as protected from deletion
 * via `protected_from_delete` to avoid breaking the bootstrap.
 */
class RoleController extends Controller
{
    /**
     * Roles that must never be deleted because they're part of the bootstrap
     * (database/seeders/RolesAndPermissionsSeeder.php).
     */
    private const PROTECTED_ROLES = ['admin', 'supervisor', 'vendedor'];

    public function index(Request $request): View
    {
        if (! $request->user()->can('roles.view') && ! $request->user()->can('roles.manage')) {
            abort(403);
        }

        $query = Role::query()->with('permissions:id,name')->withCount(['permissions', 'users']);

        if ($search = trim((string) $request->query('search'))) {
            $term = '%'.str_replace('%', '\\%', $search).'%';
            $query->where('name', 'like', $term);
        }

        $roles = $query
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('admin.roles.index', [
            'roles' => $roles,
            'summaries' => $roles->getCollection()
                ->mapWithKeys(fn (Role $role) => [$role->id => PermissionCatalog::summaryFor($role)])
                ->all(),
            'protectedRoles' => self::PROTECTED_ROLES,
            'filters' => $request->only(['search']),
        ]);
    }

    public function create(Request $request): View
    {
        if (! $request->user()->can('roles.manage')) {
            abort(403);
        }

        // "Copiar permisos de otro rol": prefill the form server-side.
        $source = $request->filled('copy_from')
            ? Role::query()->where('guard_name', 'web')->find((int) $request->query('copy_from'))
            : null;

        return view('admin.roles.create', $this->formData(
            $request,
            new Role(['guard_name' => 'web']),
            $source?->permissions->pluck('name')->all() ?? [],
        ) + [
            'copyFrom' => $source,
            'copyableRoles' => Role::query()->where('guard_name', 'web')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! $request->user()->can('roles.manage')) {
            abort(403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191', Rule::unique('roles', 'name')->where('guard_name', 'web')],
            ...$this->permissionRules(),
        ]);

        $role = Role::query()->create([
            'name' => $data['name'],
            'guard_name' => 'web',
        ]);

        $role->syncPermissions($this->resolvePermissions($data));

        return redirect()
            ->route('admin.roles.edit', $role)
            ->with('status', 'Rol creado correctamente.');
    }

    public function show(Role $role): View
    {
        $user = request()->user();

        if (! $user->can('roles.view') && ! $user->can('roles.manage')) {
            abort(403);
        }

        $role->load(['permissions', 'users:id,name,email']);

        return view('admin.roles.show', [
            'role' => $role,
            'summary' => PermissionCatalog::summaryFor($role),
            'fullAccess' => PermissionCatalog::isFullAccess($role),
            'protectedRoles' => self::PROTECTED_ROLES,
        ]);
    }

    public function edit(Request $request, Role $role): View
    {
        if (! $request->user()->can('roles.manage')) {
            abort(403);
        }

        return view('admin.roles.edit', $this->formData(
            $request,
            $role,
            $role->permissions->pluck('name')->all(),
        ) + ['protectedRoles' => self::PROTECTED_ROLES]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        if (! $request->user()->can('roles.manage')) {
            abort(403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191', Rule::unique('roles', 'name')->where('guard_name', 'web')->ignore($role->id),
                // Code and the admin bypass refer to the system roles by name:
                // renaming them would silently break permissions.
                function (string $attribute, mixed $value, \Closure $fail) use ($role): void {
                    if (in_array($role->name, self::PROTECTED_ROLES, true) && $value !== $role->name) {
                        $fail('El nombre de los roles del sistema no se puede cambiar.');
                    }
                }],
            ...$this->permissionRules(),
        ]);

        // The admin role bypasses every check (Gate::before), so its
        // permission rows are never rewritten from this form.
        $fullAccess = PermissionCatalog::isFullAccess($role);

        $role->name = $data['name'];
        $role->save();

        if (! $fullAccess) {
            $role->syncPermissions($this->resolvePermissions($data));
        }

        return redirect()
            ->route('admin.roles.edit', $role)
            ->with('status', 'Rol actualizado correctamente.');
    }

    public function destroy(Request $request, Role $role): RedirectResponse
    {
        if (! $request->user()->can('roles.manage')) {
            abort(403);
        }

        if (in_array($role->name, self::PROTECTED_ROLES, true)) {
            return redirect()
                ->route('admin.roles.index')
                ->withErrors(['role' => 'El rol "'.$role->name.'" es parte del sistema y no puede eliminarse.']);
        }

        if ($role->users()->exists()) {
            return redirect()
                ->route('admin.roles.index')
                ->withErrors(['role' => 'Reasigna primero a los usuarios con este rol antes de eliminarlo.']);
        }

        $role->delete();

        return redirect()
            ->route('admin.roles.index')
            ->with('status', 'Rol eliminado correctamente.');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function permissionRules(): array
    {
        return [
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
            'scope' => ['nullable', 'array'],
            'scope.*' => ['string', Rule::in(['none', ...PermissionCatalog::SCOPES])],
        ];
    }

    /**
     * Map the form to Spatie names: each `scope[<module>]` radio becomes the
     * single matching `<module>.view.<scope>` permission (the other two of
     * the triplet are dropped), plus every checked action permission.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function resolvePermissions(array $data): array
    {
        $chosen = $data['permissions'] ?? [];
        $scopeGroups = PermissionCatalog::scopeGroups($this->permissionNames());

        foreach ($data['scope'] ?? [] as $prefix => $scope) {
            if (! isset($scopeGroups[$prefix])) {
                continue;
            }

            $chosen = array_diff($chosen, $scopeGroups[$prefix]);

            if ($scope !== 'none') {
                $chosen[] = $scopeGroups[$prefix][$scope];
            }
        }

        return array_values(array_unique($chosen));
    }

    /**
     * View data shared by create/edit. After a failed validation the
     * submitted selection wins over the stored one.
     *
     * @param  list<string>  $selected
     * @return array<string, mixed>
     */
    private function formData(Request $request, Role $role, array $selected): array
    {
        $names = $this->permissionNames();
        $scopeGroups = PermissionCatalog::scopeGroups($names);
        $scopes = PermissionCatalog::selectedScopes($selected, $scopeGroups);

        if ($request->old('name') !== null) {
            $selected = (array) $request->old('permissions', []);
            $scopes = array_merge(
                array_fill_keys(array_keys($scopeGroups), 'none'),
                (array) $request->old('scope', []),
            );
        }

        return [
            'role' => $role,
            'fullAccess' => $role->exists && PermissionCatalog::isFullAccess($role),
            'modules' => PermissionCatalog::grouped($names),
            'selectedPermissions' => $selected,
            'selectedScopes' => $scopes,
        ];
    }

    /**
     * @return list<string>
     */
    private function permissionNames(): array
    {
        return Permission::query()
            ->where('guard_name', 'web')
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }
}
