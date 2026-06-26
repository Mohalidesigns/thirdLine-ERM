@extends('layouts.app')

@section('title', 'Stress Testing Report - GRC Risk Management')
@section('page-section', 'Quantification')
@section('page-title', 'Stress Testing Report')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.quantification.reports') }}" class="hover:text-[#1A365D]">Reports</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Stress Testing</span>
@endsection

@php
    $naira = fn ($v) => '₦' . number_format((float) $v, 2);
    $pct = fn ($v) => number_format((float) $v, 2) . '%';
@endphp

@section('content')
    <div class="flex items-center justify-between mb-6 print:hidden">
        <div>
            <h1 class="text-2xl font-bold text-[#1A365D]">Stress Testing Report</h1>
            <p class="text-sm text-gray-500 mt-1">Comprehensive stress test results under various macroeconomic scenarios</p>
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
            No ICAAP or completed simulation has been recorded. Scenario impacts below use modeled defaults — run a Monte Carlo simulation for live results.
        </div>
    @endif

    <div class="grid grid-cols-2 md:grid-cols-3 gap-4 mb-6">
        <x-kpi-card title="Pre-Stress CAR" :value="$pct($car)" icon="shield"
            :color="$car >= 10 ? 'success' : 'danger'" subtitle="CBN minimum: 10.00%" />
        <x-kpi-card title="Total Capital" :value="$naira($totalCapital)" icon="account_balance" color="primary" />
        <x-kpi-card title="Source Simulation"
            :value="$stressSim?->simulation_code ?? 'Default profile'"
            icon="calculate" color="info"
            subtitle="{{ $stressSim?->completed_at?->format('d M Y') ?? 'Modeled' }}" />
    </div>

    {{-- Scenario impacts --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden mb-6">
        <div class="px-5 py-4 border-b border-gray-100">
            <h3 class="text-sm font-semibold text-[#1A365D]">Scenario Impact on Capital</h3>
        </div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Scenario</th>
                    <th class="text-right">Capital Impact</th>
                    <th class="text-right">CAR Before</th>
                    <th class="text-right">CAR After</th>
                    <th class="text-right">Shortfall</th>
                    <th>Verdict</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($scenarios as $s)
                    <tr>
                        <td class="font-medium text-[#1A365D]">{{ $s->scenario }}</td>
                        <td class="text-right text-red-600">-{{ $naira($s->capital_impact) }}</td>
                        <td class="text-right">{{ $pct($s->car_before) }}</td>
                        <td class="text-right {{ $s->car_after >= 10 ? 'text-green-600' : 'text-red-600' }}">{{ $pct($s->car_after) }}</td>
                        <td class="text-right {{ $s->shortfall > 0 ? 'text-red-600' : 'text-gray-500' }}">{{ $s->shortfall > 0 ? $naira($s->shortfall) : '—' }}</td>
                        <td>
                            <span class="px-2 py-0.5 rounded text-[11px] font-semibold
                                {{ $s->verdict === 'Pass' ? 'bg-green-100 text-green-700' : ($s->verdict === 'Marginal' ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700') }}">
                                {{ $s->verdict }}
                            </span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 p-5">
        <h3 class="text-sm font-semibold text-[#1A365D] mb-3">Methodology</h3>
        <p class="text-sm text-gray-600 leading-relaxed">
            Scenarios are applied against the latest Monte Carlo VaR outputs where available, otherwise against a modeled baseline equal to 10% of qualifying capital.
            CAR drops follow CBN-aligned severity bands, and the reported shortfall is the additional capital required to keep CAR above the 10% regulatory minimum.
        </p>
    </div>
@endsection
