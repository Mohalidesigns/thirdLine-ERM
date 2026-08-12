@extends('layouts.app')

@section('title', 'Risk Correlation - GRC Risk Management')
@section('page-section', 'Analysis')
@section('page-title', 'Correlation')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-500">Analysis</span>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Correlation Analysis</span>
@endsection

@section('content')
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">Risk Correlation Analysis</h1>
            <p class="text-sm text-gray-500 mt-1">Identify interdependencies and correlations between risk events</p>
        </div>
        <form method="GET" action="{{ route('risk.analysis.correlation') }}" data-live-filter>
            <select name="category_id" class="text-xs border border-gray-200 rounded-lg px-3 py-2 bg-white text-gray-600">
                <option value="">All Categories</option>
                @foreach (($categories ?? []) as $cat)
                    <option value="{{ $cat->id }}" {{ (int) ($selectedCategoryId ?? 0) === (int) $cat->id ? 'selected' : '' }}>{{ $cat->name }}</option>
                @endforeach
            </select>
        </form>
    </div>

    {{-- Correlation Matrix --}}
    <div class="bg-white rounded-xl border border-gray-200 p-5 mb-6">
        <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Correlation Matrix</h3>
        <div class="overflow-x-auto">
            <canvas id="correlationChart" height="400"></canvas>
        </div>
        <div class="flex items-center justify-center gap-6 mt-4 text-xs text-gray-500">
            <span class="flex items-center gap-1"><span class="w-4 h-4 rounded bg-red-500 inline-block"></span> Strong Positive (0.7-1.0)</span>
            <span class="flex items-center gap-1"><span class="w-4 h-4 rounded bg-orange-400 inline-block"></span> Moderate (0.3-0.7)</span>
            <span class="flex items-center gap-1"><span class="w-4 h-4 rounded bg-gray-300 inline-block"></span> Weak (0-0.3)</span>
            <span class="flex items-center gap-1"><span class="w-4 h-4 rounded bg-blue-500 inline-block"></span> Negative</span>
        </div>
    </div>

    {{-- Key Correlations Table --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100"><h3 class="text-sm font-semibold text-[#1A365D]">Strongest Positive Correlations</h3></div>
            <table class="data-table">
                <thead><tr><th>Risk A</th><th>Risk B</th><th>Correlation</th><th>Significance</th></tr></thead>
                <tbody>
                    @forelse (($positiveCorrelations ?? []) as $corr)
                        <tr>
                            <td class="text-xs font-medium text-[#1A365D]">{{ $corr->risk_a ?? '-' }}</td>
                            <td class="text-xs font-medium text-[#1A365D]">{{ $corr->risk_b ?? '-' }}</td>
                            <td><span class="text-xs font-bold text-red-600">{{ number_format($corr->coefficient ?? 0, 3) }}</span></td>
                            <td><span class="badge {{ ($corr->significance ?? '') === 'high' ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700' }}">{{ ucfirst($corr->significance ?? '-') }}</span></td>
                        </tr>
                    @empty <tr><td colspan="4" class="text-center py-6 text-gray-400">No significant positive correlations found</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100"><h3 class="text-sm font-semibold text-[#1A365D]">Strongest Negative Correlations</h3></div>
            <table class="data-table">
                <thead><tr><th>Risk A</th><th>Risk B</th><th>Correlation</th><th>Significance</th></tr></thead>
                <tbody>
                    @forelse (($negativeCorrelations ?? []) as $corr)
                        <tr>
                            <td class="text-xs font-medium text-[#1A365D]">{{ $corr->risk_a ?? '-' }}</td>
                            <td class="text-xs font-medium text-[#1A365D]">{{ $corr->risk_b ?? '-' }}</td>
                            <td><span class="text-xs font-bold text-blue-600">{{ number_format($corr->coefficient ?? 0, 3) }}</span></td>
                            <td><span class="badge {{ ($corr->significance ?? '') === 'high' ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600' }}">{{ ucfirst($corr->significance ?? '-') }}</span></td>
                        </tr>
                    @empty <tr><td colspan="4" class="text-center py-6 text-gray-400">No significant negative correlations found</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection

@push('scripts')
<script>
window.onPageReady(function() {
    const corrData = @json($correlationMatrix ?? ['labels' => [], 'data' => []]);
    if (corrData.data.length > 0) {
        const ctx = document.getElementById('correlationChart').getContext('2d');
        const datasets = [];
        corrData.data.forEach((row, rowIdx) => {
            row.forEach((val, colIdx) => {
                datasets.push({ x: colIdx, y: rowIdx, v: val });
            });
        });
        new Chart(ctx, {
            type: 'scatter', data: { datasets: [{ data: datasets, pointRadius: ctx => { const size = Math.abs(ctx.raw?.v || 0) * 15 + 5; return size; }, pointBackgroundColor: ctx => { const v = ctx.raw?.v || 0; return v > 0.7 ? '#C53030' : (v > 0.3 ? '#DD6B20' : (v > 0 ? '#CBD5E0' : '#3B82F6')); } }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => corrData.labels[ctx.raw.y] + ' vs ' + corrData.labels[ctx.raw.x] + ': ' + ctx.raw.v.toFixed(3) } } }, scales: { x: { type: 'linear', min: -0.5, max: corrData.labels.length - 0.5, ticks: { callback: v => corrData.labels[v] || '', font: { size: 9 }, maxRotation: 45 } }, y: { type: 'linear', min: -0.5, max: corrData.labels.length - 0.5, ticks: { callback: v => corrData.labels[v] || '', font: { size: 9 } } } } }
        });
    }
});
</script>
@endpush
