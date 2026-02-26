@extends('layouts.app')

@section('title', 'Edit Risk ' . $risk->risk_code . ' - GRC Risk Management')
@section('page-section', 'Risk Register')
@section('page-title', 'Edit Risk')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="text-gray-500 hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.register.index') }}" class="text-gray-500 hover:text-[#1A365D]">Risk Register</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.register.show', $risk) }}" class="text-gray-500 hover:text-[#1A365D]">{{ $risk->risk_code }}</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Edit</span>
@endsection

@section('content')
    {{-- Validation Errors --}}
    @if ($errors->any())
        <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl">
            <div class="flex items-center gap-2 mb-2">
                <span class="material-symbols-outlined text-red-600">error</span>
                <span class="text-sm font-semibold text-red-700">Please correct the following errors:</span>
            </div>
            <ul class="list-disc list-inside text-sm text-red-600 space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Page Header --}}
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-[#1A365D]">Edit Risk: {{ $risk->title }}</h1>
        <p class="text-sm text-gray-500 mt-1">Code: {{ $risk->risk_code }}</p>
    </div>

    <form method="POST" action="{{ route('risk.register.update', $risk) }}">
        @csrf
        @method('PUT')

        {{-- Section 1: Risk Identification --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6">
                <div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">1</div>
                <h2 class="text-lg font-semibold text-[#1A365D]">Risk Identification</h2>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {{-- Risk Code --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Risk ID</label>
                    <input type="text" value="{{ $risk->risk_code }}" disabled
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm bg-gray-50 text-gray-500">
                </div>

                {{-- Risk Title --}}
                <div>
                    <label for="title" class="block text-sm font-medium text-gray-700 mb-2">
                        Risk Name <span class="text-red-500">*</span>
                    </label>
                    <input type="text" id="title" name="title" value="{{ old('title', $risk->title) }}"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('title') border-red-500 @enderror" required>
                    @error('title')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>

                {{-- Description --}}
                <div class="lg:col-span-2">
                    <label for="description" class="block text-sm font-medium text-gray-700 mb-2">
                        Risk Description <span class="text-red-500">*</span>
                    </label>
                    <textarea id="description" name="description" rows="4"
                              class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('description') border-red-500 @enderror" required>{{ old('description', $risk->description) }}</textarea>
                    @error('description')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>

                {{-- Risk Category --}}
                <div>
                    <label for="category_id" class="block text-sm font-medium text-gray-700 mb-2">
                        Risk Category <span class="text-red-500">*</span>
                    </label>
                    <select id="category_id" name="category_id" required
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('category_id') border-red-500 @enderror">
                        <option value="">Select Category</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" {{ old('category_id', $risk->category_id) == $category->id ? 'selected' : '' }}>
                                {{ $category->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('category_id')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>

                {{-- Business Unit --}}
                <div>
                    <label for="business_unit_id" class="block text-sm font-medium text-gray-700 mb-2">
                        Business Unit <span class="text-red-500">*</span>
                    </label>
                    <select id="business_unit_id" name="business_unit_id" required
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('business_unit_id') border-red-500 @enderror">
                        <option value="">Select Business Unit</option>
                        @foreach ($businessUnits as $unit)
                            <option value="{{ $unit->id }}" {{ old('business_unit_id', $risk->business_unit_id) == $unit->id ? 'selected' : '' }}>
                                {{ $unit->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('business_unit_id')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>

                {{-- Business Process --}}
                <div>
                    <label for="process_id" class="block text-sm font-medium text-gray-700 mb-2">Business Process</label>
                    <select id="process_id" name="process_id"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        <option value="">Select Process (Optional)</option>
                        @foreach ($processes as $proc)
                            <option value="{{ $proc->id }}" {{ old('process_id', $risk->process_id) == $proc->id ? 'selected' : '' }}>
                                {{ $proc->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                {{-- Risk Source --}}
                <div>
                    <label for="risk_source" class="block text-sm font-medium text-gray-700 mb-2">Risk Source</label>
                    <select id="risk_source" name="risk_source"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        <option value="">Select Source</option>
                        @foreach (['Self-Identified' => 'Self-Identified', 'Audit Finding' => 'Audit Finding', 'Regulatory' => 'Regulatory', 'Incident' => 'Incident', 'RCSA' => 'RCSA', 'External' => 'External'] as $val => $label)
                            <option value="{{ $val }}" {{ old('risk_source', $risk->risk_source) === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Risk Owner --}}
                <div>
                    <label for="risk_owner_id" class="block text-sm font-medium text-gray-700 mb-2">
                        Risk Owner <span class="text-red-500">*</span>
                    </label>
                    <select id="risk_owner_id" name="risk_owner_id" required
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('risk_owner_id') border-red-500 @enderror">
                        <option value="">Select Owner</option>
                        @foreach ($users as $user)
                            <option value="{{ $user->id }}" {{ old('risk_owner_id', $risk->risk_owner_id) == $user->id ? 'selected' : '' }}>
                                {{ $user->name }} ({{ $user->email }})
                            </option>
                        @endforeach
                    </select>
                    @error('risk_owner_id')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>

                {{-- Risk Steward --}}
                <div>
                    <label for="risk_steward_id" class="block text-sm font-medium text-gray-700 mb-2">Risk Steward</label>
                    <select id="risk_steward_id" name="risk_steward_id"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        <option value="">None</option>
                        @foreach ($users as $user)
                            <option value="{{ $user->id }}" {{ old('risk_steward_id', $risk->risk_steward_id) == $user->id ? 'selected' : '' }}>
                                {{ $user->name }} ({{ $user->email }})
                            </option>
                        @endforeach
                    </select>
                </div>

                {{-- Date Identified --}}
                <div>
                    <label for="date_identified" class="block text-sm font-medium text-gray-700 mb-2">Date Identified</label>
                    <input type="date" id="date_identified" name="date_identified"
                           value="{{ old('date_identified', $risk->date_identified instanceof \Carbon\Carbon ? $risk->date_identified->format('Y-m-d') : $risk->date_identified) }}"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                </div>

                {{-- Identified By --}}
                <div>
                    <label for="identified_by" class="block text-sm font-medium text-gray-700 mb-2">Identified By</label>
                    <select id="identified_by" name="identified_by"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        <option value="">Select Person</option>
                        @foreach ($users as $user)
                            <option value="{{ $user->id }}" {{ old('identified_by', $risk->identified_by) == $user->id ? 'selected' : '' }}>
                                {{ $user->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        {{-- Section 2: Risk Assessment --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6">
                <div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">2</div>
                <h2 class="text-lg font-semibold text-[#1A365D]">Risk Assessment (5x5 Matrix)</h2>
            </div>

            {{-- Likelihood --}}
            <div class="mb-6">
                <label for="inherent_likelihood" class="block text-sm font-medium text-gray-700 mb-2">
                    Likelihood (1-5) <span class="text-red-500">*</span>
                </label>
                <select id="inherent_likelihood" name="inherent_likelihood" required
                        class="w-full max-w-md px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('inherent_likelihood') border-red-500 @enderror">
                    <option value="">Select Likelihood</option>
                    @foreach ([1 => '1 - Rare', 2 => '2 - Unlikely', 3 => '3 - Possible', 4 => '4 - Likely', 5 => '5 - Almost Certain'] as $val => $label)
                        <option value="{{ $val }}" {{ old('inherent_likelihood', $risk->inherent_likelihood) == $val ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
                @error('inherent_likelihood')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
            </div>

            {{-- Multi-Dimension Impact --}}
            <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                <h4 class="text-sm font-semibold text-[#1A365D] mb-4">Multi-Dimension Impact Assessment</h4>
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    @php
                        $impactDimensions = [
                            'impact_financial' => ['label' => 'Financial Impact', 'hint' => 'Direct financial loss (NGN)', 'current' => $risk->inherent_impact_financial],
                            'impact_operational' => ['label' => 'Operational Impact', 'hint' => 'Business continuity disruption', 'current' => $risk->inherent_impact_operational],
                            'impact_reputational' => ['label' => 'Reputational Impact', 'hint' => 'Brand & customer trust', 'current' => $risk->inherent_impact_reputational],
                            'impact_regulatory' => ['label' => 'Regulatory Impact', 'hint' => 'CBN penalties & sanctions', 'current' => $risk->inherent_impact_regulatory],
                        ];
                        $impactLevels = [1 => '1 - Insignificant', 2 => '2 - Minor', 3 => '3 - Moderate', 4 => '4 - Major', 5 => '5 - Catastrophic'];
                    @endphp

                    @foreach ($impactDimensions as $field => $meta)
                        <div>
                            <label class="block text-xs font-semibold text-gray-600 mb-1.5">
                                {{ $meta['label'] }} <span class="text-red-500">*</span>
                            </label>
                            <select name="{{ $field }}" required
                                    class="w-full px-4 py-2 border border-gray-200 rounded-lg text-sm bg-white focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error($field) border-red-500 @enderror">
                                <option value="">Select</option>
                                @foreach ($impactLevels as $val => $label)
                                    <option value="{{ $val }}" {{ old($field, $meta['current']) == $val ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                            <p class="text-xs text-gray-500 mt-1">{{ $meta['hint'] }}</p>
                            @error($field)<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Current Score Display --}}
            <div class="mt-4 bg-blue-50 border border-blue-200 rounded-lg p-4">
                <div class="flex items-center gap-4">
                    <div>
                        <p class="text-sm text-gray-600">Current Inherent Score</p>
                        <p class="text-2xl font-bold text-[#1A365D]">{{ $risk->inherent_score ?? 0 }}/25</p>
                    </div>
                    <x-risk-badge :rating="$risk->inherent_rating ?? 'medium'" />
                </div>
            </div>
        </div>

        {{-- Section 3: Treatment & Classification --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6">
                <div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">3</div>
                <h2 class="text-lg font-semibold text-[#1A365D]">Treatment & Classification</h2>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {{-- Treatment Strategy --}}
                <div>
                    <label for="treatment_strategy" class="block text-sm font-medium text-gray-700 mb-2">Treatment Strategy</label>
                    <select id="treatment_strategy" name="treatment_strategy"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        <option value="">Select Strategy</option>
                        @foreach (['mitigate' => 'Mitigate', 'accept' => 'Accept', 'transfer' => 'Transfer', 'avoid' => 'Avoid'] as $val => $label)
                            <option value="{{ $val }}" {{ old('treatment_strategy', $risk->treatment_strategy) === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Status --}}
                <div>
                    <label for="status" class="block text-sm font-medium text-gray-700 mb-2">
                        Status <span class="text-red-500">*</span>
                    </label>
                    <select id="status" name="status" required
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('status') border-red-500 @enderror">
                        @foreach (['active' => 'Active', 'dormant' => 'Dormant', 'closed' => 'Closed', 'retired' => 'Retired'] as $val => $label)
                            <option value="{{ $val }}" {{ old('status', $risk->status) === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('status')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>

                {{-- Risk Velocity --}}
                <div>
                    <label for="risk_velocity" class="block text-sm font-medium text-gray-700 mb-2">Risk Velocity</label>
                    <select id="risk_velocity" name="risk_velocity"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        <option value="">Select Velocity</option>
                        @foreach (['immediate' => 'Immediate (< 1 day)', 'days' => 'Days (1-7 days)', 'weeks' => 'Weeks (1-4 weeks)', 'months' => 'Months (1-6 months)', 'years' => 'Years (> 6 months)'] as $val => $label)
                            <option value="{{ $val }}" {{ old('risk_velocity', $risk->risk_velocity) === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Review Frequency --}}
                <div>
                    <label for="review_frequency" class="block text-sm font-medium text-gray-700 mb-2">Review Frequency</label>
                    <select id="review_frequency" name="review_frequency"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        <option value="">Select Frequency</option>
                        @foreach (['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'semi-annual' => 'Semi-Annual', 'annual' => 'Annual'] as $val => $label)
                            <option value="{{ $val }}" {{ old('review_frequency', $risk->review_frequency) === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Financial Exposure --}}
                <div>
                    <label for="financial_exposure" class="block text-sm font-medium text-gray-700 mb-2">Financial Exposure (NGN)</label>
                    <input type="number" id="financial_exposure" name="financial_exposure"
                           value="{{ old('financial_exposure', $risk->financial_exposure_ngn) }}"
                           step="0.01" min="0"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                </div>
            </div>
        </div>

        {{-- Section 4: Regulatory Alignment & Notes --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6">
                <div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">4</div>
                <h2 class="text-lg font-semibold text-[#1A365D]">Regulatory Alignment & Notes</h2>
            </div>

            @php
                $frameworks = ['CBN ORMS', 'Basel III', 'NDPA', 'NFIU', 'BOFIA', 'SEC Rules'];
                $currentFrameworks = old('regulatory_tags', $risk->regulatory_mapping ?? []);
            @endphp

            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-3">Regulatory Frameworks</label>
                <div class="grid grid-cols-2 lg:grid-cols-3 gap-4">
                    @foreach ($frameworks as $framework)
                        <label class="flex items-center gap-3 p-3 bg-gray-50 rounded-lg hover:bg-blue-50 cursor-pointer transition-colors">
                            <input type="checkbox" name="regulatory_tags[]" value="{{ $framework }}"
                                   {{ in_array($framework, $currentFrameworks) ? 'checked' : '' }}
                                   class="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]">
                            <span class="text-sm font-medium text-gray-700">{{ $framework }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div>
                <label for="notes" class="block text-sm font-medium text-gray-700 mb-2">Additional Notes</label>
                <textarea id="notes" name="notes" rows="3"
                          class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">{{ old('notes', $risk->appetite_notes) }}</textarea>
            </div>
        </div>

        {{-- Form Actions --}}
        <div class="flex items-center justify-between">
            <a href="{{ route('risk.register.show', $risk) }}" class="px-6 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">
                Cancel
            </a>
            <button type="submit" class="px-6 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-2">
                <span class="material-symbols-outlined text-lg">save</span>
                Update Risk
            </button>
        </div>
    </form>
@endsection
