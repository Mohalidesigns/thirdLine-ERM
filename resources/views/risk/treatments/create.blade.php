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

    <form method="POST" action="{{ route('risk.treatments.store') }}" id="treatmentForm">
        @csrf

        {{-- Section 1: Plan Details --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6">
                <div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">1</div>
                <h2 class="text-lg font-semibold text-[#1A365D]">Plan Details</h2>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div>
                    <label for="risk_id" class="block text-sm font-medium text-gray-700 mb-2">Linked Risk <span class="text-red-500">*</span></label>
                    <select id="risk_id" name="risk_id" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('risk_id') border-red-500 @enderror" required>
                        <option value="">Select Risk</option>
                        @foreach (($risks ?? []) as $risk)
                            <option value="{{ $risk->id }}" {{ old('risk_id', request('risk_id')) == $risk->id ? 'selected' : '' }}>
                                {{ $risk->risk_code }} - {{ $risk->title }}
                            </option>
                        @endforeach
                    </select>
                    @error('risk_id')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="treatment_type" class="block text-sm font-medium text-gray-700 mb-2">Treatment Strategy <span class="text-red-500">*</span></label>
                    <select id="treatment_type" name="treatment_type" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('treatment_type') border-red-500 @enderror" required>
                        <option value="">Select Strategy</option>
                        <option value="mitigate" {{ old('treatment_type') === 'mitigate' ? 'selected' : '' }}>Mitigate - Reduce likelihood or impact</option>
                        <option value="transfer" {{ old('treatment_type') === 'transfer' ? 'selected' : '' }}>Transfer - Insurance or outsourcing</option>
                        <option value="accept" {{ old('treatment_type') === 'accept' ? 'selected' : '' }}>Accept - Within risk appetite</option>
                        <option value="avoid" {{ old('treatment_type') === 'avoid' ? 'selected' : '' }}>Avoid - Exit the activity</option>
                    </select>
                    @error('treatment_type')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>

                <div class="lg:col-span-2">
                    <label for="treatment_title" class="block text-sm font-medium text-gray-700 mb-2">Plan Title <span class="text-red-500">*</span></label>
                    <input type="text" id="treatment_title" name="treatment_title" value="{{ old('treatment_title') }}" placeholder="e.g. Implement Enhanced Credit Monitoring Controls"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('treatment_title') border-red-500 @enderror" required>
                    @error('treatment_title')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>

                <div class="lg:col-span-2">
                    <label for="treatment_description" class="block text-sm font-medium text-gray-700 mb-2">Description <span class="text-red-500">*</span></label>
                    <textarea id="treatment_description" name="treatment_description" rows="4" placeholder="Detailed description of the treatment plan including objectives, scope, and expected outcomes..."
                              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('treatment_description') border-red-500 @enderror" required>{{ old('treatment_description') }}</textarea>
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
                            <option value="{{ $user->id }}" {{ old('treatment_owner_id') == $user->id ? 'selected' : '' }}>{{ $user->name }}</option>
                        @endforeach
                    </select>
                    @error('treatment_owner_id')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="priority" class="block text-sm font-medium text-gray-700 mb-2">Priority <span class="text-red-500">*</span></label>
                    <select id="priority" name="priority" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('priority') border-red-500 @enderror" required>
                        <option value="">Select Priority</option>
                        @foreach (['critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'] as $val => $label)
                            <option value="{{ $val }}" {{ old('priority') === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('priority')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="start_date" class="block text-sm font-medium text-gray-700 mb-2">Start Date <span class="text-red-500">*</span></label>
                    <input type="date" id="start_date" name="start_date" value="{{ old('start_date', now()->format('Y-m-d')) }}"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('start_date') border-red-500 @enderror" required>
                    @error('start_date')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="target_completion_date" class="block text-sm font-medium text-gray-700 mb-2">Target Completion Date <span class="text-red-500">*</span></label>
                    <input type="date" id="target_completion_date" name="target_completion_date" value="{{ old('target_completion_date') }}"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('target_completion_date') border-red-500 @enderror" required>
                    @error('target_completion_date')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
            </div>
        </div>

        {{-- Section 3: Budget & Dependencies --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6">
                <div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">3</div>
                <h2 class="text-lg font-semibold text-[#1A365D]">Budget & Dependencies</h2>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div>
                    <label for="cost_estimate" class="block text-sm font-medium text-gray-700 mb-2">Cost Estimate</label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 text-sm font-medium">&#8358;</span>
                        <input type="number" id="estimated_cost" name="estimated_cost" value="{{ old('estimated_cost') }}" placeholder="e.g. 5000000"
                               class="w-full pl-8 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                    </div>
                    <p class="text-xs text-gray-500 mt-1">Estimated budget in Naira</p>
                    @error('estimated_cost')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="expected_residual_rating" class="block text-sm font-medium text-gray-700 mb-2">Expected Residual Rating After Treatment</label>
                    <select id="expected_residual_rating" name="expected_residual_rating" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        <option value="">Select Expected Rating</option>
                        @foreach (['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'] as $val => $label)
                            <option value="{{ $val }}" {{ old('expected_residual_rating') === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('expected_residual_rating')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>

                <div class="lg:col-span-2">
                    <label for="dependencies" class="block text-sm font-medium text-gray-700 mb-2">Dependencies</label>
                    <textarea id="dependencies" name="dependencies" rows="3" placeholder="List any dependencies, prerequisites, or related projects..."
                              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">{{ old('dependencies') }}</textarea>
                    @error('dependencies')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>

                <div class="lg:col-span-2">
                    <label for="success_criteria" class="block text-sm font-medium text-gray-700 mb-2">Success Criteria</label>
                    <textarea id="success_criteria" name="success_criteria" rows="3" placeholder="Define measurable criteria for plan success..."
                              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">{{ old('success_criteria') }}</textarea>
                    @error('success_criteria')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
            </div>
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
