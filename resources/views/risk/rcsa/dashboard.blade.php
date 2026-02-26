@extends('layouts.app')

@section('title', 'RCSA Dashboard - GRC Risk Management')
@section('page-section', 'RCSA')
@section('page-title', 'Dashboard')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.rcsa.dashboard') }}" class="hover:text-[#1A365D]">RCSA</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Dashboard</span>
@endsection

@section('content')
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">RCSA Dashboard</h1>
            <p class="text-sm text-gray-500 mt-1">Risk and Control Self-Assessment overview and completion tracking</p>
        </div>
        <div class="flex items-center gap-3">
            <select class="text-xs border border-gray-200 rounded-lg px-3 py-2 bg-white text-gray-600 focus:ring-1 focus:ring-[#1A365D]">
                <option>Current Assessment Cycle</option>
                <option>Q4 2025</option>
                <option>Q3 2025</option>
            </select>
            <a href="{{ route('risk.rcsa.worksheet') }}" class="flex items-center gap-2 px-4 py-2 bg-[#1A365D] text-white rounded-lg text-xs font-semibold hover:bg-[#2D4A7A] transition">
                <span class="material-symbols-outlined text-sm">add</span> New Assessment
            </a>
        </div>
    </div>

    @if (session('success'))
        <div class="mb-4 px-4 py-3 bg-green-50 border border-green-200 text-green-700 rounded-lg text-sm flex items-center gap-2">
            <span class="material-symbols-outlined text-lg">check_circle</span>{{ session('success') }}
        </div>
    @endif

    {{-- KPI Cards --}}
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
        <x-kpi-card title="Total Assessments" :value="$totalAssessments ?? 0" icon="assignment" color="primary" />
        <x-kpi-card title="Completed" :value="$completedAssessments ?? 0" icon="check_circle" color="success" />
        <x-kpi-card title="In Progress" :value="$inProgressAssessments ?? 0" icon="pending" color="info" />
        <x-kpi-card title="Not Started" :value="$notStartedAssessments ?? 0" icon="schedule" color="warning" />
        <x-kpi-card title="Overdue" :value="$overdueAssessments ?? 0" icon="error" color="danger" />
        <x-kpi-card title="Completion Rate" :value="($completionRate ?? 0) . '%'" icon="percent" color="primary" :change="$completionChange ?? null" :changeDirection="$completionDirection ?? null" />
    </div>

    {{-- Charts --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Completion by Business Unit</h3>
            <canvas id="completionByUnitChart" height="220"></canvas>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Risk Distribution</h3>
            <canvas id="riskDistChart" height="220"></canvas>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Control Effectiveness</h3>
            <canvas id="controlEffChart" height="220"></canvas>
        </div>
    </div>

    {{-- Business Unit Progress --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden mb-6">
        <div class="px-5 py-4 border-b border-gray-100">
            <h3 class="text-sm font-semibold text-[#1A365D]">Assessment Progress by Business Unit</h3>
        </div>
        <table class="data-table">
            <thead><tr><th>Business Unit</th><th>Total Risks</th><th>Assessed</th><th>Progress</th><th>High Risks</th><th>Control Gaps</th><th>Status</th><th>Due Date</th></tr></thead>
            <tbody>
                @forelse (($unitProgress ?? []) as $unit)
                    <tr>
                        <td class="font-medium text-[#1A365D]">{{ $unit->name ?? '-' }}</td>
                        <td class="text-xs">{{ $unit->total_risks ?? 0 }}</td>
                        <td class="text-xs">{{ $unit->assessed ?? 0 }}</td>
                        <td>
                            <div class="flex items-center gap-2">
                                <div class="w-20 bg-gray-200 rounded-full h-2"><div class="h-2 rounded-full {{ ($unit->progress ?? 0) >= 80 ? 'bg-green-500' : (($unit->progress ?? 0) >= 50 ? 'bg-yellow-500' : 'bg-red-500') }}" style="width: {{ $unit->progress ?? 0 }}%"></div></div>
                                <span class="text-xs">{{ $unit->progress ?? 0 }}%</span>
                            </div>
                        </td>
                        <td class="text-xs font-semibold text-red-600">{{ $unit->high_risks ?? 0 }}</td>
                        <td class="text-xs font-semibold text-orange-600">{{ $unit->control_gaps ?? 0 }}</td>
                        <td><x-status-badge :status="$unit->status ?? 'in progress'" /></td>
                        <td class="text-xs text-gray-500">{{ $unit->due_date ?? '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center py-8 text-gray-400"><span class="material-symbols-outlined text-3xl mb-2 block">assignment</span>No business unit data available</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Top Risks from RCSA --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100"><h3 class="text-sm font-semibold text-[#1A365D]">Top Risks Identified in Current RCSA Cycle</h3></div>
        <table class="data-table">
            <thead><tr><th>Risk</th><th>Business Unit</th><th>Inherent Rating</th><th>Residual Rating</th><th>Control Effectiveness</th><th>Action Required</th></tr></thead>
            <tbody>
                @forelse (($topRisks ?? []) as $risk)
                    <tr>
                        <td class="font-medium text-[#1A365D]">{{ $risk->title ?? '-' }}</td>
                        <td class="text-xs">{{ $risk->business_unit ?? '-' }}</td>
                        <td><x-risk-badge :rating="$risk->inherent_rating ?? 'medium'" /></td>
                        <td><x-risk-badge :rating="$risk->residual_rating ?? 'medium'" /></td>
                        <td>
                            <span class="badge {{ ($risk->control_effectiveness ?? '') === 'effective' ? 'bg-green-100 text-green-700' : (($risk->control_effectiveness ?? '') === 'partially' ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700') }}">
                                {{ ucfirst($risk->control_effectiveness ?? '-') }}
                            </span>
                        </td>
                        <td class="text-xs">{{ Str::limit($risk->action_required ?? '-', 40) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center py-8 text-gray-400">No risks identified yet</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection

@php
    $completionByUnitDataChart = $completionByUnitData ?? ['labels' => [], 'values' => []];
    $riskDistDataChart = $riskDistData ?? ['labels' => ['Critical','High','Medium','Low'], 'values' => [0,0,0,0]];
    $controlEffDataChart = $controlEffData ?? ['labels' => ['Effective','Partially Effective','Ineffective','Not Tested'], 'values' => [0,0,0,0]];
@endphp

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const unitData = @json($completionByUnitDataChart);
    new Chart(document.getElementById('completionByUnitChart'), {
        type: 'bar', data: { labels: unitData.labels, datasets: [{ data: unitData.values, backgroundColor: '#1A365D', borderRadius: 4, barThickness: 20 }] },
        options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, max: 100, grid: { color: '#F0F0F0' }, ticks: { font: { size: 10 }, callback: v => v + '%' } }, y: { grid: { display: false }, ticks: { font: { size: 10 } } } } }
    });

    const riskDist = @json($riskDistDataChart);
    new Chart(document.getElementById('riskDistChart'), {
        type: 'doughnut', data: { labels: riskDist.labels, datasets: [{ data: riskDist.values, backgroundColor: ['#C53030','#DD6B20','#D4AF37','#2D7D46'], borderWidth: 0 }] },
        options: { responsive: true, maintainAspectRatio: false, cutout: '60%', plugins: { legend: { position: 'bottom', labels: { font: { size: 10 }, usePointStyle: true } } } }
    });

    const ctrlEff = @json($controlEffDataChart);
    new Chart(document.getElementById('controlEffChart'), {
        type: 'doughnut', data: { labels: ctrlEff.labels, datasets: [{ data: ctrlEff.values, backgroundColor: ['#2D7D46','#D4AF37','#C53030','#6B7280'], borderWidth: 0 }] },
        options: { responsive: true, maintainAspectRatio: false, cutout: '60%', plugins: { legend: { position: 'bottom', labels: { font: { size: 10 }, usePointStyle: true } } } }
    });
});
</script>
@endpush
