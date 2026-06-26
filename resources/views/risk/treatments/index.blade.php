@extends('layouts.app')

@section('title', 'Treatment Plans - GRC Risk Management')
@section('page-section', 'Treatment Plans')
@section('page-title', 'All Plans')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.treatments.dashboard') }}" class="hover:text-[#1A365D]">Treatment Plans</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">All Plans</span>
@endsection

@section('content')
    {{-- Flash Messages --}}
    @if (session('success'))
        <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-xl flex items-center gap-3">
            <span class="material-symbols-outlined text-green-600">check_circle</span>
            <span class="text-sm text-green-700">{{ session('success') }}</span>
            <button onclick="this.parentElement.remove()" class="ml-auto text-green-400 hover:text-green-600">
                <span class="material-symbols-outlined text-lg">close</span>
            </button>
        </div>
    @endif

    {{-- Page Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-[#1A365D]">Treatment Plans</h1>
            <p class="text-sm text-gray-500 mt-1">{{ $plans->total() ?? 0 }} plans registered &middot; Last updated: {{ now()->format('M d, Y') }}</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('risk.treatments.create') }}"
               class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-2 transition-colors">
                <span class="material-symbols-outlined text-lg">add_circle</span> New Plan
            </a>
            <button onclick="window.print()" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2 transition-colors">
                <span class="material-symbols-outlined text-lg">download</span> Export
            </button>
        </div>
    </div>

    {{-- Quick Filter Tabs --}}
    <div class="flex gap-1 mb-4 bg-white rounded-lg border border-gray-200 p-1 w-fit flex-wrap">
        <a href="{{ route('risk.treatments.index') }}"
           class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors {{ !request('status') && !request('strategy') ? 'bg-[#1A365D] text-white' : 'text-gray-600 hover:bg-gray-100' }}">
            All
        </a>
        @foreach (['In Progress', 'Completed', 'Overdue', 'Not Started', 'On Hold'] as $statusTab)
            <a href="{{ route('risk.treatments.index', array_merge(request()->except('page'), ['status' => strtolower(str_replace(' ', '_', $statusTab))])) }}"
               class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors {{ request('status') === strtolower(str_replace(' ', '_', $statusTab)) ? 'bg-[#1A365D] text-white' : 'text-gray-600 hover:bg-gray-100' }}">
                {{ $statusTab }}
            </a>
        @endforeach
    </div>

    {{-- Filter Bar --}}
    <form method="GET" action="{{ route('risk.treatments.index') }}" id="filterForm">
        <div class="bg-white rounded-xl border border-gray-200 p-4 mb-4">
            <div class="flex flex-wrap gap-3 items-center">
                <div class="flex-1 min-w-[200px]">
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-lg">search</span>
                        <input type="text" name="search" value="{{ request('search') }}"
                               placeholder="Search by plan title, risk code, owner..."
                               class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                               data-live-search>
                    </div>
                </div>

                <select name="strategy" class="border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-700" onchange="document.getElementById('filterForm').submit()">
                    <option value="">All Strategies</option>
                    @foreach (['Mitigate', 'Transfer', 'Accept', 'Avoid'] as $strategy)
                        <option value="{{ strtolower($strategy) }}" {{ request('strategy') === strtolower($strategy) ? 'selected' : '' }}>{{ $strategy }}</option>
                    @endforeach
                </select>

                <select name="priority" class="border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-700" onchange="document.getElementById('filterForm').submit()">
                    <option value="">All Priorities</option>
                    @foreach (['Critical', 'High', 'Medium', 'Low'] as $priority)
                        <option value="{{ strtolower($priority) }}" {{ request('priority') === strtolower($priority) ? 'selected' : '' }}>{{ $priority }}</option>
                    @endforeach
                </select>

                <select name="risk_id" class="border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-700" onchange="document.getElementById('filterForm').submit()">
                    <option value="">All Risks</option>
                    @foreach (($risks ?? []) as $risk)
                        <option value="{{ $risk->id }}" {{ request('risk_id') == $risk->id ? 'selected' : '' }}>{{ $risk->risk_code }} - {{ Str::limit($risk->title, 30) }}</option>
                    @endforeach
                </select>

                @if (request()->hasAny(['search', 'strategy', 'priority', 'status', 'risk_id']))
                    <a href="{{ route('risk.treatments.index') }}" class="text-xs text-[#1A365D] font-medium hover:underline">Clear Filters</a>
                @endif
            </div>
        </div>
    </form>

    {{-- Data Table --}}
    <x-data-table id="treatmentPlansTable">
        <x-slot name="head">
            <th class="w-8"><input type="checkbox" class="rounded" id="selectAll"></th>
            <th>Plan Title</th>
            <th>Linked Risk</th>
            <th>Strategy</th>
            <th>Priority</th>
            <th>Progress</th>
            <th>Owner</th>
            <th>Target Date</th>
            <th>Cost Estimate</th>
            <th>Status</th>
            <th>Actions</th>
        </x-slot>

        @forelse (($plans ?? []) as $plan)
            <tr class="hover:bg-blue-50/50">
                <td><input type="checkbox" class="rounded plan-checkbox" value="{{ $plan->id }}"></td>
                <td class="font-medium text-[#1A365D]">
                    <a href="{{ route('risk.treatments.show', $plan) }}" class="hover:underline">{{ Str::limit($plan->title, 35) }}</a>
                </td>
                <td>
                    @if ($plan->risk)
                        <a href="{{ route('risk.register.show', $plan->risk) }}" class="text-xs text-[#1A365D] hover:underline">{{ $plan->risk->risk_code }}</a>
                    @else
                        <span class="text-xs text-gray-400">-</span>
                    @endif
                </td>
                <td>
                    <span class="badge {{ $plan->strategy === 'mitigate' ? 'bg-blue-100 text-blue-700' : ($plan->strategy === 'transfer' ? 'bg-purple-100 text-purple-700' : ($plan->strategy === 'avoid' ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700')) }}">
                        {{ ucfirst($plan->strategy ?? '-') }}
                    </span>
                </td>
                <td><x-risk-badge :rating="$plan->priority ?? 'medium'" /></td>
                <td>
                    <div class="flex items-center gap-2">
                        <div class="w-16 bg-gray-200 rounded-full h-1.5">
                            <div class="h-1.5 rounded-full {{ ($plan->progress ?? 0) >= 75 ? 'bg-green-500' : (($plan->progress ?? 0) >= 50 ? 'bg-yellow-500' : 'bg-red-500') }}"
                                 style="width: {{ $plan->progress ?? 0 }}%"></div>
                        </div>
                        <span class="text-xs text-gray-600">{{ $plan->progress ?? 0 }}%</span>
                    </div>
                </td>
                <td>
                    @if ($plan->owner)
                        <div class="flex items-center gap-2">
                            <div class="w-6 h-6 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center text-[10px] font-bold">
                                {{ strtoupper(substr($plan->owner->name ?? '', 0, 1)) }}
                            </div>
                            <span class="text-xs">{{ Str::limit($plan->owner->name ?? '-', 15) }}</span>
                        </div>
                    @else
                        <span class="text-xs text-gray-400">Unassigned</span>
                    @endif
                </td>
                <td class="text-xs text-gray-500">
                    {{ $plan->target_date?->format('d M Y') ?? '-' }}
                    @if ($plan->target_date && $plan->target_date->isPast() && ($plan->status ?? '') !== 'completed')
                        <span class="text-red-500 text-[10px] block">Overdue</span>
                    @endif
                </td>
                <td class="text-xs font-medium">₦{{ number_format($plan->cost_estimate ?? 0) }}</td>
                <td><x-status-badge :status="$plan->status ?? 'not started'" type="treatment" /></td>
                <td>
                    <div class="flex items-center gap-1">
                        <a href="{{ route('risk.treatments.show', $plan) }}" class="p-1 hover:bg-gray-100 rounded" title="View">
                            <span class="material-symbols-outlined text-gray-400 text-lg">visibility</span>
                        </a>
                        <a href="{{ route('risk.treatments.edit', $plan) }}" class="p-1 hover:bg-gray-100 rounded" title="Edit">
                            <span class="material-symbols-outlined text-gray-400 text-lg">edit</span>
                        </a>
                    </div>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="11" class="text-center py-12">
                    <div class="flex flex-col items-center gap-3">
                        <span class="material-symbols-outlined text-4xl text-gray-300">assignment</span>
                        <p class="text-sm text-gray-500">No treatment plans found.</p>
                        @if (request()->hasAny(['search', 'strategy', 'priority', 'status', 'risk_id']))
                            <a href="{{ route('risk.treatments.index') }}" class="text-sm text-[#1A365D] font-medium hover:underline">Clear all filters</a>
                        @else
                            <a href="{{ route('risk.treatments.create') }}" class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] transition-colors">
                                Create First Plan
                            </a>
                        @endif
                    </div>
                </td>
            </tr>
        @endforelse
    </x-data-table>

    {{-- Pagination --}}
    @if (isset($plans) && $plans->hasPages())
        <div class="mt-4 flex items-center justify-between">
            <span class="text-xs text-gray-500">Showing {{ $plans->firstItem() }}-{{ $plans->lastItem() }} of {{ $plans->total() }} plans</span>
            <div class="flex gap-1">{{ $plans->appends(request()->query())->links('vendor.pagination.tailwind') }}</div>
        </div>
    @endif
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const selectAll = document.getElementById('selectAll');
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            document.querySelectorAll('.plan-checkbox').forEach(cb => cb.checked = selectAll.checked);
        });
    }
    const searchInput = document.querySelector('input[name="search"]');
    if (searchInput) {
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); document.getElementById('filterForm').submit(); }
        });
    }
});
</script>
@endpush
