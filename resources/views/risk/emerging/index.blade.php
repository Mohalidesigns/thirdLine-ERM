@extends('layouts.app')

@section('title', 'Emerging Risk Register - GRC Risk Management')
@section('page-section', 'Risk Intelligence')
@section('page-title', 'Emerging Risk Register')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-500">Risk Intelligence</span>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Emerging Risk Register</span>
@endsection

@section('content')
    <div class="flex items-start justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">Emerging Risk Register</h1>
            <p class="text-sm text-gray-500 mt-1">
                Risks on the horizon that are not yet in the register proper.
            </p>
        </div>
        <div class="flex gap-2">
            @if (Route::has('risk.ai.radar'))
                <a href="{{ route('risk.ai.radar') }}"
                   class="px-4 py-2 border border-gray-300 rounded-lg text-xs font-semibold text-gray-700 hover:bg-gray-50 flex items-center gap-2">
                    <span class="material-symbols-outlined text-sm">radar</span> View radar
                </a>
            @endif
            @can('risk.create')
                <a href="{{ route('risk.emerging.create') }}"
                   class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-xs font-semibold hover:bg-[#2D4A7A] flex items-center gap-2">
                    <span class="material-symbols-outlined text-sm">add</span> Add entry
                </a>
            @endcan
        </div>
    </div>

    @if (session('success'))
        <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-xl text-sm text-green-800">
            {{ session('success') }}
        </div>
    @endif

    {{-- WP-09: the register is the shared grid — see
         App\Grids\Definitions\EmergingRisksGrid. "Mark reviewed today" and
         "Remove from register" moved from per-row POST forms to bulk actions,
         because row actions in this engine are navigation-only. --}}
    <x-data-grid grid="emerging_risks" />
@endsection
