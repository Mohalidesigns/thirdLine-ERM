@extends('layouts.app')

@section('title', 'User Management')

@section('page-section', 'Administration')
@section('page-title', 'User Management')

@section('content')
<div class="space-y-6">
    <!-- Header with Create Button -->
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-2xl font-bold text-gray-900">User Management</h2>
            <p class="text-sm text-gray-500 mt-1">Manage system users, roles, and permissions</p>
        </div>
        <a
            href="{{ route('admin.users.create') }}"
            class="inline-flex items-center gap-2 px-4 py-2.5 bg-primary hover:bg-primary/90 text-white font-semibold rounded-lg transition"
        >
            <span class="material-symbols-outlined">add</span>
            Create User
        </a>
    </div>

    <!-- Search and Filter Card -->
    <div class="bg-white rounded-lg shadow border border-gray-100 p-6">
        <form method="GET" action="{{ route('admin.users.index') }}" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <!-- Search -->
                <div>
                    <label for="search" class="block text-sm font-medium text-gray-700 mb-2">Search</label>
                    <input
                        type="text"
                        id="search"
                        name="search"
                        value="{{ $search }}"
                        placeholder="Name or email..."
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent text-sm"
                    >
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
                        <option value="super-admin">Super Administrator</option>
                        <option value="chief-risk-officer">Chief Risk Officer</option>
                        <option value="risk-manager">Risk Manager</option>
                        <option value="compliance-officer">Compliance Officer</option>
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
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>

                <!-- Submit -->
                <div class="flex items-end">
                    <button
                        type="submit"
                        class="w-full px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 font-medium rounded-lg transition text-sm"
                    >
                        Filter
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Users Table -->
    <div class="bg-white rounded-lg shadow border border-gray-100 overflow-hidden">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Staff ID</th>
                    <th>Role</th>
                    <th>Business Unit</th>
                    <th>Status</th>
                    <th>Last Login</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($users as $user)
                    <tr>
                        <td class="font-medium text-gray-900">{{ $user->name }}</td>
                        <td>{{ $user->email }}</td>
                        <td>{{ $user->staff_id }}</td>
                        <td>
                            @if($user->roles->count() > 0)
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                    {{ $user->roles->first()->name }}
                                </span>
                            @else
                                <span class="text-gray-400">No role</span>
                            @endif
                        </td>
                        <td>{{ $user->businessUnit?->name ?? 'N/A' }}</td>
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
                        <td class="text-gray-500 text-sm">
                            {{ $user->last_login_at?->format('M d, Y') ?? 'Never' }}
                        </td>
                        <td>
                            <div class="flex items-center gap-2">
                                <a
                                    href="{{ route('admin.users.show', $user) }}"
                                    class="text-primary hover:text-primary/80 text-sm font-medium"
                                >
                                    View
                                </a>
                                <a
                                    href="{{ route('admin.users.edit', $user) }}"
                                    class="text-primary hover:text-primary/80 text-sm font-medium"
                                >
                                    Edit
                                </a>
                                <form
                                    method="POST"
                                    action="{{ route('admin.users.toggle-active', $user) }}"
                                    class="inline"
                                    onsubmit="return confirm('Are you sure?');"
                                >
                                    @csrf
                                    @method('PATCH')
                                    <button
                                        type="submit"
                                        class="text-orange-600 hover:text-orange-700 text-sm font-medium"
                                    >
                                        {{ $user->is_active ? 'Deactivate' : 'Activate' }}
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center text-gray-500 py-8">
                            No users found
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <div class="bg-white rounded-lg shadow border border-gray-100 p-4">
        {{ $users->links() }}
    </div>
</div>
@endsection
