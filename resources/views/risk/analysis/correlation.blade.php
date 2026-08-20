@extends('layouts.app')

{{--
    This page was "Risk Correlation Analysis" and showed a coefficient to three
    decimal places with a Significance column. None of it was a correlation:
    the positive column was the shared-control ratio relabelled, the negative
    column was -(|score_a - score_b| / 25) generated for any two risks in
    different categories, and the matrix averaged the shared-control ratio with
    a score-similarity term and forced its diagonal to 1.0.

    A correlation needs paired observations over time and a coefficient
    reported with an n and a p-value; this product collects no risk-level time
    series, so it cannot produce one. What it can produce, exactly and from
    stored rows, is how much of two risks' control sets are the same controls —
    a real concentration signal, because risks that lean on the same controls
    fail together when those controls fail. That is what this page shows and
    what it now says it shows. See AnalysisController::correlation().
--}}

@section('title', 'Shared Control Analysis - GRC Risk Management')
@section('page-section', 'Analysis')
@section('page-title', 'Shared Controls')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-500">Analysis</span>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Shared Control Analysis</span>
@endsection

@section('content')
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">Shared Control Analysis</h1>
            <p class="text-sm text-gray-500 mt-1">Where two risks rely on the same controls &mdash; and would be exposed together if those controls failed</p>
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

    {{-- Control Overlap Matrix --}}
    <div class="bg-white rounded-xl border border-gray-200 p-5 mb-6">
        <h3 class="text-sm font-semibold text-[#1A365D] mb-1">Control Overlap Matrix</h3>
        <p class="text-xs text-gray-500 mb-4">For each pair of risks: the share of the larger control set that is mapped to both. Not a statistical correlation.</p>
        <div class="overflow-x-auto">
            <canvas id="overlapChart" height="400"></canvas>
        </div>
        <div class="flex items-center justify-center gap-6 mt-4 text-xs text-gray-500">
            <span class="flex items-center gap-1"><span class="w-4 h-4 rounded bg-red-500 inline-block"></span> Heavy overlap (70&ndash;100%)</span>
            <span class="flex items-center gap-1"><span class="w-4 h-4 rounded bg-orange-400 inline-block"></span> Moderate (30&ndash;70%)</span>
            <span class="flex items-center gap-1"><span class="w-4 h-4 rounded bg-gray-300 inline-block"></span> Light (1&ndash;30%)</span>
            <span class="flex items-center gap-1"><span class="w-4 h-4 rounded border border-gray-300 inline-block"></span> No shared controls</span>
        </div>
    </div>

    {{-- Highest overlap pairs --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100">
            <h3 class="text-sm font-semibold text-[#1A365D]">Risk Pairs With The Most Shared Controls</h3>
            <p class="text-xs text-gray-500 mt-1">Counted from the risk-to-control mappings on record.</p>
        </div>
        <table class="data-table">
            <thead><tr><th>Risk A</th><th>Risk B</th><th>Shared Controls</th><th>Controls Mapped</th><th>Overlap</th></tr></thead>
            <tbody>
                @forelse (($sharedControlPairs ?? []) as $pair)
                    <tr>
                        <td class="text-xs font-medium text-[#1A365D]">{{ $pair->risk_a ?? '-' }}</td>
                        <td class="text-xs font-medium text-[#1A365D]">{{ $pair->risk_b ?? '-' }}</td>
                        <td class="text-xs font-semibold text-gray-700">{{ $pair->shared_controls ?? 0 }}</td>
                        <td class="text-xs text-gray-500">{{ $pair->controls_a ?? 0 }} &middot; {{ $pair->controls_b ?? 0 }}</td>
                        <td><span class="text-xs font-bold {{ ($pair->overlap_pct ?? 0) >= 70 ? 'text-red-600' : (($pair->overlap_pct ?? 0) >= 30 ? 'text-orange-600' : 'text-gray-600') }}">{{ $pair->overlap_pct ?? 0 }}%</span></td>
                    </tr>
                @empty <tr><td colspan="5" class="text-center py-6 text-gray-400">No two risks in this selection share a control.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection

@push('scripts')
<script>
window.onPageReady(function() {
    const overlapData = @json($overlapMatrix ?? ['labels' => [], 'data' => []]);
    if (overlapData.data.length > 0) {
        const ctx = document.getElementById('overlapChart').getContext('2d');
        const points = [];
        overlapData.data.forEach((row, rowIdx) => {
            row.forEach((val, colIdx) => {
                // The diagonal is null — a risk shares every control with
                // itself, which says nothing — so it is simply not plotted.
                if (val === null) return;
                points.push({ x: colIdx, y: rowIdx, v: val });
            });
        });
        new Chart(ctx, {
            type: 'scatter', data: { datasets: [{ data: points, pointRadius: ctx => ((ctx.raw?.v || 0) / 100) * 15 + 5, pointBackgroundColor: ctx => { const v = ctx.raw?.v || 0; return v >= 70 ? '#C53030' : (v >= 30 ? '#DD6B20' : (v > 0 ? '#CBD5E0' : 'rgba(0,0,0,0)')); }, pointBorderColor: ctx => (ctx.raw?.v || 0) > 0 ? 'transparent' : '#E2E8F0' }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => overlapData.labels[ctx.raw.y] + ' / ' + overlapData.labels[ctx.raw.x] + ': ' + ctx.raw.v + '% of controls shared' } } }, scales: { x: { type: 'linear', min: -0.5, max: overlapData.labels.length - 0.5, ticks: { callback: v => overlapData.labels[v] || '', font: { size: 9 }, maxRotation: 45 } }, y: { type: 'linear', min: -0.5, max: overlapData.labels.length - 0.5, ticks: { callback: v => overlapData.labels[v] || '', font: { size: 9 } } } } }
        });
    }
});
</script>
@endpush
