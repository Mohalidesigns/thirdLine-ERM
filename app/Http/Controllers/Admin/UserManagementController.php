<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessUnit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class UserManagementController extends Controller
{
    /**
     * Display a listing of users
     */
    public function index(Request $request)
    {
        $query = User::with(['roles', 'businessUnit']);

        // Search by name or email
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('staff_id', 'like', "%{$search}%");
            });
        }

        // Filter by role
        if ($request->filled('role')) {
            $query->role($request->input('role'));
        }

        // Filter by status
        if ($request->filled('status')) {
            $status = $request->input('status');
            if ($status === 'active') {
                $query->where('is_active', true);
            } elseif ($status === 'inactive') {
                $query->where('is_active', false);
            }
        }

        // Filter by business unit
        if ($request->filled('business_unit')) {
            $query->where('business_unit_id', $request->input('business_unit'));
        }

        $users = $query->orderBy('name')->paginate(15)->withQueryString();
        $roles = Role::orderBy('name')->get();
        $businessUnits = BusinessUnit::orderBy('name')->get();

        return view('admin.users.index', [
            'users' => $users,
            'roles' => $roles,
            'businessUnits' => $businessUnits,
            'filters' => $request->only(['search', 'role', 'status', 'business_unit']),
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
            ->with('success', 'User created successfully. Temporary password: ' . $tempPassword);
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
            'email' => 'required|email|unique:users,email,' . $user->id,
            'staff_id' => 'required|string|unique:users,staff_id,' . $user->id,
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

        $user->update(['is_active' => !$user->is_active]);

        $status = $user->is_active ? 'activated' : 'deactivated';

        return redirect()->route('admin.users.show', $user)
            ->with('success', 'User ' . $status . ' successfully.');
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
            ->with('success', 'Password reset. New temporary password: ' . $tempPassword);
    }
}
