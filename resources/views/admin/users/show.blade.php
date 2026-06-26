@extends('layouts.app')

@section('title', $user->name)

@section('page-section', 'Administration')
@section('page-title', 'User Profile')

@section('content')
<div class="space-y-6">
    <!-- Back Navigation -->
    <div>
        <a href="{{ route('admin.users.index') }}" class="text-primary hover:text-primary/80 font-medium inline-flex items-center gap-1 text-sm">
            <span class="material-symbols-outlined text-lg">arrow_back</span>
            Back to Users
        </a>
    </div>

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
                        <p class="text-white/80">{{ $user->job_title }} &bull; {{ $user->department }}</p>
                        <div class="flex items-center gap-2 mt-2">
                            @foreach($user->roles as $role)
                                <span class="px-2 py-0.5 bg-white/20 rounded-full text-xs font-medium">
                                    {{ ucwords(str_replace('-', ' ', $role->name)) }}
                                </span>
                            @endforeach
                        </div>
                    </div>
                </div>
                <a
                    href="{{ route('admin.users.edit', $user) }}"
                    class="px-4 py-2 bg-white text-primary font-semibold rounded-lg hover:bg-white/90 transition inline-flex items-center gap-2"
                >
                    <span class="material-symbols-outlined text-lg">edit</span>
                    Edit User
                </a>
            </div>
        </div>

        <div class="p-6 grid grid-cols-1 md:grid-cols-3 gap-6">
            <!-- Contact Information -->
            <div>
                <h3 class="text-sm font-semibold text-gray-700 mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-gray-400 text-lg">contact_mail</span>
                    Contact Information
                </h3>
                <dl class="space-y-3">
                    <div>
                        <dt class="text-xs text-gray-500 uppercase tracking-wide">Email</dt>
                        <dd class="text-sm text-gray-900 font-medium">{{ $user->email }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500 uppercase tracking-wide">Phone</dt>
                        <dd class="text-sm text-gray-900 font-medium">{{ $user->phone ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500 uppercase tracking-wide">Staff ID</dt>
                        <dd class="text-sm text-gray-900 font-medium">{{ $user->staff_id ?? '-' }}</dd>
                    </div>
                </dl>
            </div>

            <!-- Account Status -->
            <div>
                <h3 class="text-sm font-semibold text-gray-700 mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-gray-400 text-lg">security</span>
                    Account Status
                </h3>
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
                                    <span class="material-symbols-outlined text-[14px] mr-1">verified_user</span>
                                    Enabled
                                </span>
                            @else
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700">
                                    Disabled
                                </span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500 uppercase tracking-wide">Password Changed</dt>
                        <dd class="text-sm text-gray-900 font-medium">
                            {{ $user->password_changed_at?->format('M d, Y') ?? 'Never' }}
                        </dd>
                    </div>
                    @if($user->locked_until && $user->locked_until->isFuture())
                        <div>
                            <dt class="text-xs text-gray-500 uppercase tracking-wide">Account Locked</dt>
                            <dd>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                    <span class="material-symbols-outlined text-[14px] mr-1">lock</span>
                                    Until {{ $user->locked_until->format('H:i') }}
                                </span>
                            </dd>
                        </div>
                    @endif
                </dl>
            </div>

            <!-- Organization Info -->
            <div>
                <h3 class="text-sm font-semibold text-gray-700 mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-gray-400 text-lg">corporate_fare</span>
                    Organization
                </h3>
                <dl class="space-y-3">
                    <div>
                        <dt class="text-xs text-gray-500 uppercase tracking-wide">Business Unit</dt>
                        <dd class="text-sm text-gray-900 font-medium">{{ $user->businessUnit?->name ?? 'Not assigned' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500 uppercase tracking-wide">Organization</dt>
                        <dd class="text-sm text-gray-900 font-medium">{{ $user->organization?->name ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500 uppercase tracking-wide">Created</dt>
                        <dd class="text-sm text-gray-900 font-medium">{{ $user->created_at->format('M d, Y') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500 uppercase tracking-wide">Last Activity</dt>
                        <dd class="text-sm text-gray-900 font-medium">{{ $user->last_activity_at?->diffForHumans() ?? 'No activity' }}</dd>
                    </div>
                </dl>
            </div>
        </div>
    </div>

    <!-- Roles and Permissions -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <!-- Roles -->
        <div class="bg-white rounded-lg shadow border border-gray-100 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
                <span class="material-symbols-outlined text-gray-400">admin_panel_settings</span>
                Assigned Roles
            </h3>
            @if($user->roles->count() > 0)
                <div class="space-y-2">
                    @foreach($user->roles as $role)
                        @php
                            $roleColors = [
                                'super-admin' => 'bg-red-50 border-red-200 text-red-900',
                                'chief-risk-officer' => 'bg-purple-50 border-purple-200 text-purple-900',
                                'risk-manager' => 'bg-blue-50 border-blue-200 text-blue-900',
                                'risk-owner' => 'bg-cyan-50 border-cyan-200 text-cyan-900',
                                'risk-analyst' => 'bg-indigo-50 border-indigo-200 text-indigo-900',
                                'compliance-officer' => 'bg-green-50 border-green-200 text-green-900',
                                'board-member' => 'bg-yellow-50 border-yellow-200 text-yellow-900',
                                'loss-event-manager' => 'bg-orange-50 border-orange-200 text-orange-900',
                                'issue-manager' => 'bg-teal-50 border-teal-200 text-teal-900',
                            ];
                            $color = $roleColors[$role->name] ?? 'bg-gray-50 border-gray-200 text-gray-900';
                        @endphp
                        <div class="flex items-center justify-between p-3 rounded-lg border {{ $color }}">
                            <span class="text-sm font-medium">{{ ucwords(str_replace('-', ' ', $role->name)) }}</span>
                            <span class="text-xs opacity-70">{{ $role->permissions->count() }} permissions</span>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-sm text-gray-500">No roles assigned</p>
            @endif
        </div>

        <!-- Permissions Summary -->
        <div class="bg-white rounded-lg shadow border border-gray-100 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
                <span class="material-symbols-outlined text-gray-400">key</span>
                Effective Permissions
            </h3>
            @php
                $allPermissions = $user->getAllPermissions()->groupBy(function($permission) {
                    return explode(' ', $permission->name)[1] ?? $permission->name;
                });
            @endphp

            @if($allPermissions->count() > 0)
                <div class="space-y-3 max-h-64 overflow-y-auto pr-1" style="scrollbar-width: thin;">
                    @foreach($allPermissions as $module => $permissions)
                        <div>
                            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">{{ $module }}</p>
                            <div class="flex flex-wrap gap-1">
                                @foreach($permissions as $permission)
                                    <span class="px-2 py-0.5 bg-gray-100 rounded text-xs text-gray-700">
                                        {{ explode(' ', $permission->name)[0] }}
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-sm text-gray-500">No permissions</p>
            @endif
        </div>
    </div>

    <!-- Security Actions -->
    <div class="bg-white rounded-lg shadow border border-gray-100 p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
            <span class="material-symbols-outlined text-gray-400">security</span>
            Security Actions
        </h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <form method="POST" action="{{ route('admin.users.reset-password', $user) }}" onsubmit="return confirm('Reset password? The user will receive a new temporary password.');">
                @csrf
                <button type="submit"
                    class="w-full px-4 py-3 bg-orange-50 hover:bg-orange-100 text-orange-700 font-medium rounded-lg border border-orange-200 transition flex items-center gap-3">
                    <span class="material-symbols-outlined text-xl">lock_reset</span>
                    <div class="text-left">
                        <p class="text-sm font-semibold">Reset Password</p>
                        <p class="text-xs text-orange-500">Generate new temporary password</p>
                    </div>
                </button>
            </form>

            @if($user->id !== auth()->id())
                @if($user->is_active)
                    <form method="POST" action="{{ route('admin.users.toggle-active', $user) }}" onsubmit="return confirm('Deactivate this user? They will be unable to log in.');">
                        @csrf
                        @method('PATCH')
                        <button type="submit"
                            class="w-full px-4 py-3 bg-red-50 hover:bg-red-100 text-red-700 font-medium rounded-lg border border-red-200 transition flex items-center gap-3">
                            <span class="material-symbols-outlined text-xl">block</span>
                            <div class="text-left">
                                <p class="text-sm font-semibold">Deactivate User</p>
                                <p class="text-xs text-red-500">Prevent this user from logging in</p>
                            </div>
                        </button>
                    </form>
                @else
                    <form method="POST" action="{{ route('admin.users.toggle-active', $user) }}" onsubmit="return confirm('Activate this user?');">
                        @csrf
                        @method('PATCH')
                        <button type="submit"
                            class="w-full px-4 py-3 bg-green-50 hover:bg-green-100 text-green-700 font-medium rounded-lg border border-green-200 transition flex items-center gap-3">
                            <span class="material-symbols-outlined text-xl">check_circle</span>
                            <div class="text-left">
                                <p class="text-sm font-semibold">Activate User</p>
                                <p class="text-xs text-green-500">Allow this user to log in</p>
                            </div>
                        </button>
                    </form>
                @endif
            @endif

            @if($user->locked_until && $user->locked_until->isFuture())
                <form method="POST" action="{{ route('admin.users.reset-password', $user) }}" onsubmit="return confirm('Unlock account and reset password?');">
                    @csrf
                    <button type="submit"
                        class="w-full px-4 py-3 bg-blue-50 hover:bg-blue-100 text-blue-700 font-medium rounded-lg border border-blue-200 transition flex items-center gap-3">
                        <span class="material-symbols-outlined text-xl">lock_open</span>
                        <div class="text-left">
                            <p class="text-sm font-semibold">Unlock Account</p>
                            <p class="text-xs text-blue-500">Clear lockout and reset password</p>
                        </div>
                    </button>
                </form>
            @endif
        </div>
    </div>
</div>
@endsection
