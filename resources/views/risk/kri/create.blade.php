@extends('layouts.app')

@section('title', 'Create KRI - GRC Risk Management')
@section('page-section', 'KRI Monitoring')
@section('page-title', 'Create KRI')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.kri.index') }}" class="hover:text-[#1A365D]">KRI Monitoring</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Create KRI</span>
@endsection

@section('content')
    @if ($errors->any())
        <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl">
            <div class="flex items-center gap-2 mb-2">
                <span class="material-symbols-outlined text-red-600">error</span>
                <span class="text-sm font-semibold text-red-700">Please correct the following errors:</span>
            </div>
            <ul class="list-disc list-inside text-sm text-red-600 space-y-1">
                @foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach
            </ul>
        </div>
    @endif

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-[#1A365D]">Create Key Risk Indicator</h1>
        <p class="text-sm text-gray-500 mt-1">Define a new KRI with thresholds and measurement parameters</p>
    </div>

    <form method="POST" action="{{ route('risk.kri.store') }}">
        @csrf

        {{-- Section 1: Basic Information --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6">
                <div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">1</div>
                <h2 class="text-lg font-semibold text-[#1A365D]">Basic Information</h2>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="lg:col-span-2">
                    <label for="kri_name" class="block text-sm font-medium text-gray-700 mb-2">KRI Name <span class="text-red-500">*</span></label>
                    <input type="text" id="kri_name" name="kri_name" value="{{ old('kri_name') }}" placeholder="e.g. Non-Performing Loan Ratio"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('kri_name') border-red-500 @enderror" required>
                    @error('kri_name')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div class="lg:col-span-2">
                    <label for="description" class="block text-sm font-medium text-gray-700 mb-2">Description <span class="text-red-500">*</span></label>
                    <textarea id="description" name="description" rows="3" placeholder="Describe what this indicator measures and why it is important..."
                              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('description') border-red-500 @enderror" required>{{ old('description') }}</textarea>
                    @error('description')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="category" class="block text-sm font-medium text-gray-700 mb-2">Category <span class="text-red-500">*</span></label>
                    <select id="category" name="category" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('category') border-red-500 @enderror" required>
                        <option value="">Select Category</option>
                        @foreach (['Credit Risk', 'Market Risk', 'Operational Risk', 'Liquidity Risk', 'Compliance Risk', 'Strategic Risk', 'Reputational Risk', 'Technology Risk'] as $cat)
                            <option value="{{ $cat }}" {{ old('category') === $cat ? 'selected' : '' }}>{{ $cat }}</option>
                        @endforeach
                    </select>
                    @error('category')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="measurement_unit" class="block text-sm font-medium text-gray-700 mb-2">Unit of Measurement <span class="text-red-500">*</span></label>
                    <input type="text" id="measurement_unit" name="measurement_unit" value="{{ old('measurement_unit') }}" placeholder="e.g. %, ₦, Count, Hours"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('measurement_unit') border-red-500 @enderror" required>
                    @error('measurement_unit')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div class="lg:col-span-2">
                    <label for="formula" class="block text-sm font-medium text-gray-700 mb-2">Calculation Formula</label>
                    <input type="text" id="formula" name="formula" value="{{ old('formula') }}" placeholder="e.g. (Non-performing Loans / Total Loans) x 100"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] font-mono text-sm">
                    @error('formula')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
            </div>
        </div>

        {{-- Section 2: Thresholds --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6">
                <div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">2</div>
                <h2 class="text-lg font-semibold text-[#1A365D]">Threshold Configuration</h2>
            </div>
            <p class="text-sm text-gray-500 mb-4">Define the traffic light thresholds for this indicator</p>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="p-4 bg-green-50 border border-green-200 rounded-lg">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="w-4 h-4 rounded-full bg-green-500"></span>
                        <label class="text-sm font-semibold text-green-700">Green (Normal)</label>
                    </div>
                    <div>
                        <label for="green_threshold" class="block text-xs text-gray-600 mb-1">Maximum value for Green</label>
                        <input type="number" step="0.01" id="green_threshold" name="green_threshold" value="{{ old('green_threshold') }}" placeholder="e.g. 5.0"
                               class="w-full px-3 py-2 border border-green-300 rounded-lg text-sm focus:ring-2 focus:ring-green-200 focus:border-green-400" required>
                    </div>
                    @error('green_threshold')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div class="p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="w-4 h-4 rounded-full bg-yellow-500"></span>
                        <label class="text-sm font-semibold text-yellow-700">Amber (Warning)</label>
                    </div>
                    <div>
                        <label for="amber_threshold" class="block text-xs text-gray-600 mb-1">Maximum value for Amber</label>
                        <input type="number" step="0.01" id="amber_threshold" name="amber_threshold" value="{{ old('amber_threshold') }}" placeholder="e.g. 8.0"
                               class="w-full px-3 py-2 border border-yellow-300 rounded-lg text-sm focus:ring-2 focus:ring-yellow-200 focus:border-yellow-400" required>
                    </div>
                    @error('amber_threshold')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div class="p-4 bg-red-50 border border-red-200 rounded-lg">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="w-4 h-4 rounded-full bg-red-500"></span>
                        <label class="text-sm font-semibold text-red-700">Red (Breach)</label>
                    </div>
                    <div>
                        <label for="red_threshold" class="block text-xs text-gray-600 mb-1">Value at which Red triggers</label>
                        <input type="number" step="0.01" id="red_threshold" name="red_threshold" value="{{ old('red_threshold') }}" placeholder="e.g. 10.0"
                               class="w-full px-3 py-2 border border-red-300 rounded-lg text-sm focus:ring-2 focus:ring-red-200 focus:border-red-400" required>
                    </div>
                    @error('red_threshold')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="mt-4">
                <label for="direction" class="block text-sm font-medium text-gray-700 mb-2">Threshold Direction <span class="text-red-500">*</span></label>
                <select id="direction" name="direction" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('direction') border-red-500 @enderror" required>
                    <option value="higher_is_worse" {{ old('direction', 'higher_is_worse') === 'higher_is_worse' ? 'selected' : '' }}>Higher is worse (e.g. NPL Ratio, Error Rate)</option>
                    <option value="lower_is_worse" {{ old('direction') === 'lower_is_worse' ? 'selected' : '' }}>Lower is worse (e.g. Capital Adequacy Ratio, Liquidity)</option>
                </select>
                @error('direction')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
            </div>
        </div>

        {{-- Section 3: Measurement & Ownership --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6">
                <div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">3</div>
                <h2 class="text-lg font-semibold text-[#1A365D]">Measurement & Ownership</h2>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div>
                    <label for="measurement_frequency" class="block text-sm font-medium text-gray-700 mb-2">Measurement Frequency <span class="text-red-500">*</span></label>
                    <select id="measurement_frequency" name="measurement_frequency" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('measurement_frequency') border-red-500 @enderror" required>
                        <option value="">Select Frequency</option>
                        @foreach (['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly'] as $val => $label)
                            <option value="{{ $val }}" {{ old('measurement_frequency') === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('measurement_frequency')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="kri_owner_id" class="block text-sm font-medium text-gray-700 mb-2">KRI Owner <span class="text-red-500">*</span></label>
                    <select id="kri_owner_id" name="kri_owner_id" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('kri_owner_id') border-red-500 @enderror" required>
                        <option value="">Select Owner</option>
                        @foreach (($users ?? []) as $user)
                            <option value="{{ $user->id }}" {{ old('kri_owner_id') == $user->id ? 'selected' : '' }}>{{ $user->name }}</option>
                        @endforeach
                    </select>
                    @error('kri_owner_id')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="data_source" class="block text-sm font-medium text-gray-700 mb-2">Data Source</label>
                    <input type="text" id="data_source" name="data_source" value="{{ old('data_source') }}" placeholder="e.g. Core Banking System, T24"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                    @error('data_source')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="risk_id" class="block text-sm font-medium text-gray-700 mb-2">Linked Risk <span class="text-red-500">*</span></label>
                    <select id="risk_id" name="risk_id" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('risk_id') border-red-500 @enderror" required>
                        <option value="">Select Risk</option>
                        @foreach (($risks ?? []) as $risk)
                            <option value="{{ $risk->id }}" {{ old('risk_id') == $risk->id ? 'selected' : '' }}>{{ $risk->risk_code }} - {{ Str::limit($risk->title, 40) }}</option>
                        @endforeach
                    </select>
                    @error('risk_id')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
            </div>
        </div>

        {{-- Form Actions --}}
        <div class="flex items-center justify-between">
            <a href="{{ route('risk.kri.index') }}" class="px-6 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">Cancel</a>
            <button type="submit" class="px-6 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] transition-colors flex items-center gap-2">
                <span class="material-symbols-outlined text-lg">save</span> Create KRI
            </button>
        </div>
    </form>
@endsection
