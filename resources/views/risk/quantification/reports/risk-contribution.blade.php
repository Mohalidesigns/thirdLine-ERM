@extends('layouts.app')

@section('title', 'Risk Contribution Analysis - GRC Risk Management')
@section('page-section', 'Quantification')
@section('page-title', 'Risk Contribution')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.quantification.reports') }}" class="hover:text-[#1A365D]">Reports</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Risk Contribution</span>
@endsection

@php
    $pct = fn ($v) => $v === null ? '—' : number_format((float) $v, 2) . '%';
    // WP-08. A row is either Naira (expected annual loss from a completed
    // simulation) or an ordinal residual-score total. The two must never be
    // rendered the same way: putting a ₦ in front of a sum of 1-25 matrix
    // scores is how "capital by business unit" got onto this page in the
    // first place.
    $amount = fn ($v, $basis) => $basis === 'expected_loss'
        ? '₦' . number_format((float) $v, 2)
        : number_format((float) $v, 2);

    $columnHeading = fn ($basis) => $basis === 'expected_loss'
        ? 'Expected Annual Loss'
        : 'Residual Score Total';
@endphp

@section('content')
    <div class="flex items-center justify-between mb-6 print:hidden">
        <div>
            <h1 class="text-2xl font-bold text-[#1A365D]">Risk Contribution Analysis</h1>
            <p class="text-sm text-gray-500 mt-1">Where modelled loss and residual risk sit, by risk type and business unit</p>
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
            No active risks or simulations available yet.
        </div>
    @endif

    @if ($latestSim && $byTypeBasis === 'expected_loss')
        <div class="mb-4 text-xs text-gray-500">
            Source: simulation
            <a href="{{ route('risk.quantification.show-results', $latestSim) }}" class="text-[#1A365D] font-semibold hover:underline">
                {{ $latestSim->simulation_reference }}
            </a>
            &middot; {{ $latestSim->completed_at?->format('d M Y') }}
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- By Risk Type --}}
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100">
                <h3 class="text-sm font-semibold text-[#1A365D]">By Risk Type</h3>
            </div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>{{ $byTypeBasis === 'expected_loss' ? 'Scenario' : 'Risk Category' }}</th>
                        <th class="text-right">{{ $columnHeading($byTypeBasis) }}</th>
                        <th class="text-right">Share</th>
                        <th>Distribution</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($byType as $row)
                        <tr>
                            <td class="font-medium text-[#1A365D]">{{ $row->label }}</td>
                            <td class="text-right">{{ $amount($row->value, $byTypeBasis) }}</td>
                            <td class="text-right">{{ $pct($row->share_pct) }}</td>
                            <td class="min-w-[160px]">
                                <div class="w-full bg-gray-100 rounded-full h-2 overflow-hidden">
                                    <div class="h-2 bg-[#1A365D]" style="width: {{ min(100, $row->share_pct ?? 0) }}%"></div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center py-6 text-gray-400">No data available</td></tr>
                    @endforelse
                </tbody>
            </table>
            @if ($byTypeBasis === 'residual_score')
                <p class="px-5 py-3 text-xs text-yellow-800 bg-yellow-50 border-t border-yellow-200 leading-relaxed">
                    No completed simulation exists, so these are totals of residual risk scores — ordinal points on
                    the 1-25 matrix, not Naira and not capital. They are shown to indicate where residual risk is
                    concentrated. Run a Monte Carlo simulation for a monetary figure.
                </p>
            @endif
        </div>

        {{-- By Business Unit --}}
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100">
                <h3 class="text-sm font-semibold text-[#1A365D]">By Business Unit</h3>
            </div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Business Unit</th>
                        <th class="text-right">Risks</th>
                        <th class="text-right">{{ $columnHeading($byUnitBasis) }}</th>
                        <th class="text-right">Share</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($byUnit as $row)
                        <tr>
                            <td class="font-medium text-[#1A365D]">{{ $row->label }}</td>
                            <td class="text-right">{{ (int) $row->risks }}</td>
                            <td class="text-right">{{ $amount($row->value, $byUnitBasis) }}</td>
                            <td class="text-right">{{ $pct($row->share_pct) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center py-6 text-gray-400">No data available</td></tr>
                    @endforelse
                </tbody>
            </table>
            <p class="px-5 py-3 text-xs text-gray-600 bg-gray-50 border-t border-gray-200 leading-relaxed">
                Residual score totals, not capital. The simulation engine does not produce a loss distribution per
                business unit, so no monetary allocation to business units exists to report.
            </p>
        </div>
    </div>

    <div class="mt-6 bg-white rounded-xl border border-gray-200 p-5">
        <h3 class="text-sm font-semibold text-[#1A365D] mb-3">Notes</h3>
        <div class="text-sm text-gray-600 leading-relaxed space-y-3">
            <p>
                <span class="font-semibold text-[#1A365D]">This is not a capital allocation.</span>
                Where a completed Monte Carlo run exists, the by-risk-type figures are each scenario's
                <span class="font-semibold">expected annual loss</span> and its share of total expected annual loss,
                taken from the engine's <code class="text-xs">risk_contributions</code>. Expected loss is a mean, not
                a tail measure: it says nothing about how a scenario contributes to VaR or to economic capital, and
                it systematically under-reports exactly the scenarios that matter most for capital — the ones with a
                small mean and a fat tail. A genuine capital allocation needs a component-VaR (Euler) decomposition,
                which this engine does not compute. Do not read the shares below as economic capital by risk type.
            </p>
            <p>
                Where no simulation has run, the figures are totals of residual risk scores from the register. Those
                are ordinal 1-25 matrix values: not additive in any strict sense, not denominated in Naira, and
                labelled accordingly. They were previously emitted under the key <code class="text-xs">capital</code>
                and shown in a column headed "Capital / Score", which invited every reader to treat a sum of matrix
                scores as a naira capital figure.
            </p>
        </div>
    </div>
@endsection
