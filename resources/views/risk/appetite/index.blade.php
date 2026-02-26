@extends('layouts.app')

@section('title', 'Risk Appetite Framework - GRC Risk Management')
@section('page-section', 'Risk Appetite')
@section('page-title', 'Appetite Framework')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Risk Appetite Framework</span>
@endsection

@section('content')
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">Risk Appetite Framework</h1>
            <p class="text-sm text-gray-500 mt-1">Board-approved appetite statements, tolerance bands, and current position monitoring</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('risk.export.appetite') }}" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2"><span class="material-symbols-outlined text-lg">download</span> Export</a>
            <button class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2"><span class="material-symbols-outlined text-lg">history</span> Version History</button>
        </div>
    </div>

    @if (session('success'))
        <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-xl flex items-center gap-3">
            <span class="material-symbols-outlined text-green-600">check_circle</span>
            <span class="text-sm text-green-700">{{ session('success') }}</span>
            <button onclick="this.parentElement.remove()" class="ml-auto text-green-400 hover:text-green-600"><span class="material-symbols-outlined text-lg">close</span></button>
        </div>
    @endif

    {{-- Overall Appetite Status --}}
    <div class="bg-gradient-to-r from-[#1A365D] to-[#2D4A7A] rounded-xl p-6 text-white mb-6">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-lg font-bold">Overall Risk Appetite Status</h2>
                <p class="text-sm text-blue-200 mt-1">Board approved: {{ $approvalDate ?? now()->subMonths(3)->format('d M Y') }} &middot; Next review: {{ $nextReviewDate ?? now()->addMonths(3)->format('d M Y') }}</p>
            </div>
            <div class="text-right">
                <div class="text-3xl font-bold">{{ $overallStatus ?? 'Within Appetite' }}</div>
                <div class="flex items-center gap-2 mt-1 justify-end">
                    <span class="w-3 h-3 rounded-full {{ ($overallStatus ?? '') === 'Within Appetite' ? 'bg-green-400' : (($overallStatus ?? '') === 'Near Limit' ? 'bg-yellow-400' : 'bg-red-400') }}"></span>
                    <span class="text-sm">{{ $appetiteBreaches ?? 0 }} breaches active</span>
                </div>
            </div>
        </div>
    </div>

    {{-- KPI Cards --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <x-kpi-card title="Appetite Metrics" :value="$totalMetrics ?? 0" icon="speed" color="primary" />
        <x-kpi-card title="Within Tolerance" :value="$withinTolerance ?? 0" icon="check_circle" color="success" />
        <x-kpi-card title="Near Limit" :value="$nearLimit ?? 0" icon="warning" color="warning" />
        <x-kpi-card title="Breach" :value="$appetiteBreaches ?? 0" icon="error" color="danger" />
    </div>

    {{-- Appetite Visualization --}}
    <div class="bg-white rounded-xl border border-gray-200 p-5 mb-6">
        <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Appetite vs Current Position</h3>
        <canvas id="appetiteChart" height="150"></canvas>
    </div>

    {{-- Appetite Metrics Table --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100">
            <h3 class="text-sm font-semibold text-[#1A365D]">Risk Appetite Metrics</h3>
        </div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Risk Category</th>
                    <th>Appetite Statement</th>
                    <th>Metric</th>
                    <th>Tolerance Band</th>
                    <th>Current Position</th>
                    <th>Status</th>
                    <th>Trend</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse (($appetiteMetrics ?? []) as $metric)
                    <tr class="{{ ($metric->status ?? '') === 'breach' ? 'border-l-4 border-l-red-500 bg-red-50/50' : (($metric->status ?? '') === 'near_limit' ? 'border-l-4 border-l-yellow-500' : '') }}">
                        <td class="font-medium text-[#1A365D] text-xs">{{ $metric->risk_category ?? '-' }}</td>
                        <td class="text-xs text-gray-600 max-w-[200px]">{{ Str::limit($metric->appetite_statement ?? '-', 60) }}</td>
                        <td class="text-xs font-medium">{{ $metric->metric_name ?? '-' }}</td>
                        <td class="text-xs">
                            <div class="flex items-center gap-1">
                                <span class="text-green-600">{{ $metric->lower_limit ?? '-' }}</span>
                                <span class="text-gray-400">-</span>
                                <span class="text-red-600">{{ $metric->upper_limit ?? '-' }}</span>
                            </div>
                        </td>
                        <td class="text-xs font-bold {{ ($metric->status ?? '') === 'breach' ? 'text-red-600' : (($metric->status ?? '') === 'near_limit' ? 'text-yellow-600' : 'text-green-600') }}">
                            {{ $metric->current_value ?? '-' }}
                        </td>
                        <td>
                            <span class="flex items-center gap-1">
                                <span class="w-2.5 h-2.5 rounded-full {{ ($metric->status ?? '') === 'breach' ? 'bg-red-500' : (($metric->status ?? '') === 'near_limit' ? 'bg-yellow-500' : 'bg-green-500') }}"></span>
                                <span class="text-xs font-medium">{{ ucfirst(str_replace('_', ' ', $metric->status ?? 'within')) }}</span>
                            </span>
                        </td>
                        <td>
                            @if (($metric->trend ?? null) === 'up') <span class="material-symbols-outlined text-sm text-red-500">trending_up</span>
                            @elseif (($metric->trend ?? null) === 'down') <span class="material-symbols-outlined text-sm text-green-500">trending_down</span>
                            @else <span class="material-symbols-outlined text-sm text-gray-400">trending_flat</span> @endif
                        </td>
                        <td>
                            @if (($metric->status ?? '') === 'breach')
                                <span class="badge bg-red-100 text-red-700">Action Required</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center py-12"><span class="material-symbols-outlined text-4xl text-gray-300 mb-2 block">speed</span><p class="text-sm text-gray-500">No appetite metrics configured</p></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection

@php
    $appetiteChartDefaults = $appetiteChartData ?? ['labels' => [], 'appetite' => [], 'current' => [], 'limit' => []];
@endphp

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const appData = @json($appetiteChartDefaults);
    new Chart(document.getElementById('appetiteChart'), {
        type: 'bar',
        data: { labels: appData.labels, datasets: [
            { label: 'Appetite Limit', data: appData.limit, backgroundColor: 'rgba(197,48,48,0.15)', borderColor: '#C53030', borderWidth: 2, borderDash: [5,5], type: 'line', fill: false, pointRadius: 0 },
            { label: 'Current Position', data: appData.current, backgroundColor: appData.current.map((v,i) => v > (appData.limit[i] || 999) ? '#C53030' : (v > (appData.appetite[i] || 0) * 0.8 ? '#D4AF37' : '#2D7D46')), borderRadius: 4 }
        ] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { font: { size: 10 }, usePointStyle: true } } }, scales: { x: { grid: { display: false }, ticks: { font: { size: 10 } } }, y: { beginAtZero: true, grid: { color: '#F0F0F0' }, ticks: { font: { size: 10 } } } } }
    });
});
</script>
@endpush
