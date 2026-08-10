@extends('layouts.app')

@section('title', 'Create Treatment Plan - GRC Risk Management')
@section('page-section', 'Treatment Plans')
@section('page-title', 'Create Plan')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.treatments.index') }}" class="hover:text-[#1A365D]">Treatment Plans</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Create Plan</span>
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

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-[#1A365D]">Create Treatment Plan</h1>
        <p class="text-sm text-gray-500 mt-1">Define a treatment plan to address an identified risk</p>
    </div>

    {{-- AI Treatment Plan Description Builder --}}
    <div x-data="treatmentDescriptionBuilder()" class="mb-6 bg-gradient-to-br from-[#1A365D] to-[#2c4a7a] rounded-xl p-5 text-white shadow-lg">
        <div class="flex items-start gap-3 mb-3">
            <div class="w-9 h-9 rounded-lg bg-[#D4AF37]/20 border border-[#D4AF37]/40 flex items-center justify-center flex-shrink-0">
                <span class="material-symbols-outlined text-[#D4AF37]" style="font-size: 20px;">auto_awesome</span>
            </div>
            <div class="flex-1">
                <div class="flex items-center gap-2">
                    <h3 class="text-sm font-semibold">AI Treatment Plan Builder</h3>
                    <span class="text-[10px] px-2 py-0.5 rounded-full bg-[#D4AF37]/20 text-[#D4AF37] border border-[#D4AF37]/40">Local LLM · Granite</span>
                </div>
                <p class="text-xs text-white/70 mt-0.5">Describe the plan in plain English — the model drafts objectives, scope and outcomes and prefills the form.</p>
            </div>
        </div>
        <div class="flex gap-2">
            <input type="text" x-model="scenario"
                   @keydown.enter.prevent="draft()"
                   placeholder='e.g. "Reduce single-obligor concentration in oil & gas portfolio over 12 months"'
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

    <form method="POST" action="{{ route('risk.treatments.store') }}" id="treatmentForm">
        @csrf

        {{--
            WP-05 TASK 2 — sections 1 to 3 (plan details, the four-strategy
            response, expected outcome) come from object_attributes on the
            TreatmentPlan type.

            Expected residual likelihood and impact are offered on the
            organisation's own scoring scale, not a hardcoded 1–5, so a tenant
            on a 4×4 matrix cannot record a 5 the matrix has no room for.

            Milestones stay hand-written below: they are a repeating group
            posted as milestones[n][...], which is a different shape from a
            field and belongs to this form.
        --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6">
                <div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">1</div>
                <h2 class="text-lg font-semibold text-[#1A365D]">Plan Details</h2>
            </div>

            <x-dynamic-form type="TreatmentPlan"
                            :sections="['Details', 'Plan', 'Expected Outcome']"
                            :defaults="['risk_id' => request('risk_id')]" />
        </div>


        {{-- Section 4: Milestones --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6">
                <div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">4</div>
                <h2 class="text-lg font-semibold text-[#1A365D]">Milestones</h2>
            </div>

            <div id="milestonesContainer">
                <div class="milestone-row grid grid-cols-1 lg:grid-cols-12 gap-4 mb-4 p-4 bg-gray-50 rounded-lg">
                    <div class="lg:col-span-5">
                        <label class="block text-xs font-medium text-gray-600 mb-1">Milestone Title</label>
                        <input type="text" name="milestones[0][title]" placeholder="e.g. Requirements gathering complete"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                    </div>
                    <div class="lg:col-span-3">
                        <label class="block text-xs font-medium text-gray-600 mb-1">Due Date</label>
                        <input type="date" name="milestones[0][due_date]"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                    </div>
                    <div class="lg:col-span-3">
                        <label class="block text-xs font-medium text-gray-600 mb-1">Responsible</label>
                        <input type="text" name="milestones[0][responsible]" placeholder="Name or role"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                    </div>
                    <div class="lg:col-span-1 flex items-end">
                        <button type="button" class="remove-milestone p-2 text-red-400 hover:text-red-600 hover:bg-red-50 rounded-lg" title="Remove">
                            <span class="material-symbols-outlined text-lg">delete</span>
                        </button>
                    </div>
                </div>
            </div>

            <button type="button" id="addMilestone" class="flex items-center gap-2 px-4 py-2 border border-dashed border-gray-300 rounded-lg text-sm text-gray-600 hover:border-[#1A365D] hover:text-[#1A365D] transition-colors">
                <span class="material-symbols-outlined text-lg">add</span> Add Milestone
            </button>
        </div>

        {{-- Form Actions --}}
        <div class="flex items-center justify-between">
            <a href="{{ route('risk.treatments.index') }}" class="px-6 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">Cancel</a>
            <div class="flex gap-2">
                <button type="submit" name="action" value="draft" class="px-6 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">Save as Draft</button>
                <button type="submit" name="action" value="submit" class="px-6 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] transition-colors flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">save</span> Submit Plan
                </button>
            </div>
        </div>
    </form>
@endsection

@push('scripts')
<script>
function treatmentDescriptionBuilder() {
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

            const titleEl = document.getElementById('field-treatment_title');
            const typeSel = document.getElementById('field-treatment_type');
            const riskSel = document.getElementById('field-risk_id');

            const started = performance.now();
            try {
                const res = await fetch('{{ route('risk.ai.tools.treatment-description') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({
                        scenario: this.scenario,
                        title: titleEl?.value || null,
                        treatment_type: typeSel?.value || null,
                        risk_id: riskSel?.value || null,
                    }),
                });
                const json = await res.json();
                const elapsed = Math.round(performance.now() - started);

                if (!json.ok) {
                    this.error = json.error || 'The local LLM did not return a usable draft.';
                    return;
                }

                const d = json.data;
                const descEl = document.getElementById('field-treatment_description');
                // Alpine owns these inputs through x-model, so a raw value
                // assignment has to be announced or the binding overwrites it.
                const set = (el, value) => {
                    if (!el || value == null) return;
                    el.value = value;
                    el.dispatchEvent(new Event('input', { bubbles: true }));
                };

                if (titleEl && !titleEl.value) set(titleEl, d.title);
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

document.addEventListener('DOMContentLoaded', function() {
    let milestoneIndex = 1;
    document.getElementById('addMilestone').addEventListener('click', function() {
        const container = document.getElementById('milestonesContainer');
        const row = document.createElement('div');
        row.className = 'milestone-row grid grid-cols-1 lg:grid-cols-12 gap-4 mb-4 p-4 bg-gray-50 rounded-lg';
        row.innerHTML = `
            <div class="lg:col-span-5">
                <label class="block text-xs font-medium text-gray-600 mb-1">Milestone Title</label>
                <input type="text" name="milestones[${milestoneIndex}][title]" placeholder="e.g. Requirements gathering complete"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
            </div>
            <div class="lg:col-span-3">
                <label class="block text-xs font-medium text-gray-600 mb-1">Due Date</label>
                <input type="date" name="milestones[${milestoneIndex}][due_date]"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
            </div>
            <div class="lg:col-span-3">
                <label class="block text-xs font-medium text-gray-600 mb-1">Responsible</label>
                <input type="text" name="milestones[${milestoneIndex}][responsible]" placeholder="Name or role"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
            </div>
            <div class="lg:col-span-1 flex items-end">
                <button type="button" class="remove-milestone p-2 text-red-400 hover:text-red-600 hover:bg-red-50 rounded-lg" title="Remove">
                    <span class="material-symbols-outlined text-lg">delete</span>
                </button>
            </div>
        `;
        container.appendChild(row);
        milestoneIndex++;
    });

    document.addEventListener('click', function(e) {
        if (e.target.closest('.remove-milestone')) {
            const rows = document.querySelectorAll('.milestone-row');
            if (rows.length > 1) {
                e.target.closest('.milestone-row').remove();
            }
        }
    });
});
</script>
@endpush
