@extends('layouts.app')

@section('title', 'ICAAP Assessment - GRC Risk Management')
@section('page-section', 'Quantification')
@section('page-title', 'ICAAP')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.quantification.dashboard') }}" class="hover:text-[#1A365D]">Quantification</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">ICAAP Assessment</span>
@endsection

@php
    // WP-08. Every formatter below distinguishes "not recorded" from zero.
    // A blank capital column and a capital column of ₦0 are different claims,
    // and on a capital adequacy screen the second one is a solvency statement.
    $naira = fn ($v) => $v === null ? null : '₦' . number_format((float) $v, 2);
    $pct = fn ($v) => $v === null ? null : number_format((float) $v, 2) . '%';
    $trim = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
    $notAssessed = '<span class="text-gray-400 italic">Not assessed</span>';
    $notRecorded = '<span class="text-gray-400 italic">Not recorded</span>';
@endphp

@section('content')
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">Internal Capital Adequacy Assessment Process (ICAAP)</h1>
            <p class="text-sm text-gray-500 mt-1">
                Capital position, Pillar 1 requirement, Pillar 2A/2B add-ons and stress testing
                @if ($assessment)
                    &middot; {{ $assessment->period }} &middot; as of {{ $assessment->created_at?->format('d M Y') }}
                @endif
            </p>
        </div>
        <div class="flex gap-2 print:hidden">
            <button onclick="window.print()" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2"><span class="material-symbols-outlined text-lg">download</span> Export Report</button>
            <button onclick="window.print()" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2"><span class="material-symbols-outlined text-lg">print</span> Print</button>
        </div>
    </div>

    @if (! $hasAssessment)
        <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-4 mb-6 text-sm text-yellow-800">
            <span class="font-semibold">No ICAAP assessment has been recorded for this organisation.</span>
            Nothing on this page is derived until an assessment exists — capital, risk-weighted assets and the
            Pillar 2 add-ons all come from the assessment record.
        </div>
    @endif

    {{-- Capital Position Overview --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <x-kpi-card title="Total Qualifying Capital"
            :value="$naira($totalCapital)" :unavailable="$totalCapital === null" unavailable-label="Not recorded"
            icon="account_balance" color="primary"
            subtitle="RWA: {{ $naira($totalRwa) ?? 'not recorded' }}" />
        <x-kpi-card title="Capital Adequacy Ratio"
            :value="$pct($carComputed)" :unavailable="$carComputed === null"
            icon="shield"
            :color="$carComputed !== null && $carComputed >= $minimumCar ? 'success' : 'danger'"
            subtitle="CBN minimum: {{ $trim($minimumCar) }}%" />
        <x-kpi-card title="CET1 Ratio"
            :value="$pct($cet1Ratio)" :unavailable="$cet1Ratio === null"
            icon="verified" color="success"
            subtitle="CET1 capital: {{ $naira($cet1Capital) ?? 'not recorded' }}" />
        <x-kpi-card title="Tier 1 Ratio"
            :value="$pct($tier1Ratio)" :unavailable="$tier1Ratio === null"
            icon="workspace_premium" color="info"
            subtitle="Tier 1 capital: {{ $naira($tier1Capital) ?? 'not recorded' }}" />
    </div>

    {{-- Regulatory basis and CAR reconciliation --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-3">Regulatory basis</h3>
            <dl class="text-sm space-y-2">
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500">Minimum CAR applied</dt>
                    <dd class="font-semibold text-[#1A365D]">{{ $trim($minimumCar) }}%</dd>
                </div>
                @if ($organizationMinimumCar !== null && abs($organizationMinimumCar - $minimumCar) > 0.001)
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500">Organisation setting</dt>
                        <dd class="font-semibold text-yellow-700">{{ $trim($organizationMinimumCar) }}%</dd>
                    </div>
                @endif
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500">Capital conservation buffer</dt>
                    <dd class="font-semibold text-[#1A365D]">
                        {{ $trim($conservationBuffer) }}% of RWA
                        @if ($conservationBufferAmount !== null)
                            <span class="text-gray-500 font-normal">({{ $naira($conservationBufferAmount) }})</span>
                        @endif
                    </dd>
                </div>
                <div class="flex justify-between gap-4 pt-2 border-t border-gray-100">
                    <dt class="text-gray-500">CET1 / Tier 1 / Tier 2 capital</dt>
                    <dd class="font-semibold text-[#1A365D] text-right">
                        {!! $naira($cet1Capital) ?? $notRecorded !!} /
                        {!! $naira($tier1Capital) ?? $notRecorded !!} /
                        {!! $naira($tier2Capital) ?? $notRecorded !!}
                    </dd>
                </div>
            </dl>
            <p class="text-xs text-gray-500 mt-3 leading-relaxed">
                CBN Guidelines on Regulatory Capital (September 2021): minimum CAR is 10.0% of total risk-weighted
                assets for banks on a national or regional authorisation and {{ $trim($internationalMinimumCar) }}% for banks on an
                international authorisation and for Domestic Systemically Important Banks (D-SIBs). The capital
                conservation buffer is 1.0% of total RWA, held in CET1; a D-SIB carries a further 1.0% higher
                loss absorbency surcharge, also in CET1.
                @if ($minimumCar < $internationalMinimumCar)
                    <span class="block mt-1 text-yellow-700">
                        This assessment is measured against {{ $trim($minimumCar) }}%. If this institution holds an
                        international authorisation or is designated a D-SIB, {{ $trim($internationalMinimumCar) }}% must be
                        configured on the assessment or in Quantification Settings — it is not inferred.
                    </span>
                @endif
                @if ($organizationMinimumCar !== null && abs($organizationMinimumCar - $minimumCar) > 0.001)
                    <span class="block mt-1 text-yellow-700">
                        This organisation's standing minimum is {{ $trim($organizationMinimumCar) }}%, but the
                        assessment carries {{ $trim($minimumCar) }}% and the assessment wins — an ICAAP is
                        reconciled against the minimum it was prepared under. To restate this assessment against
                        {{ $trim($organizationMinimumCar) }}%, change the figure on the assessment itself.
                    </span>
                @endif
            </p>
        </div>

        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-3">CAR reconciliation</h3>
            <dl class="text-sm space-y-2">
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500">Computed (qualifying capital &divide; RWA)</dt>
                    <dd class="font-semibold text-[#1A365D]">{!! $pct($carComputed) ?? $notAssessed !!}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500">As reported on the assessment</dt>
                    <dd class="font-semibold text-[#1A365D]">{!! $pct($carReported) ?? $notRecorded !!}</dd>
                </div>
                <div class="flex justify-between gap-4 pt-2 border-t border-gray-100">
                    <dt class="text-gray-500">Variance</dt>
                    <dd class="font-semibold {{ $carVarianceMaterial ? 'text-red-600' : 'text-gray-700' }}">
                        {{ $carVariance === null ? '—' : ($carVariance >= 0 ? '+' : '') . number_format($carVariance, 2) . 'pp' }}
                    </dd>
                </div>
            </dl>
            @if ($carVarianceMaterial)
                <p class="text-xs text-red-700 mt-3 leading-relaxed bg-red-50 border border-red-200 rounded-lg p-3">
                    <span class="font-semibold">Variance to investigate.</span>
                    The CAR computed from the capital and RWA stored on this assessment differs from the CAR
                    recorded on it by {{ number_format(abs($carVariance), 2) }} percentage points. One of the two
                    inputs is stale. Neither figure is overwritten by this screen.
                </p>
            @elseif ($carVariance !== null)
                <p class="text-xs text-green-700 mt-3">
                    Reported CAR agrees with the CAR computed from stored capital and RWA, within tolerance.
                </p>
            @elseif ($carComputed === null)
                <p class="text-xs text-gray-500 mt-3">
                    CAR cannot be computed without total risk-weighted assets on the assessment. The reported figure
                    is shown as recorded and is not independently verified.
                </p>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        {{-- Pillar 1 --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-1">Pillar 1 — Minimum Capital Requirement</h3>
            <p class="text-xs text-gray-500 mb-4">Minimum CAR &times; total risk-weighted assets</p>
            <div class="space-y-4">
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                    <span class="text-sm font-medium">Total risk-weighted assets</span>
                    <span class="text-sm font-bold text-[#1A365D]">{!! $naira($totalRwa) ?? $notRecorded !!}</span>
                </div>
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                    <span class="text-sm font-medium">Minimum CAR applied</span>
                    <span class="text-sm font-bold text-[#1A365D]">{{ $trim($minimumCar) }}%</span>
                </div>
                <div class="flex items-center justify-between p-3 bg-blue-50 rounded-lg border border-blue-200">
                    <span class="text-sm font-semibold text-[#1A365D]">Pillar 1 requirement</span>
                    <span class="text-lg font-bold text-[#1A365D]">{!! $naira($pillar1Requirement) ?? $notAssessed !!}</span>
                </div>
                @if ($pillar1Requirement === null)
                    <p class="text-xs text-gray-500">
                        Not computable: this assessment carries no total risk-weighted assets figure.
                    </p>
                @endif
            </div>
        </div>

        {{-- Pillar 2 --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-1">Pillar 2 — ICAAP Capital Add-on</h3>
            <p class="text-xs text-gray-500 mb-4">As recorded on the assessment; no component is derived or apportioned</p>
            @php
                $pillar2aItems = [
                    ['Pillar 2A — Credit Risk', $pillar2aCredit],
                    ['Pillar 2A — Market Risk', $pillar2aMarket],
                    ['Pillar 2A — Operational Risk', $pillar2aOperational],
                    ['Pillar 2A — Other', $pillar2aOther],
                ];
            @endphp
            <div class="space-y-4">
                @foreach ($pillar2aItems as [$label, $amount])
                    <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                        <span class="text-sm font-medium">{{ $label }}</span>
                        <span class="text-sm font-bold text-[#1A365D]">{!! $naira($amount) ?? $notRecorded !!}</span>
                    </div>
                @endforeach
                <div class="flex items-center justify-between p-3 bg-blue-50 rounded-lg border border-blue-200">
                    <span class="text-sm font-semibold text-[#1A365D]">Total Pillar 2A</span>
                    <span class="text-lg font-bold text-[#1A365D]">{!! $naira($totalPillar2a) ?? $notRecorded !!}</span>
                </div>
                <div class="flex items-center justify-between p-3 bg-amber-50 rounded-lg border border-amber-200">
                    <span class="text-sm font-semibold text-[#1A365D]">Pillar 2B — Stress Buffer</span>
                    <span class="text-lg font-bold text-[#1A365D]">{!! $naira($pillar2bStressBuffer) ?? $notRecorded !!}</span>
                </div>
            </div>
            <p class="text-xs text-gray-500 mt-4 leading-relaxed">
                "Pillar 2A — Other" is shown undecomposed. It was previously split into concentration, interest-rate,
                reputational and strategic risk on fixed 30 / 25 / 25 / 20 weights, and half the Pillar 2B stress buffer
                was presented as liquidity risk. Nothing computed those splits and no field holds them, so they are gone.
                A per-risk-type Pillar 2A breakdown needs columns this schema does not yet have.
            </p>
        </div>
    </div>

    {{-- Capital Waterfall --}}
    <div class="bg-white rounded-xl border border-gray-200 p-5 mb-6">
        <div class="flex items-baseline justify-between mb-4">
            <h3 class="text-sm font-semibold text-[#1A365D]">Capital Waterfall</h3>
            <span class="text-xs text-gray-500">
                Available capital:
                <span class="font-semibold {{ ($availableCapital ?? 0) >= 0 ? 'text-green-700' : 'text-red-600' }}">
                    {!! $naira($availableCapital) ?? $notAssessed !!}
                </span>
            </span>
        </div>
        @if ($totalCapital === null)
            <p class="text-sm text-gray-500 py-8 text-center">
                No qualifying capital is recorded on this assessment, so there is nothing to draw down.
            </p>
        @else
            <canvas id="waterfallChart" height="150"></canvas>
            @if (count($waterfallMissing) > 0)
                <p class="text-xs text-yellow-700 mt-3 bg-yellow-50 border border-yellow-200 rounded-lg p-3">
                    Left blank because the assessment does not record them:
                    <span class="font-semibold">{{ implode(', ', $waterfallMissing) }}</span>.
                    Available capital is not shown until every deduction is known — a missing deduction would
                    otherwise be drawn as a zero and read as headroom the bank does not have.
                </p>
            @endif
        @endif
    </div>

    {{-- Stress Testing --}}
    <div class="bg-white rounded-xl border border-gray-200 p-6">
        <div class="flex items-baseline justify-between mb-1">
            <h3 class="text-sm font-semibold text-[#1A365D]">Stress Testing Results</h3>
            @if ($stressSimulation)
                <a href="{{ route('risk.quantification.show-results', $stressSimulation) }}" class="text-xs text-[#1A365D] font-semibold hover:underline">
                    {{ $stressSimulation->simulation_reference }}
                </a>
            @endif
        </div>

        @if (! $stressSimulation)
            <div class="bg-gray-50 border border-gray-200 rounded-lg p-5 text-sm text-gray-600 mt-4">
                <p class="font-semibold text-gray-800 mb-1">No stress simulation is bound to this assessment.</p>
                <p class="leading-relaxed">
                    Stress impact is only reported from a Monte Carlo run that has been deliberately bound to this
                    ICAAP assessment as its stress simulation. To populate this section: run a simulation over the
                    scenarios that represent the stress, then set it as the assessment's stress simulation. Nothing
                    is shown here in the meantime — figures derived from an unrelated run, or from assumed capital
                    drops, are not stress test results.
                </p>
            </div>
        @elseif ($stressAggregateMissing)
            <div class="bg-gray-50 border border-gray-200 rounded-lg p-5 text-sm text-gray-600 mt-4">
                <p class="font-semibold text-gray-800 mb-1">The bound stress simulation has no results yet.</p>
                <p class="leading-relaxed">
                    {{ $stressSimulation->simulation_reference }} is bound to this assessment but its status is
                    <span class="font-semibold">{{ $stressSimulation->status }}</span> and it has produced no
                    aggregate loss distribution. Impact figures appear once the run completes.
                </p>
            </div>
        @else
            <p class="text-xs text-gray-500 mb-4">
                Capital impact is the aggregate Value at Risk produced by {{ $stressSimulation->simulation_reference }}
                at each confidence level the engine computed. CAR after stress is
                (qualifying capital &minus; capital impact) &divide; total RWA. Shortfall is the capital needed to
                restore a {{ $trim($minimumCar) }}% CAR.
            </p>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Confidence level</th>
                        <th class="text-right">Capital impact (VaR)</th>
                        <th class="text-right">Capital after stress</th>
                        <th class="text-right">CAR after stress</th>
                        <th>Meets {{ $trim($minimumCar) }}% minimum?</th>
                        <th class="text-right">Capital shortfall</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($stressRows as $row)
                        <tr>
                            <td class="font-medium">{{ $trim($row->confidence) }}%</td>
                            <td class="text-right text-xs text-red-600 font-medium">-{{ $naira($row->capital_impact) }}</td>
                            <td class="text-right text-xs">{!! $naira($row->capital_after) ?? $notRecorded !!}</td>
                            <td class="text-right text-xs font-bold {{ $row->car_after === null ? 'text-gray-400' : ($row->meets_minimum ? 'text-green-600' : 'text-red-600') }}">
                                {!! $pct($row->car_after) ?? $notAssessed !!}
                            </td>
                            <td>
                                @if ($row->meets_minimum === null)
                                    <span class="badge bg-gray-100 text-gray-600">Not assessable</span>
                                @elseif ($row->meets_minimum)
                                    <span class="badge bg-green-100 text-green-700">Yes</span>
                                @else
                                    <span class="badge bg-red-100 text-red-700">No — breach</span>
                                @endif
                            </td>
                            <td class="text-right text-xs">
                                {!! $row->shortfall === null ? $notAssessed : ($row->shortfall > 0 ? '<span class="text-red-600 font-medium">' . $naira($row->shortfall) . '</span>' : '—') !!}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @if ($stressHeadline)
                <p class="text-xs text-gray-600 mt-4">
                    Headline: at <span class="font-semibold">{{ $trim($stressHeadline->confidence) }}% confidence</span>,
                    stress consumes {{ $naira($stressHeadline->capital_impact) }} of capital and leaves a CAR of
                    {!! $pct($stressHeadline->car_after) ?? $notAssessed !!}
                    against a {{ $trim($minimumCar) }}% minimum.
                </p>
            @endif
            <p class="text-xs text-gray-500 mt-2 leading-relaxed">
                Rows are confidence levels, not named scenarios. This screen previously listed 'Severe Recession',
                'Oil Price Shock' and 'Cyber Attack + Market Crash' with capital drops of 3.5, 2.1 and 5.2 percentage
                points; those scenarios and those drops were hardcoded, identical for every institution, and unrelated
                to this balance sheet. Named macro scenarios belong in the scenario library, where a bank defines and
                calibrates its own.
            </p>
        @endif
    </div>
@endsection

@push('scripts')
<script>
window.onPageReady(function() {
    const canvas = document.getElementById('waterfallChart');
    if (!canvas) {
        return;
    }

    // Values may contain nulls. Chart.js leaves a gap for a null, which is the
    // point: an unrecorded deduction must not be drawn as a zero bar.
    const wfData = @json($waterfallData);

    new Chart(canvas, {
        type: 'bar',
        data: { labels: wfData.labels, datasets: [{ data: wfData.values, backgroundColor: ['#1A365D','#C53030','#DD6B20','#B7791F','#D4AF37','#2D7D46'], borderRadius: 4, barThickness: 44 }] },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: ctx => ctx.parsed.y === null ? 'Not recorded' : '₦' + ctx.parsed.y.toLocaleString() } }
            },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 10 } } },
                y: { grid: { color: '#F0F0F0' }, ticks: { font: { size: 10 }, callback: v => '₦' + (v/1000000000).toFixed(2) + 'B' } }
            }
        }
    });
});
</script>
@endpush
