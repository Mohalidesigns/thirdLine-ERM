@extends('layouts.app')

@section('title', 'Executive Risk Summary - GRC Risk Management')
@section('page-section', 'Reports')
@section('page-title', 'Executive Summary')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-500">Reports</span>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Executive Summary</span>
@endsection

@section('content')
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">Executive Risk Summary</h1>
            <p class="text-sm text-gray-500 mt-1">High-level risk overview for executive management &middot; {{ now()->format('F Y') }}</p>
        </div>
        <div class="flex gap-2">
            {{-- A real server-rendered PDF, not the browser print dialog. --}}
            <a href="{{ route('risk.reports.executive', ['download' => 1, 'format' => 'pdf']) }}"
               class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm hover:bg-[#2D4A7A] flex items-center gap-2">
                <span class="material-symbols-outlined text-lg">picture_as_pdf</span> Download PDF
            </a>
            <a href="{{ route('risk.reports.executive', ['download' => 1, 'format' => 'xlsx']) }}"
               class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2">
                <span class="material-symbols-outlined text-lg">table_view</span> Excel
            </a>
            <button onclick="window.print()" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2"><span class="material-symbols-outlined text-lg">print</span> Print</button>
        </div>
    </div>

    {{-- KPI Cards --}}
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
        <x-kpi-card title="Total Active Risks" :value="$totalRisks ?? 0" icon="shield" color="primary" :change="$risksChange ?? null" :changeDirection="$risksDirection ?? null" />
        <x-kpi-card title="Critical Risks" :value="$criticalRisks ?? 0" icon="error" color="danger" />
        <x-kpi-card title="Financial Exposure" :value="'₦' . number_format($financialExposure ?? 0)" icon="payments" color="warning" />
        <x-kpi-card title="Treatment Completion"
                    :value="($treatmentCompletion ?? 0) . '%'"
                    :unavailable="($treatmentCompletion ?? null) === null"
                    unavailableLabel="No plans on record"
                    icon="task_alt" color="success" />
        <x-kpi-card title="KRI Breaches" :value="$kriBreaches ?? 0" icon="notifications_active" color="danger" />
        {{-- Reads from the declared appetite statements via RiskAppetiteService.
             The literal fallback here asserted "Within" — a green tile claiming
             the organisation sat inside a Board-approved appetite — for any
             tenant the controller had produced no status for. --}}
        <x-kpi-card title="Risk Appetite Status"
                    :value="$appetiteStatus"
                    :unavailable="($appetiteStatus ?? null) === null"
                    unavailableLabel="No appetite declared"
                    icon="speed"
                    :color="($appetiteStatus ?? '') === 'Within' ? 'success' : 'danger'" />
    </div>

    {{-- Charts --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Risk by Category</h3>
            <canvas id="categoryChart" height="220"></canvas>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Risk Rating Distribution</h3>
            <canvas id="ratingChart" height="220"></canvas>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Risk Trend (12 Months)</h3>
            <canvas id="trendChart" height="220"></canvas>
        </div>
    </div>

    {{-- Top Risks Table --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden mb-6">
        <div class="px-5 py-4 border-b border-gray-100"><h3 class="text-sm font-semibold text-[#1A365D]">Top 10 Risks</h3></div>
        <table class="data-table">
            {{-- The Trend column is gone: it read `$risk->trend`, which does
                 not exist on `risks` and is not derived anywhere, so every row
                 drew a grey flat arrow — a claim of "no change" that nothing
                 computed. Treatment Status no longer reads the non-existent
                 `treatment_status` column; see ReportController::applyTreatmentStatus(). --}}
            <thead><tr><th>Rank</th><th>Risk Code</th><th>Title</th><th>Category</th><th>Residual Rating</th><th>Owner</th><th>Treatment Status</th></tr></thead>
            <tbody>
                @forelse (($topRisks ?? []) as $index => $risk)
                    <tr>
                        <td class="text-xs font-bold text-gray-400">{{ $index + 1 }}</td>
                        <td class="font-medium text-[#1A365D]"><a href="{{ route('risk.register.show', $risk) }}" class="hover:underline">{{ $risk->risk_code }}</a></td>
                        <td class="text-xs">{{ Str::limit($risk->title, 40) }}</td>
                        <td class="text-xs">{{ $risk->category->name ?? '-' }}</td>
                        <td><x-risk-badge :rating="$risk->residual_rating ?? 'Unrated'" /></td>
                        <td class="text-xs">{{ $risk->owner->name ?? '-' }}</td>
                        <td><x-status-badge :status="$risk->derived_treatment_status ?? 'Not started'" type="treatment" /></td>
                    </tr>
                @empty <tr><td colspan="7" class="text-center py-8 text-gray-400">No risk data available</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Key Metrics Summary --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Financial Exposure by Category</h3>
            <canvas id="exposureChart" height="200"></canvas>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Key Risk Indicators Status</h3>
            <div class="grid grid-cols-3 gap-4 mb-4">
                <div class="text-center p-3 bg-green-50 rounded-lg"><p class="text-2xl font-bold text-green-700">{{ $kriGreen ?? 0 }}</p><p class="text-xs text-gray-500">Green</p></div>
                <div class="text-center p-3 bg-yellow-50 rounded-lg"><p class="text-2xl font-bold text-yellow-700">{{ $kriAmber ?? 0 }}</p><p class="text-xs text-gray-500">Amber</p></div>
                <div class="text-center p-3 bg-red-50 rounded-lg"><p class="text-2xl font-bold text-red-700">{{ $kriRed ?? 0 }}</p><p class="text-xs text-gray-500">Red</p></div>
            </div>
            <canvas id="kriStatusChart" height="120"></canvas>
        </div>
    </div>
@endsection

@php
    $categoryChartDataChart = $categoryChartData ?? ['labels' => [], 'values' => []];
    $ratingChartDataChart = $ratingChartData ?? ['labels' => ['Critical','High','Medium','Low'], 'values' => [0,0,0,0]];
    $trendChartDataChart = $trendChartData ?? ['labels' => [], 'values' => []];
    $exposureChartDataChart = $exposureChartData ?? ['labels' => [], 'values' => []];
    $kriStatusChartDataChart = $kriStatusChartData ?? ['labels' => ['Green','Amber','Red'], 'values' => [0,0,0]];
@endphp

@push('scripts')
<script>
window.onPageReady(function() {
    const catData = @json($categoryChartDataChart);
    new Chart(document.getElementById('categoryChart'), { type: 'bar', data: { labels: catData.labels, datasets: [{ data: catData.values, backgroundColor: '#1A365D', borderRadius: 4, barThickness: 20 }] }, options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, grid: { color: '#F0F0F0' }, ticks: { font: { size: 10 }, stepSize: 1 } }, y: { grid: { display: false }, ticks: { font: { size: 10 } } } } } });

    const ratingData = @json($ratingChartDataChart);
    new Chart(document.getElementById('ratingChart'), { type: 'doughnut', data: { labels: ratingData.labels, datasets: [{ data: ratingData.values, backgroundColor: ['#C53030','#DD6B20','#D4AF37','#2D7D46'], borderWidth: 0 }] }, options: { responsive: true, maintainAspectRatio: false, cutout: '60%', plugins: { legend: { position: 'bottom', labels: { font: { size: 10 }, usePointStyle: true } } } } });

    const trendData = @json($trendChartDataChart);
    new Chart(document.getElementById('trendChart'), { type: 'line', data: { labels: trendData.labels, datasets: [{ label: 'Total Risks', data: trendData.values, borderColor: '#1A365D', backgroundColor: 'rgba(26,54,93,0.1)', tension: 0.3, fill: true }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: { display: false }, ticks: { font: { size: 10 } } }, y: { beginAtZero: true, grid: { color: '#F0F0F0' }, ticks: { font: { size: 10 } } } } } });

    const expData = @json($exposureChartDataChart);
    new Chart(document.getElementById('exposureChart'), { type: 'bar', data: { labels: expData.labels, datasets: [{ data: expData.values, backgroundColor: '#D4AF37', borderRadius: 4 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => '₦' + ctx.parsed.y.toLocaleString() } } }, scales: { x: { grid: { display: false }, ticks: { font: { size: 10 } } }, y: { beginAtZero: true, grid: { color: '#F0F0F0' }, ticks: { font: { size: 10 }, callback: v => '₦' + (v/1000000).toFixed(0) + 'M' } } } } });

    const kriData = @json($kriStatusChartDataChart);
    new Chart(document.getElementById('kriStatusChart'), { type: 'bar', data: { labels: kriData.labels, datasets: [{ data: kriData.values, backgroundColor: ['#2D7D46','#D4AF37','#C53030'], borderRadius: 4 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: { display: false } }, y: { beginAtZero: true, grid: { color: '#F0F0F0' }, ticks: { stepSize: 1 } } } } });
});
</script>
@endpush
