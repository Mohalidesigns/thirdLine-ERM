@extends('layouts.app')

@section('title', 'Create New User')

@section('page-section', 'Administration')
@section('page-title', 'Create User')

@section('content')
<div class="max-w-3xl mx-auto">
    <!-- Back Navigation -->
    <div class="mb-4">
        <a href="{{ route('admin.users.index') }}" class="text-primary hover:text-primary/80 font-medium inline-flex items-center gap-1 text-sm">
            <span class="material-symbols-outlined text-lg">arrow_back</span>
            Back to Users
        </a>
    </div>

    <div class="bg-white rounded-lg shadow border border-gray-100">
        <div class="p-6 border-b border-gray-100">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-primary/10 flex items-center justify-center">
                    <span class="material-symbols-outlined text-primary">person_add</span>
                </div>
                <div>
                    <h2 class="text-xl font-bold text-gray-900">Create New User Account</h2>
                    <p class="text-sm text-gray-500 mt-0.5">Add a new user to the system. A temporary password will be generated.</p>
                </div>
            </div>
        </div>

        <div class="p-6">
            @if ($errors->any())
                <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="material-symbols-outlined text-red-600 text-lg">error</span>
                        <h3 class="text-sm font-semibold text-red-900">Please fix the following errors:</h3>
                    </div>
                    <ul class="space-y-1 ml-7">
                        @foreach ($errors->all() as $error)
                            <li class="text-red-600 text-sm">{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('admin.users.store') }}" class="space-y-6">
                @csrf

                <!-- Basic Information Section -->
                <div class="border-b border-gray-200 pb-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
                        <span class="material-symbols-outlined text-gray-400">badge</span>
                        Basic Information
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="name" class="block text-sm font-medium text-gray-700 mb-2">Full Name *</label>
                            <input type="text" id="name" name="name" value="{{ old('name') }}" required
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition"
                                placeholder="e.g. Adebayo Ogundimu">
                        </div>

                        <div>
                            <label for="email" class="block text-sm font-medium text-gray-700 mb-2">Email Address *</label>
                            <input type="email" id="email" name="email" value="{{ old('email') }}" required
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition"
                                placeholder="user@organization.com">
                        </div>

                        <div>
                            <label for="staff_id" class="block text-sm font-medium text-gray-700 mb-2">Staff ID *</label>
                            <input type="text" id="staff_id" name="staff_id" value="{{ old('staff_id') }}" required
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition"
                                placeholder="e.g. STF-001">
                        </div>

                        <div>
                            <label for="phone" class="block text-sm font-medium text-gray-700 mb-2">Phone *</label>
                            <input type="tel" id="phone" name="phone" value="{{ old('phone') }}" required
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition"
                                placeholder="+234 XXX XXX XXXX">
                        </div>

                        <div>
                            <label for="job_title" class="block text-sm font-medium text-gray-700 mb-2">Job Title *</label>
                            <input type="text" id="job_title" name="job_title" value="{{ old('job_title') }}" required
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition"
                                placeholder="e.g. Risk Manager">
                        </div>

                        <div>
                            <label for="department" class="block text-sm font-medium text-gray-700 mb-2">Department *</label>
                            <input type="text" id="department" name="department" value="{{ old('department') }}" required
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition"
                                placeholder="e.g. Risk Management">
                        </div>

                        <div class="md:col-span-2">
                            <label for="business_unit_id" class="block text-sm font-medium text-gray-700 mb-2">Business Unit *</label>
                            <select id="business_unit_id" name="business_unit_id" required
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition">
                                <option value="">Select a business unit...</option>
                                @foreach ($businessUnits as $unit)
                                    <option value="{{ $unit->id }}" {{ old('business_unit_id') == $unit->id ? 'selected' : '' }}>{{ $unit->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Roles Section -->
                <div>
                    <h3 class="text-lg font-semibold text-gray-900 mb-2 flex items-center gap-2">
                        <span class="material-symbols-outlined text-gray-400">admin_panel_settings</span>
                        Assign Roles
                    </h3>
                    <p class="text-sm text-gray-500 mb-4">Select one or more roles. Roles determine what the user can access.</p>

                    @php
                        $roleDescriptions = [
                            'super-admin' => 'Full system access including user management and settings',
                            'chief-risk-officer' => 'Strategic risk oversight, approvals, and enterprise-wide risk view',
                            'risk-manager' => 'Manage risks, assessments, controls, KRIs, and generate reports',
                            'risk-owner' => 'Own and manage assigned risks, create assessments and treatments',
                            'risk-analyst' => 'View risks, perform assessments, monitor KRIs, and create reports',
                            'compliance-officer' => 'Monitor compliance, manage loss events and issues, generate reports',
                            'board-member' => 'Read-only access to risks, dashboards, and reports',
                            'loss-event-manager' => 'Manage loss events, near misses, and root cause analysis',
                            'issue-manager' => 'Manage issues, findings, escalations, and remediation tracking',
                        ];
                        $roleIcons = [
                            'super-admin' => 'shield_person',
                            'chief-risk-officer' => 'supervisor_account',
                            'risk-manager' => 'manage_accounts',
                            'risk-owner' => 'person_pin',
                            'risk-analyst' => 'analytics',
                            'compliance-officer' => 'gavel',
                            'board-member' => 'groups',
                            'loss-event-manager' => 'report_problem',
                            'issue-manager' => 'bug_report',
                        ];
                    @endphp

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        @foreach($roles as $role)
                            <label class="flex items-start gap-3 p-3 border border-gray-200 rounded-lg hover:bg-gray-50 cursor-pointer transition {{ in_array($role->name, old('roles', [])) ? 'bg-primary/5 border-primary/30' : '' }}">
                                <input type="checkbox" name="roles[]" value="{{ $role->name }}"
                                    class="w-4 h-4 rounded border-gray-300 text-primary focus:ring-primary mt-0.5"
                                    {{ in_array($role->name, old('roles', [])) ? 'checked' : '' }}>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="material-symbols-outlined text-gray-400 text-[16px]">{{ $roleIcons[$role->name] ?? 'person' }}</span>
                                        <span class="text-sm font-medium text-gray-900">{{ ucwords(str_replace('-', ' ', $role->name)) }}</span>
                                    </div>
                                    <p class="text-xs text-gray-500 mt-0.5">{{ $roleDescriptions[$role->name] ?? 'Standard role' }}</p>
                                </div>
                            </label>
                        @endforeach
                    </div>
                </div>

                <!-- Info Banner -->
                <div class="p-4 bg-blue-50 border border-blue-200 rounded-lg flex items-start gap-3">
                    <span class="material-symbols-outlined text-blue-600 text-lg mt-0.5">info</span>
                    <div>
                        <p class="text-sm text-blue-800 font-medium">Temporary Password</p>
                        <p class="text-xs text-blue-600 mt-0.5">A temporary password will be generated automatically. The user will be required to change it on first login.</p>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="flex gap-3 pt-6 border-t border-gray-200">
                    <button type="submit"
                        class="px-6 py-2.5 bg-primary hover:bg-primary/90 text-white font-semibold rounded-lg transition inline-flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg">person_add</span>
                        Create User
                    </button>
                    <a href="{{ route('admin.users.index') }}"
                        class="px-6 py-2.5 bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold rounded-lg transition">
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
