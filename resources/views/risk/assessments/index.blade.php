@extends('layouts.app')

@section('title', 'Risk Assessments - GRC Risk Management')
@section('page-section', 'Assessments')
@section('page-title', 'Assessment List')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Risk Assessments</span>
@endsection

@section('content')
    @if (session('success'))
        <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-xl flex items-center gap-3">
            <span class="material-symbols-outlined text-green-600">check_circle</span>
            <span class="text-sm text-green-700">{{ session('success') }}</span>
            <button onclick="this.parentElement.remove()" class="ml-auto text-green-400 hover:text-green-600"><span class="material-symbols-outlined text-lg">close</span></button>
        </div>
    @endif

    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-[#1A365D]">Risk Assessments</h1>
            <p class="text-sm text-gray-500 mt-1">{{ $assessments->total() ?? 0 }} assessments &middot; Multi-dimensional risk scoring and tracking</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('risk.assessments.create') }}" class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-2">
                <span class="material-symbols-outlined text-lg">add_circle</span> New Assessment
            </a>
            <a href="{{ route('risk.export.assessments') }}" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2"><span class="material-symbols-outlined text-lg">download</span> Export</a>
        </div>
    </div>

    {{-- Filter --}}
    <form method="GET" action="{{ route('risk.assessments.index') }}" id="filterForm">
        <div class="bg-white rounded-xl border border-gray-200 p-4 mb-4">
            <div class="flex flex-wrap gap-3 items-center">
                <div class="flex-1 min-w-[200px]">
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-lg">search</span>
                        <input type="text" name="search" value="{{ request('search') }}" placeholder="Search assessments..." class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                    </div>
                </div>
                <select name="status" class="border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-700" onchange="document.getElementById('filterForm').submit()">
                    <option value="">All Statuses</option>
                    @foreach (['Draft', 'In Progress', 'Completed', 'Approved'] as $status)
                        <option value="{{ strtolower(str_replace(' ', '_', $status)) }}" {{ request('status') === strtolower(str_replace(' ', '_', $status)) ? 'selected' : '' }}>{{ $status }}</option>
                    @endforeach
                </select>
                <select name="risk_id" class="border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-700" onchange="document.getElementById('filterForm').submit()">
                    <option value="">All Risks</option>
                    @foreach (($risks ?? []) as $risk)
                        <option value="{{ $risk->id }}" {{ request('risk_id') == $risk->id ? 'selected' : '' }}>{{ $risk->risk_code }}</option>
                    @endforeach
                </select>
                @if (request()->hasAny(['search', 'status', 'risk_id']))
                    <a href="{{ route('risk.assessments.index') }}" class="text-xs text-[#1A365D] font-medium hover:underline">Clear</a>
                @endif
            </div>
        </div>
    </form>

    <x-data-table id="assessmentsTable">
        <x-slot name="head">
            <th>Assessment ID</th>
            <th>Risk</th>
            <th>Assessor</th>
            <th>Assessment Date</th>
            <th>Likelihood</th>
            <th>Impact</th>
            <th>Overall Score</th>
            <th>Rating</th>
            <th>vs Previous</th>
            <th>Status</th>
            <th>Actions</th>
        </x-slot>

        @forelse (($assessments ?? []) as $assessment)
            <tr class="hover:bg-blue-50/50">
                <td class="font-medium text-[#1A365D]"><a href="{{ route('risk.assessments.show', $assessment) }}" class="hover:underline">ASS-{{ str_pad($assessment->id, 4, '0', STR_PAD_LEFT) }}</a></td>
                <td class="text-xs">
                    @if ($assessment->risk)
                        <a href="{{ route('risk.register.show', $assessment->risk) }}" class="text-[#1A365D] hover:underline">{{ $assessment->risk->risk_code }}</a>
                    @else - @endif
                </td>
                <td class="text-xs">{{ $assessment->assessor->name ?? '-' }}</td>
                <td class="text-xs text-gray-500">{{ $assessment->assessment_date?->format('d M Y') ?? '-' }}</td>
                <td class="text-xs text-center font-medium">{{ $assessment->likelihood ?? '-' }}/5</td>
                <td class="text-xs text-center font-medium">{{ $assessment->impact ?? '-' }}/5</td>
                <td class="text-sm font-bold text-center">{{ $assessment->overall_score ?? '-' }}</td>
                <td><x-risk-badge :rating="$assessment->rating ?? 'medium'" /></td>
                <td>
                    @if (($assessment->score_change ?? 0) > 0)
                        <span class="text-red-500 text-xs flex items-center gap-0.5"><span class="material-symbols-outlined text-sm">arrow_upward</span>+{{ $assessment->score_change }}</span>
                    @elseif (($assessment->score_change ?? 0) < 0)
                        <span class="text-green-500 text-xs flex items-center gap-0.5"><span class="material-symbols-outlined text-sm">arrow_downward</span>{{ $assessment->score_change }}</span>
                    @else
                        <span class="text-gray-400 text-xs">-</span>
                    @endif
                </td>
                <td><x-status-badge :status="$assessment->status ?? 'draft'" /></td>
                <td>
                    <div class="flex items-center gap-1">
                        <a href="{{ route('risk.assessments.show', $assessment) }}" class="p-1 hover:bg-gray-100 rounded"><span class="material-symbols-outlined text-gray-400 text-lg">visibility</span></a>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="11" class="text-center py-12"><span class="material-symbols-outlined text-4xl text-gray-300 mb-2 block">assessment</span><p class="text-sm text-gray-500">No assessments found.</p><a href="{{ route('risk.assessments.create') }}" class="mt-2 inline-block px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A]">Create First Assessment</a></td></tr>
        @endforelse
    </x-data-table>

    @if (isset($assessments) && $assessments->hasPages())
        <div class="mt-4 flex items-center justify-between">
            <span class="text-xs text-gray-500">Showing {{ $assessments->firstItem() }}-{{ $assessments->lastItem() }} of {{ $assessments->total() }}</span>
            <div>{{ $assessments->appends(request()->query())->links('vendor.pagination.tailwind') }}</div>
        </div>
    @endif
@endsection
