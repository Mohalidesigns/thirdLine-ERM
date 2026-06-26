@extends('layouts.app')

@section('title', ($result->name ?? 'Simulation') . ' Results - GRC Risk Management')
@section('page-section', 'Quantification')
@section('page-title', 'Simulation Results')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.quantification.results') }}" class="hover:text-[#1A365D]">Results</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">{{ Str::limit($result->name ?? '', 40) }}</span>
@endsection

@section('content')
    <div class="mb-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-[#1A365D]">{{ $result->name }}</h1>
                <p class="text-sm text-gray-500 mt-1">{{ number_format($result->iterations ?? 0) }} iterations &middot; {{ $result->scenario_count ?? 0 }} scenarios &middot; {{ $result->created_at?->format('d M Y H:i') }}</p>
            </div>
            <div class="flex gap-2">
                <button onclick="window.print()" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2"><span class="material-symbols-outlined text-lg">download</span> Export PDF</button>
                <a href="{{ route('risk.quantification.results') }}" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Back</a>
            </div>
        </div>
    </div>

    {{-- Key Metrics --}}
    @php
        // Compact Naira so large amounts fit inside narrow KPI cards.
        $compactNaira = function ($amount) {
            $a = abs((float) $amount);
            if ($a >= 1e9) return '₦' . number_format($amount / 1e9, 2) . 'B';
            if ($a >= 1e6) return '₦' . number_format($amount / 1e6, 2) . 'M';
            if ($a >= 1e3) return '₦' . number_format($amount / 1e3, 1) . 'K';
            return '₦' . number_format($amount, 0);
        };
    @endphp
    <style>
        /* Compact variant so 6 KPI cards stack comfortably without overflow. */
        .kpi-strip .kpi-card { padding: 0.85rem 1rem; }
        .kpi-strip .kpi-card .text-2xl { font-size: 1.25rem; line-height: 1.75rem; }
    </style>
    <div class="kpi-strip grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
        <x-kpi-card title="Expected Loss" :value="$compactNaira($result->expected_loss ?? 0)" icon="payments" color="primary" />
        <x-kpi-card title="VaR (95%)" :value="$compactNaira($result->var_95 ?? 0)" icon="trending_up" color="warning" />
        <x-kpi-card title="VaR (99%)" :value="$compactNaira($result->var_99 ?? 0)" icon="trending_up" color="warning" />
        <x-kpi-card title="VaR (99.5%)" :value="$compactNaira($result->var_995 ?? 0)" icon="priority_high" color="danger" />
        <x-kpi-card title="Expected Shortfall" :value="$compactNaira($result->expected_shortfall ?? 0)" icon="warning" color="danger" />
        <x-kpi-card title="Max Simulated Loss" :value="$compactNaira($result->max_loss ?? 0)" icon="error" color="danger" />
    </div>

    {{-- Charts --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Loss Distribution (Histogram)</h3>
            <canvas id="lossHistogram" height="280"></canvas>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Risk Contributions</h3>
            <canvas id="riskContribChart" height="280"></canvas>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Cumulative Distribution Function</h3>
            <canvas id="cdfChart" height="250"></canvas>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Percentile Table</h3>
            <table class="data-table">
                <thead><tr><th>Percentile</th><th>Loss Amount (₦)</th></tr></thead>
                <tbody>
                    @foreach (($result->percentiles ?? []) as $percentile => $value)
                        <tr class="{{ in_array($percentile, ['95%', '99%', '99.5%']) ? 'bg-yellow-50 font-semibold' : '' }}">
                            <td>{{ $percentile }}</td>
                            <td>₦{{ number_format($value) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- Scenario Contributions --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100"><h3 class="text-sm font-semibold text-[#1A365D]">Scenario Contributions</h3></div>
        <table class="data-table">
            <thead><tr><th>Scenario</th><th>Category</th><th>Expected Loss</th><th>Contribution %</th><th>VaR (95%)</th></tr></thead>
            <tbody>
                @forelse (($result->scenario_contributions ?? []) as $contrib)
                    <tr>
                        <td class="font-medium text-[#1A365D]">{{ $contrib->scenario_name ?? '-' }}</td>
                        <td class="text-xs">{{ $contrib->category ?? '-' }}</td>
                        <td class="text-xs font-medium">₦{{ number_format($contrib->expected_loss ?? 0) }}</td>
                        <td>
                            <div class="flex items-center gap-2">
                                <div class="w-16 bg-gray-200 rounded-full h-1.5"><div class="h-1.5 rounded-full bg-[#1A365D]" style="width: {{ $contrib->contribution_pct ?? 0 }}%"></div></div>
                                <span class="text-xs">{{ number_format($contrib->contribution_pct ?? 0, 1) }}%</span>
                            </div>
                        </td>
                        <td class="text-xs font-medium">₦{{ number_format($contrib->var_95 ?? 0) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center py-6 text-gray-400">No scenario breakdown available</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const histData = @json($histogramData ?? ['labels' => [], 'values' => []]);
    new Chart(document.getElementById('lossHistogram'), {
        type: 'bar',
        data: { labels: histData.labels, datasets: [{ label: 'Frequency', data: histData.values, backgroundColor: 'rgba(26,54,93,0.7)', borderRadius: 2 }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: { display: false }, ticks: { font: { size: 9 }, maxRotation: 45 }, title: { display: true, text: 'Loss (₦M)', font: { size: 10 } } }, y: { beginAtZero: true, grid: { color: '#F0F0F0' }, ticks: { font: { size: 10 } } } } }
    });

    const contribData = @json($contribChartData ?? ['labels' => [], 'values' => []]);
    new Chart(document.getElementById('riskContribChart'), {
        type: 'doughnut',
        data: { labels: contribData.labels, datasets: [{ data: contribData.values, backgroundColor: ['#1A365D','#C53030','#D4AF37','#2D7D46','#553C9A','#DD6B20'], borderWidth: 0 }] },
        options: { responsive: true, maintainAspectRatio: false, cutout: '55%', plugins: { legend: { position: 'bottom', labels: { font: { size: 10 }, usePointStyle: true } } } }
    });

    const cdfData = @json($cdfData ?? ['labels' => [], 'values' => []]);
    new Chart(document.getElementById('cdfChart'), {
        type: 'line',
        data: { labels: cdfData.labels, datasets: [{ label: 'CDF', data: cdfData.values, borderColor: '#1A365D', backgroundColor: 'rgba(26,54,93,0.1)', fill: true, tension: 0.3, pointRadius: 0 }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: { display: false }, ticks: { font: { size: 9 } }, title: { display: true, text: 'Loss (₦M)', font: { size: 10 } } }, y: { beginAtZero: true, max: 1, grid: { color: '#F0F0F0' }, ticks: { font: { size: 10 }, callback: v => (v*100) + '%' } } } }
    });
});
</script>
@endpush
