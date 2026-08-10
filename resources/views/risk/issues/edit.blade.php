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

        {{--
            WP-05 TASK 2 — rendered from object_attributes on the Issue type.

            recommended_action is excluded: IssueController::update() does not
            accept it, so it would be an editable field that never saves. It is
            set when the issue is logged and revised through the remediation
            actions, not by overwriting the original recommendation.

            The CBN response deadline appears only once the issue is marked a
            CBN examination finding — a conditional-visibility rule on the
            field, not an @if here.
        --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-5">
                <span class="material-symbols-outlined text-[#D4AF37]">bug_report</span>
                <h2 class="text-base font-bold text-[#1A365D]">Issue Details</h2>
            </div>

            <x-dynamic-form type="Issue" :record="$issue" :omit="['recommended_action']" />
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
