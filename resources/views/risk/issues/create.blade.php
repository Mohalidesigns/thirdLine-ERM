@extends('layouts.app')

@section('title', 'Log New Issue - GRC Platform')

@section('breadcrumbs')
    <span>Risk Management</span>
    <span class="text-gray-300">/</span>
    <span>Issues & Findings</span>
    <span class="text-gray-300">/</span>
    <span class="text-[#1A365D] font-semibold">Log New Issue</span>
@endsection

@section('content')
    {{-- Page Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">Log New Issue / Finding</h1>
            <p class="text-sm text-gray-500 mt-1">Record audit findings, regulatory issues, and operational observations</p>
        </div>
        <a href="{{ url('/risk/issues') }}" class="flex items-center gap-1 text-xs text-gray-500 hover:text-[#1A365D]">
            <span class="material-symbols-outlined text-sm">arrow_back</span>
            Back to Register
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

    <form method="POST" action="{{ url('/risk/issues') }}" enctype="multipart/form-data" id="issueForm">
        @csrf

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
                    <input type="text" name="title" value="{{ old('title') }}" required
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
                              placeholder="Detailed description of the issue, its impact, and how it was identified">{{ old('description') }}</textarea>
                    @error('description')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Source --}}
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                        Source <span class="text-red-500">*</span>
                    </label>
                    <select name="source" required
                            class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('source') border-red-300 @enderror">
                        <option value="">Select Source</option>
                        @foreach (['Internal Audit', 'External Audit', 'CBN Examination', 'Self-Identified', 'Regulatory Review', 'Customer Complaint', 'Incident Report', 'RCSA', 'Whistle-blower'] as $src)
                            <option value="{{ $src }}" {{ old('source') === $src ? 'selected' : '' }}>{{ $src }}</option>
                        @endforeach
                    </select>
                    @error('source')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Category --}}
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                        Category <span class="text-red-500">*</span>
                    </label>
                    <select name="category" required
                            class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('category') border-red-300 @enderror">
                        <option value="">Select Category</option>
                        @foreach (($categories ?? ['Process Deficiency', 'Control Weakness', 'Policy Non-Compliance', 'System Issue', 'Governance Gap', 'Documentation Gap', 'Regulatory Non-Compliance', 'Data Quality', 'Other']) as $cat)
                            @php $catValue = is_string($cat) ? $cat : $cat->name; @endphp
                            <option value="{{ $catValue }}" {{ old('category') === $catValue ? 'selected' : '' }}>{{ $catValue }}</option>
                        @endforeach
                    </select>
                    @error('category')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Priority --}}
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                        Priority <span class="text-red-500">*</span>
                    </label>
                    <select name="priority" required
                            class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('priority') border-red-300 @enderror">
                        <option value="">Select Priority</option>
                        @foreach (['Critical', 'High', 'Medium', 'Low'] as $pri)
                            <option value="{{ strtolower($pri) }}" {{ old('priority') === strtolower($pri) ? 'selected' : '' }}>{{ $pri }}</option>
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
                    <select name="business_unit_id" required
                            class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('business_unit_id') border-red-300 @enderror">
                        <option value="">Select Business Unit</option>
                        @foreach (($businessUnits ?? []) as $unit)
                            <option value="{{ $unit->id }}" {{ old('business_unit_id') == $unit->id ? 'selected' : '' }}>{{ $unit->name }}</option>
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
                    <select name="owner_id" required
                            class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('owner_id') border-red-300 @enderror">
                        <option value="">Select Owner</option>
                        @foreach (($users ?? []) as $owner)
                            <option value="{{ $owner->id }}" {{ old('owner_id') == $owner->id ? 'selected' : '' }}>{{ $owner->name }}</option>
                        @endforeach
                    </select>
                    @error('owner_id')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Date Identified --}}
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                        Date Identified <span class="text-red-500">*</span>
                    </label>
                    <input type="date" name="date_identified" value="{{ old('date_identified', date('Y-m-d')) }}" required
                           class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('date_identified') border-red-300 @enderror">
                    @error('date_identified')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
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
                {{-- Response Due Date --}}
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                        Response Due Date <span class="text-red-500">*</span>
                    </label>
                    <input type="date" name="due_date" value="{{ old('due_date') }}" required
                           class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('due_date') border-red-300 @enderror">
                    @error('due_date')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Target Completion Date --}}
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">Target Completion Date</label>
                    <input type="date" name="target_completion_date" value="{{ old('target_completion_date') }}"
                           class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                </div>

                {{-- Action Plan --}}
                <div class="lg:col-span-2">
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                        Action Plan / Remediation Steps <span class="text-red-500">*</span>
                    </label>
                    <textarea name="action_plan" rows="4" required
                              class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('action_plan') border-red-300 @enderror"
                              placeholder="Describe the steps that will be taken to remediate this issue">{{ old('action_plan') }}</textarea>
                    @error('action_plan')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Interim Controls --}}
                <div class="lg:col-span-2">
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">Interim Controls / Mitigating Actions</label>
                    <textarea name="interim_controls" rows="3"
                              class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                              placeholder="Describe any interim controls or immediate mitigating actions taken">{{ old('interim_controls') }}</textarea>
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
                        <input type="checkbox" name="cbn_examination_finding" value="1" {{ old('cbn_examination_finding') ? 'checked' : '' }}
                               class="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                               onchange="document.getElementById('cbnFields').classList.toggle('hidden', !this.checked)">
                        This is a CBN Examination Finding
                    </label>
                </div>

                {{-- CBN-specific fields --}}
                <div id="cbnFields" class="lg:col-span-2 {{ old('cbn_examination_finding') ? '' : 'hidden' }}">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 p-4 bg-red-50/50 rounded-lg border border-red-200">
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1.5">CBN Examination Report Reference</label>
                            <input type="text" name="cbn_report_reference" value="{{ old('cbn_report_reference') }}"
                                   class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] bg-white"
                                   placeholder="e.g., CBN/EXM/2025/001">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1.5">CBN Regulatory Deadline</label>
                            <input type="date" name="cbn_regulatory_deadline" value="{{ old('cbn_regulatory_deadline') }}"
                                   class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] bg-white">
                        </div>
                    </div>
                </div>

                {{-- BOFIA Section --}}
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">BOFIA Section (if applicable)</label>
                    <select name="bofia_section"
                            class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        <option value="">Select BOFIA Section</option>
                        @foreach (['Section 2 - Licensing', 'Section 12 - Capital Adequacy', 'Section 15 - Reserve Fund', 'Section 16 - Dividend', 'Section 18 - Exposure Limits', 'Section 28 - Returns', 'Section 34 - Internal Controls', 'Section 56 - AML/CFT', 'Other'] as $section)
                            <option value="{{ $section }}" {{ old('bofia_section') === $section ? 'selected' : '' }}>{{ $section }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- NDPA Breach Type --}}
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">NDPA Breach Type (if applicable)</label>
                    <select name="ndpa_breach_type"
                            class="w-full text-sm border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        <option value="">Select Breach Type</option>
                        @foreach (['Data Collection without Consent', 'Unauthorized Data Processing', 'Breach of Data Subject Rights', 'Cross-Border Transfer Violation', 'Data Breach Notification Failure', 'Inadequate Security Measures', 'Data Retention Violation', 'None'] as $type)
                            <option value="{{ $type }}" {{ old('ndpa_breach_type') === $type ? 'selected' : '' }}>{{ $type }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        {{-- Attachments --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-5">
                <span class="material-symbols-outlined text-[#D4AF37]">attach_file</span>
                <h2 class="text-base font-bold text-[#1A365D]">Attachments</h2>
            </div>
            <div class="border-2 border-dashed border-gray-200 rounded-lg p-6 text-center hover:border-[#1A365D]/30 transition">
                <input type="file" name="attachments[]" multiple id="fileInput" class="hidden">
                <label for="fileInput" class="cursor-pointer">
                    <span class="material-symbols-outlined text-3xl text-gray-400 mb-2 block">cloud_upload</span>
                    <p class="text-sm text-gray-600 font-medium">Click to upload or drag and drop</p>
                    <p class="text-xs text-gray-400 mt-1">PDF, DOC, XLSX, JPG, PNG (max 10MB each)</p>
                </label>
            </div>
        </div>

        {{-- Form Actions --}}
        <div class="flex items-center justify-between">
            <a href="{{ url('/risk/issues') }}" class="px-4 py-2.5 border border-gray-200 rounded-lg text-sm font-medium text-gray-600 hover:bg-gray-50 transition">
                Cancel
            </a>
            <div class="flex items-center gap-3">
                <button type="submit" name="action" value="draft" class="px-4 py-2.5 border border-gray-200 rounded-lg text-sm font-medium text-gray-600 hover:bg-gray-50 transition">
                    Save as Draft
                </button>
                <button type="submit" name="action" value="submit" class="flex items-center gap-2 px-6 py-2.5 bg-[#1A365D] text-white rounded-lg text-sm font-semibold hover:bg-[#2D4A7A] transition">
                    <span class="material-symbols-outlined text-lg">check</span>
                    Submit Issue
                </button>
            </div>
        </div>
    </form>
@endsection
