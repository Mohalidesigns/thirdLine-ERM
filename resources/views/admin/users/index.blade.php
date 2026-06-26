@extends('layouts.app')

@section('title', 'User Management')

@section('page-section', 'Administration')
@section('page-title', 'User Management')

@section('content')
<div class="space-y-6">
    <!-- Header with Stats -->
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-2xl font-bold text-gray-900">User Management</h2>
            <p class="text-sm text-gray-500 mt-1">Manage system users, roles, and access permissions</p>
        </div>
        <a
            href="{{ route('admin.users.create') }}"
            class="inline-flex items-center gap-2 px-4 py-2.5 bg-primary hover:bg-primary/90 text-white font-semibold rounded-lg transition"
        >
            <span class="material-symbols-outlined text-lg">person_add</span>
            Create User
        </a>
    </div>

    <!-- Summary Stats -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        @php
            $totalUsers = $users->total();
            $activeUsers = \App\Models\User::where('is_active', true)->count();
            $inactiveUsers = \App\Models\User::where('is_active', false)->count();
            $mfaEnabled = \App\Models\User::where('mfa_enabled', true)->count();
        @endphp
        <div class="kpi-card">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center">
                    <span class="material-symbols-outlined text-blue-600">group</span>
                </div>
                <div>
                    <p class="text-2xl font-bold text-gray-900">{{ $totalUsers }}</p>
                    <p class="text-xs text-gray-500">Total Users</p>
                </div>
            </div>
        </div>
        <div class="kpi-card">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-green-100 flex items-center justify-center">
                    <span class="material-symbols-outlined text-green-600">check_circle</span>
                </div>
                <div>
                    <p class="text-2xl font-bold text-gray-900">{{ $activeUsers }}</p>
                    <p class="text-xs text-gray-500">Active Users</p>
                </div>
            </div>
        </div>
        <div class="kpi-card">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-gray-100 flex items-center justify-center">
                    <span class="material-symbols-outlined text-gray-500">block</span>
                </div>
                <div>
                    <p class="text-2xl font-bold text-gray-900">{{ $inactiveUsers }}</p>
                    <p class="text-xs text-gray-500">Inactive Users</p>
                </div>
            </div>
        </div>
        <div class="kpi-card">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-purple-100 flex items-center justify-center">
                    <span class="material-symbols-outlined text-purple-600">verified_user</span>
                </div>
                <div>
                    <p class="text-2xl font-bold text-gray-900">{{ $mfaEnabled }}</p>
                    <p class="text-xs text-gray-500">MFA Enabled</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Search and Filter Card -->
    <div class="bg-white rounded-lg shadow border border-gray-100 p-6">
        <form method="GET" action="{{ route('admin.users.index') }}" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                <!-- Search -->
                <div>
                    <label for="search" class="block text-sm font-medium text-gray-700 mb-2">Search</label>
                    <div class="relative">
                        <span class="absolute left-3 top-2.5 material-symbols-outlined text-gray-400 text-[18px]">search</span>
                        <input
                            type="text"
                            id="search"
                            name="search"
                            value="{{ $filters['search'] ?? '' }}"
                            placeholder="Name, email, or staff ID..."
                            class="w-full pl-9 pr-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm"
                            data-live-search
                        >
                    </div>
                </div>

                <!-- Role Filter -->
                <div>
                    <label for="role" class="block text-sm font-medium text-gray-700 mb-2">Role</label>
                    <select
                        id="role"
                        name="role"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm"
                    >
                        <option value="">All Roles</option>
                        @foreach($roles as $role)
                            <option value="{{ $role->name }}" {{ ($filters['role'] ?? '') === $role->name ? 'selected' : '' }}>
                                {{ ucwords(str_replace('-', ' ', $role->name)) }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <!-- Status Filter -->
                <div>
                    <label for="status" class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                    <select
                        id="status"
                        name="status"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm"
                    >
                        <option value="">All Status</option>
                        <option value="active" {{ ($filters['status'] ?? '') === 'active' ? 'selected' : '' }}>Active</option>
                        <option value="inactive" {{ ($filters['status'] ?? '') === 'inactive' ? 'selected' : '' }}>Inactive</option>
                    </select>
                </div>

                <!-- Business Unit Filter -->
                <div>
                    <label for="business_unit" class="block text-sm font-medium text-gray-700 mb-2">Business Unit</label>
                    <select
                        id="business_unit"
                        name="business_unit"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm"
                    >
                        <option value="">All Units</option>
                        @foreach($businessUnits as $unit)
                            <option value="{{ $unit->id }}" {{ ($filters['business_unit'] ?? '') == $unit->id ? 'selected' : '' }}>
                                {{ $unit->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <!-- Submit -->
                <div class="flex items-end gap-2">
                    <button
                        type="submit"
                        class="flex-1 px-4 py-2 bg-primary hover:bg-primary/90 text-white font-medium rounded-lg transition text-sm"
                    >
                        Filter
                    </button>
                    <a
                        href="{{ route('admin.users.index') }}"
                        class="px-3 py-2 bg-gray-100 hover:bg-gray-200 text-gray-600 rounded-lg transition text-sm"
                        title="Clear filters"
                    >
                        <span class="material-symbols-outlined text-lg">clear_all</span>
                    </a>
                </div>
            </div>
        </form>
    </div>

    <!-- Users Table -->
    <div class="bg-white rounded-lg shadow border border-gray-100 overflow-hidden">
        <table class="data-table">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Staff ID</th>
                    <th>Role</th>
                    <th>Business Unit</th>
                    <th>Status</th>
                    <th>MFA</th>
                    <th>Last Login</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($users as $user)
                    <tr>
                        <td>
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 bg-primary/10 rounded-full flex items-center justify-center text-primary text-xs font-bold flex-shrink-0">
                                    {{ collect(explode(' ', $user->name))->map(fn($w) => strtoupper(substr($w, 0, 1)))->take(2)->implode('') }}
                                </div>
                                <div>
                                    <p class="font-medium text-gray-900 text-sm">{{ $user->name }}</p>
                                    <p class="text-xs text-gray-500">{{ $user->email }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="text-sm">{{ $user->staff_id ?? '-' }}</td>
                        <td>
                            @foreach($user->roles as $role)
                                @php
                                    $roleColors = [
                                        'super-admin' => 'bg-red-100 text-red-800',
                                        'chief-risk-officer' => 'bg-purple-100 text-purple-800',
                                        'risk-manager' => 'bg-blue-100 text-blue-800',
                                        'risk-owner' => 'bg-cyan-100 text-cyan-800',
                                        'risk-analyst' => 'bg-indigo-100 text-indigo-800',
                                        'compliance-officer' => 'bg-green-100 text-green-800',
                                        'board-member' => 'bg-yellow-100 text-yellow-800',
                                        'loss-event-manager' => 'bg-orange-100 text-orange-800',
                                        'issue-manager' => 'bg-teal-100 text-teal-800',
                                    ];
                                    $color = $roleColors[$role->name] ?? 'bg-gray-100 text-gray-800';
                                @endphp
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $color }}">
                                    {{ ucwords(str_replace('-', ' ', $role->name)) }}
                                </span>
                            @endforeach
                            @if($user->roles->isEmpty())
                                <span class="text-gray-400 text-xs">No role</span>
                            @endif
                        </td>
                        <td class="text-sm">{{ $user->businessUnit?->name ?? '-' }}</td>
                        <td>
                            @if($user->is_active)
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                    <span class="w-1.5 h-1.5 bg-green-500 rounded-full mr-1.5"></span>
                                    Active
                                </span>
                            @else
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700">
                                    <span class="w-1.5 h-1.5 bg-gray-400 rounded-full mr-1.5"></span>
                                    Inactive
                                </span>
                            @endif
                        </td>
                        <td>
                            @if($user->mfa_enabled)
                                <span class="material-symbols-outlined text-green-500 text-lg" title="MFA Enabled">verified_user</span>
                            @else
                                <span class="material-symbols-outlined text-gray-300 text-lg" title="MFA Disabled">shield</span>
                            @endif
                        </td>
                        <td class="text-gray-500 text-sm">
                            {{ $user->last_login_at?->format('M d, Y H:i') ?? 'Never' }}
                        </td>
                        <td>
                            <div class="flex items-center justify-end gap-1">
                                <a
                                    href="{{ route('admin.users.show', $user) }}"
                                    class="p-1.5 rounded-lg hover:bg-gray-100 text-gray-500 hover:text-primary transition"
                                    title="View User"
                                >
                                    <span class="material-symbols-outlined text-lg">visibility</span>
                                </a>
                                <a
                                    href="{{ route('admin.users.edit', $user) }}"
                                    class="p-1.5 rounded-lg hover:bg-gray-100 text-gray-500 hover:text-primary transition"
                                    title="Edit User"
                                >
                                    <span class="material-symbols-outlined text-lg">edit</span>
                                </a>
                                @if($user->id !== auth()->id())
                                    <form
                                        method="POST"
                                        action="{{ route('admin.users.toggle-active', $user) }}"
                                        class="inline"
                                        onsubmit="return confirm('{{ $user->is_active ? 'Deactivate' : 'Activate' }} this user?');"
                                    >
                                        @csrf
                                        @method('PATCH')
                                        <button
                                            type="submit"
                                            class="p-1.5 rounded-lg hover:bg-gray-100 transition {{ $user->is_active ? 'text-orange-500 hover:text-orange-700' : 'text-green-500 hover:text-green-700' }}"
                                            title="{{ $user->is_active ? 'Deactivate' : 'Activate' }}"
                                        >
                                            <span class="material-symbols-outlined text-lg">{{ $user->is_active ? 'block' : 'check_circle' }}</span>
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center text-gray-500 py-12">
                            <span class="material-symbols-outlined text-4xl text-gray-300 block mb-2">group_off</span>
                            <p class="text-sm font-medium">No users found</p>
                            <p class="text-xs text-gray-400 mt-1">Try adjusting your search or filter criteria</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    @if($users->hasPages())
        <div class="bg-white rounded-lg shadow border border-gray-100 p-4">
            {{ $users->links() }}
        </div>
    @endif
</div>
@endsection
