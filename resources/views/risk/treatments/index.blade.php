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
            <p class="text-sm text-gray-500 mt-1">{{ $total }} plans registered &middot; Last updated: {{ now()->format('M d, Y') }}</p>
        </div>
        <div class="flex gap-2">
            @can('treatment.create')
                <a href="{{ route('risk.treatments.create') }}"
                   class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-2 transition-colors">
                    <span class="material-symbols-outlined text-lg">add_circle</span> New Plan
                </a>
            @endcan
        </div>
    </div>

    {{-- Quick Filter Tabs — each pre-filters the grid via filters[status].
         "Overdue" is honoured by the grid's status filter (target date past
         while the plan is still open), which the old dead select never was. --}}
    @php $currentStatus = request()->input('filters.status'); @endphp
    <div class="flex gap-1 mb-4 bg-white rounded-lg border border-gray-200 p-1 w-fit flex-wrap">
        <a href="{{ route('risk.treatments.index') }}"
           class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors {{ !$currentStatus ? 'bg-[#1A365D] text-white' : 'text-gray-600 hover:bg-gray-100' }}">
            All
        </a>
        @foreach (['In Progress', 'Completed', 'Overdue', 'Not Started', 'On Hold'] as $statusTab)
            @php $statusValue = strtolower(str_replace(' ', '_', $statusTab)); @endphp
            <a href="{{ route('risk.treatments.index', ['filters' => ['status' => $statusValue]]) }}"
               class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors {{ $currentStatus === $statusValue ? 'bg-[#1A365D] text-white' : 'text-gray-600 hover:bg-gray-100' }}">
                {{ $statusTab }}
            </a>
        @endforeach
    </div>

    {{-- WP-09: search, filters (strategy and priority are now real),
         sorting, column chooser, saved views, bulk delete and export all
         live inside the shared grid — see App\Grids\Definitions\TreatmentPlansGrid. --}}
    <x-data-grid grid="treatments" />
@endsection
