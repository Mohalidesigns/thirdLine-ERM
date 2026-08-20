@extends('layouts.app')

@section('title', 'Board Risk Report - GRC Risk Management')
@section('page-section', 'Reports')
@section('page-title', 'Board Report')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-500">Reports</span>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Board Report</span>
@endsection

@section('content')
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">Board Risk Report</h1>
            <p class="text-sm text-gray-500 mt-1">Comprehensive risk report for Board of Directors &middot; {{ now()->format('F Y') }}</p>
        </div>
        <div class="flex gap-2">
            <select class="text-xs border border-gray-200 rounded-lg px-3 py-2 bg-white text-gray-600">
                <option>{{ now()->format('F Y') }}</option>
                <option>{{ now()->subMonth()->format('F Y') }}</option>
                <option>{{ now()->subMonths(2)->format('F Y') }}</option>
            </select>
            {{-- The full assembled board pack, in the section order this
                 organisation has configured. --}}
            <a href="{{ route('risk.reports.board', ['download' => 1]) }}"
               class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm hover:bg-[#2D4A7A] flex items-center gap-2">
                <span class="material-symbols-outlined text-lg">picture_as_pdf</span> Download board pack
            </a>
            <a href="{{ route('risk.reports.board-pack.sections') }}"
               class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2">
                <span class="material-symbols-outlined text-lg">tune</span> Sections
            </a>
            <button onclick="window.print()" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2"><span class="material-symbols-outlined text-lg">print</span> Print</button>
        </div>
    </div>

    {{-- Executive Summary

         The fallback here used to be a paragraph of invented prose: a
         Board-approved appetite the reader was told the profile sat inside, a
         named "credit concentration in the oil and gas sector" that no risk in
         the register had produced, and a CAR of 15.2% printed whenever the
         controller had no ICAAP row to read. None of it was computed. If the
         controller cannot produce a summary, the section says exactly that. --}}
    <div class="bg-gradient-to-r from-[#1A365D] to-[#2D4A7A] rounded-xl p-6 text-white mb-6">
        <h2 class="text-lg font-bold mb-3">Executive Summary</h2>
        @if (! empty($executiveSummary))
            <p class="text-sm text-blue-100 leading-relaxed">{{ $executiveSummary }}</p>
        @else
            <p class="text-sm text-blue-100/70 italic leading-relaxed">No executive summary has been generated for this period.</p>
        @endif
    </div>

    {{-- KPI Cards

         Each tile is driven by a value the controller either computed or
         reported as absent. All four previously carried a `?? <literal>`
         fallback — 3.2/5, 72%, 15.2% and 78% — so a tenant with no data at all
         saw a plausible, healthy-looking board dashboard. The Capital Adequacy
         tile was the worst of them: it printed 15.2% in a green tile on the
         same screen as a narrative reading "No ICAAP assessment is on record
         for the current period". --}}
    @php
        $carSubtitle = ($capitalAdequacyMinimum ?? null) !== null
            ? 'Regulatory minimum: '.rtrim(rtrim(number_format($capitalAdequacyMinimum, 2), '0'), '.').'%'
            : null;
    @endphp
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <x-kpi-card title="Risk Profile Score"
                    :value="$riskProfileScore"
                    :unavailable="($riskProfileScore ?? null) === null"
                    icon="analytics" color="primary" />

        {{-- Utilisation of declared appetite tolerance, not the share of risks
             that happen not to be rated Critical. See ReportController::board(). --}}
        <x-kpi-card title="Appetite Utilization"
                    :value="($appetiteUtilization ?? 0) . '%'"
                    :unavailable="($appetiteUtilization ?? null) === null"
                    unavailableLabel="No appetite declared"
                    icon="speed"
                    :subtitle="($appetiteUtilization ?? null) === null ? null : 'Of upper tolerance, across ' . ($appetiteCategoriesWithTolerance ?? 0) . ' categor' . (($appetiteCategoriesWithTolerance ?? 0) === 1 ? 'y' : 'ies')"
                    :color="($appetiteUtilization ?? 0) > 90 ? 'danger' : (($appetiteUtilization ?? 0) > 75 ? 'warning' : 'success')" />

        <x-kpi-card title="Capital Adequacy"
                    :value="($capitalAdequacyRatio ?? 0) . '%'"
                    :unavailable="($capitalAdequacyRatio ?? null) === null"
                    unavailableLabel="No ICAAP on record"
                    icon="account_balance" color="success"
                    :subtitle="$carSubtitle" />

        <x-kpi-card title="Control Effectiveness"
                    :value="($controlEffectiveness ?? 0) . '%'"
                    :unavailable="($controlEffectiveness ?? null) === null"
                    unavailableLabel="No controls rated"
                    icon="verified_user" color="info" />
    </div>

    {{-- Risk Profile --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Risk Profile by Category</h3>
            <canvas id="profileChart" height="250"></canvas>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Risk Appetite vs Current Position</h3>
            <canvas id="appetiteChart" height="250"></canvas>
        </div>
    </div>

    {{-- Critical Risks for Board --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden mb-6">
        <div class="px-5 py-4 border-b border-gray-100 bg-red-50"><h3 class="text-sm font-semibold text-red-700">Critical Risks Requiring Board Attention</h3></div>
        {{-- Four of the seven columns this table used to carry read properties
             that do not exist on `risks`:

               * financial_exposure  — the column is financial_exposure_ngn, so
                                       every row showed ₦0 regardless of the
                                       exposure recorded against the risk;
               * trend               — no trend is stored or derived anywhere,
                                       so every critical risk was drawn with a
                                       grey flat arrow, which a reader takes as
                                       "stable". Column removed;
               * treatment_status    — every row was badged "In Progress". Now
                                       derived from the risk's treatment plans;
               * recommendation      — nothing produces one. Column removed.

             The residual rating badge also defaulted to "critical" when a risk
             had not been re-scored after controls; it now shows Unrated. --}}
        <table class="data-table">
            <thead><tr><th>Risk</th><th>Category</th><th>Residual Rating</th><th>Financial Exposure</th><th>Treatment</th></tr></thead>
            <tbody>
                @forelse (($criticalRisksForBoard ?? []) as $risk)
                    <tr class="border-l-4 border-l-red-500">
                        <td class="font-medium text-[#1A365D]">{{ $risk->title ?? '-' }}</td>
                        <td class="text-xs">{{ $risk->category?->name ?? '-' }}</td>
                        <td><x-risk-badge :rating="$risk->residual_rating ?? 'Unrated'" /></td>
                        <td class="text-xs font-semibold">
                            @if ($risk->financial_exposure_ngn !== null)
                                ₦{{ number_format((float) $risk->financial_exposure_ngn) }}
                            @else
                                <span class="text-gray-400 font-normal italic">Not quantified</span>
                            @endif
                        </td>
                        <td><x-status-badge :status="$risk->derived_treatment_status ?? 'Not started'" type="treatment" /></td>
                    </tr>
                @empty <tr><td colspan="5" class="text-center py-8 text-gray-400">No critical risks to report</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Key Decisions Required --}}
    <div class="bg-white rounded-xl border border-gray-200 p-6">
        <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Key Decisions / Actions Required from Board</h3>
        @forelse (($boardActions ?? []) as $action)
            <div class="flex items-start gap-3 p-3 bg-blue-50 rounded-lg mb-3">
                <span class="material-symbols-outlined text-[#1A365D] flex-shrink-0">gavel</span>
                <div>
                    <p class="text-sm font-medium text-gray-800">{{ $action->title ?? '' }}</p>
                    <p class="text-xs text-gray-500 mt-1">{{ $action->description ?? '' }}</p>
                    <div class="flex items-center gap-3 mt-2 text-xs text-gray-500">
                        <span>Priority: <span class="font-semibold">{{ $action->priority ?? '-' }}</span></span>
                        <span>Due: <span class="font-semibold">{{ $action->due_date ?? '-' }}</span></span>
                    </div>
                </div>
            </div>
        @empty
            <div class="text-center py-6 text-gray-400 text-sm">No pending board actions</div>
        @endforelse
    </div>
@endsection

@push('scripts')
<script>
window.onPageReady(function() {
    const profData = @json($profileChartData ?? ['labels' => [], 'inherent' => [], 'residual' => []]);
    new Chart(document.getElementById('profileChart'), {
        type: 'radar', data: { labels: profData.labels, datasets: [{ label: 'Inherent', data: profData.inherent, borderColor: '#C53030', backgroundColor: 'rgba(197,48,48,0.1)' }, { label: 'Residual', data: profData.residual, borderColor: '#1A365D', backgroundColor: 'rgba(26,54,93,0.1)' }] },
        options: { responsive: true, maintainAspectRatio: false, scales: { r: { beginAtZero: true, max: 5, ticks: { font: { size: 9 } }, pointLabels: { font: { size: 10 } } } }, plugins: { legend: { position: 'bottom', labels: { font: { size: 10 }, usePointStyle: true } } } }
    });

    // Appetite is null for a category with no declared tolerance, which Chart.js
    // draws as a gap. The current-position bar is only coloured against a limit
    // when there is a limit to colour it against — grey otherwise, because
    // "breaching" a tolerance nobody declared is not a finding.
    const appData = @json($appetiteChartData ?? ['labels' => [], 'appetite' => [], 'current' => []]);
    new Chart(document.getElementById('appetiteChart'), {
        type: 'bar', data: { labels: appData.labels, datasets: [{ label: 'Declared Upper Tolerance', data: appData.appetite, backgroundColor: 'rgba(26,54,93,0.3)', borderColor: '#1A365D', borderWidth: 2, borderDash: [5,5] }, { label: 'Current Position', data: appData.current, backgroundColor: appData.current.map((v,i) => appData.appetite[i] == null ? '#9CA3AF' : (v > appData.appetite[i] ? '#C53030' : '#2D7D46')), borderRadius: 4 }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { font: { size: 10 }, usePointStyle: true } } }, scales: { x: { grid: { display: false }, ticks: { font: { size: 10 } } }, y: { beginAtZero: true, grid: { color: '#F0F0F0' } } } }
    });
});
</script>
@endpush
