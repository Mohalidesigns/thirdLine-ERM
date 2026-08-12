@extends('layouts.app')

@section('title', 'KRI Dashboard - GRC Risk Management')
@section('page-section', 'KRI Monitoring')
@section('page-title', 'Dashboard')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.kri.index') }}" class="hover:text-[#1A365D]">KRI Monitoring</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Dashboard</span>
@endsection

@section('content')
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">KRI Monitoring Dashboard</h1>
            <p class="text-sm text-gray-500 mt-1">Real-time key risk indicator status, thresholds, and breach monitoring</p>
        </div>
        <div class="flex items-center gap-3">
            <select id="periodFilter" class="text-xs border border-gray-200 rounded-lg px-3 py-2 bg-white text-gray-600 focus:ring-1 focus:ring-[#1A365D]">
                <option value="30">Last 30 Days</option>
                <option value="90" selected>Last 90 Days</option>
                <option value="ytd">Year to Date</option>
            </select>
            <a href="{{ route('risk.kri.create') }}" class="flex items-center gap-2 px-4 py-2 bg-[#1A365D] text-white rounded-lg text-xs font-semibold hover:bg-[#2D4A7A] transition">
                <span class="material-symbols-outlined text-sm">add</span> New KRI
            </a>
        </div>
    </div>

    @if (session('success'))
        <div class="mb-4 px-4 py-3 bg-green-50 border border-green-200 text-green-700 rounded-lg text-sm flex items-center gap-2">
            <span class="material-symbols-outlined text-lg">check_circle</span>{{ session('success') }}
        </div>
    @endif

    {{-- KPI Summary --}}
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
        <x-kpi-card title="Total KRIs" :value="$totalKris ?? 0" icon="speed" color="primary" />
        <x-kpi-card title="Green (Normal)" :value="$greenCount ?? 0" icon="check_circle" color="success" />
        <x-kpi-card title="Amber (Warning)" :value="$amberCount ?? 0" icon="warning" color="warning" />
        <x-kpi-card title="Red (Breach)" :value="$redCount ?? 0" icon="error" color="danger" />
        <x-kpi-card title="Active Breaches" :value="$activeBreaches ?? 0" icon="notifications_active" color="danger" subtitle="Requires action" />
        <x-kpi-card title="Avg. Health Score" :value="($avgHealthScore ?? 0) . '%'" icon="monitor_heart" color="info" :change="$healthScoreChange ?? null" :changeDirection="$healthScoreDirection ?? null" />
    </div>

    {{-- Traffic Light Grid --}}
    <div class="bg-white rounded-xl border border-gray-200 p-5 mb-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-semibold text-[#1A365D]">KRI Traffic Light Overview</h3>
            <div class="flex items-center gap-4 text-xs text-gray-500">
                <span class="flex items-center gap-1"><span class="w-3 h-3 rounded-full bg-green-500 inline-block"></span> Within Tolerance</span>
                <span class="flex items-center gap-1"><span class="w-3 h-3 rounded-full bg-yellow-500 inline-block"></span> Warning</span>
                <span class="flex items-center gap-1"><span class="w-3 h-3 rounded-full bg-red-500 inline-block"></span> Breach</span>
            </div>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6 gap-3">
            @forelse (($kris ?? []) as $kri)
                <a href="{{ route('risk.kri.show', $kri) }}"
                   class="block p-3 rounded-lg border transition-all hover:shadow-md
                       {{ ($kri->current_status ?? 'green') === 'red' ? 'border-red-300 bg-red-50' : (($kri->current_status ?? 'green') === 'amber' ? 'border-yellow-300 bg-yellow-50' : 'border-green-300 bg-green-50') }}">
                    <div class="flex items-center justify-between mb-2">
                        <span class="w-3 h-3 rounded-full {{ ($kri->current_status ?? 'green') === 'red' ? 'bg-red-500' : (($kri->current_status ?? 'green') === 'amber' ? 'bg-yellow-500' : 'bg-green-500') }}"></span>
                        @if (($kri->trend ?? null) === 'up')
                            <span class="material-symbols-outlined text-xs text-red-500">trending_up</span>
                        @elseif (($kri->trend ?? null) === 'down')
                            <span class="material-symbols-outlined text-xs text-green-500">trending_down</span>
                        @else
                            <span class="material-symbols-outlined text-xs text-gray-400">trending_flat</span>
                        @endif
                    </div>
                    <p class="text-xs font-semibold text-gray-800 truncate" title="{{ $kri->name }}">{{ $kri->name }}</p>
                    <p class="text-lg font-bold mt-1 {{ ($kri->current_status ?? 'green') === 'red' ? 'text-red-700' : (($kri->current_status ?? 'green') === 'amber' ? 'text-yellow-700' : 'text-green-700') }}">
                        {{ $kri->current_value ?? '-' }}{{ $kri->unit ?? '' }}
                    </p>
                    <p class="text-[10px] text-gray-500 mt-1">{{ $kri->category ?? '' }}</p>
                </a>
            @empty
                <div class="col-span-full text-center py-8 text-gray-400">
                    <span class="material-symbols-outlined text-3xl mb-2 block">speed</span>
                    No KRIs configured. <a href="{{ route('risk.kri.create') }}" class="text-[#1A365D] hover:underline">Create your first KRI</a>
                </div>
            @endforelse
        </div>
    </div>

    {{-- Charts Row --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold text-[#1A365D]">KRI Status Distribution</h3>
                <span class="material-symbols-outlined text-gray-400 text-lg">donut_large</span>
            </div>
            <canvas id="statusDistChart" height="220"></canvas>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold text-[#1A365D]">Monthly Breach Trend</h3>
                <span class="material-symbols-outlined text-gray-400 text-lg">show_chart</span>
            </div>
            <canvas id="breachTrendChart" height="220"></canvas>
        </div>
    </div>

    {{-- Recent Breaches Table --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
            <h3 class="text-sm font-semibold text-[#1A365D]">Recent KRI Breaches</h3>
            <a href="{{ route('risk.kri.breaches') }}" class="text-xs text-[#1A365D] font-medium hover:underline">View All Breaches</a>
        </div>
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>KRI</th>
                        <th>Current Value</th>
                        <th>Threshold</th>
                        <th>Breach Level</th>
                        <th>Category</th>
                        <th>Owner</th>
                        <th>Breach Date</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (($recentBreaches ?? []) as $breach)
                        <tr>
                            <td><a href="{{ route('risk.kri.show', $breach->kri_id ?? $breach->id) }}" class="text-[#1A365D] font-medium hover:underline">{{ $breach->kri_name ?? $breach->name }}</a></td>
                            <td class="font-medium text-red-600">{{ $breach->current_value ?? '-' }}</td>
                            <td class="text-xs text-gray-500">{{ $breach->threshold_value ?? '-' }}</td>
                            <td><span class="badge {{ ($breach->level ?? '') === 'red' ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700' }}">{{ ucfirst($breach->level ?? 'breach') }}</span></td>
                            <td class="text-xs">{{ $breach->category ?? '-' }}</td>
                            <td class="text-xs">{{ $breach->owner ?? '-' }}</td>
                            <td class="text-xs text-gray-500">{{ isset($breach->breach_date) ? $breach->breach_date->format('d M Y') : '-' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center py-8 text-gray-400">
                                <span class="material-symbols-outlined text-3xl mb-2 block">verified</span>
                                No recent breaches - all KRIs within tolerance
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection

@php
    $statusDistDataChart = $statusDistData ?? ['labels' => ['Green', 'Amber', 'Red'], 'values' => [0, 0, 0]];
    $breachTrendDataChart = $breachTrendData ?? ['labels' => ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'], 'red' => [0,0,0,0,0,0,0,0,0,0,0,0], 'amber' => [0,0,0,0,0,0,0,0,0,0,0,0]];
@endphp

@push('scripts')
<script>
window.onPageReady(function() {
    const distData = @json($statusDistDataChart);
    new Chart(document.getElementById('statusDistChart'), {
        type: 'doughnut',
        data: {
            labels: distData.labels,
            datasets: [{ data: distData.values, backgroundColor: ['#2D7D46', '#D4AF37', '#C53030'], borderWidth: 0, spacing: 2 }]
        },
        options: {
            responsive: true, maintainAspectRatio: false, cutout: '65%',
            plugins: { legend: { position: 'bottom', labels: { font: { size: 10 }, usePointStyle: true, padding: 12 } } }
        }
    });

    const trendData = @json($breachTrendDataChart);
    new Chart(document.getElementById('breachTrendChart'), {
        type: 'bar',
        data: {
            labels: trendData.labels,
            datasets: [
                { label: 'Red Breaches', data: trendData.red, backgroundColor: '#C53030', borderRadius: 4 },
                { label: 'Amber Warnings', data: trendData.amber, backgroundColor: '#D4AF37', borderRadius: 4 }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false, barPercentage: 0.7,
            plugins: { legend: { position: 'bottom', labels: { font: { size: 10 }, usePointStyle: true } } },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 10 } }, stacked: true },
                y: { beginAtZero: true, grid: { color: '#F0F0F0' }, ticks: { font: { size: 10 }, stepSize: 1 }, stacked: true }
            }
        }
    });
});
</script>
@endpush
