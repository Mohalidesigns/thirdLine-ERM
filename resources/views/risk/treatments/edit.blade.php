@extends('layouts.app')

@php
    $planTitle = $plan->treatment_title ?? $plan->action_title ?? 'Treatment Plan';
    $planDescription = $plan->treatment_description ?? $plan->action_description ?? '';
    $planProgress = $plan->progress_percentage ?? $plan->progress_pct ?? 0;
    $planBudget = $plan->estimated_cost ?? $plan->cost_estimate_ngn ?? '';
    $planActualCost = $plan->actual_cost ?? $plan->actual_cost_ngn ?? '';
    $planType = $plan->treatment_type ?? $plan->strategy ?? '';
    $planOwnerId = $plan->treatment_owner_id ?? $plan->owner_id ?? '';
    $planTargetDate = $plan->target_completion_date ?? $plan->target_date ?? null;
    $planCompletionDate = $plan->actual_completion_date ?? $plan->completion_date ?? null;
@endphp

@section('title', 'Edit Treatment Plan - GRC Risk Management')
@section('page-section', 'Treatment Plans')
@section('page-title', 'Edit Plan')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.treatments.index') }}" class="hover:text-[#1A365D]">Treatment Plans</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.treatments.show', $plan) }}" class="hover:text-[#1A365D]">{{ Str::limit($planTitle, 25) }}</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Edit</span>
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
        <h1 class="text-2xl font-bold text-[#1A365D]">Edit Treatment Plan</h1>
        <p class="text-sm text-gray-500 mt-1">Update treatment plan details for "{{ $planTitle }}"</p>
    </div>

    <form method="POST" action="{{ route('risk.treatments.update', $plan) }}" id="treatmentForm">
        @csrf
        @method('PUT')

        {{--
            WP-05 TASK 2 — plan details, response, expected outcome and
            progress come from object_attributes on the TreatmentPlan type.

            risk_id is excluded: TreatmentPlanController::update() does not
            accept it, so offering it would be an editable field that never
            saves. Re-pointing a plan at a different risk is a move, not an edit.

            The milestones repeater below stays hand-written: it posts as
            milestones[n][...], which is a repeating group rather than a field.
        --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6">
                <div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">1</div>
                <h2 class="text-lg font-semibold text-[#1A365D]">Plan Details</h2>
            </div>

            <x-dynamic-form type="TreatmentPlan" :record="$plan" :omit="['risk_id']" />
        </div>

        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6">
                <div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">2</div>
                <h2 class="text-lg font-semibold text-[#1A365D]">Milestones</h2>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                <div class="lg:col-span-2">
                    @php
                        $existingMilestones = old('milestones');
                        if ($existingMilestones === null) {
                            $existingMilestones = is_array($plan->milestones)
                                ? $plan->milestones
                                : (json_decode($plan->milestones ?? '[]', true) ?: []);
                        }
                    @endphp
                    <label class="block text-sm font-medium text-gray-700 mb-2">Milestones</label>
                    <div id="milestonesContainer">
                        @forelse ($existingMilestones as $i => $m)
                            <div class="milestone-row grid grid-cols-1 lg:grid-cols-12 gap-4 mb-4 p-4 bg-gray-50 rounded-lg">
                                <div class="lg:col-span-5">
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Milestone Title</label>
                                    <input type="text" name="milestones[{{ $i }}][title]" value="{{ $m['title'] ?? '' }}" placeholder="e.g. Requirements gathering complete" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                                </div>
                                <div class="lg:col-span-3">
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Due Date</label>
                                    <input type="date" name="milestones[{{ $i }}][due_date]" value="{{ $m['due_date'] ?? '' }}" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                                </div>
                                <div class="lg:col-span-3">
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Responsible</label>
                                    <input type="text" name="milestones[{{ $i }}][responsible]" value="{{ $m['responsible'] ?? '' }}" placeholder="Name or role" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                                </div>
                                <div class="lg:col-span-1 flex items-end">
                                    <button type="button" class="remove-milestone p-2 text-red-400 hover:text-red-600 hover:bg-red-50 rounded-lg" title="Remove">
                                        <span class="material-symbols-outlined text-lg">delete</span>
                                    </button>
                                </div>
                            </div>
                        @empty
                            <div class="milestone-row grid grid-cols-1 lg:grid-cols-12 gap-4 mb-4 p-4 bg-gray-50 rounded-lg">
                                <div class="lg:col-span-5">
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Milestone Title</label>
                                    <input type="text" name="milestones[0][title]" placeholder="e.g. Requirements gathering complete" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                                </div>
                                <div class="lg:col-span-3">
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Due Date</label>
                                    <input type="date" name="milestones[0][due_date]" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                                </div>
                                <div class="lg:col-span-3">
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Responsible</label>
                                    <input type="text" name="milestones[0][responsible]" placeholder="Name or role" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                                </div>
                                <div class="lg:col-span-1 flex items-end">
                                    <button type="button" class="remove-milestone p-2 text-red-400 hover:text-red-600 hover:bg-red-50 rounded-lg" title="Remove">
                                        <span class="material-symbols-outlined text-lg">delete</span>
                                    </button>
                                </div>
                            </div>
                        @endforelse
                    </div>
                    <button type="button" id="addMilestone" class="flex items-center gap-2 px-4 py-2 border border-dashed border-gray-300 rounded-lg text-sm text-gray-600 hover:border-[#1A365D] hover:text-[#1A365D] transition-colors">
                        <span class="material-symbols-outlined text-lg">add</span> Add Milestone
                    </button>
                    @error('milestones')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
            </div>
        </div>

        {{-- Form Actions --}}
        <div class="flex items-center justify-between">
            <a href="{{ route('risk.treatments.show', $plan) }}" class="px-6 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">Cancel</a>
            <button type="submit" class="px-6 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] transition-colors flex items-center gap-2">
                <span class="material-symbols-outlined text-lg">save</span> Update Plan
            </button>
        </div>
    </form>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const container = document.getElementById('milestonesContainer');
    if (!container) return;

    let milestoneIndex = container.querySelectorAll('.milestone-row').length;

    document.getElementById('addMilestone').addEventListener('click', function() {
        const row = document.createElement('div');
        row.className = 'milestone-row grid grid-cols-1 lg:grid-cols-12 gap-4 mb-4 p-4 bg-gray-50 rounded-lg';
        row.innerHTML = `
            <div class="lg:col-span-5">
                <label class="block text-xs font-medium text-gray-600 mb-1">Milestone Title</label>
                <input type="text" name="milestones[${milestoneIndex}][title]" placeholder="e.g. Requirements gathering complete" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
            </div>
            <div class="lg:col-span-3">
                <label class="block text-xs font-medium text-gray-600 mb-1">Due Date</label>
                <input type="date" name="milestones[${milestoneIndex}][due_date]" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
            </div>
            <div class="lg:col-span-3">
                <label class="block text-xs font-medium text-gray-600 mb-1">Responsible</label>
                <input type="text" name="milestones[${milestoneIndex}][responsible]" placeholder="Name or role" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
            </div>
            <div class="lg:col-span-1 flex items-end">
                <button type="button" class="remove-milestone p-2 text-red-400 hover:text-red-600 hover:bg-red-50 rounded-lg" title="Remove">
                    <span class="material-symbols-outlined text-lg">delete</span>
                </button>
            </div>`;
        container.appendChild(row);
        milestoneIndex++;
    });

    container.addEventListener('click', function(e) {
        const btn = e.target.closest('.remove-milestone');
        if (!btn) return;
        const rows = container.querySelectorAll('.milestone-row');
        if (rows.length <= 1) {
            btn.closest('.milestone-row').querySelectorAll('input').forEach(i => i.value = '');
            return;
        }
        btn.closest('.milestone-row').remove();
    });
});
</script>
@endpush
