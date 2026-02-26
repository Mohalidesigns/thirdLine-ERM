@extends('layouts.app')

@section('title', 'Edit ' . ($issue->issue_reference ?? 'Issue') . ' - GRC Platform')

@section('breadcrumbs')
    <span>Risk Management</span>
    <span class="text-gray-300">/</span>
    <span>Issues & Findings</span>
    <span class="text-gray-300">/</span>
    <a href="{{ url('/risk/issues/' . $issue->id) }}" class="hover:text-[#1A365D]">{{ $issue->issue_reference ?? 'Detail' }}</a>
    <span class="text-gray-300">/</span>
    <span class="text-[#1A365D] font-semibold">Edit</span>
@endsection

@section('content')
    {{-- Page Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">Edit Issue / Finding</h1>
            <p class="text-sm text-gray-500 mt-1">Update issue details for {{ $issue->issue_reference }}</p>
        </div>
        <a href="{{ url('/risk/issues/' . $issue->id) }}" class="flex items-center gap-1 text-xs text-gray-500 hover:text-[#1A365D]">
            <span class="material-symbols-outlined text-sm">arrow_back</span>
            Back to Issue
        </a>
    </div>

    {{-- Validation Errors --}}
    @if ($errors->any())
        <div class="mb-6 px-4 py-3 bg-red-50 border border-red-200 rounded-lg">
            <div class="flex items-center gap-2 text-red-700 text-sm font-medium mb-2">
                <span class="material-symbols-outlined text-lg">error</span>
                Please correct the following errors:
            </div>
            <ul class="list-disc list-inside text-xs text-red-600 space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ url('/risk/issues/' . $issue->id) }}" id="issueEditForm">
        @csrf
        @method('PUT')

        {{-- Issue Details --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-5">
                <span class="material-symbols-outlined text-[#D4AF37]">bug_report</span>
                <h2 class="text-base font-bold text-[#1A365D]">Issue Details</h2>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {{-- Title --}}
                <div class="lg:col-span-2">
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                        Issue Title <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="title" value="{{ old('title', $issue->title) }}" required
                           class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('title') border-red-300 @enderror"
                           placeholder="Clear, concise title describing the issue or finding">
                    @error('title')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Description --}}
                <div class="lg:col-span-2">
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                        Description <span class="text-red-500">*</span>
                    </label>
                    <textarea name="description" rows="4" required
                              class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('description') border-red-300 @enderror"
                              placeholder="Detailed description of the issue">{{ old('description', $issue->description) }}</textarea>
                    @error('description')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Source --}}
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                        Source <span class="text-red-500">*</span>
                    </label>
                    @php $currentSource = old('issue_source', $issue->issue_source); @endphp
                    <select name="issue_source" required
                            class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('issue_source') border-red-300 @enderror">
                        <option value="">Select Source</option>
                        @foreach ([
                            'INTERNAL_AUDIT' => 'Internal Audit',
                            'EXTERNAL_AUDIT' => 'External Audit',
                            'CBN_EXAMINATION' => 'CBN Examination',
                            'SELF_IDENTIFIED' => 'Self-Identified',
                            'REGULATORY_REVIEW' => 'Regulatory Review',
                            'CUSTOMER_COMPLAINT' => 'Customer Complaint',
                            'INCIDENT_REPORT' => 'Incident Report',
                            'RCSA' => 'RCSA',
                            'WHISTLE_BLOWER' => 'Whistle-blower',
                        ] as $value => $label)
                            <option value="{{ $value }}" {{ $currentSource === $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('issue_source')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Priority --}}
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                        Priority <span class="text-red-500">*</span>
                    </label>
                    @php $currentPriority = old('priority', $issue->priority); @endphp
                    <select name="priority" required
                            class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('priority') border-red-300 @enderror">
                        <option value="">Select Priority</option>
                        @foreach (['CRITICAL' => 'Critical', 'HIGH' => 'High', 'MEDIUM' => 'Medium', 'LOW' => 'Low'] as $value => $label)
                            <option value="{{ $value }}" {{ $currentPriority === $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('priority')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Business Unit --}}
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                        Business Unit <span class="text-red-500">*</span>
                    </label>
                    @php $currentBU = old('business_unit_id', $issue->business_unit_id); @endphp
                    <select name="business_unit_id" required
                            class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('business_unit_id') border-red-300 @enderror">
                        <option value="">Select Business Unit</option>
                        @foreach (($businessUnits ?? []) as $unit)
                            <option value="{{ $unit->id }}" {{ $currentBU == $unit->id ? 'selected' : '' }}>{{ $unit->name }}</option>
                        @endforeach
                    </select>
                    @error('business_unit_id')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Issue Owner --}}
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                        Issue Owner <span class="text-red-500">*</span>
                    </label>
                    @php $currentOwner = old('responsible_owner_id', $issue->responsible_owner_id); @endphp
                    <select name="responsible_owner_id" required
                            class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('responsible_owner_id') border-red-300 @enderror">
                        <option value="">Select Owner</option>
                        @foreach (($users ?? []) as $user)
                            <option value="{{ $user->id }}" {{ $currentOwner == $user->id ? 'selected' : '' }}>{{ $user->name }}</option>
                        @endforeach
                    </select>
                    @error('responsible_owner_id')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Linked Risk --}}
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">Linked Risk</label>
                    @php $currentRisk = old('risk_register_id', $issue->risk_register_id); @endphp
                    <select name="risk_register_id"
                            class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        <option value="">None</option>
                        @foreach (($risks ?? []) as $risk)
                            <option value="{{ $risk->id }}" {{ $currentRisk == $risk->id ? 'selected' : '' }}>{{ $risk->risk_code }} - {{ $risk->title }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Issue Category --}}
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">Category</label>
                    @php $currentCategory = old('issue_category', $issue->issue_category); @endphp
                    <select name="issue_category"
                            class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        <option value="">Select Category</option>
                        @foreach (['OPERATIONAL', 'COMPLIANCE', 'FINANCIAL', 'TECHNOLOGY', 'GOVERNANCE', 'STRATEGIC', 'OTHER'] as $cat)
                            <option value="{{ $cat }}" {{ $currentCategory === $cat ? 'selected' : '' }}>{{ ucwords(strtolower(str_replace('_', ' ', $cat))) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        {{-- Remediation Plan --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-5">
                <span class="material-symbols-outlined text-[#D4AF37]">healing</span>
                <h2 class="text-base font-bold text-[#1A365D]">Remediation Plan</h2>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {{-- Remediation Due Date --}}
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                        Remediation Due Date <span class="text-red-500">*</span>
                    </label>
                    <input type="date" name="target_resolution_date"
                           value="{{ old('target_resolution_date', $issue->target_resolution_date ?? ($issue->remediation_due_date ? \Carbon\Carbon::parse($issue->remediation_due_date)->format('Y-m-d') : '')) }}"
                           required
                           class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('target_resolution_date') border-red-300 @enderror">
                    @error('target_resolution_date')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Management Response Due --}}
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">Management Response Due</label>
                    <input type="date" name="management_response_due"
                           value="{{ old('management_response_due', $issue->management_response_due ? \Carbon\Carbon::parse($issue->management_response_due)->format('Y-m-d') : '') }}"
                           class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                </div>

                {{-- Root Cause --}}
                <div class="lg:col-span-2">
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">Root Cause Analysis</label>
                    <textarea name="root_cause" rows="3"
                              class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                              placeholder="Describe the root cause of this issue">{{ old('root_cause', $issue->root_cause) }}</textarea>
                </div>

                {{-- Action Plan --}}
                <div class="lg:col-span-2">
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">Action Plan / Remediation Steps</label>
                    <textarea name="action_plan" rows="4"
                              class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('action_plan') border-red-300 @enderror"
                              placeholder="Describe the steps that will be taken to remediate this issue">{{ old('action_plan', $issue->action_plan) }}</textarea>
                </div>

                {{-- Management Response --}}
                <div class="lg:col-span-2">
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">Management Response</label>
                    <textarea name="management_response" rows="3"
                              class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                              placeholder="Management's response to this issue">{{ old('management_response', $issue->management_response) }}</textarea>
                </div>

                {{-- Interim Controls --}}
                <div class="lg:col-span-2">
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">Interim Controls / Mitigating Actions</label>
                    <textarea name="interim_controls" rows="3"
                              class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                              placeholder="Describe any interim controls or immediate mitigating actions">{{ old('interim_controls', $issue->interim_controls) }}</textarea>
                </div>

                {{-- Impact Description --}}
                <div class="lg:col-span-2">
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">Impact Description</label>
                    <textarea name="impact_description" rows="3"
                              class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                              placeholder="Describe the impact of this issue">{{ old('impact_description', $issue->impact_description) }}</textarea>
                </div>
            </div>
        </div>

        {{-- Regulatory Information --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-5">
                <span class="material-symbols-outlined text-red-500">gavel</span>
                <h2 class="text-base font-bold text-[#1A365D]">Regulatory Information</h2>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {{-- CBN Examination Finding --}}
                <div class="lg:col-span-2">
                    <label class="flex items-center gap-2 text-xs font-semibold text-gray-700 cursor-pointer">
                        <input type="checkbox" name="cbn_examination_finding" value="1"
                               {{ old('cbn_examination_finding', $issue->cbn_examination_finding) ? 'checked' : '' }}
                               class="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                               onchange="document.getElementById('cbnFields').classList.toggle('hidden', !this.checked)">
                        This is a CBN Examination Finding
                    </label>
                </div>

                {{-- CBN-specific fields --}}
                <div id="cbnFields" class="lg:col-span-2 {{ old('cbn_examination_finding', $issue->cbn_examination_finding) ? '' : 'hidden' }}">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 p-4 bg-red-50/50 rounded-lg border border-red-200">
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1.5">CBN Examination Report Reference</label>
                            <input type="text" name="examination_ref" value="{{ old('examination_ref', $issue->examination_ref) }}"
                                   class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] bg-white"
                                   placeholder="e.g., CBN/EXM/2025/001">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1.5">CBN Response Deadline</label>
                            <input type="date" name="cbn_response_deadline"
                                   value="{{ old('cbn_response_deadline', $issue->cbn_response_deadline ? \Carbon\Carbon::parse($issue->cbn_response_deadline)->format('Y-m-d') : '') }}"
                                   class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] bg-white">
                        </div>
                    </div>
                </div>

                {{-- NDPA Breach Type --}}
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">NDPA Breach Type (if applicable)</label>
                    @php $currentNdpa = old('ndpa_breach_type', $issue->ndpa_breach_type); @endphp
                    <select name="ndpa_breach_type"
                            class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        <option value="">None</option>
                        @foreach (['Data Collection without Consent', 'Unauthorized Data Processing', 'Breach of Data Subject Rights', 'Cross-Border Transfer Violation', 'Data Breach Notification Failure', 'Inadequate Security Measures', 'Data Retention Violation'] as $type)
                            <option value="{{ $type }}" {{ $currentNdpa === $type ? 'selected' : '' }}>{{ $type }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Regulatory Reportable --}}
                <div>
                    <label class="flex items-center gap-2 text-xs font-semibold text-gray-700 cursor-pointer mt-6">
                        <input type="checkbox" name="regulatory_reportable" value="1"
                               {{ old('regulatory_reportable', $issue->regulatory_reportable) ? 'checked' : '' }}
                               class="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]">
                        Regulatory Reportable
                    </label>
                </div>
            </div>
        </div>

        {{-- Form Actions --}}
        <div class="flex items-center justify-between">
            <a href="{{ url('/risk/issues/' . $issue->id) }}" class="px-4 py-2.5 border border-gray-200 rounded-lg text-sm font-medium text-gray-600 hover:bg-gray-50 transition">
                Cancel
            </a>
            <button type="submit" class="flex items-center gap-2 px-6 py-2.5 bg-[#1A365D] text-white rounded-lg text-sm font-semibold hover:bg-[#2D4A7A] transition">
                <span class="material-symbols-outlined text-lg">save</span>
                Save Changes
            </button>
        </div>
    </form>
@endsection
