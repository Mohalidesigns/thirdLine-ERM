@extends('layouts.app')

@section('title', 'Edit Control - GRC Risk Management')
@section('page-section', 'Controls')
@section('page-title', 'Edit Control')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.controls.index') }}" class="hover:text-[#1A365D]">Controls</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.controls.show', $control) }}" class="hover:text-[#1A365D]">{{ Str::limit($control->name, 20) }}</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Edit</span>
@endsection

@section('content')
    @if ($errors->any())
        <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl">
            <div class="flex items-center gap-2 mb-2"><span class="material-symbols-outlined text-red-600">error</span><span class="text-sm font-semibold text-red-700">Please correct the following errors:</span></div>
            <ul class="list-disc list-inside text-sm text-red-600 space-y-1">@foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach</ul>
        </div>
    @endif

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-[#1A365D]">Edit Control</h1>
        <p class="text-sm text-gray-500 mt-1">Update control details for "{{ $control->name }}"</p>
    </div>

    <form method="POST" action="{{ route('risk.controls.update', $control) }}">
        @csrf
        @method('PUT')

        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6"><div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">1</div><h2 class="text-lg font-semibold text-[#1A365D]">Control Details</h2></div>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="lg:col-span-2">
                    <label for="name" class="block text-sm font-medium text-gray-700 mb-2">Control Name <span class="text-red-500">*</span></label>
                    <input type="text" id="name" name="name" value="{{ old('name', $control->name) }}" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('name') border-red-500 @enderror" required>
                    @error('name')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div class="lg:col-span-2">
                    <label for="description" class="block text-sm font-medium text-gray-700 mb-2">Description <span class="text-red-500">*</span></label>
                    <textarea id="description" name="description" rows="3" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('description') border-red-500 @enderror" required>{{ old('description', $control->description) }}</textarea>
                    @error('description')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="control_type" class="block text-sm font-medium text-gray-700 mb-2">Control Type <span class="text-red-500">*</span></label>
                    <select id="control_type" name="control_type" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]" required>
                        @foreach (['preventive' => 'Preventive', 'detective' => 'Detective', 'corrective' => 'Corrective', 'directive' => 'Directive'] as $val => $label)
                            <option value="{{ $val }}" {{ old('control_type', $control->control_type) === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('control_type')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="control_nature" class="block text-sm font-medium text-gray-700 mb-2">Control Nature</label>
                    <select id="control_nature" name="control_nature" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        <option value="">Select Nature</option>
                        @foreach (['manual' => 'Manual', 'automated' => 'Automated', 'semi_automated' => 'Semi-Automated'] as $val => $label)
                            <option value="{{ $val }}" {{ old('control_nature', $control->control_nature) === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('control_nature')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="frequency" class="block text-sm font-medium text-gray-700 mb-2">Frequency</label>
                    <select id="frequency" name="frequency" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        <option value="">Select Frequency</option>
                        @foreach (['continuous' => 'Continuous', 'daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'annually' => 'Annually', 'ad_hoc' => 'Ad Hoc'] as $val => $label)
                            <option value="{{ $val }}" {{ old('frequency', $control->frequency) === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('frequency')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="owner_id" class="block text-sm font-medium text-gray-700 mb-2">Control Owner <span class="text-red-500">*</span></label>
                    <select id="owner_id" name="owner_id" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]" required>
                        @foreach (($users ?? []) as $user) <option value="{{ $user->id }}" {{ old('owner_id', $control->owner_id) == $user->id ? 'selected' : '' }}>{{ $user->name }}</option> @endforeach
                    </select>
                    @error('owner_id')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="business_unit_id" class="block text-sm font-medium text-gray-700 mb-2">Business Unit</label>
                    <select id="business_unit_id" name="business_unit_id" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        <option value="">Select Business Unit</option>
                        @foreach (($businessUnits ?? []) as $bu) <option value="{{ $bu->id }}" {{ old('business_unit_id', $control->business_unit_id) == $bu->id ? 'selected' : '' }}>{{ $bu->name }}</option> @endforeach
                    </select>
                </div>
                <div>
                    <label for="effectiveness_rating" class="block text-sm font-medium text-gray-700 mb-2">Effectiveness</label>
                    <select id="effectiveness_rating" name="effectiveness_rating" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        <option value="">Select Rating</option>
                        @foreach (['effective' => 'Effective', 'partially_effective' => 'Partially Effective', 'ineffective' => 'Ineffective'] as $val => $label)
                            <option value="{{ $val }}" {{ old('effectiveness_rating', $control->effectiveness_rating) === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="status" class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                    <select id="status" name="status" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        @foreach (['active' => 'Active', 'inactive' => 'Inactive', 'under_review' => 'Under Review'] as $val => $label)
                            <option value="{{ $val }}" {{ old('status', $control->status ?? 'active') === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-between">
            <a href="{{ route('risk.controls.show', $control) }}" class="px-6 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</a>
            <button type="submit" class="px-6 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-2"><span class="material-symbols-outlined text-lg">save</span> Update Control</button>
        </div>
    </form>
@endsection
