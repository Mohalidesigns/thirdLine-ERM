@extends('layouts.app')

@section('title', 'Risk Register - GRC Risk Management')
@section('page-section', 'Risk Register')
@section('page-title', 'Risk Register')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="text-gray-500 hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Risk Register</span>
@endsection

@section('content')
    @isset($asOfPeriod)
        {{-- ============================================================
             HISTORIC "AS AT" VIEW — untouched by the WP-09 grid migration.
             Scores below are the ones approved as at the close of the
             selected period, read from the measure engine — not today's.
             The overlay values exist only in memory, so this path keeps
             the hand-rolled table instead of the SQL-backed data grid.
             ============================================================ --}}
        <div class="mb-4 p-4 bg-blue-50 border border-blue-200 rounded-xl flex items-start gap-3">
            <span class="material-symbols-outlined text-blue-600">history</span>
            <div class="text-sm text-blue-800">
                <p class="font-semibold">Showing the register as at {{ $asOfPeriod->name }}
                    ({{ $asOfPeriod->end_date?->format('d M Y') }}).</p>
                <p class="text-xs text-blue-700 mt-0.5">
                    Scores are the last approved on or before that date. Risks identified afterwards are excluded.
                    <a href="{{ route('risk.periods.select', ['direction' => 'current', 'redirect' => '/risk/register']) }}"
                       class="underline font-medium">Return to the current period</a>.
                </p>
            </div>
        </div>

        {{-- Page Header --}}
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold text-[#1A365D]">Risk Register</h1>
                <p class="text-sm text-gray-500 mt-1">{{ $risks->total() ?? 0 }} risks registered</p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('risk.register.create') }}" class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">add_circle</span> New Risk
                </a>
            </div>
        </div>

        {{-- Quick Filter Pills --}}
        @php
            $currentRating = request('rating');
            $ratingCounts = [
                'Critical' => ($risks->total() > 0) ? \App\Models\Risk::where('organization_id', auth()->user()->organization_id ?? 1)->where('inherent_rating', 'Critical')->count() : 0,
                'High' => ($risks->total() > 0) ? \App\Models\Risk::where('organization_id', auth()->user()->organization_id ?? 1)->where('inherent_rating', 'High')->count() : 0,
                'Medium' => ($risks->total() > 0) ? \App\Models\Risk::where('organization_id', auth()->user()->organization_id ?? 1)->where('inherent_rating', 'Medium')->count() : 0,
                'Low' => ($risks->total() > 0) ? \App\Models\Risk::where('organization_id', auth()->user()->organization_id ?? 1)->where('inherent_rating', 'Low')->count() : 0,
            ];
        @endphp
        <div class="flex gap-1 mb-4 bg-white rounded-lg border border-gray-200 p-1 w-fit">
            <a href="{{ route('risk.register.index', request()->except('rating', 'page')) }}"
               class="px-3 py-1.5 text-xs font-medium rounded-md {{ !$currentRating ? 'bg-[#1A365D] text-white' : 'text-gray-600 hover:bg-gray-100' }}">
                All ({{ $risks->total() }})
            </a>
            @foreach ($ratingCounts as $rating => $count)
                <a href="{{ route('risk.register.index', array_merge(request()->except('page'), ['rating' => $rating])) }}"
                   class="px-3 py-1.5 text-xs font-medium rounded-md {{ $currentRating === $rating ? 'bg-[#1A365D] text-white' : 'text-gray-600 hover:bg-gray-100' }}">
                    {{ $rating }} ({{ $count }})
                </a>
            @endforeach
        </div>

        {{-- Filter Bar --}}
        <div class="bg-white rounded-xl border border-gray-200 p-4 mb-4">
            <form method="GET" action="{{ route('risk.register.index') }}" class="flex flex-wrap gap-3 items-center">
                <div class="flex-1 min-w-[200px]">
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-lg">search</span>
                        <input type="text" name="search" value="{{ request('search') }}"
                               placeholder="Search by Risk ID, name, description..."
                               class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                               data-live-search>
                    </div>
                </div>
                <select name="category" onchange="this.form.submit()"
                        class="border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-700">
                    <option value="">All Categories</option>
                    @foreach ($categories as $cat)
                        <option value="{{ $cat->id }}" {{ request('category') == $cat->id ? 'selected' : '' }}>{{ $cat->name }}</option>
                    @endforeach
                </select>
                <select name="status" onchange="this.form.submit()"
                        class="border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-700">
                    <option value="">All Statuses</option>
                    @foreach (['active' => 'Active', 'dormant' => 'Dormant', 'closed' => 'Closed', 'retired' => 'Retired'] as $val => $label)
                        <option value="{{ $val }}" {{ request('status') === $val ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
                <select name="business_unit" onchange="this.form.submit()"
                        class="border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-700">
                    <option value="">All Business Units</option>
                    @foreach ($businessUnits as $bu)
                        <option value="{{ $bu->id }}" {{ request('business_unit') == $bu->id ? 'selected' : '' }}>{{ $bu->name }}</option>
                    @endforeach
                </select>
                @if (request('rating'))
                    <input type="hidden" name="rating" value="{{ request('rating') }}">
                @endif
                <button type="submit" class="px-3 py-2 bg-[#1A365D] text-white rounded-lg text-sm hover:bg-[#2D4A7A]">
                    <span class="material-symbols-outlined text-lg">filter_list</span>
                </button>
                @if (request()->hasAny(['search', 'category', 'status', 'business_unit', 'rating']))
                    <a href="{{ route('risk.register.index') }}" class="text-xs text-[#1A365D] font-medium hover:underline">Clear Filters</a>
                @endif
            </form>
        </div>

        {{-- Risk Register Table --}}
        <x-data-table id="riskRegisterTable">
            <x-slot name="head">
                <th>Risk ID</th>
                <th>Risk Name</th>
                <th>Category</th>
                <th>Inherent Rating</th>
                <th>Residual Rating</th>
                <th>Risk Owner</th>
                <th>Business Unit</th>
                <th>Status</th>
                <th>Actions</th>
            </x-slot>

            @forelse ($risks as $risk)
                <tr class="hover:bg-blue-50/50">
                    <td>
                        <a href="{{ route('risk.register.show', $risk) }}" class="font-medium text-[#1A365D] hover:underline">
                            {{ $risk->risk_code }}
                        </a>
                    </td>
                    <td class="max-w-[250px]">
                        <div class="truncate">{{ $risk->title }}</div>
                    </td>
                    <td>
                        <span class="text-xs">{{ $risk->category->name ?? '-' }}</span>
                    </td>
                    <td>
                        <x-risk-badge :rating="$risk->inherent_rating ?? 'unrated'" />
                    </td>
                    <td>
                        @if ($risk->residual_rating)
                            <x-risk-badge :rating="$risk->residual_rating" />
                        @else
                            <span class="text-xs text-gray-400">Not Assessed</span>
                        @endif
                    </td>
                    <td>
                        @if ($risk->riskOwner)
                            <div class="flex items-center gap-2">
                                <div class="w-6 h-6 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center text-[9px] font-bold flex-shrink-0">
                                    {{ collect(explode(' ', $risk->riskOwner->name))->map(fn($n) => strtoupper(substr($n, 0, 1)))->take(2)->join('') }}
                                </div>
                                <span class="text-xs truncate">{{ $risk->riskOwner->name }}</span>
                            </div>
                        @else
                            <span class="text-xs text-gray-400">Unassigned</span>
                        @endif
                    </td>
                    <td>
                        <span class="text-xs">{{ $risk->businessUnit->name ?? '-' }}</span>
                    </td>
                    <td>
                        <x-status-badge :status="$risk->status ?? 'active'" />
                    </td>
                    <td>
                        <div class="flex items-center gap-1">
                            <a href="{{ route('risk.register.show', $risk) }}" class="p-1 text-gray-400 hover:text-[#1A365D]" title="View">
                                <span class="material-symbols-outlined text-lg">visibility</span>
                            </a>
                            <a href="{{ route('risk.register.edit', $risk) }}" class="p-1 text-gray-400 hover:text-[#1A365D]" title="Edit">
                                <span class="material-symbols-outlined text-lg">edit</span>
                            </a>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="text-center py-12">
                        <span class="material-symbols-outlined text-4xl text-gray-300 mb-2">assessment</span>
                        <p class="text-sm text-gray-500">No risks found matching your criteria.</p>
                    </td>
                </tr>
            @endforelse
        </x-data-table>

        {{-- Pagination --}}
        @if ($risks->hasPages())
            <div class="mt-4">
                {{ $risks->links() }}
            </div>
        @endif
    @else
        {{-- ============================================================
             LIVE VIEW — WP-09: search, filters, sorting, column chooser,
             saved views, export and the heat-map drill-through all live
             inside the shared grid. See App\Grids\Definitions\RisksGrid.
             ============================================================ --}}

        {{-- Success Flash --}}
        @if (session('success'))
            <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-xl flex items-center gap-3">
                <span class="material-symbols-outlined text-green-600">check_circle</span>
                <span class="text-sm text-green-700">{{ session('success') }}</span>
            </div>
        @endif

        {{-- Page Header --}}
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold text-[#1A365D]">Risk Register</h1>
                <p class="text-sm text-gray-500 mt-1">{{ $total }} risks registered</p>
            </div>
            <div class="flex gap-2">
                @can('risk.create')
                    <a href="{{ route('risk.register.create') }}" class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg">add_circle</span> New Risk
                    </a>
                @endcan
            </div>
        </div>

        {{-- Quick Filter Pills — counts come from the controller; each pill
             pre-filters the grid via its filters[rating] URL state. --}}
        @php $currentRating = request()->input('filters.rating'); @endphp
        <div class="flex gap-1 mb-4 bg-white rounded-lg border border-gray-200 p-1 w-fit">
            <a href="{{ route('risk.register.index') }}"
               class="px-3 py-1.5 text-xs font-medium rounded-md {{ !$currentRating ? 'bg-[#1A365D] text-white' : 'text-gray-600 hover:bg-gray-100' }}">
                All ({{ $total }})
            </a>
            @foreach ($ratingCounts as $rating => $count)
                <a href="{{ route('risk.register.index', ['filters' => ['rating' => $rating]]) }}"
                   class="px-3 py-1.5 text-xs font-medium rounded-md {{ $currentRating === $rating ? 'bg-[#1A365D] text-white' : 'text-gray-600 hover:bg-gray-100' }}">
                    {{ $rating }} ({{ $count }})
                </a>
            @endforeach
        </div>

        <x-data-grid grid="risks" />
    @endisset
@endsection
