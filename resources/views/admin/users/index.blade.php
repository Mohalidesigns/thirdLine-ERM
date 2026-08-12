@extends('layouts.app')

@section('title', 'User Management')

@section('page-section', 'Administration')
@section('page-title', 'User Management')

@section('content')
<div class="space-y-6">
    @if (session('success'))
        <div class="p-4 bg-green-50 border border-green-200 rounded-xl flex items-center gap-3">
            <span class="material-symbols-outlined text-green-600">check_circle</span>
            <span class="text-sm text-green-700">{{ session('success') }}</span>
        </div>
    @endif

    @if (session('error'))
        <div class="p-4 bg-red-50 border border-red-200 rounded-xl flex items-center gap-3">
            <span class="material-symbols-outlined text-red-600">error</span>
            <span class="text-sm text-red-700">{{ session('error') }}</span>
        </div>
    @endif

    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-2xl font-bold text-gray-900">User Management</h2>
            <p class="text-sm text-gray-500 mt-1">Manage system users, roles, and access permissions</p>
        </div>
        @can('admin.users')
            <a
                href="{{ route('admin.users.create') }}"
                class="inline-flex items-center gap-2 px-4 py-2.5 bg-primary hover:bg-primary/90 text-white font-semibold rounded-lg transition"
            >
                <span class="material-symbols-outlined text-lg">person_add</span>
                Create User
            </a>
        @endcan
    </div>

    <!-- Summary Stats -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
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

    {{-- WP-09: search, filters, sorting, column chooser, saved views and
         export all live inside the shared grid — see
         App\Grids\Definitions\AdminUsersGrid. Activation/deactivation stays on
         the user's own page: the controller refuses to let anyone toggle their
         own account, which is a per-actor rule. --}}
    <x-data-grid grid="admin_users" />
</div>
@endsection
