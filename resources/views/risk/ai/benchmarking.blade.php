@extends('layouts.app')

@section('title', 'Industry Benchmarking - GRC Risk Management')
@section('page-section', 'AI Intelligence')
@section('page-title', 'Benchmarking')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-500">AI Intelligence</span>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Industry Benchmarking</span>
@endsection

@section('content')
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">Industry Benchmarking</h1>
            <p class="text-sm text-gray-500 mt-1">Compare your risk metrics against Nigerian banking industry peers</p>
        </div>
        <select class="text-xs border border-gray-200 rounded-lg px-3 py-2 bg-white text-gray-600">
            <option>All Peer Banks</option>
            <option>Tier 1 Banks</option>
            <option>Tier 2 Banks</option>
            <option>Commercial Banks</option>
        </select>
    </div>

    {{-- Comparison Cards --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <x-kpi-card title="Your CAR" :value="($yourCAR ?? 15.2) . '%'" icon="account_balance" :color="($yourCAR ?? 15.2) >= ($peerCAR ?? 14.5) ? 'success' : 'warning'" :subtitle="'Peer avg: ' . ($peerCAR ?? 14.5) . '%'" />
        <x-kpi-card title="Your NPL Ratio" :value="($yourNPL ?? 3.8) . '%'" icon="trending_down" :color="($yourNPL ?? 3.8) <= ($peerNPL ?? 5.2) ? 'success' : 'danger'" :subtitle="'Peer avg: ' . ($peerNPL ?? 5.2) . '%'" />
        <x-kpi-card title="Op Risk Loss Ratio" :value="($yourOpRisk ?? 0.15) . '%'" icon="settings" :color="($yourOpRisk ?? 0.15) <= ($peerOpRisk ?? 0.22) ? 'success' : 'warning'" :subtitle="'Peer avg: ' . ($peerOpRisk ?? 0.22) . '%'" />
        <x-kpi-card title="Risk Maturity Score" :value="($yourMaturity ?? 3.8) . '/5'" icon="psychology" :color="($yourMaturity ?? 3.8) >= ($peerMaturity ?? 3.2) ? 'success' : 'warning'" :subtitle="'Peer avg: ' . ($peerMaturity ?? 3.2) . '/5'" />
    </div>

    {{-- Benchmark Charts --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Risk Profile Comparison</h3>
            <canvas id="profileCompChart" height="300"></canvas>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Key Metrics vs Peer Average</h3>
            <canvas id="metricsChart" height="300"></canvas>
        </div>
    </div>

    {{-- Benchmark Table --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100"><h3 class="text-sm font-semibold text-[#1A365D]">Detailed Metric Comparison - Nigerian Banking Sector</h3></div>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="px-5 py-3 text-left font-semibold text-gray-700">Metric</th>
                        <th class="px-5 py-3 text-center font-semibold text-gray-700">Your Value</th>
                        <th class="px-5 py-3 text-center font-semibold text-gray-700">Peer Average</th>
                        <th class="px-5 py-3 text-center font-semibold text-gray-700">Top Quartile</th>
                        <th class="px-5 py-3 text-center font-semibold text-gray-700">Performance</th>
                        <th class="px-5 py-3 text-center font-semibold text-gray-700">Percentile</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse (($benchmarkMetrics ?? []) as $metric)
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-5 py-3 font-medium text-[#1A365D]">{{ $metric->name ?? '-' }}</td>
                            <td class="px-5 py-3 text-center font-semibold text-gray-800">{{ $metric->your_value ?? '-' }}</td>
                            <td class="px-5 py-3 text-center text-gray-600">{{ $metric->peer_avg ?? '-' }}</td>
                            <td class="px-5 py-3 text-center text-green-600 font-semibold">{{ $metric->peer_best ?? '-' }}</td>
                            <td class="px-5 py-3 text-center">
                                @php
                                    $perf = $metric->trend ?? 'stable';
                                    if (strpos($metric->name, 'Average') !== false || strpos($metric->name, 'Open') !== false || strpos($metric->name, 'Loss') !== false) {
                                        $perf = 'good';
                                    } else {
                                        $perf = 'excellent';
                                    }
                                @endphp
                                <span class="inline-flex items-center gap-1 badge {{ $perf === 'excellent' ? 'bg-green-100 text-green-700' : (in_array($perf, ['good', 'improving']) ? 'bg-blue-100 text-blue-700' : 'bg-yellow-100 text-yellow-700') }}">
                                    @if($perf === 'excellent')
                                        <span class="material-symbols-outlined text-sm">check_circle</span> Excellent
                                    @elseif(in_array($perf, ['good', 'improving']))
                                        <span class="material-symbols-outlined text-sm">trending_up</span> Good
                                    @else
                                        <span class="material-symbols-outlined text-sm">trending_down</span> Fair
                                    @endif
                                </span>
                            </td>
                            <td class="px-5 py-3 text-center">
                                <div class="flex items-center gap-2 justify-center">
                                    <div class="w-20 bg-gray-200 rounded-full h-1.5"><div class="h-1.5 rounded-full bg-[#2D7D46]" style="width: {{ $metric->percentile ?? 0 }}%"></div></div>
                                    <span class="font-semibold text-gray-700 w-10">{{ round($metric->percentile ?? 0) }}%</span>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-8 text-gray-400">No benchmark data available</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Peer Group Info --}}
    <div class="grid grid-cols-3 gap-4 mt-6">
        <div class="bg-white rounded-xl border border-gray-200 p-5 text-center">
            <p class="text-sm font-semibold text-[#1A365D] mb-1">Peer Group</p>
            <p class="text-xs text-gray-600">Nigerian Tier 1 & 2 Banks</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5 text-center">
            <p class="text-sm font-semibold text-[#1A365D] mb-1">Data Period</p>
            <p class="text-xs text-gray-600">Q4 2025 - Q1 2026</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5 text-center">
            <p class="text-sm font-semibold text-[#1A365D] mb-1">Your Ranking</p>
            <p class="text-xs text-gray-600">3 of 14 Banks</p>
        </div>
    </div>
@endsection

@php
    $profileCompDataChart = $profileCompData ?? ['labels' => ['Credit','Market','Operational','Liquidity','Strategic','Compliance'], 'yours' => [0,0,0,0,0,0], 'peers' => [0,0,0,0,0,0]];
    $metricsCompDataChart = $metricsCompData ?? ['labels' => [], 'yours' => [], 'peers' => []];
@endphp

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const profComp = @json($profileCompDataChart);
    new Chart(document.getElementById('profileCompChart'), {
        type: 'radar', data: { labels: profComp.labels, datasets: [{ label: 'Your Bank', data: profComp.yours, borderColor: '#1A365D', backgroundColor: 'rgba(26,54,93,0.1)', pointBackgroundColor: '#1A365D' }, { label: 'Peer Average', data: profComp.peers, borderColor: '#D4AF37', backgroundColor: 'rgba(212,175,55,0.1)', pointBackgroundColor: '#D4AF37' }] },
        options: { responsive: true, maintainAspectRatio: false, scales: { r: { beginAtZero: true, max: 5, ticks: { font: { size: 9 } }, pointLabels: { font: { size: 10 } } } }, plugins: { legend: { position: 'bottom', labels: { font: { size: 10 }, usePointStyle: true } } } }
    });

    const metrData = @json($metricsCompDataChart);
    new Chart(document.getElementById('metricsChart'), {
        type: 'bar', data: { labels: metrData.labels, datasets: [{ label: 'Your Bank', data: metrData.yours, backgroundColor: '#1A365D', borderRadius: 4 }, { label: 'Peer Average', data: metrData.peers, backgroundColor: '#D4AF37', borderRadius: 4 }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { font: { size: 10 }, usePointStyle: true } } }, scales: { x: { grid: { display: false }, ticks: { font: { size: 10 } } }, y: { beginAtZero: true, grid: { color: '#F0F0F0' } } } }
    });
});
</script>
@endpush
