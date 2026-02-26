@extends('layouts.app')

@section('title', 'Edit KRI - GRC Risk Management')
@section('page-section', 'KRI Monitoring')
@section('page-title', 'Edit KRI')

@php
    // Compute single threshold values from min/max pairs for form display
    $isHigherWorse = ($kri->direction ?? $kri->threshold_direction ?? 'higher_worse') === 'higher_is_worse'
                  || ($kri->direction ?? $kri->threshold_direction ?? 'higher_worse') === 'higher_worse';
    $greenVal = $isHigherWorse ? $kri->green_threshold_max : $kri->green_threshold_min;
    $amberVal = $isHigherWorse ? $kri->amber_threshold_max : $kri->amber_threshold_max;
    $redVal   = $isHigherWorse ? $kri->red_threshold_min   : $kri->red_threshold_max;

    // Map direction to the controller's enum values
    $directionValue = 'higher_is_worse';
    $rawDir = $kri->direction ?? $kri->threshold_direction ?? '';
    if (in_array($rawDir, ['lower_is_worse', 'lower_worse'])) {
        $directionValue = 'lower_is_worse';
    }
@endphp

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.kri.index') }}" class="hover:text-[#1A365D]">KRI Monitoring</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.kri.show', $kri) }}" class="hover:text-[#1A365D]">{{ Str::limit($kri->kri_name ?? $kri->name, 25) }}</a>
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
        <h1 class="text-2xl font-bold text-[#1A365D]">Edit KRI: {{ $kri->kri_name ?? $kri->name }}</h1>
        <p class="text-sm text-gray-500 mt-1">Update key risk indicator configuration and thresholds</p>
    </div>

    <form method="POST" action="{{ route('risk.kri.update', $kri) }}">
        @csrf
        @method('PUT')

        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6"><div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">1</div><h2 class="text-lg font-semibold text-[#1A365D]">Basic Information</h2></div>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="lg:col-span-2">
                    <label for="kri_name" class="block text-sm font-medium text-gray-700 mb-2">KRI Name <span class="text-red-500">*</span></label>
                    <input type="text" id="kri_name" name="kri_name" value="{{ old('kri_name', $kri->kri_name ?? $kri->name) }}" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('kri_name') border-red-500 @enderror" required>
                    @error('kri_name')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div class="lg:col-span-2">
                    <label for="description" class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                    <textarea id="description" name="description" rows="3" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('description') border-red-500 @enderror">{{ old('description', $kri->description) }}</textarea>
                    @error('description')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="measurement_unit" class="block text-sm font-medium text-gray-700 mb-2">Measurement Unit <span class="text-red-500">*</span></label>
                    <input type="text" id="measurement_unit" name="measurement_unit" value="{{ old('measurement_unit', $kri->measurement_unit ?? $kri->unit_of_measure) }}" placeholder="e.g. %, NGN, Count, Days" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('measurement_unit') border-red-500 @enderror" required>
                    @error('measurement_unit')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="target_value" class="block text-sm font-medium text-gray-700 mb-2">Target Value</label>
                    <input type="number" step="0.01" id="target_value" name="target_value" value="{{ old('target_value', $kri->target_value) }}" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                </div>
                <div class="lg:col-span-2">
                    <label for="formula" class="block text-sm font-medium text-gray-700 mb-2">Formula</label>
                    <input type="text" id="formula" name="formula" value="{{ old('formula', $kri->metric_formula) }}" placeholder="e.g. (Total Defaults / Total Loans) x 100" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] font-mono text-sm">
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6"><div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">2</div><h2 class="text-lg font-semibold text-[#1A365D]">Thresholds</h2></div>
            <div class="mb-4">
                <label for="direction" class="block text-sm font-medium text-gray-700 mb-2">Threshold Direction <span class="text-red-500">*</span></label>
                <select id="direction" name="direction" class="w-full max-w-sm px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]" required>
                    <option value="higher_is_worse" {{ old('direction', $directionValue) === 'higher_is_worse' ? 'selected' : '' }}>Higher is worse (e.g. default rate, error count)</option>
                    <option value="lower_is_worse" {{ old('direction', $directionValue) === 'lower_is_worse' ? 'selected' : '' }}>Lower is worse (e.g. capital ratio, coverage)</option>
                </select>
                @error('direction')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="p-4 bg-green-50 border border-green-200 rounded-lg">
                    <label class="flex items-center gap-2 mb-3"><span class="w-4 h-4 rounded-full bg-green-500"></span><span class="text-sm font-semibold text-green-700">Green Threshold</span></label>
                    <input type="number" step="0.01" name="green_threshold" value="{{ old('green_threshold', $greenVal) }}" class="w-full px-3 py-2 border border-green-300 rounded-lg text-sm">
                </div>
                <div class="p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                    <label class="flex items-center gap-2 mb-3"><span class="w-4 h-4 rounded-full bg-yellow-500"></span><span class="text-sm font-semibold text-yellow-700">Amber Threshold</span></label>
                    <input type="number" step="0.01" name="amber_threshold" value="{{ old('amber_threshold', $amberVal) }}" class="w-full px-3 py-2 border border-yellow-300 rounded-lg text-sm">
                </div>
                <div class="p-4 bg-red-50 border border-red-200 rounded-lg">
                    <label class="flex items-center gap-2 mb-3"><span class="w-4 h-4 rounded-full bg-red-500"></span><span class="text-sm font-semibold text-red-700">Red Threshold</span></label>
                    <input type="number" step="0.01" name="red_threshold" value="{{ old('red_threshold', $redVal) }}" class="w-full px-3 py-2 border border-red-300 rounded-lg text-sm">
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6"><div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">3</div><h2 class="text-lg font-semibold text-[#1A365D]">Measurement & Ownership</h2></div>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div>
                    <label for="measurement_frequency" class="block text-sm font-medium text-gray-700 mb-2">Measurement Frequency <span class="text-red-500">*</span></label>
                    <select id="measurement_frequency" name="measurement_frequency" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]" required>
                        @foreach (['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly'] as $val => $label)
                            <option value="{{ $val }}" {{ old('measurement_frequency', $kri->measurement_frequency) === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('measurement_frequency')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="kri_owner_id" class="block text-sm font-medium text-gray-700 mb-2">Owner <span class="text-red-500">*</span></label>
                    <select id="kri_owner_id" name="kri_owner_id" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]" required>
                        @foreach (($users ?? []) as $user)
                            <option value="{{ $user->id }}" {{ old('kri_owner_id', $kri->kri_owner_id ?? $kri->owner_id) == $user->id ? 'selected' : '' }}>{{ $user->name }}</option>
                        @endforeach
                    </select>
                    @error('kri_owner_id')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="data_source" class="block text-sm font-medium text-gray-700 mb-2">Data Source</label>
                    <input type="text" id="data_source" name="data_source" value="{{ old('data_source', $kri->data_source) }}" placeholder="e.g. Core Banking System, Treasury Module" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                    <label class="flex items-center gap-2 mt-2">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" class="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]" {{ old('is_active', $kri->is_active ?? true) ? 'checked' : '' }}>
                        <span class="text-sm text-gray-700">Active</span>
                    </label>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-between">
            <a href="{{ route('risk.kri.show', $kri) }}" class="px-6 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</a>
            <button type="submit" class="px-6 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-2"><span class="material-symbols-outlined text-lg">save</span> Update KRI</button>
        </div>
    </form>
@endsection
