@extends('layouts.app')

@section('title', 'Capital Adequacy Summary - GRC Risk Management')
@section('page-section', 'Quantification')
@section('page-title', 'Capital Adequacy Summary')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.quantification.reports') }}" class="hover:text-[#1A365D]">Reports</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Capital Adequacy Summary</span>
@endsection

@php
    $naira = fn ($v) => '₦' . number_format((float) $v, 2);
    $pct = fn ($v) => number_format((float) $v, 2) . '%';
@endphp

@section('content')
    <div class="flex items-center justify-between mb-6 print:hidden">
        <div>
            <h1 class="text-2xl font-bold text-[#1A365D]">Capital Adequacy Summary</h1>
            <p class="text-sm text-gray-500 mt-1">Summary of capital position with Pillar 1 and Pillar 2 breakdown for CBN submission
                @if ($d->as_of)
                    &middot; as of {{ $d->as_of->format('d M Y') }}
                @endif
            </p>
        </div>
        <div class="flex gap-2">
            <button onclick="window.print()" class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-2">
                <span class="material-symbols-outlined text-lg">download</span> Download PDF
            </button>
            <a href="{{ route('risk.quantification.reports') }}" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Back</a>
        </div>
    </div>

    @if (! $hasData)
        <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-4 mb-6 text-sm text-yellow-800">
            No ICAAP assessment has been recorded yet. Values below are zeroed.
        </div>
    @endif

    {{-- Headline KPIs --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <x-kpi-card title="Capital Adequacy Ratio" :value="$pct($d->car_actual)" icon="shield"
            :color="$d->car_actual >= $d->car_required ? 'success' : 'danger'"
            subtitle="CBN minimum: {{ $pct($d->car_required) }}" />
        <x-kpi-card title="Total Qualifying Capital" :value="$naira($d->total_capital)" icon="account_balance" color="primary" />
        <x-kpi-card title="Tier 1 Capital"            :value="$naira($d->tier1)"         icon="verified_user" color="info" />
        <x-kpi-card title="Tier 2 Capital"            :value="$naira($d->tier2)"         icon="workspace_premium" color="info" />
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- Pillar 1 --}}
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100">
                <h3 class="text-sm font-semibold text-[#1A365D]">Pillar 1 — Minimum Capital Requirements</h3>
            </div>
            <table class="data-table">
                <thead><tr><th>Risk Type</th><th class="text-right">Capital Charge</th></tr></thead>
                <tbody>
                    <tr><td>Credit Risk</td>      <td class="text-right">{{ $naira($d->pillar1_credit) }}</td></tr>
                    <tr><td>Market Risk</td>      <td class="text-right">{{ $naira($d->pillar1_market) }}</td></tr>
                    <tr><td>Operational Risk</td> <td class="text-right">{{ $naira($d->pillar1_operational) }}</td></tr>
                    <tr class="font-semibold bg-gray-50"><td>Total Pillar 1</td><td class="text-right">{{ $naira($d->total_pillar1) }}</td></tr>
                </tbody>
            </table>
        </div>

        {{-- Pillar 2 --}}
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100">
                <h3 class="text-sm font-semibold text-[#1A365D]">Pillar 2 — Supervisory Review</h3>
            </div>
            <table class="data-table">
                <thead><tr><th>Component</th><th class="text-right">Capital Charge</th></tr></thead>
                <tbody>
                    <tr><td>Stress Buffer</td>              <td class="text-right">{{ $naira($d->pillar2_buffer) }}</td></tr>
                    <tr><td>Other Pillar 2 Risks</td>       <td class="text-right">{{ $naira($d->pillar2_other) }}</td></tr>
                    <tr><td>Conservation Buffer</td>        <td class="text-right">{{ $pct($d->conservation_buffer) }}</td></tr>
                    <tr class="font-semibold bg-gray-50"><td>Total Pillar 2</td><td class="text-right">{{ $naira($d->total_pillar2) }}</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    {{-- Summary --}}
    <div class="mt-6 bg-white rounded-xl border border-gray-200 p-5">
        <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Capital Position Summary</h3>
        <dl class="grid grid-cols-2 md:grid-cols-3 gap-4 text-sm">
            <div><dt class="text-xs text-gray-500">Total Capital</dt><dd class="font-semibold text-[#1A365D]">{{ $naira($d->total_capital) }}</dd></div>
            <div><dt class="text-xs text-gray-500">Pillar 1 Demand</dt><dd class="font-semibold text-[#1A365D]">{{ $naira($d->total_pillar1) }}</dd></div>
            <div><dt class="text-xs text-gray-500">Pillar 2 Demand</dt><dd class="font-semibold text-[#1A365D]">{{ $naira($d->total_pillar2) }}</dd></div>
            <div><dt class="text-xs text-gray-500">Headroom</dt><dd class="font-semibold {{ $d->headroom > 0 ? 'text-green-600' : 'text-red-600' }}">{{ $naira($d->headroom) }}</dd></div>
            <div><dt class="text-xs text-gray-500">CAR vs CBN Min</dt><dd class="font-semibold {{ $d->car_surplus >= 0 ? 'text-green-600' : 'text-red-600' }}">{{ ($d->car_surplus >= 0 ? '+' : '') . $pct($d->car_surplus) }}</dd></div>
            <div><dt class="text-xs text-gray-500">Status</dt><dd class="font-semibold {{ $d->car_actual >= $d->car_required ? 'text-green-600' : 'text-red-600' }}">{{ $d->car_actual >= $d->car_required ? 'Compliant' : 'Breach' }}</dd></div>
        </dl>
    </div>
@endsection
