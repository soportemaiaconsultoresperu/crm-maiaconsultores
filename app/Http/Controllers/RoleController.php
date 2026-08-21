<?php

namespace App\Http\Controllers;

use App\Models\User;
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

        $query = Role::query()->withCount(['permissions', 'users']);

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
            'protectedRoles' => self::PROTECTED_ROLES,
            'filters' => $request->only(['search']),
        ]);
    }

    public function create(Request $request): View
    {
        if (! $request->user()->can('roles.manage')) {
            abort(403);
        }

        return view('admin.roles.create', [
            'role' => new Role(['guard_name' => 'web']),
            'permissions' => $this->permissionList(),
            'selectedPermissions' => [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! $request->user()->can('roles.manage')) {
            abort(403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191', Rule::unique('roles', 'name')->where('guard_name', 'web')],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $role = Role::query()->create([
            'name' => $data['name'],
            'guard_name' => 'web',
        ]);

        $role->syncPermissions($data['permissions'] ?? []);

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

        return view('admin.roles.show', [
            'role' => $role->load(['permissions', 'users:id,name,email']),
            'protectedRoles' => self::PROTECTED_ROLES,
        ]);
    }

    public function edit(Request $request, Role $role): View
    {
        if (! $request->user()->can('roles.manage')) {
            abort(403);
        }

        return view('admin.roles.edit', [
            'role' => $role,
            'permissions' => $this->permissionList(),
            'selectedPermissions' => $role->permissions->pluck('name')->all(),
            'protectedRoles' => self::PROTECTED_ROLES,
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        if (! $request->user()->can('roles.manage')) {
            abort(403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191', Rule::unique('roles', 'name')->where('guard_name', 'web')->ignore($role->id)],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $role->name = $data['name'];
        $role->save();
        $role->syncPermissions($data['permissions'] ?? []);

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
     * Group permissions by their leading module (e.g. leads.view.any →
     * module "leads"). The view renders a multi-select grouped by module.
     *
     * @return array<string, array<int, array{name:string, label:string}>>
     */
    private function permissionList(): array
    {
        return Permission::query()
            ->where('guard_name', 'web')
            ->orderBy('name')
            ->get()
            ->groupBy(fn (Permission $p) => explode('.', $p->name)[0])
            ->map(fn ($group, $module) => [
                'module' => $module,
                'items' => $group->map(fn (Permission $p) => [
                    'name' => $p->name,
                    'label' => $p->name,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }
}