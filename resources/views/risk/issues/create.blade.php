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

        {{--
            WP-05 TASK 2 — the field grid comes from object_attributes on the
            Issue type. Sections (Details, Classification, Ownership, Analysis)
            are metadata, not markup.

            The examination reference only appears once the source is set to
            "Regulatory examination" — a conditional-visibility rule on the
            field, not an @if in this file. Attachments stay hand-written below:
            a file upload is not a field value.
        --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-5">
                <span class="material-symbols-outlined text-[#D4AF37]">bug_report</span>
                <h2 class="text-base font-bold text-[#1A365D]">Issue Details</h2>
            </div>

            <x-dynamic-form type="Issue"
                            :sections="['Details', 'Classification', 'Ownership', 'Analysis']"
                            :defaults="['risk_register_id' => request('risk_id')]" />
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
