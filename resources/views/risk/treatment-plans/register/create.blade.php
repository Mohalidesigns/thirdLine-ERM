@extends('layouts.app')

@section('title', 'Create New Risk - GRC Risk Management')
@section('page-section', 'Risk Register')
@section('page-title', 'Create New Risk')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.register.index') }}" class="hover:text-[#1A365D]">Risk Register</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Create New Risk</span>
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
        <h1 class="text-2xl font-bold text-[#1A365D]">Create New Risk</h1>
        <p class="text-sm text-gray-500 mt-1">Complete all sections to register a new risk in the enterprise register</p>
    </div>

    <form method="POST" action="{{ route('risk.register.store') }}" id="riskForm">
        @csrf

        {{-- Section 1: Basic Information --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6">
                <div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">1</div>
                <h2 class="text-lg font-semibold text-[#1A365D]">Basic Information</h2>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {{-- Risk Title --}}
                <div>
                    <label for="title" class="block text-sm font-medium text-gray-700 mb-2">Risk Title <span class="text-red-500">*</span></label>
                    <input type="text"
                           id="title"
                           name="title"
                           value="{{ old('title') }}"
                           placeholder="e.g. Credit Concentration Risk - Oil & Gas"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('title') border-red-500 @enderror"
                           required>
                    @error('title')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Risk Category --}}
                <div>
                    <label for="category_id" class="block text-sm font-medium text-gray-700 mb-2">Risk Category <span class="text-red-500">*</span></label>
                    <select id="category_id"
                            name="category_id"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('category_id') border-red-500 @enderror"
                            required>
                        <option value="">Select Category</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id ?? $category }}" {{ old('category_id') == ($category->id ?? $category) ? 'selected' : '' }}>
                                {{ $category->name ?? $category }}
                            </option>
                        @endforeach
                    </select>
                    @error('category_id')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Risk Description (Cause-Event-Effect) --}}
                <div class="lg:col-span-2">
                    <label for="description" class="block text-sm font-medium text-gray-700 mb-2">Risk Statement (Cause-Event-Effect) <span class="text-red-500">*</span></label>
                    <textarea id="description"
                              name="description"
                              rows="4"
                              placeholder="Cause: [What causes the risk]&#10;Event: [What could happen]&#10;Effect: [What is the impact]"
                              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] font-mono text-xs @error('description') border-red-500 @enderror"
                              required>{{ old('description') }}</textarea>
                    @error('description')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Risk Source --}}
                <div>
                    <label for="risk_source" class="block text-sm font-medium text-gray-700 mb-2">Risk Source</label>
                    <select id="risk_source"
                            name="risk_source"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        <option value="">Select Source</option>
                        @foreach (['RCSA', 'Audit Finding', 'Incident', 'Regulatory', 'Self-Identified', 'External', 'KRI Breach'] as $source)
                            <option value="{{ $source }}" {{ old('risk_source') === $source ? 'selected' : '' }}>{{ $source }}</option>
                        @endforeach
                    </select>
                    @error('risk_source')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Business Unit --}}
                <div>
                    <label for="business_unit_id" class="block text-sm font-medium text-gray-700 mb-2">Business Unit <span class="text-red-500">*</span></label>
                    <select id="business_unit_id"
                            name="business_unit_id"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('business_unit_id') border-red-500 @enderror"
                            required>
                        <option value="">Select Business Unit</option>
                        @foreach ($businessUnits as $unit)
                            <option value="{{ $unit->id ?? $unit }}" {{ old('business_unit_id') == ($unit->id ?? $unit) ? 'selected' : '' }}>
                                {{ $unit->name ?? $unit }}
                            </option>
                        @endforeach
                    </select>
                    @error('business_unit_id')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Process --}}
                <div>
                    <label for="process_id" class="block text-sm font-medium text-gray-700 mb-2">Process</label>
                    <select id="process_id"
                            name="process_id"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        <option value="">Select Process</option>
                        @foreach ($processes as $process)
                            <option value="{{ $process->id ?? $process }}" {{ old('process_id') == ($process->id ?? $process) ? 'selected' : '' }}>
                                {{ $process->name ?? $process }}
                            </option>
                        @endforeach
                    </select>
                    @error('process_id')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </div>

        {{-- Section 2: Risk Owner --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6">
                <div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">2</div>
                <h2 class="text-lg font-semibold text-[#1A365D]">Risk Ownership</h2>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {{-- Risk Owner --}}
                <div>
                    <label for="risk_owner_id" class="block text-sm font-medium text-gray-700 mb-2">Risk Owner <span class="text-red-500">*</span></label>
                    <select id="risk_owner_id"
                            name="risk_owner_id"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('risk_owner_id') border-red-500 @enderror"
                            required>
                        <option value="">Select Risk Owner</option>
                        @foreach ($users as $user)
                            <option value="{{ $user->id }}" {{ old('risk_owner_id') == $user->id ? 'selected' : '' }}>
                                {{ $user->name }}{{ $user->role ? ' (' . $user->role . ')' : '' }}
                            </option>
                        @endforeach
                    </select>
                    @error('risk_owner_id')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Risk Steward --}}
                <div>
                    <label for="risk_steward_id" class="block text-sm font-medium text-gray-700 mb-2">Risk Steward</label>
                    <select id="risk_steward_id"
                            name="risk_steward_id"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        <option value="">Select Risk Steward</option>
                        @foreach ($users as $user)
                            <option value="{{ $user->id }}" {{ old('risk_steward_id') == $user->id ? 'selected' : '' }}>
                                {{ $user->name }}{{ $user->role ? ' (' . $user->role . ')' : '' }}
                            </option>
                        @endforeach
                    </select>
                    @error('risk_steward_id')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Identified By --}}
                <div>
                    <label for="identified_by" class="block text-sm font-medium text-gray-700 mb-2">Identified By</label>
                    <select id="identified_by"
                            name="identified_by"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        <option value="">Select Person</option>
                        @foreach ($users as $user)
                            <option value="{{ $user->id }}" {{ old('identified_by') == $user->id ? 'selected' : '' }}>
                                {{ $user->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('identified_by')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Date Identified --}}
                <div>
                    <label for="date_identified" class="block text-sm font-medium text-gray-700 mb-2">Date Identified <span class="text-red-500">*</span></label>
                    <input type="date"
                           id="date_identified"
                           name="date_identified"
                           value="{{ old('date_identified', now()->format('Y-m-d')) }}"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('date_identified') border-red-500 @enderror"
                           required>
                    @error('date_identified')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </div>

        {{-- Section 3: Initial Assessment --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6">
                <div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">3</div>
                <h2 class="text-lg font-semibold text-[#1A365D]">Initial Assessment (5x5 Matrix)</h2>
            </div>

            {{-- Inherent Likelihood --}}
            <div class="mb-8">
                <label class="block text-sm font-medium text-gray-700 mb-4">Inherent Likelihood (1-5) <span class="text-red-500">*</span></label>
                <div class="flex flex-wrap gap-2">
                    @foreach ([1 => 'Rare', 2 => 'Unlikely', 3 => 'Possible', 4 => 'Likely', 5 => 'Almost Certain'] as $score => $label)
                        <label class="cursor-pointer">
                            <input type="radio" name="inherent_likelihood" value="{{ $score }}" class="sr-only peer" {{ old('inherent_likelihood') == $score ? 'checked' : '' }} required>
                            <div class="px-4 py-2 border-2 border-gray-300 rounded-lg text-sm font-medium peer-checked:border-[#1A365D] peer-checked:bg-[#F0F4F8] peer-checked:text-[#1A365D] hover:border-[#1A365D]/50 transition-colors">
                                {{ $score }}: {{ $label }}
                            </div>
                        </label>
                    @endforeach
                </div>
                @error('inherent_likelihood')
                    <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                @enderror
            </div>

            {{-- Impact Dimensions --}}
            <div class="mb-8">
                <label class="block text-sm font-medium text-gray-700 mb-4">Impact Dimensions (1-5 each) <span class="text-red-500">*</span></label>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    @foreach ([
                        'impact_financial' => ['Financial Impact', 'Direct financial loss exposure'],
                        'impact_operational' => ['Operational Impact', 'Business continuity disruption'],
                        'impact_reputational' => ['Reputational Impact', 'Brand & customer trust damage'],
                        'impact_regulatory' => ['Regulatory Impact', 'CBN penalties & sanctions exposure'],
                    ] as $field => [$label, $hint])
                        <div>
                            <p class="text-sm font-semibold text-gray-700 mb-2">{{ $label }}</p>
                            <select name="{{ $field }}"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error($field) border-red-500 @enderror"
                                    required>
                                <option value="">Select Score</option>
                                @foreach ([1 => 'Insignificant', 2 => 'Minor', 3 => 'Moderate', 4 => 'Major', 5 => 'Catastrophic'] as $score => $impactLabel)
                                    <option value="{{ $score }}" {{ old($field) == $score ? 'selected' : '' }}>{{ $score }}: {{ $impactLabel }}</option>
                                @endforeach
                            </select>
                            <p class="text-xs text-gray-500 mt-1">{{ $hint }}</p>
                            @error($field)
                                <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Inherent Risk Score Preview --}}
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                <p class="text-sm text-gray-600">Inherent Risk Score (Auto-Calculated)</p>
                <div class="flex items-baseline gap-2">
                    <span class="text-3xl font-bold text-[#1A365D]" id="inherentScorePreview">0</span>
                    <span class="text-sm text-gray-600">/25 (Likelihood x Max Impact)</span>
                </div>
            </div>
        </div>

        {{-- Section 4: Treatment --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6">
                <div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">4</div>
                <h2 class="text-lg font-semibold text-[#1A365D]">Treatment Strategy</h2>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {{-- Treatment Strategy --}}
                <div>
                    <label for="treatment_strategy" class="block text-sm font-medium text-gray-700 mb-2">Treatment Strategy <span class="text-red-500">*</span></label>
                    <select id="treatment_strategy"
                            name="treatment_strategy"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('treatment_strategy') border-red-500 @enderror"
                            required>
                        <option value="">Select Strategy</option>
                        @foreach (['Mitigate' => 'Reduce likelihood or impact', 'Accept' => 'Accept within appetite', 'Transfer' => 'Insurance or outsourcing', 'Avoid' => 'Exit the risk activity'] as $strategy => $description)
                            <option value="{{ strtolower($strategy) }}" {{ old('treatment_strategy') === strtolower($strategy) ? 'selected' : '' }}>
                                {{ $strategy }} - {{ $description }}
                            </option>
                        @endforeach
                    </select>
                    @error('treatment_strategy')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Risk Velocity --}}
                <div>
                    <label for="risk_velocity" class="block text-sm font-medium text-gray-700 mb-2">Risk Velocity</label>
                    <select id="risk_velocity"
                            name="risk_velocity"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        <option value="">Select Velocity</option>
                        @foreach (['Immediate' => '< 1 day', 'Fast' => '1-7 days', 'Moderate' => '1-3 months', 'Slow' => '3-12 months', 'Very Slow' => '> 12 months'] as $velocity => $desc)
                            <option value="{{ strtolower(str_replace(' ', '_', $velocity)) }}" {{ old('risk_velocity') === strtolower(str_replace(' ', '_', $velocity)) ? 'selected' : '' }}>
                                {{ $velocity }} ({{ $desc }})
                            </option>
                        @endforeach
                    </select>
                    @error('risk_velocity')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Review Frequency --}}
                <div>
                    <label for="review_frequency" class="block text-sm font-medium text-gray-700 mb-2">Review Frequency</label>
                    <select id="review_frequency"
                            name="review_frequency"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        <option value="">Select Frequency</option>
                        @foreach (['Monthly', 'Quarterly', 'Semi-Annual', 'Annual'] as $freq)
                            <option value="{{ strtolower(str_replace('-', '_', $freq)) }}" {{ old('review_frequency') === strtolower(str_replace('-', '_', $freq)) ? 'selected' : '' }}>
                                {{ $freq }}
                            </option>
                        @endforeach
                    </select>
                    @error('review_frequency')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </div>

        {{-- Section 5: Additional --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6">
                <div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">5</div>
                <h2 class="text-lg font-semibold text-[#1A365D]">Additional Information</h2>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {{-- Regulatory Mapping Tags --}}
                <div class="lg:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Regulatory Mapping</label>
                    <div class="flex flex-wrap gap-4">
                        @foreach (['CBN ORMS', 'Basel III', 'NDPA', 'NFIU', 'ISO 31000', 'COSO ERM'] as $tag)
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox"
                                       name="regulatory_tags[]"
                                       value="{{ $tag }}"
                                       class="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                                       {{ in_array($tag, old('regulatory_tags', [])) ? 'checked' : '' }}>
                                <span class="text-sm text-gray-700">{{ $tag }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('regulatory_tags')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Financial Exposure --}}
                <div>
                    <label for="financial_exposure" class="block text-sm font-medium text-gray-700 mb-2">Financial Exposure Estimate</label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 text-sm font-medium">&#8358;</span>
                        <input type="number"
                               id="financial_exposure"
                               name="financial_exposure"
                               value="{{ old('financial_exposure') }}"
                               placeholder="e.g. 50000000"
                               class="w-full pl-8 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                    </div>
                    <p class="text-xs text-gray-500 mt-1">Maximum potential financial loss in Naira</p>
                    @error('financial_exposure')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Risk Appetite Category --}}
                <div>
                    <label for="appetite_category" class="block text-sm font-medium text-gray-700 mb-2">Risk Appetite Category</label>
                    <select id="appetite_category"
                            name="appetite_category"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        <option value="">Select Appetite Level</option>
                        @foreach (['Minimal' => 'Near zero tolerance', 'Conservative' => 'Tightly controlled', 'Moderate' => 'Balanced approach', 'Growth' => 'Calculated risks', 'Aggressive' => 'Higher risk tolerance'] as $level => $desc)
                            <option value="{{ strtolower($level) }}" {{ old('appetite_category') === strtolower($level) ? 'selected' : '' }}>
                                {{ $level }} - {{ $desc }}
                            </option>
                        @endforeach
                    </select>
                    @error('appetite_category')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Notes --}}
                <div class="lg:col-span-2">
                    <label for="notes" class="block text-sm font-medium text-gray-700 mb-2">Additional Notes</label>
                    <textarea id="notes"
                              name="notes"
                              rows="3"
                              placeholder="Any additional context, references, or notes..."
                              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">{{ old('notes') }}</textarea>
                    @error('notes')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </div>

        {{-- Form Actions --}}
        <div class="flex items-center justify-between">
            <a href="{{ route('risk.register.index') }}"
               class="px-6 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                Cancel
            </a>
            <div class="flex gap-2">
                <button type="submit"
                        name="action"
                        value="draft"
                        class="px-6 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                    Save as Draft
                </button>
                <button type="submit"
                        name="action"
                        value="submit"
                        class="px-6 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] transition-colors flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">save</span> Submit Risk
                </button>
            </div>
        </div>
    </form>

@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        /**
         * Auto-calculate inherent risk score from likelihood and max impact dimension.
         */
        function calculateInherentScore() {
            const likelihood = document.querySelector('input[name="inherent_likelihood"]:checked');
            const impactFields = ['impact_financial', 'impact_operational', 'impact_reputational', 'impact_regulatory'];
            let maxImpact = 0;

            impactFields.forEach(function(field) {
                const select = document.querySelector('select[name="' + field + '"]');
                if (select && parseInt(select.value) > maxImpact) {
                    maxImpact = parseInt(select.value);
                }
            });

            const likelihoodVal = likelihood ? parseInt(likelihood.value) : 0;
            const score = likelihoodVal * maxImpact;
            const preview = document.getElementById('inherentScorePreview');
            if (preview) {
                preview.textContent = score;
            }
        }

        // Bind events to likelihood radio buttons
        document.querySelectorAll('input[name="inherent_likelihood"]').forEach(function(radio) {
            radio.addEventListener('change', calculateInherentScore);
        });

        // Bind events to impact select fields
        ['impact_financial', 'impact_operational', 'impact_reputational', 'impact_regulatory'].forEach(function(field) {
            const select = document.querySelector('select[name="' + field + '"]');
            if (select) {
                select.addEventListener('change', calculateInherentScore);
            }
        });

        // Calculate on page load if old values exist
        calculateInherentScore();
    });
</script>
@endpush
