@extends('layouts.app')

@section('title', 'Near Misses - GRC Platform')

@section('breadcrumbs')
    <span>Risk Management</span>
    <span class="text-gray-300">/</span>
    <span>Loss Events</span>
    <span class="text-gray-300">/</span>
    <span class="text-[#1A365D] font-semibold">Near Misses</span>
@endsection

@section('content')
    {{-- Page Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">Near Miss Register</h1>
            <p class="text-sm text-gray-500 mt-1">Track near-miss events that could have resulted in operational losses</p>
        </div>
        <div class="flex items-center gap-3">
            @can('loss_event.create')
                <a href="{{ route('risk.loss-events.create-near-miss') }}" class="flex items-center gap-2 px-4 py-2 bg-[#1A365D] text-white rounded-lg text-xs font-semibold hover:bg-[#2D4A7A] transition">
                    <span class="material-symbols-outlined text-sm">add</span>
                    Log Near Miss
                </a>
            @endcan
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

    {{-- Summary Cards --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <x-kpi-card title="Total Near Misses" :value="$totalNearMisses ?? 0" icon="warning" color="warning" />
        <x-kpi-card title="Open" :value="$openNearMisses ?? 0" icon="pending" color="info" />
        <x-kpi-card title="Under Review" :value="$underReviewNearMisses ?? 0" icon="rate_review" color="primary" />
        <x-kpi-card title="Potential Loss Avoided" :value="'₦' . number_format($potentialLossAvoided ?? 0, 2)" icon="savings" color="success" />
    </div>

    {{-- WP-09: search, filters, sorting, column chooser, saved views,
         export and bulk convert all live inside the shared grid — see
         App\Grids\Definitions\NearMissesGrid. --}}
    <x-data-grid grid="near_misses" />
@endsection
