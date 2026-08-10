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

    {{-- Executive Summary --}}
    <div class="bg-gradient-to-r from-[#1A365D] to-[#2D4A7A] rounded-xl p-6 text-white mb-6">
        <h2 class="text-lg font-bold mb-3">Executive Summary</h2>
        <p class="text-sm text-blue-100 leading-relaxed">{{ $executiveSummary ?? 'The overall risk profile remains within the Board-approved risk appetite framework. Key areas of attention include credit concentration in the oil and gas sector, rising operational risk incidents, and emerging cyber security threats. Capital adequacy remains above regulatory minimums with a CAR of ' . ($capitalAdequacyRatio ?? '15.2') . '%. ' . ($criticalRisks ?? 0) . ' critical risks require Board-level attention.' }}</p>
    </div>

    {{-- KPI Cards --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <x-kpi-card title="Risk Profile Score" :value="$riskProfileScore ?? '3.2/5'" icon="analytics" color="primary" />
        <x-kpi-card title="Appetite Utilization" :value="($appetiteUtilization ?? 72) . '%'" icon="speed" :color="($appetiteUtilization ?? 72) > 90 ? 'danger' : (($appetiteUtilization ?? 72) > 75 ? 'warning' : 'success')" />
        <x-kpi-card title="Capital Adequacy" :value="($capitalAdequacyRatio ?? 15.2) . '%'" icon="account_balance" color="success" subtitle="Min: 10%" />
        <x-kpi-card title="Control Effectiveness" :value="($controlEffectiveness ?? 78) . '%'" icon="verified_user" color="info" />
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
        <table class="data-table">
            <thead><tr><th>Risk</th><th>Category</th><th>Rating</th><th>Financial Exposure</th><th>Trend</th><th>Treatment</th><th>Recommendation</th></tr></thead>
            <tbody>
                @forelse (($criticalRisksForBoard ?? []) as $risk)
                    <tr class="border-l-4 border-l-red-500">
                        <td class="font-medium text-[#1A365D]">{{ $risk->title ?? '-' }}</td>
                        <td class="text-xs">{{ $risk->category?->name ?? '-' }}</td>
                        <td><x-risk-badge :rating="$risk->residual_rating ?? 'critical'" /></td>
                        <td class="text-xs font-semibold">₦{{ number_format($risk->financial_exposure ?? 0) }}</td>
                        <td>
                            @if (($risk->trend ?? null) === 'up') <span class="material-symbols-outlined text-sm text-red-500">trending_up</span>
                            @elseif (($risk->trend ?? null) === 'down') <span class="material-symbols-outlined text-sm text-green-500">trending_down</span>
                            @else <span class="material-symbols-outlined text-sm text-gray-400">trending_flat</span> @endif
                        </td>
                        <td><x-status-badge :status="$risk->treatment_status ?? 'in progress'" type="treatment" /></td>
                        <td class="text-xs text-gray-600">{{ $risk->recommendation ?? '-' }}</td>
                    </tr>
                @empty <tr><td colspan="7" class="text-center py-8 text-gray-400">No critical risks to report</td></tr>
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
document.addEventListener('DOMContentLoaded', function() {
    const profData = @json($profileChartData ?? ['labels' => [], 'inherent' => [], 'residual' => []]);
    new Chart(document.getElementById('profileChart'), {
        type: 'radar', data: { labels: profData.labels, datasets: [{ label: 'Inherent', data: profData.inherent, borderColor: '#C53030', backgroundColor: 'rgba(197,48,48,0.1)' }, { label: 'Residual', data: profData.residual, borderColor: '#1A365D', backgroundColor: 'rgba(26,54,93,0.1)' }] },
        options: { responsive: true, maintainAspectRatio: false, scales: { r: { beginAtZero: true, max: 5, ticks: { font: { size: 9 } }, pointLabels: { font: { size: 10 } } } }, plugins: { legend: { position: 'bottom', labels: { font: { size: 10 }, usePointStyle: true } } } }
    });

    const appData = @json($appetiteChartData ?? ['labels' => [], 'appetite' => [], 'current' => []]);
    new Chart(document.getElementById('appetiteChart'), {
        type: 'bar', data: { labels: appData.labels, datasets: [{ label: 'Appetite Limit', data: appData.appetite, backgroundColor: 'rgba(26,54,93,0.3)', borderColor: '#1A365D', borderWidth: 2, borderDash: [5,5] }, { label: 'Current Position', data: appData.current, backgroundColor: appData.current.map((v,i) => v > (appData.appetite[i] || 0) ? '#C53030' : '#2D7D46'), borderRadius: 4 }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { font: { size: 10 }, usePointStyle: true } } }, scales: { x: { grid: { display: false }, ticks: { font: { size: 10 } } }, y: { beginAtZero: true, grid: { color: '#F0F0F0' } } } }
    });
});
</script>
@endpush
