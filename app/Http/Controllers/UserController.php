<?php

namespace App\Http\Controllers;

use App\Http\Requests\ResetPasswordRequest;
use App\Http\Requests\SetActiveRequest;
use App\Http\Requests\UserStoreRequest;
use App\Http\Requests\UserUpdateRequest;
use App\Models\User;
use App\Services\UserService;
use Spatie\Permission\Models\Role;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Admin users UI (B08 / RF-USR-001, RF-USR-005, RF-USR-006, RF-USR-008).
 *
 * Thin controller: every write goes through UserService, validation lives in
 * FormRequests, authorization is enforced via Spatie permissions. The
 * service-level guards (self-deactivation, random password generation,
 * password reset audit event) are owned by UserService — this class is just
 * the HTTP boundary.
 *
 * Authorization is checked directly against Spatie permissions instead of
 * a UserPolicy because the auth user model doesn't have a dedicated policy
 * class in this codebase. `users.view`, `users.create`, `users.update`,
 * `users.deactivate`, `users.reset_password` are all B08 admin permissions
 * (see database/seeders/AdditionalPermissionsSeeder.php).
 */
class UserController extends Controller
{
    public function __construct(
        private readonly UserService $users,
    ) {}

    /**
     * Paginated user directory with owner-aware data scope (ADR-006). Search
     * filters by name/email; the active toggle is purely a client-side chip
     * (the data scope never hides inactive rows so admin can still see who
     * was deactivated).
     */
    public function index(Request $request): View
    {
        abort_unless($request->user()->can('users.view'), 403);

        $query = $this->users->scopeQuery($request->user())
            ->with(['roles:id,name', 'teams:id,name']);

        $search = trim((string) $request->query('search'));
        if ($search !== '') {
            $term = '%'.str_replace('%', '\\%', $search).'%';
            $query->where(function ($q) use ($term): void {
                $q->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term);
            });
        }

        if ($request->filled('role')) {
            $role = (string) $request->query('role');
            $query->whereHas('roles', fn ($q) => $q->where('name', $role));
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', (bool) $request->boolean('is_active'));
        }

        $users = $query
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'roles' => $this->availableRoles(),
            'filters' => $request->only(['search', 'role', 'is_active']),
        ]);
    }

    public function create(Request $request): View
    {
        abort_unless($request->user()->can('users.create'), 403);

        return view('admin.users.create', [
            'user' => new User(['is_active' => true]),
            'roles' => $this->availableRoles(),
        ]);
    }

    public function store(UserStoreRequest $request): RedirectResponse
    {
        abort_unless($request->user()->can('users.create'), 403);

        $user = $this->users->create($request->validated(), $request->user());

        $message = 'Usuario creado correctamente.';
        $random = $user->getAttribute('random_password');
        if (is_string($random) && $random !== '') {
            // Surface the cleartext password exactly once; the controller
            // never persists it.
            $message .= ' Contraseña temporal: '.$random;
        }

        return redirect()
            ->route('admin.users.edit', $user)
            ->with('status', $message);
    }

    public function show(Request $request, User $user): View
    {
        abort_unless($request->user()->can('users.view'), 403);

        $user->load(['roles:id,name', 'teams:id,name', 'supervisedTeams:id,name']);

        return view('admin.users.show', [
            'user' => $user,
            'roles' => $this->availableRoles(),
        ]);
    }

    public function edit(Request $request, User $user): View
    {
        abort_unless($request->user()->can('users.update'), 403);

        $user->load(['roles:id,name', 'teams:id,name']);

        return view('admin.users.edit', [
            'user' => $user,
            'roles' => $this->availableRoles(),
        ]);
    }

    public function update(UserUpdateRequest $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->can('users.update'), 403);

        $this->users->update($user, $request->validated(), $request->user());

        return redirect()
            ->route('admin.users.edit', $user)
            ->with('status', 'Usuario actualizado correctamente.');
    }

    /**
     * Deactivate endpoint (alias for the old destroy route, kept for URL
     * backwards compatibility with the B01-era "destroy" pattern). The
     * service throws when the actor tries to deactivate themselves; we
     * convert that into a redirect with a Spanish error flash.
     */
    public function destroy(SetActiveRequest $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->can('users.deactivate'), 403);

        try {
            $this->users->setActive($user, false, $request->user());
        } catch (\App\Exceptions\InvalidOperationException $e) {
            return redirect()
                ->route('admin.users.edit', $user)
                ->withErrors(['is_active' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.users.index')
            ->with('status', 'Usuario desactivado correctamente.');
    }

    /**
     * Explicit POST /admin/users/{user}/reset-password — RF-USR-005. Routes
     * the cleartext password through the service so the dedicated audit
     * event fires.
     */
    public function resetPassword(ResetPasswordRequest $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->can('users.reset_password'), 403);

        $this->users->resetPassword($user, (string) $request->validated()['password'], $request->user());

        return redirect()
            ->route('admin.users.edit', $user)
            ->with('status', 'Contraseña restablecida correctamente.');
    }

    /**
     * POST /admin/users/{user}/set-active — explicit activate/deactivate
     * toggle from the ficha with a "bool active" payload. Reuses the same
     * service guard that blocks self-deactivation.
     */
    public function setActive(SetActiveRequest $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->can('users.deactivate'), 403);

        $payload = $request->validated();
        $active = (bool) ($payload['is_active'] ?? true);

        try {
            $this->users->setActive($user, $active, $request->user());
        } catch (\App\Exceptions\InvalidOperationException $e) {
            return redirect()
                ->route('admin.users.edit', $user)
                ->withErrors(['is_active' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.users.edit', $user)
            ->with('status', $active ? 'Usuario activado.' : 'Usuario desactivado.');
    }

    /**
     * Roles the admin may assign from the user form. Hardcoded to the three
     * seeded roles so the UI cannot create new permissions from this
     * surface.
     *
     * @return array<int, string>
     */
    private function availableRoles(): array
    {
        return Role::query()
            ->where('guard_name', 'web')
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }
}