@extends('layouts.app')

@section('title', 'Trend Analysis - GRC Risk Management')
@section('page-section', 'Analysis')
@section('page-title', 'Trends')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-500">Analysis</span>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Trend Analysis</span>
@endsection

@section('content')
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">Risk Trend Analysis</h1>
            <p class="text-sm text-gray-500 mt-1">Multi-metric trend analysis across risk categories and time periods</p>
        </div>
        <form method="GET" action="{{ route('risk.analysis.trends') }}" class="flex items-center gap-3">
            <input type="date" name="from" value="{{ $fromValue ?? now()->subMonths(12)->format('Y-m-d') }}" class="text-xs border border-gray-200 rounded-lg px-3 py-2 bg-white">
            <span class="text-xs text-gray-400">to</span>
            <input type="date" name="to" value="{{ $toValue ?? now()->format('Y-m-d') }}" class="text-xs border border-gray-200 rounded-lg px-3 py-2 bg-white">
            <button type="submit" class="px-3 py-2 bg-[#1A365D] text-white rounded-lg text-xs font-medium hover:bg-[#2D4A7A]">Apply</button>
        </form>
    </div>

    {{-- KPI Trend Cards --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <x-kpi-card title="Total Active Risks" :value="$totalActiveRisks ?? 0" icon="shield" color="primary" :change="$activeRisksChange ?? null" :changeDirection="$activeRisksDirection ?? null" />
        <x-kpi-card title="Average Risk Score" :value="number_format($avgRiskScore ?? 0, 1)" icon="analytics" color="warning" :change="$avgScoreChange ?? null" :changeDirection="$avgScoreDirection ?? null" />
        <x-kpi-card title="New Risks (Period)" :value="$newRisks ?? 0" icon="add_circle" color="info" />
        <x-kpi-card title="Closed Risks (Period)" :value="$closedRisks ?? 0" icon="check_circle" color="success" />
    </div>

    {{-- Main Trend Charts --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Risk Count by Rating Over Time</h3>
            <canvas id="ratingTrendChart" height="280"></canvas>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Average Risk Score Trend</h3>
            <canvas id="scoreTrendChart" height="280"></canvas>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Risk Events by Category</h3>
            <canvas id="categoryTrendChart" height="280"></canvas>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Treatment Effectiveness Over Time</h3>
            <canvas id="treatmentTrendChart" height="280"></canvas>
        </div>
    </div>

    {{-- Movers Table --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- These two panels used to be titled "Top Risk Increasers" and "Top
             Risk Decreasers" with Previous / Current columns, which asserted
             movement over time. The figure behind them has never been a
             time series — it is inherent score minus residual score, i.e. how
             far the control environment moves each risk as assessed today. The
             headings, columns and arrow direction now say that. A genuine
             period-over-period comparison needs RiskRepository::asOf() against
             the measure engine and is a separate panel. --}}
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100">
                <h3 class="text-sm font-semibold text-green-600">Largest control effect</h3>
                <p class="text-xs text-gray-500 mt-0.5">Residual position furthest below inherent</p>
            </div>
            <table class="data-table">
                <thead><tr><th>Risk</th><th>Inherent</th><th>Residual</th><th>Reduction</th></tr></thead>
                <tbody>
                    @forelse (($riskIncreasers ?? []) as $r)
                        <tr>
                            <td class="text-xs font-medium text-[#1A365D]">{{ $r->risk_code ?? '-' }}</td>
                            <td><x-risk-badge :rating="$r->inherent_rating ?? 'unrated'" /></td>
                            <td><x-risk-badge :rating="$r->residual_rating ?? 'unrated'" /></td>
                            <td class="text-green-500"><span class="material-symbols-outlined text-sm">arrow_downward</span> {{ abs($r->score_change ?? 0) }}</td>
                        </tr>
                    @empty <tr><td colspan="4" class="text-center py-6 text-gray-400">No assessed risks with a residual position</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100">
                <h3 class="text-sm font-semibold text-red-600">Residual above inherent</h3>
                <p class="text-xs text-gray-500 mt-0.5">Controls are not reducing exposure — review the assessment</p>
            </div>
            <table class="data-table">
                <thead><tr><th>Risk</th><th>Inherent</th><th>Residual</th><th>Increase</th></tr></thead>
                <tbody>
                    @forelse (($riskDecreasers ?? []) as $r)
                        <tr>
                            <td class="text-xs font-medium text-[#1A365D]">{{ $r->risk_code ?? '-' }}</td>
                            <td><x-risk-badge :rating="$r->inherent_rating ?? 'unrated'" /></td>
                            <td><x-risk-badge :rating="$r->residual_rating ?? 'unrated'" /></td>
                            <td class="text-red-500"><span class="material-symbols-outlined text-sm">arrow_upward</span> +{{ abs($r->score_change ?? 0) }}</td>
                        </tr>
                    @empty <tr><td colspan="4" class="text-center py-6 text-gray-400">No risks where residual exceeds inherent</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection

@php
    $ratingTrendDataChart = $ratingTrendData ?? ['labels' => [], 'critical' => [], 'high' => [], 'medium' => [], 'low' => []];
    $scoreTrendDataChart = $scoreTrendData ?? ['labels' => [], 'values' => []];
    $categoryTrendDataChart = $categoryTrendData ?? ['labels' => [], 'datasets' => []];
    $treatmentTrendDataChart = $treatmentTrendData ?? ['labels' => [], 'completed' => [], 'overdue' => []];
@endphp

@push('scripts')
<script>
window.onPageReady(function() {
    const ratingTrend = @json($ratingTrendDataChart);
    new Chart(document.getElementById('ratingTrendChart'), {
        type: 'line', data: { labels: ratingTrend.labels, datasets: [
            { label: 'Critical', data: ratingTrend.critical, borderColor: '#C53030', tension: 0.3, fill: true, backgroundColor: 'rgba(197,48,48,0.1)' },
            { label: 'High', data: ratingTrend.high, borderColor: '#DD6B20', tension: 0.3, fill: true, backgroundColor: 'rgba(221,107,32,0.1)' },
            { label: 'Medium', data: ratingTrend.medium, borderColor: '#D4AF37', tension: 0.3, fill: true, backgroundColor: 'rgba(212,175,55,0.1)' },
            { label: 'Low', data: ratingTrend.low, borderColor: '#2D7D46', tension: 0.3, fill: true, backgroundColor: 'rgba(45,125,70,0.1)' },
        ] }, options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, plugins: { legend: { position: 'bottom', labels: { font: { size: 10 }, usePointStyle: true } } }, scales: { x: { grid: { display: false }, ticks: { font: { size: 10 } } }, y: { beginAtZero: true, stacked: true, grid: { color: '#F0F0F0' }, ticks: { font: { size: 10 } } } } }
    });

    const scoreTrend = @json($scoreTrendDataChart);
    new Chart(document.getElementById('scoreTrendChart'), {
        type: 'line', data: { labels: scoreTrend.labels, datasets: [{ label: 'Avg Score', data: scoreTrend.values, borderColor: '#1A365D', backgroundColor: 'rgba(26,54,93,0.1)', tension: 0.3, fill: true }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: { display: false }, ticks: { font: { size: 10 } } }, y: { grid: { color: '#F0F0F0' }, ticks: { font: { size: 10 } } } } }
    });

    const catTrend = @json($categoryTrendDataChart);
    new Chart(document.getElementById('categoryTrendChart'), {
        type: 'bar', data: { labels: catTrend.labels, datasets: (catTrend.datasets || []).map((ds, i) => ({ ...ds, borderRadius: 2, backgroundColor: ['#1A365D','#C53030','#D4AF37','#2D7D46','#553C9A','#DD6B20'][i % 6] })) },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { font: { size: 10 }, usePointStyle: true } } }, scales: { x: { grid: { display: false }, stacked: true }, y: { beginAtZero: true, stacked: true, grid: { color: '#F0F0F0' } } } }
    });

    const treatTrend = @json($treatmentTrendDataChart);
    new Chart(document.getElementById('treatmentTrendChart'), {
        type: 'bar', data: { labels: treatTrend.labels, datasets: [{ label: 'Completed', data: treatTrend.completed, backgroundColor: '#2D7D46', borderRadius: 2 }, { label: 'Overdue', data: treatTrend.overdue, backgroundColor: '#C53030', borderRadius: 2 }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { font: { size: 10 }, usePointStyle: true } } }, scales: { x: { grid: { display: false } }, y: { beginAtZero: true, grid: { color: '#F0F0F0' }, ticks: { stepSize: 1 } } } }
    });
});
</script>
@endpush
