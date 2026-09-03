<?php

namespace App\Http\Controllers\Admin;

use App\Grids\GridRegistry;
use App\Http\Controllers\Controller;
use App\Models\BusinessUnit;
use App\Models\User;
use App\Presenters\GridPresenter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;

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
     * Show the form for creating a new user
     */
    public function create()
    {
        $businessUnits = BusinessUnit::orderBy('name')->get();
        $roles = Role::orderBy('name')->get();

        return view('admin.users.create', compact('businessUnits', 'roles'));
    }

    /**
     * Store a newly created user in storage
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'staff_id' => 'required|string|unique:users',
            'job_title' => 'required|string',
            'department' => 'required|string',
            'phone' => 'required|string',
            'roles' => 'required|array|min:1',
            'business_unit_id' => 'required|exists:business_units,id',
        ]);

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
        $user->load(['roles.permissions', 'businessUnit', 'organization']);

        return view('admin.users.show', [
            'user' => $user,
        ]);
    }

    /**
     * Show the form for editing the specified user
     */
    public function edit(User $user)
    {
        $businessUnits = BusinessUnit::orderBy('name')->get();
        $roles = Role::orderBy('name')->get();

        return view('admin.users.edit', [
            'user' => $user,
            'businessUnits' => $businessUnits,
            'roles' => $roles,
        ]);
    }

    /**
     * Update the specified user in storage
     */
    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,'.$user->id,
            'staff_id' => 'required|string|unique:users,staff_id,'.$user->id,
            'job_title' => 'required|string',
            'department' => 'required|string',
            'phone' => 'required|string',
            'roles' => 'required|array|min:1',
            'business_unit_id' => 'required|exists:business_units,id',
        ]);

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
        // Prevent deactivating yourself
        if ($user->id === auth()->id()) {
            return redirect()->route('admin.users.index')
                ->with('error', 'You cannot deactivate your own account.');
        }

        $user->update(['is_active' => false]);

        return redirect()->route('admin.users.index')
            ->with('success', 'User deactivated successfully.');
    }

    /**
     * Toggle user active status
     */
    public function toggleActive(User $user)
    {
        // Prevent toggling yourself
        if ($user->id === auth()->id()) {
            return back()->with('error', 'You cannot change your own account status.');
        }

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
