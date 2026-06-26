@extends('layouts.app')

@section('title', 'Risk Register - GRC Risk Management')
@section('page-section', 'Risk Register')
@section('page-title', 'Register')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.register.index') }}" class="hover:text-[#1A365D]">Risk Register</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Register</span>
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

    @if (session('error'))
        <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-xl flex items-center gap-3">
            <span class="material-symbols-outlined text-red-600">error</span>
            <span class="text-sm text-red-700">{{ session('error') }}</span>
            <button onclick="this.parentElement.remove()" class="ml-auto text-red-400 hover:text-red-600">
                <span class="material-symbols-outlined text-lg">close</span>
            </button>
        </div>
    @endif

    {{-- Page Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-[#1A365D]">Risk Register</h1>
            <p class="text-sm text-gray-500 mt-1">{{ $risks->total() }} risks registered &middot; Last updated: {{ now()->format('M d, Y') }}</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('risk.register.create') }}"
               class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-2 transition-colors">
                <span class="material-symbols-outlined text-lg">add_circle</span> New Risk
            </a>
            <button class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2 transition-colors">
                <span class="material-symbols-outlined text-lg">upload</span> Import
            </button>
            <a href="{{ route('risk.export.register') }}" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2 transition-colors">
                <span class="material-symbols-outlined text-lg">download</span> Export
            </a>
        </div>
    </div>

    {{-- Quick Filter Tabs --}}
    <div class="flex gap-1 mb-4 bg-white rounded-lg border border-gray-200 p-1 w-fit flex-wrap">
        <a href="{{ route('risk.register.index') }}"
           class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors {{ !request('rating') && !request('status') ? 'bg-[#1A365D] text-white' : 'text-gray-600 hover:bg-gray-100' }}">
            All ({{ $risks->total() }})
        </a>
        @foreach (['Critical', 'High', 'Medium', 'Low'] as $ratingTab)
            <a href="{{ route('risk.register.index', array_merge(request()->except('page'), ['rating' => strtolower($ratingTab)])) }}"
               class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors {{ request('rating') === strtolower($ratingTab) ? 'bg-[#1A365D] text-white' : 'text-gray-600 hover:bg-gray-100' }}">
                {{ $ratingTab }}
            </a>
        @endforeach
        <a href="{{ route('risk.register.index', array_merge(request()->except('page'), ['status' => 'open'])) }}"
           class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors {{ request('status') === 'open' ? 'bg-[#1A365D] text-white' : 'text-gray-600 hover:bg-gray-100' }}">
            Open
        </a>
        <a href="{{ route('risk.register.index', array_merge(request()->except('page'), ['status' => 'overdue'])) }}"
           class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors {{ request('status') === 'overdue' ? 'bg-[#1A365D] text-white' : 'text-gray-600 hover:bg-gray-100' }}">
            Overdue
        </a>
    </div>

    {{-- Filter Bar --}}
    <form method="GET" action="{{ route('risk.register.index') }}" id="filterForm">
        <div class="bg-white rounded-xl border border-gray-200 p-4 mb-4">
            <div class="flex flex-wrap gap-3 items-center">
                {{-- Search --}}
                <div class="flex-1 min-w-[200px]">
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-lg">search</span>
                        <input type="text"
                               name="search"
                               value="{{ request('search') }}"
                               placeholder="Search by Risk ID, name, owner..."
                               class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                               data-live-search>
                    </div>
                </div>

                {{-- Category Filter --}}
                <select name="category" class="border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-700" onchange="document.getElementById('filterForm').submit()">
                    <option value="">All Categories</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id ?? $category }}" {{ request('category') == ($category->id ?? $category) ? 'selected' : '' }}>
                            {{ $category->name ?? $category }}
                        </option>
                    @endforeach
                </select>

                {{-- Business Unit Filter --}}
                <select name="business_unit" class="border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-700" onchange="document.getElementById('filterForm').submit()">
                    <option value="">All Business Units</option>
                    @foreach ($businessUnits as $unit)
                        <option value="{{ $unit->id ?? $unit }}" {{ request('business_unit') == ($unit->id ?? $unit) ? 'selected' : '' }}>
                            {{ $unit->name ?? $unit }}
                        </option>
                    @endforeach
                </select>

                {{-- Rating Filter --}}
                <select name="rating" class="border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-700" onchange="document.getElementById('filterForm').submit()">
                    <option value="">All Ratings</option>
                    @foreach (['Critical', 'High', 'Medium', 'Low'] as $rating)
                        <option value="{{ strtolower($rating) }}" {{ request('rating') === strtolower($rating) ? 'selected' : '' }}>
                            {{ $rating }}
                        </option>
                    @endforeach
                </select>

                {{-- Status Filter --}}
                <select name="status" class="border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-700" onchange="document.getElementById('filterForm').submit()">
                    <option value="">All Statuses</option>
                    @foreach (['Open', 'Mitigating', 'Monitoring', 'Accepted', 'Closed', 'Escalated'] as $status)
                        <option value="{{ strtolower($status) }}" {{ request('status') === strtolower($status) ? 'selected' : '' }}>
                            {{ $status }}
                        </option>
                    @endforeach
                </select>

                {{-- Clear Filters --}}
                @if (request()->hasAny(['search', 'category', 'business_unit', 'rating', 'status']))
                    <a href="{{ route('risk.register.index') }}" class="text-xs text-[#1A365D] font-medium hover:underline">Clear Filters</a>
                @endif
            </div>
        </div>
    </form>

    {{-- Register Table --}}
    <x-data-table id="riskRegisterTable">
        <x-slot name="head">
            <th class="w-8"><input type="checkbox" class="rounded" id="selectAll"></th>
            <th>Risk Code</th>
            <th>Title</th>
            <th>Category</th>
            <th>Business Unit</th>
            <th>Inherent</th>
            <th>Residual</th>
            <th>Trend</th>
            <th>Owner</th>
            <th>Status</th>
            <th>Actions</th>
        </x-slot>

        @forelse ($risks as $risk)
            <tr class="hover:bg-blue-50/50">
                <td><input type="checkbox" class="rounded risk-checkbox" value="{{ $risk->id }}"></td>
                <td class="font-medium text-[#1A365D]">
                    <a href="{{ route('risk.register.show', $risk) }}" class="hover:underline">{{ $risk->risk_code }}</a>
                </td>
                <td class="max-w-[200px]">
                    <div class="truncate" title="{{ $risk->title }}">{{ $risk->title }}</div>
                </td>
                <td><span class="text-xs">{{ $risk->category->name ?? $risk->category ?? '-' }}</span></td>
                <td><span class="text-xs">{{ $risk->businessUnit->name ?? $risk->business_unit ?? '-' }}</span></td>
                <td>
                    <div class="flex items-center gap-1">
                        <x-risk-badge :rating="$risk->inherent_rating ?? 'N/A'" />
                        @if ($risk->inherent_score ?? null)
                            <span class="text-xs text-gray-500">{{ $risk->inherent_score }}</span>
                        @endif
                    </div>
                </td>
                <td>
                    <div class="flex items-center gap-1">
                        <x-risk-badge :rating="$risk->residual_rating ?? 'N/A'" />
                        @if ($risk->residual_score ?? null)
                            <span class="text-xs text-gray-500">{{ $risk->residual_score }}</span>
                        @endif
                    </div>
                </td>
                <td class="text-center">
                    @if (($risk->trend ?? null) === 'up')
                        <span class="material-symbols-outlined text-sm text-red-500">arrow_upward</span>
                    @elseif (($risk->trend ?? null) === 'down')
                        <span class="material-symbols-outlined text-sm text-green-500">arrow_downward</span>
                    @else
                        <span class="material-symbols-outlined text-sm text-gray-400">remove</span>
                    @endif
                </td>
                <td>
                    @if ($risk->owner)
                        <div class="flex items-center gap-2">
                            <div class="w-6 h-6 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center text-[10px] font-bold">
                                {{ strtoupper(substr($risk->owner->name ?? '', 0, 1)) }}{{ strtoupper(substr($risk->owner->name ?? '', strpos($risk->owner->name ?? ' ', ' ') + 1, 1)) }}
                            </div>
                            <span class="text-xs">{{ Str::limit($risk->owner->name ?? '-', 15) }}</span>
                        </div>
                    @else
                        <span class="text-xs text-gray-400">Unassigned</span>
                    @endif
                </td>
                <td><x-status-badge :status="$risk->status ?? 'Open'" /></td>
                <td>
                    <div class="relative" x-data="{ open: false }">
                        <button @click="open = !open" class="p-1 hover:bg-gray-100 rounded">
                            <span class="material-symbols-outlined text-gray-400 text-lg">more_vert</span>
                        </button>
                        <div x-show="open"
                             x-cloak
                             @click.away="open = false"
                             x-transition
                             class="absolute right-0 mt-1 w-44 bg-white rounded-lg shadow-lg border border-gray-200 py-1 z-20">
                            <a href="{{ route('risk.register.show', $risk) }}" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                                <span class="material-symbols-outlined text-[16px] text-gray-400">visibility</span> View Details
                            </a>
                            <a href="{{ route('risk.register.edit', $risk) }}" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                                <span class="material-symbols-outlined text-[16px] text-gray-400">edit</span> Edit Risk
                            </a>
                            <div class="h-px bg-gray-100 my-1"></div>
                            <form method="POST" action="{{ route('risk.register.destroy', $risk) }}" onsubmit="return confirm('Are you sure you want to delete this risk?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="flex items-center gap-2 px-4 py-2 text-sm text-red-600 hover:bg-red-50 w-full text-left">
                                    <span class="material-symbols-outlined text-[16px]">delete</span> Delete
                                </button>
                            </form>
                        </div>
                    </div>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="11" class="text-center py-12">
                    <div class="flex flex-col items-center gap-3">
                        <span class="material-symbols-outlined text-4xl text-gray-300">shield</span>
                        <p class="text-sm text-gray-500">No risks found matching your criteria.</p>
                        @if (request()->hasAny(['search', 'category', 'business_unit', 'rating', 'status']))
                            <a href="{{ route('risk.register.index') }}" class="text-sm text-[#1A365D] font-medium hover:underline">Clear all filters</a>
                        @else
                            <a href="{{ route('risk.register.create') }}" class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] transition-colors">
                                Register First Risk
                            </a>
                        @endif
                    </div>
                </td>
            </tr>
        @endforelse
    </x-data-table>

    {{-- Pagination --}}
    @if ($risks->hasPages())
        <div class="mt-4 flex items-center justify-between">
            <span class="text-xs text-gray-500">
                Showing {{ $risks->firstItem() }}-{{ $risks->lastItem() }} of {{ $risks->total() }} risks
            </span>
            <div class="flex gap-1">
                {{ $risks->appends(request()->query())->links('vendor.pagination.tailwind') }}
            </div>
        </div>
    @endif

@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Select All checkbox
        const selectAll = document.getElementById('selectAll');
        if (selectAll) {
            selectAll.addEventListener('change', function() {
                document.querySelectorAll('.risk-checkbox').forEach(function(cb) {
                    cb.checked = selectAll.checked;
                });
            });
        }

        // Submit filter form on Enter key in search
        const searchInput = document.querySelector('input[name="search"]');
        if (searchInput) {
            searchInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    document.getElementById('filterForm').submit();
                }
            });
        }
    });
</script>
@endpush
