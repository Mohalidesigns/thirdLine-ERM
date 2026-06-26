@extends('layouts.app')

@section('title', 'Create New Risk - GRC Risk Management')
@section('page-section', 'Risk Register')
@section('page-title', 'Create New Risk')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="text-gray-500 hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.register.index') }}" class="text-gray-500 hover:text-[#1A365D]">Risk Register</a>
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
        <p class="text-sm text-gray-500 mt-1">Risk Identification & Assessment</p>
    </div>

    {{-- AI Risk Statement Builder --}}
    <div x-data="riskStatementBuilder()" class="mb-6 bg-gradient-to-br from-[#1A365D] to-[#2c4a7a] rounded-xl p-5 text-white shadow-lg">
        <div class="flex items-start gap-3 mb-3">
            <div class="w-9 h-9 rounded-lg bg-[#D4AF37]/20 border border-[#D4AF37]/40 flex items-center justify-center flex-shrink-0">
                <span class="material-symbols-outlined text-[#D4AF37]" style="font-size: 20px;">auto_awesome</span>
            </div>
            <div class="flex-1">
                <div class="flex items-center gap-2">
                    <h3 class="text-sm font-semibold">AI Risk Statement Builder</h3>
                    <span class="text-[10px] px-2 py-0.5 rounded-full bg-[#D4AF37]/20 text-[#D4AF37] border border-[#D4AF37]/40">Local LLM · Granite</span>
                </div>
                <p class="text-xs text-white/70 mt-0.5">Describe the scenario in plain English — the model drafts a board-ready Cause → Event → Consequence statement and prefills the form.</p>
            </div>
        </div>
        <div class="flex gap-2">
            <input type="text" x-model="scenario" x-ref="scenarioInput"
                   @keydown.enter.prevent="draft()"
                   placeholder='e.g. "Phishing on tellers stealing customer OTPs" or "Exposure to single oil & gas borrower above single-obligor limit"'
                   class="flex-1 px-3 py-2 rounded-lg bg-white/10 border border-white/20 text-white placeholder-white/40 text-sm focus:outline-none focus:ring-2 focus:ring-[#D4AF37]/60">
            <button type="button" @click="draft()" :disabled="loading || scenario.length < 3"
                    class="px-4 py-2 rounded-lg bg-[#D4AF37] hover:bg-[#c09e2d] disabled:opacity-40 disabled:cursor-not-allowed text-[#1A365D] text-sm font-semibold flex items-center gap-1.5 transition">
                <span x-show="!loading" class="material-symbols-outlined" style="font-size: 16px;">bolt</span>
                <span x-show="loading" class="material-symbols-outlined animate-spin" style="font-size: 16px;">progress_activity</span>
                <span x-text="loading ? 'Drafting…' : 'Draft with AI'"></span>
            </button>
        </div>
        <div x-show="error" x-cloak class="mt-3 text-xs bg-red-500/20 border border-red-400/40 rounded-lg px-3 py-2 text-red-100">
            <span class="font-semibold">AI unavailable.</span> <span x-text="error"></span> You can fill the form manually below.
        </div>
        <div x-show="lastResult" x-cloak class="mt-3 text-xs text-white/80">
            <span class="material-symbols-outlined align-middle" style="font-size: 14px;">check_circle</span>
            Draft inserted. Review and edit before saving. Generated in <span x-text="lastResult?.elapsed_ms"></span>ms.
        </div>
    </div>

    <form method="POST" action="{{ route('risk.register.store') }}">
        @csrf

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
                    <input type="text" value="AUTO-GENERATED" disabled
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm bg-gray-50 text-gray-500">
                    <p class="text-xs text-gray-500 mt-1">Automatically assigned on save</p>
                </div>

                {{-- Risk Title --}}
                <div>
                    <label for="title" class="block text-sm font-medium text-gray-700 mb-2">
                        Risk Name <span class="text-red-500">*</span>
                    </label>
                    <input type="text" id="title" name="title" value="{{ old('title') }}"
                           placeholder="e.g. Credit Concentration Risk - Oil & Gas"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('title') border-red-500 @enderror" required>
                    @error('title')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>

                {{-- Description --}}
                <div class="lg:col-span-2">
                    <label for="description" class="block text-sm font-medium text-gray-700 mb-2">
                        Risk Description <span class="text-red-500">*</span>
                    </label>
                    <textarea id="description" name="description" rows="4"
                              placeholder="Cause: [What causes the risk]&#10;Event: [What could happen]&#10;Effect: [What is the impact]"
                              class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('description') border-red-500 @enderror" required>{{ old('description') }}</textarea>
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
                            <option value="{{ $category->id }}" {{ old('category_id') == $category->id ? 'selected' : '' }}>
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
                            <option value="{{ $unit->id }}" {{ old('business_unit_id') == $unit->id ? 'selected' : '' }}>
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
                            <option value="{{ $proc->id }}" {{ old('process_id') == $proc->id ? 'selected' : '' }}>
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
                            <option value="{{ $val }}" {{ old('risk_source') === $val ? 'selected' : '' }}>{{ $label }}</option>
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
                            <option value="{{ $user->id }}" {{ old('risk_owner_id') == $user->id ? 'selected' : '' }}>
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
                            <option value="{{ $user->id }}" {{ old('risk_steward_id') == $user->id ? 'selected' : '' }}>
                                {{ $user->name }} ({{ $user->email }})
                            </option>
                        @endforeach
                    </select>
                </div>

                {{-- Date Identified --}}
                <div>
                    <label for="date_identified" class="block text-sm font-medium text-gray-700 mb-2">Date Identified</label>
                    <input type="date" id="date_identified" name="date_identified" value="{{ old('date_identified', date('Y-m-d')) }}"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                </div>

                {{-- Identified By --}}
                <div>
                    <label for="identified_by" class="block text-sm font-medium text-gray-700 mb-2">Identified By</label>
                    <select id="identified_by" name="identified_by"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        <option value="">Select Person</option>
                        @foreach ($users as $user)
                            <option value="{{ $user->id }}" {{ old('identified_by') == $user->id ? 'selected' : '' }}>
                                {{ $user->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        {{-- Section 2: Risk Assessment (5x5 Matrix) --}}
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
                        <option value="{{ $val }}" {{ old('inherent_likelihood') == $val ? 'selected' : '' }}>{{ $label }}</option>
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
                            'impact_financial' => ['label' => 'Financial Impact', 'hint' => 'Direct financial loss (NGN)'],
                            'impact_operational' => ['label' => 'Operational Impact', 'hint' => 'Business continuity disruption'],
                            'impact_reputational' => ['label' => 'Reputational Impact', 'hint' => 'Brand & customer trust'],
                            'impact_regulatory' => ['label' => 'Regulatory Impact', 'hint' => 'CBN penalties & sanctions'],
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
                                    <option value="{{ $val }}" {{ old($field) == $val ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                            <p class="text-xs text-gray-500 mt-1">{{ $meta['hint'] }}</p>
                            @error($field)<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Inherent Score Preview --}}
            <div class="mt-4 bg-blue-50 border border-blue-200 rounded-lg p-4">
                <p class="text-sm text-gray-600">Inherent Risk Score</p>
                <p class="text-xs text-gray-500">Auto-calculated: Likelihood x max(Impact dimensions) = Score /25</p>
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
                        @foreach (['mitigate' => 'Mitigate - Reduce likelihood or impact', 'accept' => 'Accept - Accept within appetite', 'transfer' => 'Transfer - Insurance or outsourcing', 'avoid' => 'Avoid - Exit the risk activity'] as $val => $label)
                            <option value="{{ $val }}" {{ old('treatment_strategy') === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Risk Type --}}
                <div>
                    <label for="risk_type" class="block text-sm font-medium text-gray-700 mb-2">Risk Type</label>
                    <select id="risk_type" name="risk_type"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        <option value="">Select Type</option>
                        @foreach (['strategic' => 'Strategic', 'operational' => 'Operational', 'financial' => 'Financial', 'compliance' => 'Compliance', 'technology' => 'Technology', 'reputational' => 'Reputational'] as $val => $label)
                            <option value="{{ $val }}" {{ old('risk_type') === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Risk Velocity --}}
                <div>
                    <label for="risk_velocity" class="block text-sm font-medium text-gray-700 mb-2">Risk Velocity</label>
                    <select id="risk_velocity" name="risk_velocity"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        <option value="">Select Velocity</option>
                        @foreach (['immediate' => 'Immediate (< 1 day)', 'days' => 'Days (1-7 days)', 'weeks' => 'Weeks (1-4 weeks)', 'months' => 'Months (1-6 months)', 'years' => 'Years (> 6 months)'] as $val => $label)
                            <option value="{{ $val }}" {{ old('risk_velocity') === $val ? 'selected' : '' }}>{{ $label }}</option>
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
                            <option value="{{ $val }}" {{ old('review_frequency') === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Financial Exposure --}}
                <div>
                    <label for="financial_exposure" class="block text-sm font-medium text-gray-700 mb-2">Financial Exposure (NGN)</label>
                    <input type="number" id="financial_exposure" name="financial_exposure" value="{{ old('financial_exposure') }}"
                           placeholder="e.g. 50000000" step="0.01" min="0"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                    <p class="text-xs text-gray-500 mt-1">Estimated financial exposure in Naira</p>
                </div>

                {{-- Status --}}
                <div>
                    <label for="status" class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                    <select id="status" name="status"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        @foreach (['active' => 'Active', 'dormant' => 'Dormant'] as $val => $label)
                            <option value="{{ $val }}" {{ old('status', 'active') === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
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
                $oldFrameworks = old('regulatory_tags', []);
            @endphp

            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-3">Regulatory Frameworks</label>
                <div class="grid grid-cols-2 lg:grid-cols-3 gap-4">
                    @foreach ($frameworks as $framework)
                        <label class="flex items-center gap-3 p-3 bg-gray-50 rounded-lg hover:bg-blue-50 cursor-pointer transition-colors">
                            <input type="checkbox" name="regulatory_tags[]" value="{{ $framework }}"
                                   {{ in_array($framework, $oldFrameworks) ? 'checked' : '' }}
                                   class="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]">
                            <span class="text-sm font-medium text-gray-700">{{ $framework }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div>
                <label for="notes" class="block text-sm font-medium text-gray-700 mb-2">Additional Notes</label>
                <textarea id="notes" name="notes" rows="3"
                          placeholder="Any additional risk appetite notes, context, or remarks..."
                          class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">{{ old('notes') }}</textarea>
            </div>
        </div>

        {{-- Form Actions --}}
        <div class="flex items-center justify-between">
            <a href="{{ route('risk.register.index') }}" class="px-6 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">
                Cancel
            </a>
            <button type="submit" class="px-6 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-2">
                <span class="material-symbols-outlined text-lg">save</span>
                Create Risk
            </button>
        </div>
    </form>

    <script>
        function riskStatementBuilder() {
            return {
                scenario: '',
                loading: false,
                error: null,
                lastResult: null,
                async draft() {
                    if (this.scenario.trim().length < 3) return;
                    this.loading = true;
                    this.error = null;
                    this.lastResult = null;

                    const catSelect = document.getElementById('category_id');
                    const buSelect = document.getElementById('business_unit_id');
                    const category = catSelect?.options[catSelect.selectedIndex]?.text || null;
                    const businessUnit = buSelect?.options[buSelect.selectedIndex]?.text || null;

                    const started = performance.now();
                    try {
                        const res = await fetch('{{ route('risk.ai.tools.risk-statement') }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            },
                            body: JSON.stringify({
                                scenario: this.scenario,
                                category: category && category !== 'Select Category' ? category : null,
                                business_unit: businessUnit && businessUnit !== 'Select Business Unit' ? businessUnit : null,
                            }),
                        });
                        const json = await res.json();
                        const elapsed = Math.round(performance.now() - started);

                        if (!json.ok) {
                            this.error = json.error || 'The local LLM did not return a usable draft.';
                            return;
                        }

                        const d = json.data;
                        const titleEl = document.getElementById('title');
                        const descEl = document.getElementById('description');
                        if (titleEl) titleEl.value = d.title;
                        if (descEl) {
                            descEl.value = 'Cause: ' + d.cause
                                + '\nEvent: ' + d.event
                                + '\nConsequence: ' + d.consequence
                                + '\n\n' + d.description;
                        }
                        this.lastResult = { elapsed_ms: elapsed };
                    } catch (e) {
                        this.error = 'Network error: ' + e.message;
                    } finally {
                        this.loading = false;
                    }
                },
            };
        }
    </script>
@endsection
