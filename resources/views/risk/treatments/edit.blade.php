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

        {{-- Section 1: Plan Details --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6">
                <div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">1</div>
                <h2 class="text-lg font-semibold text-[#1A365D]">Plan Details</h2>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div>
                    <label for="risk_id" class="block text-sm font-medium text-gray-700 mb-2">Linked Risk</label>
                    <select id="risk_id" name="risk_id" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] bg-gray-50" disabled>
                        <option value="">Select Risk</option>
                        @foreach (($risks ?? []) as $risk)
                            <option value="{{ $risk->id }}" {{ $plan->risk_id == $risk->id ? 'selected' : '' }}>{{ $risk->risk_code }} - {{ $risk->risk_title ?? $risk->title ?? '' }}</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-gray-400 mt-1">Linked risk cannot be changed after creation.</p>
                </div>
                <div>
                    <label for="treatment_type" class="block text-sm font-medium text-gray-700 mb-2">Treatment Strategy <span class="text-red-500">*</span></label>
                    <select id="treatment_type" name="treatment_type" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('treatment_type') border-red-500 @enderror" required>
                        <option value="">Select Strategy</option>
                        @foreach (['mitigate' => 'Mitigate', 'transfer' => 'Transfer', 'accept' => 'Accept', 'avoid' => 'Avoid'] as $val => $label)
                            <option value="{{ $val }}" {{ old('treatment_type', $planType) === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('treatment_type')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div class="lg:col-span-2">
                    <label for="treatment_title" class="block text-sm font-medium text-gray-700 mb-2">Plan Title <span class="text-red-500">*</span></label>
                    <input type="text" id="treatment_title" name="treatment_title" value="{{ old('treatment_title', $planTitle) }}"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('treatment_title') border-red-500 @enderror" required>
                    @error('treatment_title')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div class="lg:col-span-2">
                    <label for="treatment_description" class="block text-sm font-medium text-gray-700 mb-2">Description <span class="text-red-500">*</span></label>
                    <textarea id="treatment_description" name="treatment_description" rows="4"
                              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('treatment_description') border-red-500 @enderror" required>{{ old('treatment_description', $planDescription) }}</textarea>
                    @error('treatment_description')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
            </div>
        </div>

        {{-- Section 2: Ownership & Timeline --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6">
                <div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">2</div>
                <h2 class="text-lg font-semibold text-[#1A365D]">Ownership & Timeline</h2>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div>
                    <label for="treatment_owner_id" class="block text-sm font-medium text-gray-700 mb-2">Plan Owner <span class="text-red-500">*</span></label>
                    <select id="treatment_owner_id" name="treatment_owner_id" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('treatment_owner_id') border-red-500 @enderror" required>
                        <option value="">Select Owner</option>
                        @foreach (($users ?? []) as $user)
                            <option value="{{ $user->id }}" {{ old('treatment_owner_id', $planOwnerId) == $user->id ? 'selected' : '' }}>{{ $user->name }}</option>
                        @endforeach
                    </select>
                    @error('treatment_owner_id')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="priority" class="block text-sm font-medium text-gray-700 mb-2">Priority <span class="text-red-500">*</span></label>
                    <select id="priority" name="priority" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('priority') border-red-500 @enderror" required>
                        @foreach (['critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'] as $val => $label)
                            <option value="{{ $val }}" {{ old('priority', $plan->priority) === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('priority')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="target_completion_date" class="block text-sm font-medium text-gray-700 mb-2">Target Completion Date <span class="text-red-500">*</span></label>
                    <input type="date" id="target_completion_date" name="target_completion_date"
                           value="{{ old('target_completion_date', $planTargetDate instanceof \Carbon\Carbon ? $planTargetDate->format('Y-m-d') : ($planTargetDate ? \Carbon\Carbon::parse($planTargetDate)->format('Y-m-d') : '')) }}"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('target_completion_date') border-red-500 @enderror" required>
                    @error('target_completion_date')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="actual_completion_date" class="block text-sm font-medium text-gray-700 mb-2">Actual Completion Date</label>
                    <input type="date" id="actual_completion_date" name="actual_completion_date"
                           value="{{ old('actual_completion_date', $planCompletionDate instanceof \Carbon\Carbon ? $planCompletionDate->format('Y-m-d') : ($planCompletionDate ? \Carbon\Carbon::parse($planCompletionDate)->format('Y-m-d') : '')) }}"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                    @error('actual_completion_date')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="progress_percentage" class="block text-sm font-medium text-gray-700 mb-2">Progress (%)</label>
                    <input type="number" id="progress_percentage" name="progress_percentage" value="{{ old('progress_percentage', $planProgress) }}" min="0" max="100"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                    @error('progress_percentage')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="status" class="block text-sm font-medium text-gray-700 mb-2">Status <span class="text-red-500">*</span></label>
                    <select id="status" name="status" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('status') border-red-500 @enderror" required>
                        @foreach (['not_started' => 'Not Started', 'in_progress' => 'In Progress', 'completed' => 'Completed', 'on_hold' => 'On Hold', 'overdue' => 'Overdue', 'pending_review' => 'Pending Review', 'cancelled' => 'Cancelled'] as $val => $label)
                            <option value="{{ $val }}" {{ old('status', $plan->status) === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('status')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
            </div>
        </div>

        {{-- Section 3: Budget & Risk Reduction --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6">
                <div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">3</div>
                <h2 class="text-lg font-semibold text-[#1A365D]">Budget & Risk Reduction</h2>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div>
                    <label for="estimated_cost" class="block text-sm font-medium text-gray-700 mb-2">Estimated Cost (NGN)</label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 text-sm font-medium">&#8358;</span>
                        <input type="number" id="estimated_cost" name="estimated_cost" value="{{ old('estimated_cost', $planBudget) }}" step="0.01" min="0"
                               class="w-full pl-8 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                    </div>
                    @error('estimated_cost')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="actual_cost" class="block text-sm font-medium text-gray-700 mb-2">Actual Cost (NGN)</label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 text-sm font-medium">&#8358;</span>
                        <input type="number" id="actual_cost" name="actual_cost" value="{{ old('actual_cost', $planActualCost) }}" step="0.01" min="0"
                               class="w-full pl-8 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                    </div>
                    @error('actual_cost')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="expected_residual_likelihood" class="block text-sm font-medium text-gray-700 mb-2">Expected Residual Likelihood</label>
                    <select id="expected_residual_likelihood" name="expected_residual_likelihood" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        <option value="">Select</option>
                        @for ($i = 1; $i <= 5; $i++)
                            <option value="{{ $i }}" {{ old('expected_residual_likelihood', $plan->expected_residual_likelihood) == $i ? 'selected' : '' }}>{{ $i }} - {{ ['Rare','Unlikely','Possible','Likely','Almost Certain'][$i-1] }}</option>
                        @endfor
                    </select>
                    @error('expected_residual_likelihood')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="expected_residual_impact" class="block text-sm font-medium text-gray-700 mb-2">Expected Residual Impact</label>
                    <select id="expected_residual_impact" name="expected_residual_impact" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        <option value="">Select</option>
                        @for ($i = 1; $i <= 5; $i++)
                            <option value="{{ $i }}" {{ old('expected_residual_impact', $plan->expected_residual_impact) == $i ? 'selected' : '' }}>{{ $i }} - {{ ['Insignificant','Minor','Moderate','Major','Catastrophic'][$i-1] }}</option>
                        @endfor
                    </select>
                    @error('expected_residual_impact')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
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
                <div class="lg:col-span-2">
                    <label for="success_criteria" class="block text-sm font-medium text-gray-700 mb-2">Success Criteria</label>
                    <textarea id="success_criteria" name="success_criteria" rows="3"
                              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]" placeholder="Define what success looks like...">{{ old('success_criteria', $plan->success_criteria) }}</textarea>
                    @error('success_criteria')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div class="lg:col-span-2">
                    <label for="implementation_notes" class="block text-sm font-medium text-gray-700 mb-2">Implementation Notes</label>
                    <textarea id="implementation_notes" name="implementation_notes" rows="3"
                              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]" placeholder="Any notes on implementation progress...">{{ old('implementation_notes', $plan->implementation_notes) }}</textarea>
                    @error('implementation_notes')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
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
