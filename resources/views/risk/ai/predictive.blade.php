@extends('layouts.app')

@section('title', 'Risk Forecast - GRC Risk Management')
@section('page-section', 'Risk Intelligence')
@section('page-title', 'Forecast')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-500">Risk Intelligence</span>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Forecast</span>
@endsection

@section('content')
    <div class="flex items-start justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">Risk Score Forecast</h1>
            <p class="text-sm text-gray-500 mt-1">
                A projection of this organisation's own assessment history. Not a prediction — see method below.
            </p>
        </div>
        <div class="text-right text-xs text-gray-500">
            <p>As at <span class="font-semibold text-[#1A365D]">{{ \Carbon\Carbon::parse($asAt)->format('d M Y H:i') }}</span></p>
            <p class="mt-1">Window {{ $inputs['window_start'] }} to {{ $inputs['window_end'] }}</p>
        </div>
    </div>

    {{-- Method disclosure. This sits above the numbers deliberately: the
         reader should know what produced them before they read them. --}}
    <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-6">
        <div class="flex items-start gap-3">
            <span class="material-symbols-outlined text-blue-600 text-lg">function</span>
            <div class="text-xs text-blue-900 leading-relaxed">
                <p class="font-semibold mb-1">Method</p>
                <p>
                    The line is the monthly mean <span class="font-medium">residual score</span> of risk assessments
                    dated in each month, taken from the risk register's own assessment records. The forward segment
                    extrapolates an ordinary-least-squares fit through those monthly means. The shaded band is the
                    standard error of prediction from that fit — it is not a confidence interval, and no probability
                    is attached to it.
                </p>
                <p class="mt-2">
                    Inputs: <span class="font-medium">{{ number_format($inputs['assessments_in_window']) }}</span>
                    assessments dated in the window across
                    <span class="font-medium">{{ number_format($inputs['active_risks']) }}</span> active risks;
                    <span class="font-medium">{{ $fit['n'] ?? 0 }}</span> of the last
                    {{ $inputs['history_months'] }} months carry at least one assessment.
                </p>
            </div>
        </div>
    </div>

    @if (! ($fit['available'] ?? false))
        <div class="bg-white rounded-xl border border-gray-200 p-8 text-center mb-6">
            <span class="material-symbols-outlined text-3xl text-gray-300 mb-2 block">query_stats</span>
            <p class="text-sm font-semibold text-gray-700">No projection available</p>
            <p class="text-xs text-gray-500 mt-2 max-w-xl mx-auto">{{ $fit['reason'] }}</p>
            <p class="text-xs text-gray-400 mt-3">
                Rather than draw a line through too few points, this screen shows nothing until the assessment
                history supports one.
            </p>
        </div>
    @else
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <x-kpi-card
                title="Trend direction"
                :value="ucfirst($fit['direction'])"
                icon="{{ $fit['direction'] === 'rising' ? 'trending_up' : ($fit['direction'] === 'falling' ? 'trending_down' : 'trending_flat') }}"
                color="{{ $fit['direction'] === 'rising' ? 'danger' : ($fit['direction'] === 'falling' ? 'success' : 'info') }}"
                subtitle="{{ sprintf('%+.2f residual score per month', $fit['slope_per_month']) }}" />
            <x-kpi-card
                title="Months fitted"
                :value="$fit['n']"
                icon="calendar_month"
                color="info"
                subtitle="of {{ $inputs['history_months'] }} in window" />
            <x-kpi-card
                title="Residual std. error"
                :value="number_format($fit['residual_std_error'], 2)"
                icon="straighten"
                color="warning"
                subtitle="spread of months about the fit" />
            <x-kpi-card
                title="Risks deteriorating"
                :value="count($deteriorating)"
                icon="north_east"
                color="danger"
                subtitle="latest vs previous assessment" />
        </div>

        <div class="bg-white rounded-xl border border-gray-200 p-5 mb-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold text-[#1A365D]">Mean residual score — observed and projected</h3>
                <span class="text-xs text-gray-500">
                    {{ $inputs['history_months'] }} months observed · {{ $inputs['horizon_months'] }} months projected
                </span>
            </div>
            <canvas id="forecastChart" height="150"></canvas>
            <p class="text-[11px] text-gray-500 mt-3">
                Gaps in the observed line are months in which no assessment was dated. They are left empty rather
                than interpolated.
            </p>
        </div>

        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden mb-6">
            <div class="px-5 py-4 border-b border-gray-100">
                <h3 class="text-sm font-semibold text-[#1A365D]">Projected months</h3>
            </div>
            <table class="w-full text-xs">
                <thead class="bg-gray-50 text-gray-600">
                    <tr>
                        <th class="text-left px-5 py-2 font-medium">Month</th>
                        <th class="text-right px-5 py-2 font-medium">Projected mean residual score</th>
                        <th class="text-right px-5 py-2 font-medium">Range</th>
                        <th class="text-left px-5 py-2 font-medium">Basis</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($projection as $p)
                        <tr>
                            <td class="px-5 py-3 font-medium text-[#1A365D]">{{ $p['label'] }}</td>
                            <td class="px-5 py-3 text-right">{{ number_format($p['projected_mean_residual_score'], 2) }}</td>
                            <td class="px-5 py-3 text-right text-gray-600">
                                {{ number_format($p['range_low'], 2) }} – {{ number_format($p['range_high'], 2) }}
                            </td>
                            <td class="px-5 py-3 text-gray-500">{{ $p['range_basis'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Leading signals --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        @php
            $velocity = $signals['treatment_velocity'];
            $kri = $signals['kri_breach_frequency'];
            $tests = $signals['control_test_failure_rate'];
        @endphp

        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-1">Treatment velocity</h3>
            <p class="text-[11px] text-gray-500 mb-4">{{ $velocity['definition'] }}</p>
            <p class="text-3xl font-bold {{ $velocity['net_per_month'] > 0 ? 'text-red-600' : 'text-green-700' }}">
                {{ sprintf('%+.2f', $velocity['net_per_month']) }}
            </p>
            <p class="text-xs text-gray-500 mt-1">net plans per month over the window</p>
            <div class="mt-4 pt-4 border-t border-gray-100 text-xs text-gray-600 space-y-1">
                <div class="flex justify-between"><span>Currently overdue</span><span class="font-semibold">{{ $velocity['currently_overdue'] }}</span></div>
                <div class="flex justify-between"><span>Net over window</span><span class="font-semibold">{{ $velocity['net_over_window'] }}</span></div>
            </div>
        </div>

        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-1">KRI breach frequency</h3>
            <p class="text-[11px] text-gray-500 mb-4">{{ $kri['definition'] }}</p>
            @if ($kri['breach_rate_pct'] === null)
                <p class="text-sm text-gray-400 py-2">No measurements recorded in the window.</p>
            @else
                <p class="text-3xl font-bold text-[#1A365D]">{{ number_format($kri['breach_rate_pct'], 1) }}%</p>
                <p class="text-xs text-gray-500 mt-1">
                    {{ number_format($kri['breaches_in_window']) }} of
                    {{ number_format($kri['measurements_in_window']) }} measurements
                </p>
            @endif
            <div class="mt-4 pt-4 border-t border-gray-100 text-xs text-gray-600">
                <div class="flex justify-between"><span>Indicators red now</span><span class="font-semibold">{{ $kri['currently_red'] }}</span></div>
            </div>
        </div>

        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-1">Control test failure rate</h3>
            <p class="text-[11px] text-gray-500 mb-4">{{ $tests['definition'] }}</p>
            @if ($tests['failure_rate_pct'] === null)
                <p class="text-sm text-gray-400 py-2">No tests completed in the window.</p>
            @else
                <p class="text-3xl font-bold text-[#1A365D]">{{ number_format($tests['failure_rate_pct'], 1) }}%</p>
                <p class="text-xs text-gray-500 mt-1">
                    {{ number_format($tests['failures_in_window']) }} of
                    {{ number_format($tests['tests_in_window']) }} completed tests
                </p>
            @endif
        </div>
    </div>

    {{-- Watchlist: observed movement --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 bg-red-50">
                <h3 class="text-sm font-semibold text-red-700">Deteriorating — residual score rose</h3>
                <p class="text-[11px] text-red-600/80 mt-1">Latest assessment compared with the one before it.</p>
            </div>
            <div class="divide-y divide-gray-100">
                @forelse ($deteriorating as $row)
                    <div class="px-5 py-4 border-l-4 border-l-red-500">
                        <div class="flex items-start justify-between mb-2">
                            <div>
                                <p class="text-xs font-semibold text-[#1A365D]">{{ $row['risk_code'] }}</p>
                                <p class="text-xs text-gray-600 mt-1">{{ Str::limit($row['title'], 50) }}</p>
                            </div>
                            <span class="badge bg-red-100 text-red-700 whitespace-nowrap">+{{ $row['delta'] }}</span>
                        </div>
                        <div class="text-[11px] text-gray-600 bg-gray-50 rounded p-2">
                            {{ $row['previous_score'] }} on {{ \Carbon\Carbon::parse($row['previous_date'])->format('d M Y') }}
                            → {{ $row['current_score'] }} on {{ \Carbon\Carbon::parse($row['current_date'])->format('d M Y') }}
                            @if ($row['overdue_treatments'] > 0)
                                <span class="block mt-1 text-red-700 font-medium">
                                    {{ $row['overdue_treatments'] }} overdue treatment plan{{ $row['overdue_treatments'] === 1 ? '' : 's' }} on this risk
                                </span>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="px-5 py-8 text-center text-gray-400 text-sm">
                        <span class="material-symbols-outlined text-2xl mb-2 block">check_circle</span>
                        No risk has a higher residual score than at its previous assessment
                    </div>
                @endforelse
            </div>
        </div>

        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 bg-green-50">
                <h3 class="text-sm font-semibold text-green-700">Improving — residual score fell</h3>
                <p class="text-[11px] text-green-600/80 mt-1">Latest assessment compared with the one before it.</p>
            </div>
            <div class="divide-y divide-gray-100">
                @forelse ($improving as $row)
                    <div class="px-5 py-4 border-l-4 border-l-green-500">
                        <div class="flex items-start justify-between mb-2">
                            <div>
                                <p class="text-xs font-semibold text-[#1A365D]">{{ $row['risk_code'] }}</p>
                                <p class="text-xs text-gray-600 mt-1">{{ Str::limit($row['title'], 50) }}</p>
                            </div>
                            <span class="badge bg-green-100 text-green-700 whitespace-nowrap">{{ $row['delta'] }}</span>
                        </div>
                        <div class="text-[11px] text-gray-600 bg-gray-50 rounded p-2">
                            {{ $row['previous_score'] }} on {{ \Carbon\Carbon::parse($row['previous_date'])->format('d M Y') }}
                            → {{ $row['current_score'] }} on {{ \Carbon\Carbon::parse($row['current_date'])->format('d M Y') }}
                        </div>
                    </div>
                @empty
                    <div class="px-5 py-8 text-center text-gray-400 text-sm">
                        <span class="material-symbols-outlined text-2xl mb-2 block">trending_flat</span>
                        No risk has a lower residual score than at its previous assessment
                    </div>
                @endforelse
            </div>
        </div>
    </div>
@endsection

@push('scripts')
@if ($fit['available'] ?? false)
<script>
window.onPageReady(function () {
    const data = @json($chart);

    new Chart(document.getElementById('forecastChart'), {
        type: 'line',
        data: {
            labels: data.labels,
            datasets: [
                {
                    label: 'Observed monthly mean',
                    data: data.observed,
                    borderColor: '#1A365D',
                    backgroundColor: 'transparent',
                    tension: 0.2,
                    pointRadius: 3,
                    spanGaps: false,
                },
                {
                    label: 'Projected (OLS extrapolation)',
                    data: data.projected,
                    borderColor: '#C53030',
                    borderDash: [5, 5],
                    backgroundColor: 'transparent',
                    tension: 0.2,
                    pointRadius: 3,
                },
                {
                    label: '± standard error of prediction',
                    data: data.rangeHigh,
                    borderColor: 'rgba(197,48,48,0.25)',
                    backgroundColor: 'rgba(197,48,48,0.07)',
                    fill: '+1',
                    tension: 0.2,
                    pointRadius: 0,
                },
                {
                    label: '',
                    data: data.rangeLow,
                    borderColor: 'rgba(197,48,48,0.25)',
                    backgroundColor: 'rgba(197,48,48,0.07)',
                    fill: false,
                    tension: 0.2,
                    pointRadius: 0,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        font: { size: 10 },
                        usePointStyle: true,
                        filter: (item) => item.text !== '',
                    },
                },
            },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 10 } } },
                y: { grid: { color: '#F0F0F0' }, ticks: { font: { size: 10 } }, title: { display: true, text: 'Mean residual score', font: { size: 10 } } },
            },
        },
    });
});
</script>
@endif
@endpush
