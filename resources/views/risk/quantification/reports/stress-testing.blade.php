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
    // Null means "not on file", and is rendered as such. It is never a zero:
    // "₦0.00 capital shortfall" and "we do not know the shortfall" are
    // opposite statements to a supervisor.
    $naira = fn ($v) => $v === null ? null : '₦' . number_format((float) $v, 2);
    $pct = fn ($v) => $v === null ? null : number_format((float) $v, 2) . '%';
    $trim = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
    $notAssessed = '<span class="text-gray-400 italic">Not assessed</span>';
@endphp

@section('content')
    <div class="flex items-center justify-between mb-6 print:hidden">
        <div>
            <h1 class="text-2xl font-bold text-[#1A365D]">Stress Testing Report</h1>
            <p class="text-sm text-gray-500 mt-1">Capital impact of the stress simulation bound to the latest ICAAP assessment</p>
        </div>
        <div class="flex gap-2">
            <button onclick="window.print()" class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-2">
                <span class="material-symbols-outlined text-lg">download</span> Download PDF
            </button>
            <a href="{{ route('risk.quantification.reports') }}" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Back</a>
        </div>
    </div>

    {{-- Headline position --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <x-kpi-card title="Pre-Stress CAR" :value="$pct($carComputed)" :unavailable="$carComputed === null"
            icon="shield"
            :color="$carComputed !== null && $carComputed >= $minimumCar ? 'success' : 'danger'"
            subtitle="CBN minimum: {{ $trim($minimumCar) }}%" />
        <x-kpi-card title="Total Qualifying Capital" :value="$naira($totalCapital)" :unavailable="$totalCapital === null"
            unavailable-label="Not recorded" icon="account_balance" color="primary" />
        <x-kpi-card title="Total RWA" :value="$naira($totalRwa)" :unavailable="$totalRwa === null"
            unavailable-label="Not recorded" icon="donut_large" color="primary" />
        <x-kpi-card title="Bound Stress Simulation"
            :value="$stressSim?->simulation_reference" :unavailable="! $hasBoundRun" unavailable-label="None bound"
            icon="calculate" color="info"
            subtitle="{{ $stressSim?->completed_at?->format('d M Y') ?? ($stressSim?->status ?? 'Nothing bound to the assessment') }}" />
    </div>

    @if ($carComputed === null && $carReported !== null)
        <div class="bg-gray-50 border border-gray-200 rounded-xl p-4 mb-6 text-sm text-gray-700">
            The assessment reports a CAR of {{ $pct($carReported) }} but carries no total risk-weighted assets, so
            neither the pre-stress CAR nor any post-stress CAR can be derived from it. Record total RWA on the
            assessment for the table below to produce ratios.
        </div>
    @endif

    @if (! $icaap)
        <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-5 mb-6 text-sm text-yellow-900">
            <p class="font-semibold mb-1">No ICAAP assessment exists for this organisation.</p>
            <p class="leading-relaxed">
                A stress test is measured against a capital position. Record an ICAAP assessment with total
                qualifying capital and total risk-weighted assets, then bind a completed simulation to it as its
                stress simulation. This report stays empty until both exist.
            </p>
        </div>
    @elseif (! $hasBoundRun)
        <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-5 mb-6 text-sm text-yellow-900">
            <p class="font-semibold mb-1">No stress simulation is bound to the latest ICAAP assessment.</p>
            <p class="leading-relaxed">
                To populate this report: create or select the scenarios that represent the stress, run a Monte Carlo
                simulation over them, then set that completed run as the assessment's stress simulation
                (<code class="text-xs">stress_simulation_id</code>).
            </p>
            <p class="leading-relaxed mt-2">
                This report deliberately does <span class="font-semibold">not</span> fall back to the most recently
                completed simulation. That fallback used to be here, and it meant an unrelated run — a single
                operational-risk calibration, for instance — could be presented to a board and to the CBN as a
                macroeconomic stress test. There is no way to guess which run a preparer intended, so it is not
                guessed.
            </p>
        </div>
    @elseif (! $hasRows)
        <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-5 mb-6 text-sm text-yellow-900">
            <p class="font-semibold mb-1">The bound simulation has produced no results.</p>
            <p class="leading-relaxed">
                {{ $stressSim->simulation_reference }} is bound to the assessment but its status is
                <span class="font-semibold">{{ $stressSim->status }}</span> and it has no aggregate loss
                distribution to report against.
            </p>
        </div>
    @endif

    @if ($hasRows)
        {{-- Capital impact by confidence level --}}
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden mb-6">
            <div class="px-5 py-4 border-b border-gray-100">
                <h3 class="text-sm font-semibold text-[#1A365D]">Capital Impact by Confidence Level</h3>
                <p class="text-xs text-gray-500 mt-1">
                    Source: <a href="{{ route('risk.quantification.show-results', $stressSim) }}" class="text-[#1A365D] font-semibold hover:underline">{{ $stressSim->simulation_reference }}</a>
                    &middot; {{ number_format($stressSim->iterations ?? 0) }} iterations
                    &middot; seed {{ $stressSim->random_seed ?? 'not recorded' }}
                    &middot; completed {{ $stressSim->completed_at?->format('d M Y') ?? '—' }}
                </p>
            </div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Confidence level</th>
                        <th class="text-right">Capital impact (aggregate VaR)</th>
                        <th class="text-right">Capital after stress</th>
                        <th class="text-right">CAR before</th>
                        <th class="text-right">CAR after</th>
                        <th class="text-right">Shortfall to {{ $trim($minimumCar) }}%</th>
                        <th>Verdict</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td class="font-medium text-[#1A365D]">{{ $trim($row->confidence) }}%</td>
                            <td class="text-right text-red-600">-{{ $naira($row->capital_impact) }}</td>
                            <td class="text-right">{!! $naira($row->capital_after) ?? $notAssessed !!}</td>
                            <td class="text-right">{!! $pct($carComputed) ?? $notAssessed !!}</td>
                            <td class="text-right {{ $row->car_after === null ? 'text-gray-400' : ($row->meets_minimum ? 'text-green-600' : 'text-red-600') }}">
                                {!! $pct($row->car_after) ?? $notAssessed !!}
                            </td>
                            <td class="text-right {{ ($row->shortfall ?? 0) > 0 ? 'text-red-600' : 'text-gray-500' }}">
                                {!! $row->shortfall === null ? $notAssessed : ($row->shortfall > 0 ? $naira($row->shortfall) : '—') !!}
                            </td>
                            <td>
                                @if ($row->meets_minimum === null)
                                    <span class="px-2 py-0.5 rounded text-[11px] font-semibold bg-gray-100 text-gray-600">Not assessable</span>
                                @elseif ($row->meets_minimum)
                                    <span class="px-2 py-0.5 rounded text-[11px] font-semibold bg-green-100 text-green-700">Pass</span>
                                @else
                                    <span class="px-2 py-0.5 rounded text-[11px] font-semibold bg-red-100 text-red-700">Breach</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- The tenant's own stress scenarios --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden mb-6">
        <div class="px-5 py-4 border-b border-gray-100">
            <h3 class="text-sm font-semibold text-[#1A365D]">Stress Scenarios Defined by This Institution</h3>
            <p class="text-xs text-gray-500 mt-1">Scenarios carrying a CBN stress designation or typed as stress</p>
        </div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Reference</th>
                    <th>Scenario</th>
                    <th>CBN category</th>
                    <th>Stress designation</th>
                    <th class="text-right">Expected annual loss</th>
                    <th>In bound run?</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($stressScenarios as $scenario)
                    <tr>
                        <td class="text-xs font-medium text-[#1A365D]">{{ $scenario->reference }}</td>
                        <td class="font-medium">{{ $scenario->name }}</td>
                        <td class="text-xs">{{ $scenario->category ?? '—' }}</td>
                        <td class="text-xs">{{ $scenario->cbn_stress_scenario ?? '—' }}</td>
                        <td class="text-right text-xs">{!! $naira($scenario->expected_annual_loss) ?? '—' !!}</td>
                        <td>
                            @if ($scenario->in_bound_run)
                                <span class="px-2 py-0.5 rounded text-[11px] font-semibold bg-green-100 text-green-700">Yes</span>
                            @else
                                <span class="px-2 py-0.5 rounded text-[11px] font-semibold bg-gray-100 text-gray-600">No</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center py-6 text-gray-500 text-sm">
                            This organisation has not defined any stress scenarios. Create scenarios and mark them
                            with a CBN stress designation for them to appear here.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 p-5">
        <h3 class="text-sm font-semibold text-[#1A365D] mb-3">Methodology</h3>
        <div class="text-sm text-gray-600 leading-relaxed space-y-3">
            <p>
                Every figure above is arithmetic on stored quantities. For each confidence level the simulation
                engine computed:
            </p>
            <ul class="list-disc list-inside text-xs space-y-1 text-gray-600">
                <li>capital impact = the bound run's aggregate Value at Risk at that confidence level</li>
                <li>capital after stress = total qualifying capital &minus; capital impact</li>
                <li>CAR after stress = capital after stress &divide; total risk-weighted assets &times; 100</li>
                <li>shortfall = max(0, (minimum CAR &divide; 100) &times; total RWA &minus; capital after stress)</li>
                <li>verdict = CAR after stress is at or above the resolved minimum of {{ $trim($minimumCar) }}%</li>
            </ul>
            <p class="text-xs">
                Rows are confidence levels, not scenario names. This report previously listed five hardcoded
                scenarios — 'Severe Recession', 'Oil Price Shock', 'Naira Devaluation', 'Cyber Attack + Market Crash'
                and 'Liquidity Squeeze' — with fixed CAR drops of 3.5, 2.1, 2.8, 5.2 and 1.6 percentage points.
                Those drops were literals independent of this institution's capital, RWA and portfolio, and no bank
                using this product had defined those scenarios. A named macro scenario belongs in the scenario
                library, calibrated by the institution, and reaches this report by being included in the run bound
                to the assessment.
            </p>
            <p class="text-xs">
                The minimum CAR of {{ $trim($minimumCar) }}% is resolved from the ICAAP assessment, then the
                organisation's quantification settings, then the CBN default of 10.0%. A bank on international
                authorisation or designated a D-SIB is measured against 15.0% and must configure it.
            </p>
        </div>
    </div>
@endsection
