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
    // Missing stays missing. This report is filed with the CBN; a zero in a
    // capital column is a statement about the bank, not a placeholder.
    $naira = fn ($v) => $v === null ? null : '₦' . number_format((float) $v, 2);
    $pct = fn ($v) => $v === null ? null : number_format((float) $v, 2) . '%';
    $trim = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
    $notRecorded = '<span class="text-gray-400 italic">Not recorded</span>';
    $notAssessed = '<span class="text-gray-400 italic">Not assessed</span>';
@endphp

@section('content')
    <div class="flex items-center justify-between mb-6 print:hidden">
        <div>
            <h1 class="text-2xl font-bold text-[#1A365D]">Capital Adequacy Summary</h1>
            <p class="text-sm text-gray-500 mt-1">Capital position with Pillar 1 requirement and Pillar 2 add-ons for CBN submission
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
            No ICAAP assessment has been recorded yet. Every figure below is sourced from an assessment, so the
            report stays blank rather than showing zeroes.
        </div>
    @endif

    {{-- Headline KPIs --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <x-kpi-card title="Capital Adequacy Ratio" :value="$pct($d->car_computed)" :unavailable="$d->car_computed === null"
            icon="shield"
            :color="$d->car_computed !== null && $d->car_computed >= $d->car_required ? 'success' : 'danger'"
            subtitle="CBN minimum: {{ $trim($d->car_required) }}%" />
        <x-kpi-card title="Total Qualifying Capital" :value="$naira($d->total_capital)" :unavailable="$d->total_capital === null"
            unavailable-label="Not recorded" icon="account_balance" color="primary" />
        <x-kpi-card title="CET1 Ratio" :value="$pct($d->cet1_ratio)" :unavailable="$d->cet1_ratio === null"
            icon="verified_user" color="success" subtitle="CET1 capital: {{ $naira($d->cet1) ?? 'not recorded' }}" />
        <x-kpi-card title="Tier 1 Ratio" :value="$pct($d->tier1_ratio)" :unavailable="$d->tier1_ratio === null"
            icon="workspace_premium" color="info" subtitle="Tier 1 capital: {{ $naira($d->tier1) ?? 'not recorded' }}" />
    </div>

    @if ($d->car_variance_material)
        <div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-6 text-sm text-red-800">
            <span class="font-semibold">CAR variance.</span>
            The ratio computed from stored capital and RWA ({{ $pct($d->car_computed) }}) differs from the CAR
            recorded on the assessment ({{ $pct($d->car_reported) }}) by
            {{ number_format(abs($d->car_variance), 2) }} percentage points. Both are shown; neither is overwritten.
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- Pillar 1 --}}
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100">
                <h3 class="text-sm font-semibold text-[#1A365D]">Pillar 1 — Minimum Capital Requirement</h3>
                <p class="text-xs text-gray-500 mt-1">Minimum CAR &times; total risk-weighted assets</p>
            </div>
            <table class="data-table">
                <thead><tr><th>Input</th><th class="text-right">Value</th></tr></thead>
                <tbody>
                    <tr><td>Total risk-weighted assets</td><td class="text-right">{!! $naira($d->total_rwa) ?? $notRecorded !!}</td></tr>
                    <tr><td>Minimum CAR applied</td><td class="text-right">{{ $trim($d->car_required) }}%</td></tr>
                    <tr class="font-semibold bg-gray-50"><td>Pillar 1 requirement</td><td class="text-right">{!! $naira($d->pillar1_requirement) ?? $notAssessed !!}</td></tr>
                </tbody>
            </table>
        </div>

        {{-- Pillar 2 --}}
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100">
                <h3 class="text-sm font-semibold text-[#1A365D]">Pillar 2 — ICAAP Capital Add-on</h3>
                <p class="text-xs text-gray-500 mt-1">As recorded on the assessment; nothing is apportioned</p>
            </div>
            <table class="data-table">
                <thead><tr><th>Component</th><th class="text-right">Capital Charge</th></tr></thead>
                <tbody>
                    <tr><td>Pillar 2A — Credit Risk</td>      <td class="text-right">{!! $naira($d->pillar2a_credit) ?? $notRecorded !!}</td></tr>
                    <tr><td>Pillar 2A — Market Risk</td>      <td class="text-right">{!! $naira($d->pillar2a_market) ?? $notRecorded !!}</td></tr>
                    <tr><td>Pillar 2A — Operational Risk</td> <td class="text-right">{!! $naira($d->pillar2a_operational) ?? $notRecorded !!}</td></tr>
                    <tr><td>Pillar 2A — Other</td>            <td class="text-right">{!! $naira($d->pillar2a_other) ?? $notRecorded !!}</td></tr>
                    <tr class="font-semibold bg-gray-50"><td>Total Pillar 2A</td><td class="text-right">{!! $naira($d->total_pillar2a) ?? $notRecorded !!}</td></tr>
                    <tr><td>Pillar 2B — Stress Buffer</td>    <td class="text-right">{!! $naira($d->pillar2b_buffer) ?? $notRecorded !!}</td></tr>
                    <tr><td>Capital conservation buffer</td>  <td class="text-right">{{ $trim($d->conservation_buffer) }}% of RWA</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    {{-- Summary --}}
    <div class="mt-6 bg-white rounded-xl border border-gray-200 p-5">
        <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Capital Position Summary</h3>
        <dl class="grid grid-cols-2 md:grid-cols-3 gap-4 text-sm">
            <div><dt class="text-xs text-gray-500">Total Qualifying Capital</dt><dd class="font-semibold text-[#1A365D]">{!! $naira($d->total_capital) ?? $notRecorded !!}</dd></div>
            <div><dt class="text-xs text-gray-500">Pillar 1 Requirement</dt><dd class="font-semibold text-[#1A365D]">{!! $naira($d->pillar1_requirement) ?? $notAssessed !!}</dd></div>
            <div><dt class="text-xs text-gray-500">Pillar 2A Add-on</dt><dd class="font-semibold text-[#1A365D]">{!! $naira($d->total_pillar2a) ?? $notRecorded !!}</dd></div>
            <div><dt class="text-xs text-gray-500">Pillar 2B Stress Buffer</dt><dd class="font-semibold text-[#1A365D]">{!! $naira($d->pillar2b_buffer) ?? $notRecorded !!}</dd></div>
            <div>
                <dt class="text-xs text-gray-500">Headroom</dt>
                <dd class="font-semibold {{ $d->headroom === null ? 'text-gray-400' : ($d->headroom > 0 ? 'text-green-600' : 'text-red-600') }}">
                    {!! $naira($d->headroom) ?? $notAssessed !!}
                </dd>
            </div>
            <div>
                <dt class="text-xs text-gray-500">CAR vs CBN Minimum</dt>
                <dd class="font-semibold {{ $d->car_surplus === null ? 'text-gray-400' : ($d->car_surplus >= 0 ? 'text-green-600' : 'text-red-600') }}">
                    {{ $d->car_surplus === null ? '—' : ($d->car_surplus >= 0 ? '+' : '') . number_format($d->car_surplus, 2) . 'pp' }}
                </dd>
            </div>
        </dl>
        <p class="text-xs text-gray-500 mt-4 leading-relaxed">
            CAR, the CET1 ratio and the Tier 1 ratio are computed from stored capital and total risk-weighted assets;
            each is shown as "Not assessed" rather than 0% when RWA is not on file. Headroom is
            total qualifying capital less the Pillar 1 requirement, the Pillar 2A add-on and the Pillar 2B stress
            buffer, and is withheld until all three are known. The minimum CAR of {{ $trim($d->car_required) }}% is
            resolved from the assessment, then the organisation's quantification settings, then the CBN default of
            10.0% — the previous code read a <code class="text-xs">car_required</code> column that does not exist and
            therefore showed every institution a 10% minimum. A bank on international authorisation or designated a
            D-SIB is measured against 15.0% and must configure it.
        </p>
    </div>
@endsection
