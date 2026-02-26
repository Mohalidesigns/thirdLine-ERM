@extends('layouts.app')

@section('title', 'Create New User')

@section('page-section', 'Administration')
@section('page-title', 'Create User')

@section('content')
<div class="max-w-3xl mx-auto">
    <div class="bg-white rounded-lg shadow border border-gray-100">
        <div class="p-6 border-b border-gray-100">
            <h2 class="text-xl font-bold text-gray-900">Create New User Account</h2>
            <p class="text-sm text-gray-500 mt-1">Add a new user to the system with assigned roles</p>
        </div>

        <div class="p-6">
            @if ($errors->any())
                <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
                    <h3 class="text-sm font-semibold text-red-900 mb-2">Please fix the following errors:</h3>
                    <ul class="space-y-1">
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
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Basic Information</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Full Name -->
                        <div>
                            <label for="name" class="block text-sm font-medium text-gray-700 mb-2">Full Name *</label>
                            <input
                                type="text"
                                id="name"
                                name="name"
                                value="{{ old('name') }}"
                                required
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition"
                                placeholder="John Doe"
                            >
                        </div>

                        <!-- Email -->
                        <div>
                            <label for="email" class="block text-sm font-medium text-gray-700 mb-2">Email Address *</label>
                            <input
                                type="email"
                                id="email"
                                name="email"
                                value="{{ old('email') }}"
                                required
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition"
                                placeholder="john@example.com"
                            >
                        </div>

                        <!-- Staff ID -->
                        <div>
                            <label for="staff_id" class="block text-sm font-medium text-gray-700 mb-2">Staff ID *</label>
                            <input
                                type="text"
                                id="staff_id"
                                name="staff_id"
                                value="{{ old('staff_id') }}"
                                required
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition"
                                placeholder="STF-001"
                            >
                        </div>

                        <!-- Phone -->
                        <div>
                            <label for="phone" class="block text-sm font-medium text-gray-700 mb-2">Phone *</label>
                            <input
                                type="tel"
                                id="phone"
                                name="phone"
                                value="{{ old('phone') }}"
                                required
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition"
                                placeholder="+234 XXX XXX XXXX"
                            >
                        </div>

                        <!-- Job Title -->
                        <div>
                            <label for="job_title" class="block text-sm font-medium text-gray-700 mb-2">Job Title *</label>
                            <input
                                type="text"
                                id="job_title"
                                name="job_title"
                                value="{{ old('job_title') }}"
                                required
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition"
                                placeholder="Risk Officer"
                            >
                        </div>

                        <!-- Department -->
                        <div>
                            <label for="department" class="block text-sm font-medium text-gray-700 mb-2">Department *</label>
                            <input
                                type="text"
                                id="department"
                                name="department"
                                value="{{ old('department') }}"
                                required
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition"
                                placeholder="Risk Management"
                            >
                        </div>

                        <!-- Business Unit -->
                        <div class="md:col-span-2">
                            <label for="business_unit_id" class="block text-sm font-medium text-gray-700 mb-2">Business Unit *</label>
                            <select
                                id="business_unit_id"
                                name="business_unit_id"
                                required
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent transition"
                            >
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
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Assign Roles</h3>
                    <p class="text-sm text-gray-500 mb-4">Select one or more roles for this user</p>

                    <div class="space-y-3">
                        @php
                            $roleOptions = [
                                'super-admin' => ['name' => 'Super Administrator', 'desc' => 'Full system access'],
                                'chief-risk-officer' => ['name' => 'Chief Risk Officer', 'desc' => 'Risk oversight and strategy'],
                                'risk-manager' => ['name' => 'Risk Manager', 'desc' => 'Risk management and assessments'],
                                'compliance-officer' => ['name' => 'Compliance Officer', 'desc' => 'Compliance management'],
                                'risk-owner' => ['name' => 'Risk Owner', 'desc' => 'Own and manage risks'],
                                'control-owner' => ['name' => 'Control Owner', 'desc' => 'Control management'],
                            ]
                        @endphp

                        @foreach($roleOptions as $roleValue => $roleLabel)
                            <label class="flex items-start gap-3 p-3 border border-gray-200 rounded-lg hover:bg-gray-50 cursor-pointer transition">
                                <input
                                    type="checkbox"
                                    name="roles[]"
                                    value="{{ $roleValue }}"
                                    class="w-4 h-4 rounded border-gray-300 text-primary focus:ring-primary mt-1"
                                    {{ in_array($roleValue, old('roles', [])) ? 'checked' : '' }}
                                >
                                <div>
                                    <span class="text-sm font-medium text-gray-900">{{ $roleLabel['name'] }}</span>
                                    <p class="text-xs text-gray-500 mt-0.5">{{ $roleLabel['desc'] }}</p>
                                </div>
                            </label>
                        @endforeach
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="flex gap-3 pt-6 border-t border-gray-200">
                    <button
                        type="submit"
                        class="px-6 py-2.5 bg-primary hover:bg-primary/90 text-white font-semibold rounded-lg transition"
                    >
                        Create User
                    </button>
                    <a
                        href="{{ route('admin.users.index') }}"
                        class="px-6 py-2.5 bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold rounded-lg transition"
                    >
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
