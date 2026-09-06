<?php

namespace App\Http\Controllers\Admin;

use App\Grids\GridRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\BusinessUnit;
use App\Models\User;
use App\Presenters\GridPresenter;
use App\Support\AssignableRoles;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;
use ThirdLine\Platform\Tenancy\TenantContext;

class UserManagementController extends Controller
{
    /**
     * Display a listing of users.
     *
     * WP-09: search, filters, sorting and pagination moved into the shared data
     * grid (App\Grids\Definitions\AdminUsersGrid). What remains is the header's
     * four counters, which the grid does not own.
     */
    public function index(Request $request, GridPresenter $presenter)
    {
        $orgId = TenantContext::organizationId();
        $scoped = fn () => User::where('organization_id', $orgId);

        return Inertia::render('Admin/Users/Index', [
            'totalUsers' => $scoped()->count(),
            'activeUsers' => $scoped()->where('is_active', true)->count(),
            'inactiveUsers' => $scoped()->where('is_active', false)->count(),
            'mfaEnabled' => $scoped()->where('mfa_enabled', true)->count(),
            'grid' => fn () => $presenter->present(GridRegistry::resolve('admin_users'), $request, $request->user()),
        ]);
    }

    /**
     * The lists both user forms offer.
     *
     * The roles list is `Role::all()` filtered to what this actor may hand out,
     * which removes the last hard-coded role list in the product and is the
     * same list StoreUserRequest validates against — 4.6's rule: what a form
     * OFFERS must be what the validator ACCEPTS.
     *
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'businessUnits' => BusinessUnit::orderBy('name')->get(['id', 'name'])->values(),
            'roles' => AssignableRoles::for(request()->user()),
        ];
    }

    /**
     * Show the form for creating a new user
     */
    public function create()
    {
        Gate::authorize('create', User::class);

        return Inertia::render('Admin/Users/Create', $this->formOptions());
    }

    /**
     * Store a newly created user in storage
     */
    public function store(StoreUserRequest $request)
    {
        $validated = $request->validated();

        // Create user with temporary password
        $tempPassword = Str::random(12);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($tempPassword),
            'staff_id' => $validated['staff_id'],
            'job_title' => $validated['job_title'],
            'department' => $validated['department'],
            'phone' => $validated['phone'],
            'business_unit_id' => $validated['business_unit_id'],
            'organization_id' => auth()->user()->organization_id,
            'is_active' => true,
            'must_change_password' => true,
            'password_changed_at' => now(),
        ]);

        // Assign roles
        $user->syncRoles($validated['roles']);

        return redirect()->route('admin.users.show', $user)
            ->with('success', 'User created successfully. Temporary password: '.$tempPassword);
    }

    /**
     * Display the specified user
     */
    public function show(User $user)
    {
        Gate::authorize('view', $user);

        $user->load(['roles.permissions', 'businessUnit', 'organization']);

        // getAllPermissions(), not roles.permissions: a user may hold a
        // permission directly as well as through a role, and the Blade profile
        // grouped the effective set. Grouping stays on the server because the
        // "action module" convention in a permission name is the server's.
        $permissions = $user->getAllPermissions()
            ->groupBy(fn ($permission) => explode(' ', $permission->name)[1] ?? $permission->name)
            ->map(fn ($group) => $group->map(fn ($permission) => explode(' ', $permission->name)[0])->values())
            ->sortKeys();

        return Inertia::render('Admin/Users/Show', [
            'subject' => array_merge($user->only([
                'id', 'name', 'email', 'staff_id', 'job_title', 'department', 'phone',
                'is_active', 'must_change_password', 'mfa_enabled', 'last_login_at',
                'password_changed_at', 'last_activity_at', 'locked_until', 'created_at',
            ]), [
                'business_unit' => $user->getRelationValue('businessUnit')?->name,
                'organization' => $user->getRelationValue('organization')?->name,
                // Carbon::parse rather than the cast: the same shape
                // AuthenticatedSessionController uses to read this column.
                'is_locked' => $user->locked_until !== null && Carbon::parse($user->locked_until)->isFuture(),
                'roles' => $user->roles->map(fn (Role $role) => [
                    'name' => $role->name,
                    'permission_count' => $role->permissions->count(),
                ])->values(),
                'permissions' => $permissions,
            ]),
            'isSelf' => $user->id === request()->user()?->id,
            'canManage' => Gate::allows('update', $user),
            'canDeactivate' => Gate::allows('deactivate', $user),
            'canResetPassword' => Gate::allows('resetPassword', $user),
        ]);
    }

    /**
     * Show the form for editing the specified user
     */
    public function edit(User $user)
    {
        Gate::authorize('update', $user);

        return Inertia::render('Admin/Users/Edit', array_merge($this->formOptions(), [
            'subject' => array_merge($user->only([
                'id', 'name', 'email', 'staff_id', 'job_title', 'department', 'phone', 'business_unit_id',
            ]), ['roles' => $user->roles->pluck('name')->values()]),
            // The roles field is disabled when an administrator is editing
            // their own account; UpdateUserRequest refuses the change server-side.
            'isSelf' => $user->id === request()->user()?->id,
        ]));
    }

    /**
     * Update the specified user in storage
     */
    public function update(UpdateUserRequest $request, User $user)
    {
        $validated = $request->validated();

        $user->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'staff_id' => $validated['staff_id'],
            'job_title' => $validated['job_title'],
            'department' => $validated['department'],
            'phone' => $validated['phone'],
            'business_unit_id' => $validated['business_unit_id'],
        ]);

        // Sync roles
        $user->syncRoles($validated['roles']);

        return redirect()->route('admin.users.show', $user)
            ->with('success', 'User updated successfully.');
    }

    /**
     * Soft delete (deactivate) the specified user
     */
    public function destroy(User $user)
    {
        // "Never yourself" is UserPolicy::deactivate's rule now, so the same
        // answer comes back whether it is asked from here, a form request or a
        // console command.
        Gate::authorize('deactivate', $user);

        $user->update(['is_active' => false]);

        return redirect()->route('admin.users.index')
            ->with('success', 'User deactivated successfully.');
    }

    /**
     * Toggle user active status
     */
    public function toggleActive(User $user)
    {
        Gate::authorize('deactivate', $user);

        $user->update(['is_active' => ! $user->is_active]);

        $status = $user->is_active ? 'activated' : 'deactivated';

        return redirect()->route('admin.users.show', $user)
            ->with('success', 'User '.$status.' successfully.');
    }

    /**
     * Force password reset for user
     */
    public function resetPassword(Request $request, User $user)
    {
        Gate::authorize('resetPassword', $user);

        $tempPassword = Str::random(12);

        $user->update([
            'password' => Hash::make($tempPassword),
            'must_change_password' => true,
            'password_changed_at' => now(),
            'login_attempts' => 0,
            'locked_until' => null,
        ]);

        return redirect()->route('admin.users.show', $user)
            ->with('success', 'Password reset. New temporary password: '.$tempPassword);
    }
}
