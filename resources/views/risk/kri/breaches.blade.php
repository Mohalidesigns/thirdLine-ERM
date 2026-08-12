@extends('layouts.app')

@section('title', 'KRI Breaches - GRC Risk Management')
@section('page-section', 'KRI Monitoring')
@section('page-title', 'Active Breaches')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.kri.dashboard') }}" class="hover:text-[#1A365D]">KRI Monitoring</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Active Breaches</span>
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
            <h1 class="text-2xl font-bold text-[#1A365D]">Active KRI Breaches</h1>
            <p class="text-sm text-gray-500 mt-1">{{ $activeBreaches }} active breaches requiring attention</p>
        </div>
    </div>

    {{-- Summary Cards --}}
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <x-kpi-card title="Red Breaches" :value="$redBreaches ?? 0" icon="error" color="danger" subtitle="Open - immediate action required" />
        <x-kpi-card title="Amber Warnings" :value="$amberBreaches ?? 0" icon="warning" color="warning" subtitle="Open - approaching threshold" />
        <x-kpi-card title="Avg. Days Open" :value="$avgDaysInBreach ?? 0" icon="schedule" color="info"
                    :subtitle="($unacknowledged ?? 0) . ' awaiting acknowledgement'" />
        {{-- Mean time to resolve is computed from the resolved rows in the
             breach register. Before WP-04 a breach existed only as a
             notification, so this number could not be produced at all. --}}
        <x-kpi-card title="Mean Time to Resolve"
                    :value="isset($mttrHours) ? number_format($mttrHours / 24, 1) . ' d' : 'n/a'"
                    icon="timer" color="success"
                    :subtitle="isset($mttrHours) ? 'Across resolved breaches' : 'No breach resolved yet'" />
    </div>

    {{-- WP-09: search, filters, sorting, column chooser, saved views,
         bulk acknowledge/resolve and export all live inside the shared grid —
         see App\Grids\Definitions\KriBreachesGrid. The register defaults to
         the work list (open + acknowledged), not the archive. --}}
    <x-data-grid grid="kri_breaches" :initial-filters="['status' => 'active']" />
@endsection
