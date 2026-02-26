@extends('layouts.app')

@section('title', 'Issues Register - GRC Platform')

@section('breadcrumbs')
    <span>Risk Management</span>
    <span class="text-gray-300">/</span>
    <span>Issues & Findings</span>
    <span class="text-gray-300">/</span>
    <span class="text-[#1A365D] font-semibold">Issues Register</span>
@endsection

@section('content')
    {{-- Page Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">Issues Register</h1>
            <p class="text-sm text-gray-500 mt-1">Comprehensive register of all issues, findings, and audit observations</p>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ route('risk.export.issues') }}" class="flex items-center gap-2 px-3 py-2 border border-gray-200 rounded-lg text-xs font-medium text-gray-600 hover:bg-gray-50 transition">
                <span class="material-symbols-outlined text-sm">download</span>
                Export
            </a>
            <a href="{{ url('/risk/issues/create') }}" class="flex items-center gap-2 px-4 py-2 bg-[#1A365D] text-white rounded-lg text-xs font-semibold hover:bg-[#2D4A7A] transition">
                <span class="material-symbols-outlined text-sm">add</span>
                Log New Issue
            </a>
        </div>
    </div>

    {{-- Flash Messages --}}
    @if (session('success'))
        <div class="mb-4 px-4 py-3 bg-green-50 border border-green-200 text-green-700 rounded-lg text-sm flex items-center gap-2">
            <span class="material-symbols-outlined text-lg">check_circle</span>
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="mb-4 px-4 py-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm flex items-center gap-2">
            <span class="material-symbols-outlined text-lg">error</span>
            {{ session('error') }}
        </div>
    @endif

    {{-- Filters Panel --}}
    <div class="bg-white rounded-xl border border-gray-200 p-5 mb-6">
        <form method="GET" action="{{ url('/risk/issues') }}" id="issueFilterForm">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold text-[#1A365D] flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">filter_list</span>
                    Filters
                </h3>
                <a href="{{ url('/risk/issues') }}" class="text-xs text-gray-500 hover:text-[#1A365D]">Clear All</a>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
                {{-- Status --}}
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Status</label>
                    <select name="status" class="w-full text-xs border border-gray-200 rounded-lg px-3 py-2 focus:ring-1 focus:ring-[#1A365D] focus:border-[#1A365D]">
                        <option value="">All Statuses</option>
                        @foreach (['Open', 'In Progress', 'Pending Closure', 'Closed', 'Overdue', 'Escalated'] as $status)
                            <option value="{{ strtolower($status) }}" {{ request('status') === strtolower($status) ? 'selected' : '' }}>{{ $status }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Priority --}}
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Priority</label>
                    <select name="priority" class="w-full text-xs border border-gray-200 rounded-lg px-3 py-2 focus:ring-1 focus:ring-[#1A365D] focus:border-[#1A365D]">
                        <option value="">All Priorities</option>
                        @foreach (['Critical', 'High', 'Medium', 'Low'] as $pri)
                            <option value="{{ strtolower($pri) }}" {{ request('priority') === strtolower($pri) ? 'selected' : '' }}>{{ $pri }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Source --}}
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Source</label>
                    <select name="source" class="w-full text-xs border border-gray-200 rounded-lg px-3 py-2 focus:ring-1 focus:ring-[#1A365D] focus:border-[#1A365D]">
                        <option value="">All Sources</option>
                        @foreach (['Internal Audit', 'External Audit', 'CBN Examination', 'Self-Identified', 'Regulatory Review', 'Customer Complaint', 'Incident Report'] as $src)
                            <option value="{{ $src }}" {{ request('source') === $src ? 'selected' : '' }}>{{ $src }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Overdue --}}
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Overdue Status</label>
                    <select name="overdue" class="w-full text-xs border border-gray-200 rounded-lg px-3 py-2 focus:ring-1 focus:ring-[#1A365D] focus:border-[#1A365D]">
                        <option value="">All</option>
                        <option value="yes" {{ request('overdue') === 'yes' ? 'selected' : '' }}>Overdue Only</option>
                        <option value="no" {{ request('overdue') === 'no' ? 'selected' : '' }}>Not Overdue</option>
                    </select>
                </div>

                {{-- Escalation Level --}}
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Escalation Level</label>
                    <select name="escalation_level" class="w-full text-xs border border-gray-200 rounded-lg px-3 py-2 focus:ring-1 focus:ring-[#1A365D] focus:border-[#1A365D]">
                        <option value="">All Levels</option>
                        @foreach (['0' => 'Level 0 - None', '1' => 'Level 1', '2' => 'Level 2', '3' => 'Level 3 - Board'] as $key => $label)
                            <option value="{{ $key }}" {{ request('escalation_level') === (string) $key ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="flex items-center justify-end mt-4 pt-3 border-t border-gray-100">
                <button type="submit" class="px-4 py-2 bg-[#1A365D] text-white text-xs font-semibold rounded-lg hover:bg-[#2D4A7A] transition">
                    Apply Filters
                </button>
            </div>
        </form>
    </div>

    {{-- Results Summary --}}
    <div class="flex items-center justify-between mb-3">
        <span class="text-xs text-gray-500">
            Showing {{ $issues->firstItem() ?? 0 }}-{{ $issues->lastItem() ?? 0 }} of {{ $issues->total() }} issues
        </span>
    </div>

    {{-- Issues Table --}}
    <x-data-table id="issuesTable">
        <x-slot:head>
            <th>Reference</th>
            <th>Title</th>
            <th>Source</th>
            <th>Priority</th>
            <th>Status</th>
            <th>Owner</th>
            <th>Due Date</th>
            <th>Days Open</th>
            <th>Escalation</th>
            <th>Actions</th>
        </x-slot:head>

        @forelse ($issues as $issue)
            <tr class="{{ $issue->is_overdue ? 'bg-red-50/30' : '' }}">
                <td>
                    <a href="{{ url('/risk/issues/' . $issue->id) }}" class="text-[#1A365D] font-semibold hover:underline text-xs">
                        {{ $issue->reference }}
                    </a>
                </td>
                <td class="max-w-[200px]">
                    <div class="truncate text-sm font-medium text-gray-800">{{ $issue->title }}</div>
                    @if ($issue->cbn_examination_finding)
                        <span class="inline-flex items-center gap-0.5 mt-0.5 px-1.5 py-0.5 rounded bg-red-50 text-red-600 text-[9px] font-bold">
                            CBN Finding
                        </span>
                    @endif
                </td>
                <td class="text-xs">
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-gray-100 text-gray-700 text-[11px] font-medium">
                        {{ $issue->source ?? '-' }}
                    </span>
                </td>
                <td><x-risk-badge :rating="$issue->priority ?? 'medium'" /></td>
                <td><x-status-badge :status="$issue->status ?? 'open'" /></td>
                <td class="text-xs text-gray-600">{{ $issue->owner->name ?? '-' }}</td>
                <td class="text-xs {{ $issue->is_overdue ? 'text-red-600 font-semibold' : 'text-gray-500' }}">
                    {{ $issue->due_date?->format('d M Y') ?? '-' }}
                </td>
                <td class="text-xs text-gray-500">
                    @if ($issue->status !== 'closed' && $issue->created_at)
                        {{ $issue->created_at->diffInDays(now()) }}d
                    @else
                        -
                    @endif
                </td>
                <td>
                    @php
                        $escLevel = $issue->escalation_level ?? 0;
                        $escColors = ['bg-gray-100 text-gray-500', 'bg-yellow-100 text-yellow-700', 'bg-orange-100 text-orange-700', 'bg-red-100 text-red-700'];
                    @endphp
                    <span class="badge {{ $escColors[$escLevel] ?? $escColors[0] }}">L{{ $escLevel }}</span>
                </td>
                <td>
                    <div class="flex items-center gap-1">
                        <a href="{{ url('/risk/issues/' . $issue->id) }}" class="p-1 rounded hover:bg-gray-100" title="View">
                            <span class="material-symbols-outlined text-gray-500 text-lg">visibility</span>
                        </a>
                        <a href="{{ url('/risk/issues/' . $issue->id . '/edit') }}" class="p-1 rounded hover:bg-gray-100" title="Edit">
                            <span class="material-symbols-outlined text-gray-500 text-lg">edit</span>
                        </a>
                    </div>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="10" class="text-center py-12 text-gray-400">
                    <span class="material-symbols-outlined text-4xl mb-2 block">search_off</span>
                    <p class="text-sm font-medium">No issues found</p>
                    <p class="text-xs mt-1">Try adjusting your filters or log a new issue</p>
                    <a href="{{ url('/risk/issues/create') }}" class="inline-flex items-center gap-1 mt-3 px-4 py-2 bg-[#1A365D] text-white text-xs rounded-lg hover:bg-[#2D4A7A]">
                        <span class="material-symbols-outlined text-sm">add</span>
                        Log New Issue
                    </a>
                </td>
            </tr>
        @endforelse
    </x-data-table>

    {{-- Pagination --}}
    @if ($issues->hasPages())
        <div class="mt-4 flex justify-center">
            {{ $issues->withQueryString()->links() }}
        </div>
    @endif

@endsection
