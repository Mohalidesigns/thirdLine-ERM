@extends('layouts.app')

@section('title', $user->name)

@section('page-section', 'Administration')
@section('page-title', 'User Profile')

@section('content')
<div class="space-y-6">
    <!-- User Profile Card -->
    <div class="bg-white rounded-lg shadow border border-gray-100 overflow-hidden">
        <div class="bg-gradient-to-r from-primary to-secondary p-6 text-white">
            <div class="flex items-start justify-between">
                <div class="flex items-center gap-4">
                    <div class="w-16 h-16 bg-white/20 rounded-full flex items-center justify-center text-white text-2xl font-bold">
                        {{ collect(explode(' ', $user->name))->map(fn($w) => strtoupper(substr($w, 0, 1)))->take(2)->implode('') }}
                    </div>
                    <div>
                        <h2 class="text-2xl font-bold">{{ $user->name }}</h2>
                        <p class="text-white/80">{{ $user->job_title }} • {{ $user->department }}</p>
                    </div>
                </div>
                <a
                    href="{{ route('admin.users.edit', $user) }}"
                    class="px-4 py-2 bg-white text-primary font-semibold rounded-lg hover:bg-white/90 transition"
                >
                    Edit User
                </a>
            </div>
        </div>

        <div class="p-6 grid grid-cols-1 md:grid-cols-3 gap-6">
            <!-- Contact Information -->
            <div>
                <h3 class="text-sm font-semibold text-gray-700 mb-4">Contact Information</h3>
                <dl class="space-y-3">
                    <div>
                        <dt class="text-xs text-gray-500 uppercase tracking-wide">Email</dt>
                        <dd class="text-sm text-gray-900 font-medium">{{ $user->email }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500 uppercase tracking-wide">Phone</dt>
                        <dd class="text-sm text-gray-900 font-medium">{{ $user->phone }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500 uppercase tracking-wide">Staff ID</dt>
                        <dd class="text-sm text-gray-900 font-medium">{{ $user->staff_id }}</dd>
                    </div>
                </dl>
            </div>

            <!-- Account Status -->
            <div>
                <h3 class="text-sm font-semibold text-gray-700 mb-4">Account Status</h3>
                <dl class="space-y-3">
                    <div>
                        <dt class="text-xs text-gray-500 uppercase tracking-wide">Status</dt>
                        <dd>
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
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500 uppercase tracking-wide">Last Login</dt>
                        <dd class="text-sm text-gray-900 font-medium">
                            {{ $user->last_login_at?->format('M d, Y H:i') ?? 'Never logged in' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500 uppercase tracking-wide">MFA Status</dt>
                        <dd>
                            @if($user->mfa_enabled)
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                    Enabled
                                </span>
                            @else
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700">
                                    Disabled
                                </span>
                            @endif
                        </dd>
                    </div>
                </dl>
            </div>

            <!-- Organization Info -->
            <div>
                <h3 class="text-sm font-semibold text-gray-700 mb-4">Organization</h3>
                <dl class="space-y-3">
                    <div>
                        <dt class="text-xs text-gray-500 uppercase tracking-wide">Business Unit</dt>
                        <dd class="text-sm text-gray-900 font-medium">{{ $user->businessUnit?->name ?? 'N/A' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500 uppercase tracking-wide">Created</dt>
                        <dd class="text-sm text-gray-900 font-medium">{{ $user->created_at->format('M d, Y') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500 uppercase tracking-wide">Updated</dt>
                        <dd class="text-sm text-gray-900 font-medium">{{ $user->updated_at->format('M d, Y') }}</dd>
                    </div>
                </dl>
            </div>
        </div>
    </div>

    <!-- Roles and Permissions -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <!-- Roles -->
        <div class="bg-white rounded-lg shadow border border-gray-100 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Assigned Roles</h3>
            @if($user->roles->count() > 0)
                <div class="space-y-2">
                    @foreach($user->roles as $role)
                        <div class="flex items-center justify-between p-3 bg-blue-50 rounded-lg border border-blue-200">
                            <span class="text-sm font-medium text-blue-900">{{ ucwords(str_replace('-', ' ', $role->name)) }}</span>
                            <span class="text-xs text-blue-700">{{ $role->permissions->count() }} permissions</span>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-sm text-gray-500">No roles assigned</p>
            @endif
        </div>

        <!-- Direct Permissions -->
        <div class="bg-white rounded-lg shadow border border-gray-100 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Direct Permissions</h3>
            @if($user->getDirectPermissions()->count() > 0)
                <div class="space-y-2">
                    @foreach($user->getDirectPermissions() as $permission)
                        <div class="flex items-center justify-between p-3 bg-green-50 rounded-lg border border-green-200">
                            <span class="text-sm font-medium text-green-900">{{ ucwords(str_replace('-', ' ', $permission->name)) }}</span>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-sm text-gray-500">No direct permissions</p>
            @endif
        </div>
    </div>

    <!-- Security Actions -->
    <div class="bg-white rounded-lg shadow border border-gray-100 p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Security Actions</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <form method="POST" action="{{ route('admin.users.reset-password', $user) }}" onsubmit="return confirm('Are you sure? The user will need to use the temporary password.');">
                @csrf
                <button
                    type="submit"
                    class="w-full px-4 py-2 bg-orange-50 hover:bg-orange-100 text-orange-700 font-medium rounded-lg border border-orange-200 transition"
                >
                    <span class="material-symbols-outlined inline text-[18px] mr-2">lock_reset</span>
                    Reset Password
                </button>
            </form>

            @if($user->is_active)
                <form method="POST" action="{{ route('admin.users.toggle-active', $user) }}" onsubmit="return confirm('Deactivate this user?');">
                    @csrf
                    @method('PATCH')
                    <button
                        type="submit"
                        class="w-full px-4 py-2 bg-red-50 hover:bg-red-100 text-red-700 font-medium rounded-lg border border-red-200 transition"
                    >
                        <span class="material-symbols-outlined inline text-[18px] mr-2">block</span>
                        Deactivate User
                    </button>
                </form>
            @else
                <form method="POST" action="{{ route('admin.users.toggle-active', $user) }}" onsubmit="return confirm('Activate this user?');">
                    @csrf
                    @method('PATCH')
                    <button
                        type="submit"
                        class="w-full px-4 py-2 bg-green-50 hover:bg-green-100 text-green-700 font-medium rounded-lg border border-green-200 transition"
                    >
                        <span class="material-symbols-outlined inline text-[18px] mr-2">check_circle</span>
                        Activate User
                    </button>
                </form>
            @endif
        </div>
    </div>

    <!-- Back Button -->
    <div>
        <a
            href="{{ route('admin.users.index') }}"
            class="text-primary hover:text-primary/80 font-medium inline-flex items-center gap-1"
        >
            <span class="material-symbols-outlined">arrow_back</span>
            Back to Users
        </a>
    </div>
</div>
@endsection
