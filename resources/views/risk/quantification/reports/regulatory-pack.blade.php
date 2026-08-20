@extends('layouts.app')

@section('title', 'Regulatory Compliance Pack - GRC Risk Management')
@section('page-section', 'Quantification')
@section('page-title', 'Regulatory Compliance Pack')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.quantification.reports') }}" class="hover:text-[#1A365D]">Reports</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Regulatory Compliance Pack</span>
@endsection

@php
    $naira = fn ($v) => '₦' . number_format((float) $v, 2);
    $pct = fn ($v) => number_format((float) $v, 2) . '%';
    $statusClasses = [
        'pass'    => 'bg-green-100 text-green-700',
        'warning' => 'bg-yellow-100 text-yellow-700',
        'fail'    => 'bg-red-100 text-red-700',
    ];
    $statusIcons = [
        'pass'    => 'check_circle',
        'warning' => 'warning',
        'fail'    => 'error',
    ];
@endphp

@section('content')
    <div class="flex items-center justify-between mb-6 print:hidden">
        <div>
            <h1 class="text-2xl font-bold text-[#1A365D]">Regulatory Compliance Pack</h1>
            <p class="text-sm text-gray-500 mt-1">Combined regulatory reporting package for CBN ORMS compliance &middot; {{ now()->format('d M Y') }}</p>
        </div>
        <div class="flex gap-2">
            <button onclick="window.print()" class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-2">
                <span class="material-symbols-outlined text-lg">download</span> Download PDF
            </button>
            <a href="{{ route('risk.quantification.reports') }}" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Back</a>
        </div>
    </div>

    {{-- Headline Indicators --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        {{-- WP-08: CAR is computed from capital / RWA where both are on file, and
             is null — "Not assessed" — where they are not. It is never shown as 0%,
             which would read as an insolvent bank rather than as missing data. --}}
        <x-kpi-card title="Capital Adequacy Ratio"
            :value="$summary->car_actual === null ? 'Not assessed' : $pct($summary->car_actual)" icon="shield"
            :color="$summary->car_actual === null ? 'info' : ($summary->car_actual >= $summary->car_required ? 'success' : 'danger')"
            subtitle="CBN minimum: {{ $pct($summary->car_required) }}{{ $summary->car_actual === null ? '' : ' · ' . $summary->car_basis }}" />
        <x-kpi-card title="Total Capital" :value="$summary->total_capital === null ? 'Not recorded' : $naira($summary->total_capital)" icon="account_balance" color="primary" />
        <x-kpi-card title="Active Risks"    :value="$summary->active_risks"         icon="security"        color="primary" subtitle="{{ $summary->critical_risks }} critical / {{ $summary->high_risks }} high" />
        <x-kpi-card title="Loss Events YTD" :value="$summary->loss_events_ytd"      icon="report_problem"  color="warning" subtitle="Net loss {{ $naira($summary->net_loss_ytd) }}" />
    </div>

    {{-- Checklist --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden mb-6">
        <div class="px-5 py-4 border-b border-gray-100">
            <h3 class="text-sm font-semibold text-[#1A365D]">Compliance Checklist</h3>
        </div>
        <table class="data-table">
            <thead><tr><th>Item</th><th>Status</th><th>Detail</th></tr></thead>
            <tbody>
                @foreach ($checklist as $row)
                    <tr>
                        <td class="font-medium text-[#1A365D]">{{ $row['item'] }}</td>
                        <td>
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-semibold {{ $statusClasses[$row['status']] }}">
                                <span class="material-symbols-outlined text-[14px]">{{ $statusIcons[$row['status']] }}</span>
                                {{ strtoupper($row['status']) }}
                            </span>
                        </td>
                        <td class="text-sm text-gray-600">{{ $row['detail'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Supporting metrics --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Key Risk Indicators</h3>
            <dl class="grid grid-cols-2 gap-4 text-sm">
                <div><dt class="text-xs text-gray-500">Red (Breach)</dt><dd class="text-lg font-bold text-red-600">{{ $summary->red_kris }}</dd></div>
                <div><dt class="text-xs text-gray-500">Amber (Warning)</dt><dd class="text-lg font-bold text-yellow-600">{{ $summary->amber_kris }}</dd></div>
            </dl>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Issues &amp; Findings</h3>
            <dl class="grid grid-cols-2 gap-4 text-sm">
                <div><dt class="text-xs text-gray-500">Open</dt><dd class="text-lg font-bold text-[#1A365D]">{{ $summary->open_issues }}</dd></div>
                <div><dt class="text-xs text-gray-500">Overdue</dt><dd class="text-lg font-bold text-red-600">{{ $summary->overdue_issues }}</dd></div>
                <div><dt class="text-xs text-gray-500">Regulatory</dt><dd class="text-lg font-bold text-yellow-600">{{ $summary->regulatory_issues }}</dd></div>
            </dl>
        </div>
    </div>

    <div class="mt-6 bg-white rounded-xl border border-gray-200 p-5">
        <h3 class="text-sm font-semibold text-[#1A365D] mb-3">Filing Note</h3>
        <p class="text-sm text-gray-600 leading-relaxed">
            This pack consolidates the Capital Adequacy position, residual risk profile, KRI traffic-light status, loss-event history, and open regulatory findings into the format expected by the Central Bank of Nigeria Operational Risk Management System (CBN ORMS) cycle submission. Attach the Capital Adequacy Summary and Stress Testing Report alongside when filing.
        </p>
    </div>
@endsection
