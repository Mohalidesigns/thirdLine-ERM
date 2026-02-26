@extends('layouts.app')

@section('title', ($scenario->name ?? 'Scenario') . ' - GRC Risk Management')
@section('page-section', 'Quantification')
@section('page-title', 'Scenario Detail')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.quantification.scenarios') }}" class="hover:text-[#1A365D]">Scenarios</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">{{ Str::limit($scenario->name ?? '', 40) }}</span>
@endsection

@section('content')
    <div class="mb-6">
        <div class="flex items-center justify-between">
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-2xl font-bold text-[#1A365D]">{{ $scenario->name }}</h1>
                    <x-status-badge :status="$scenario->status ?? 'active'" />
                    <span class="badge bg-blue-100 text-blue-700">{{ ucfirst($scenario->distribution_type ?? '-') }}</span>
                </div>
                <p class="text-sm text-gray-500 mt-1">{{ $scenario->description }}</p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('risk.quantification.simulate', ['scenario' => $scenario->id]) }}" class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-2"><span class="material-symbols-outlined text-lg">play_arrow</span> Run Simulation</a>
                <a href="{{ route('risk.quantification.scenarios') }}" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Back</a>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <x-kpi-card title="Mean Loss" :value="'₦' . number_format($scenario->mean ?? 0)" icon="payments" color="primary" />
        <x-kpi-card title="Std Deviation" :value="'₦' . number_format($scenario->std_dev ?? 0)" icon="analytics" color="warning" />
        <x-kpi-card title="Frequency" :value="($scenario->frequency_per_year ?? 0) . '/year'" icon="event_repeat" color="info" />
        <x-kpi-card title="Distribution" :value="ucfirst($scenario->distribution_type ?? '-')" icon="ssid_chart" color="primary" />
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Distribution Visualization</h3>
            <canvas id="distributionChart" height="250"></canvas>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-6">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Scenario Parameters</h3>
            <dl class="space-y-3">
                <div class="flex justify-between border-b border-gray-100 pb-2"><dt class="text-xs text-gray-500">Risk Category</dt><dd class="text-xs font-medium">{{ $scenario->risk_category ?? '-' }}</dd></div>
                <div class="flex justify-between border-b border-gray-100 pb-2"><dt class="text-xs text-gray-500">Distribution Type</dt><dd class="text-xs font-medium">{{ ucfirst($scenario->distribution_type ?? '-') }}</dd></div>
                <div class="flex justify-between border-b border-gray-100 pb-2"><dt class="text-xs text-gray-500">Mean Loss</dt><dd class="text-xs font-medium">₦{{ number_format($scenario->mean ?? 0) }}</dd></div>
                <div class="flex justify-between border-b border-gray-100 pb-2"><dt class="text-xs text-gray-500">Standard Deviation</dt><dd class="text-xs font-medium">₦{{ number_format($scenario->std_dev ?? 0) }}</dd></div>
                <div class="flex justify-between border-b border-gray-100 pb-2"><dt class="text-xs text-gray-500">Min Loss</dt><dd class="text-xs font-medium">₦{{ number_format($scenario->min_loss ?? 0) }}</dd></div>
                <div class="flex justify-between border-b border-gray-100 pb-2"><dt class="text-xs text-gray-500">Max Loss</dt><dd class="text-xs font-medium">₦{{ number_format($scenario->max_loss ?? 0) }}</dd></div>
                <div class="flex justify-between"><dt class="text-xs text-gray-500">Frequency</dt><dd class="text-xs font-medium">{{ $scenario->frequency_per_year ?? 0 }} events/year</dd></div>
            </dl>
        </div>
    </div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const distViz = @json($distributionVisualization ?? ['labels' => [], 'values' => []]);
    new Chart(document.getElementById('distributionChart'), {
        type: 'bar',
        data: { labels: distViz.labels, datasets: [{ label: 'Probability Density', data: distViz.values, backgroundColor: 'rgba(26,54,93,0.6)', borderColor: '#1A365D', borderWidth: 1, borderRadius: 2 }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: { display: false }, ticks: { font: { size: 9 } }, title: { display: true, text: 'Loss Amount (₦)', font: { size: 10 } } }, y: { beginAtZero: true, grid: { color: '#F0F0F0' }, ticks: { font: { size: 10 } }, title: { display: true, text: 'Probability', font: { size: 10 } } } } }
    });
});
</script>
@endpush
