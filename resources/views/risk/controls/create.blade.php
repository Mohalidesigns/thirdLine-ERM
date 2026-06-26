@extends('layouts.app')

@section('title', 'Create Control - GRC Risk Management')
@section('page-section', 'Controls')
@section('page-title', 'Create Control')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.controls.index') }}" class="hover:text-[#1A365D]">Controls</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Create Control</span>
@endsection

@section('content')
    @if ($errors->any())
        <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl">
            <div class="flex items-center gap-2 mb-2"><span class="material-symbols-outlined text-red-600">error</span><span class="text-sm font-semibold text-red-700">Please correct the following errors:</span></div>
            <ul class="list-disc list-inside text-sm text-red-600 space-y-1">@foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach</ul>
        </div>
    @endif

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-[#1A365D]">Create New Control</h1>
        <p class="text-sm text-gray-500 mt-1">Define a new risk control in the organizational control library</p>
    </div>

    {{-- AI Control Description Builder --}}
    <div x-data="controlDescriptionBuilder()" class="mb-6 bg-gradient-to-br from-[#1A365D] to-[#2c4a7a] rounded-xl p-5 text-white shadow-lg">
        <div class="flex items-start gap-3 mb-3">
            <div class="w-9 h-9 rounded-lg bg-[#D4AF37]/20 border border-[#D4AF37]/40 flex items-center justify-center flex-shrink-0">
                <span class="material-symbols-outlined text-[#D4AF37]" style="font-size: 20px;">auto_awesome</span>
            </div>
            <div class="flex-1">
                <div class="flex items-center gap-2">
                    <h3 class="text-sm font-semibold">AI Control Description Builder</h3>
                    <span class="text-[10px] px-2 py-0.5 rounded-full bg-[#D4AF37]/20 text-[#D4AF37] border border-[#D4AF37]/40">Local LLM · Granite</span>
                </div>
                <p class="text-xs text-white/70 mt-0.5">Describe the control in plain English — the model drafts an audit-ready description and prefills the form.</p>
            </div>
        </div>
        <div class="flex gap-2">
            <input type="text" x-model="scenario"
                   @keydown.enter.prevent="draft()"
                   placeholder='e.g. "Dual approval on wire transfers above NGN 50m" or "Daily reconciliation of suspense accounts"'
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

    <form method="POST" action="{{ route('risk.controls.store') }}">
        @csrf

        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6"><div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">1</div><h2 class="text-lg font-semibold text-[#1A365D]">Control Details</h2></div>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="lg:col-span-2">
                    <label for="name" class="block text-sm font-medium text-gray-700 mb-2">Control Name <span class="text-red-500">*</span></label>
                    <input type="text" id="name" name="name" value="{{ old('name') }}" placeholder="e.g. Dual Authorization for Wire Transfers" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('name') border-red-500 @enderror" required>
                    @error('name')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div class="lg:col-span-2">
                    <label for="description" class="block text-sm font-medium text-gray-700 mb-2">Description <span class="text-red-500">*</span></label>
                    <textarea id="description" name="description" rows="3" placeholder="Describe how this control operates..." class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('description') border-red-500 @enderror" required>{{ old('description') }}</textarea>
                    @error('description')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="control_type" class="block text-sm font-medium text-gray-700 mb-2">Control Type <span class="text-red-500">*</span></label>
                    <select id="control_type" name="control_type" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]" required>
                        <option value="">Select Type</option>
                        @foreach (['preventive' => 'Preventive', 'detective' => 'Detective', 'corrective' => 'Corrective', 'directive' => 'Directive'] as $val => $label)
                            <option value="{{ $val }}" {{ old('control_type') === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('control_type')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="control_nature" class="block text-sm font-medium text-gray-700 mb-2">Control Nature</label>
                    <select id="control_nature" name="control_nature" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        <option value="">Select Nature</option>
                        @foreach (['manual' => 'Manual', 'automated' => 'Automated', 'semi_automated' => 'Semi-Automated'] as $val => $label)
                            <option value="{{ $val }}" {{ old('control_nature') === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('control_nature')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="frequency" class="block text-sm font-medium text-gray-700 mb-2">Control Frequency</label>
                    <select id="frequency" name="frequency" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        <option value="">Select Frequency</option>
                        @foreach (['continuous' => 'Continuous', 'daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'annually' => 'Annually', 'ad_hoc' => 'Ad Hoc'] as $val => $label)
                            <option value="{{ $val }}" {{ old('frequency') === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('frequency')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="owner_id" class="block text-sm font-medium text-gray-700 mb-2">Control Owner <span class="text-red-500">*</span></label>
                    <select id="owner_id" name="owner_id" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]" required>
                        <option value="">Select Owner</option>
                        @foreach (($users ?? []) as $user) <option value="{{ $user->id }}" {{ old('owner_id') == $user->id ? 'selected' : '' }}>{{ $user->name }}</option> @endforeach
                    </select>
                    @error('owner_id')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="business_unit_id" class="block text-sm font-medium text-gray-700 mb-2">Business Unit</label>
                    <select id="business_unit_id" name="business_unit_id" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        <option value="">Select Business Unit</option>
                        @foreach (($businessUnits ?? []) as $bu) <option value="{{ $bu->id }}" {{ old('business_unit_id') == $bu->id ? 'selected' : '' }}>{{ $bu->name }}</option> @endforeach
                    </select>
                </div>
                <div>
                    <label for="effectiveness_rating" class="block text-sm font-medium text-gray-700 mb-2">Effectiveness Rating</label>
                    <select id="effectiveness_rating" name="effectiveness_rating" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        <option value="">Select Rating</option>
                        @foreach (['effective' => 'Effective', 'partially_effective' => 'Partially Effective', 'ineffective' => 'Ineffective'] as $val => $label)
                            <option value="{{ $val }}" {{ old('effectiveness_rating') === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="status" class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                    <select id="status" name="status" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        @foreach (['active' => 'Active', 'inactive' => 'Inactive', 'under_review' => 'Under Review'] as $val => $label)
                            <option value="{{ $val }}" {{ old('status', 'active') === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6"><div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">2</div><h2 class="text-lg font-semibold text-[#1A365D]">Linked Risks</h2></div>
            <div class="space-y-2">
                @foreach (($risks ?? []) as $risk)
                    <label class="flex items-center gap-3 p-3 bg-gray-50 rounded-lg hover:bg-blue-50 cursor-pointer">
                        @php
                            $preselectedIds = old('risk_ids', request('risk_id') ? [(int) request('risk_id')] : []);
                        @endphp
                        <input type="checkbox" name="risk_ids[]" value="{{ $risk->id }}" class="rounded border-gray-300 text-[#1A365D]" {{ in_array($risk->id, $preselectedIds) ? 'checked' : '' }}>
                        <div>
                            <span class="text-xs font-semibold text-[#1A365D]">{{ $risk->risk_code }}</span>
                            <span class="text-xs text-gray-600 ml-2">{{ Str::limit($risk->title, 50) }}</span>
                        </div>
                        <x-risk-badge :rating="$risk->residual_rating ?? 'medium'" class="ml-auto" />
                    </label>
                @endforeach
            </div>
        </div>

        <div class="flex items-center justify-between">
            <a href="{{ route('risk.controls.index') }}" class="px-6 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</a>
            <button type="submit" class="px-6 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-2"><span class="material-symbols-outlined text-lg">save</span> Create Control</button>
        </div>
    </form>

    <script>
        function controlDescriptionBuilder() {
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

                    const nameEl = document.getElementById('name');
                    const typeSel = document.getElementById('control_type');
                    const natureSel = document.getElementById('control_nature');
                    const freqSel = document.getElementById('frequency');

                    const started = performance.now();
                    try {
                        const res = await fetch('{{ route('risk.ai.tools.control-description') }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            },
                            body: JSON.stringify({
                                scenario: this.scenario,
                                name: nameEl?.value || null,
                                control_type: typeSel?.value || null,
                                control_nature: natureSel?.value || null,
                                frequency: freqSel?.value || null,
                            }),
                        });
                        const json = await res.json();
                        const elapsed = Math.round(performance.now() - started);

                        if (!json.ok) {
                            this.error = json.error || 'The local LLM did not return a usable draft.';
                            return;
                        }

                        const d = json.data;
                        const descEl = document.getElementById('description');
                        if (nameEl && !nameEl.value && d.name) nameEl.value = d.name;
                        if (descEl) descEl.value = d.description;
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
