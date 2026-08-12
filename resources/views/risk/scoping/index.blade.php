@extends('layouts.app')

@section('title', 'Entity Register - GRC Risk Management')
@section('page-section', 'Scoping')
@section('page-title', 'Entity Register')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="text-gray-500 hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.scoping.dashboard') }}" class="text-gray-500 hover:text-[#1A365D]">Scoping</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Entity Register</span>
@endsection

@section('content')
    {{-- Success / Error Messages --}}
    @if (session('success'))
        <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-xl flex items-center gap-3">
            <span class="material-symbols-outlined text-green-600">check_circle</span>
            <span class="text-sm text-green-700">{{ session('success') }}</span>
        </div>
    @endif
    @if (session('error'))
        <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-xl flex items-center gap-3">
            <span class="material-symbols-outlined text-red-600">error</span>
            <span class="text-sm text-red-700">{{ session('error') }}</span>
        </div>
    @endif

    {{-- Page Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-[#1A365D]">Entity Register</h1>
            <p class="text-sm text-gray-500 mt-1">{{ $totalCount }} total entities across the organization</p>
        </div>
        <div class="flex gap-2">
            @can('entity.create')
                <a href="{{ route('risk.scoping.create') }}" class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">add_circle</span> New Entity
                </a>
            @endcan
            <a href="{{ route('risk.scoping.dashboard') }}" class="px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 flex items-center gap-2">
                <span class="material-symbols-outlined text-lg">dashboard</span> Dashboard
            </a>
        </div>
    </div>

    {{-- Quick Filter Pills (pre-filter the grid by entity type) --}}
    <div class="flex gap-2 flex-wrap mb-4">
        <a href="{{ route('risk.scoping.index') }}"
           class="px-3 py-1 rounded-full text-xs font-semibold {{ !request()->input('filters.entity_type_id') ? 'bg-[#1A365D] text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">
            All ({{ $totalCount }})
        </a>
        @foreach ($entityTypes as $type)
            @php $count = $typeCounts[$type->id] ?? 0; @endphp
            @if ($count > 0)
                <a href="{{ route('risk.scoping.index', ['filters' => ['entity_type_id' => $type->id]]) }}"
                   class="px-3 py-1 rounded-full text-xs font-semibold {{ request()->input('filters.entity_type_id') == $type->id ? 'bg-[#1A365D] text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">
                    {{ $type->name }} ({{ $count }})
                </a>
            @endif
        @endforeach
    </div>

    {{-- WP-09: search, filters, sorting, column chooser, saved views and
         export all live inside the shared grid — see
         App\Grids\Definitions\EntitiesGrid. --}}
    <x-data-grid grid="entities" />
@endsection
