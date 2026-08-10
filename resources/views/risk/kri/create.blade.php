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

    {{-- AI KRI Description Builder --}}
    <div x-data="kriDescriptionBuilder()" class="mb-6 bg-gradient-to-br from-[#1A365D] to-[#2c4a7a] rounded-xl p-5 text-white shadow-lg">
        <div class="flex items-start gap-3 mb-3">
            <div class="w-9 h-9 rounded-lg bg-[#D4AF37]/20 border border-[#D4AF37]/40 flex items-center justify-center flex-shrink-0">
                <span class="material-symbols-outlined text-[#D4AF37]" style="font-size: 20px;">auto_awesome</span>
            </div>
            <div class="flex-1">
                <div class="flex items-center gap-2">
                    <h3 class="text-sm font-semibold">AI KRI Description Builder</h3>
                    <span class="text-[10px] px-2 py-0.5 rounded-full bg-[#D4AF37]/20 text-[#D4AF37] border border-[#D4AF37]/40">Local LLM · Granite</span>
                </div>
                <p class="text-xs text-white/70 mt-0.5">Describe the indicator in plain English — the model drafts what it measures, why it is predictive and prefills the form.</p>
            </div>
        </div>
        <div class="flex gap-2">
            <input type="text" x-model="scenario"
                   @keydown.enter.prevent="draft()"
                   placeholder='e.g. "Non-performing loans ratio in retail portfolio" or "Failed login attempts on mobile app"'
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

    <form method="POST" action="{{ route('risk.kri.store') }}">
        @csrf

        {{--
            WP-05 TASK 2 — the field grid comes from object_attributes on the
            KeyRiskIndicator type. The threshold fields are grouped into their
            own section by the metadata, not by markup here, so a tenant that
            adds a fourth threshold band gets it rendered in the right place.
        --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <x-dynamic-form type="KeyRiskIndicator"
                            :defaults="['risk_id' => request('risk_id')]" />
        </div>

        {{-- Form Actions --}}
        <div class="flex items-center justify-between">
            <a href="{{ route('risk.kri.index') }}" class="px-6 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">Cancel</a>
            <button type="submit" class="px-6 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] transition-colors flex items-center gap-2">
                <span class="material-symbols-outlined text-lg">save</span> Create KRI
            </button>
        </div>
    </form>

    <script>
        function kriDescriptionBuilder() {
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

                    const nameEl = document.getElementById('field-kri_name');
                    const catSel = document.getElementById('field-category');
                    const unitEl = document.getElementById('field-measurement_unit');

                    const started = performance.now();
                    try {
                        const res = await fetch('{{ route('risk.ai.tools.kri-description') }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            },
                            body: JSON.stringify({
                                scenario: this.scenario,
                                name: nameEl?.value || null,
                                category: catSel?.value || null,
                                measurement_unit: unitEl?.value || null,
                            }),
                        });
                        const json = await res.json();
                        const elapsed = Math.round(performance.now() - started);

                        if (!json.ok) {
                            this.error = json.error || 'The local LLM did not return a usable draft.';
                            return;
                        }

                        const d = json.data;
                        const descEl = document.getElementById('field-description');
                        const set = (el, value) => {
                            if (!el || value == null) return;
                            el.value = value;
                            el.dispatchEvent(new Event('input', { bubbles: true }));
                        };

                        if (nameEl && !nameEl.value) set(nameEl, d.name);
                        set(descEl, d.description);
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
