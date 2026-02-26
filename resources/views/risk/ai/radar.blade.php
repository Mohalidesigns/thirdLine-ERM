@extends('layouts.app')

@section('title', 'Risk Radar - GRC Risk Management')
@section('page-section', 'AI Intelligence')
@section('page-title', 'Risk Radar')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-500">AI Intelligence</span>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Risk Radar</span>
@endsection

@section('content')
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">Risk Radar</h1>
            <p class="text-sm text-gray-500 mt-1">Emerging risks, velocity indicators, and external risk signals</p>
        </div>
        <button class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-xs font-semibold hover:bg-[#2D4A7A] flex items-center gap-2"><span class="material-symbols-outlined text-sm">refresh</span> Scan Now</button>
    </div>

    {{-- KPI --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <x-kpi-card title="Emerging Risks Identified" :value="($emergingRisks ?? 0)" icon="explore" color="warning" subtitle="Under radar" />
        <x-kpi-card title="Fast-Moving Risks" :value="($fastMovingRisks ?? 0)" icon="speed" color="danger" subtitle="High velocity" />
        <x-kpi-card title="External Risk Signals" :value="($externalSignals ?? 0)" icon="language" color="info" subtitle="Last 30 days" />
        <x-kpi-card title="New This Month" :value="($newThisMonth ?? 0)" icon="new_releases" color="primary" />
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        {{-- Radar Chart --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Risk Radar Visualization</h3>
            <canvas id="radarChart" height="350"></canvas>
        </div>

        {{-- Velocity Indicators --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Risk Velocity Indicators</h3>
            <div class="space-y-3">
                @forelse (($velocityIndicators ?? []) as $indicator)
                    <div class="flex items-center gap-4 p-3 bg-gray-50 rounded-lg">
                        <div class="flex-shrink-0 w-10 h-10 rounded-full flex items-center justify-center {{ ($indicator->velocity ?? '') === 'immediate' ? 'bg-red-100 text-red-600' : (($indicator->velocity ?? '') === 'fast' ? 'bg-orange-100 text-orange-600' : 'bg-yellow-100 text-yellow-600') }}">
                            <span class="material-symbols-outlined text-lg">{{ ($indicator->velocity ?? '') === 'immediate' ? 'bolt' : 'speed' }}</span>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-xs font-semibold text-gray-800">{{ $indicator->title ?? '' }}</p>
                            <p class="text-[10px] text-gray-500">{{ $indicator->category ?? '' }} &middot; Velocity: {{ ucfirst($indicator->velocity ?? '-') }}</p>
                        </div>
                        <div class="text-right">
                            <span class="badge {{ ($indicator->velocity ?? '') === 'immediate' ? 'bg-red-100 text-red-700' : (($indicator->velocity ?? '') === 'fast' ? 'bg-orange-100 text-orange-700' : 'bg-yellow-100 text-yellow-700') }}">
                                {{ ucfirst($indicator->velocity ?? '-') }}
                            </span>
                        </div>
                    </div>
                @empty
                    <div class="text-center py-6 text-gray-400 text-sm">No velocity indicators to display</div>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Emerging Risks Cards --}}
    <div class="mb-6">
        <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Emerging Risk Signals (AI-Detected)</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @forelse (($emergingRiskSignals ?? []) as $signal)
                <div class="bg-white rounded-xl border {{ ($signal->impact_potential ?? '') === 'Critical' ? 'border-red-300 bg-red-50' : (($signal->impact_potential ?? '') === 'High' ? 'border-orange-300 bg-orange-50' : 'border-yellow-300 bg-yellow-50') }} p-4">
                    <div class="flex items-start justify-between mb-2">
                        <div class="flex-1">
                            <h4 class="text-xs font-bold text-gray-800">{{ $signal->title ?? '-' }}</h4>
                            <p class="text-[10px] text-gray-600 mt-1">{{ $signal->category ?? '-' }}</p>
                        </div>
                        <span class="badge {{ ($signal->impact_potential ?? '') === 'Critical' ? 'bg-red-200 text-red-700' : (($signal->impact_potential ?? '') === 'High' ? 'bg-orange-200 text-orange-700' : 'bg-yellow-200 text-yellow-700') }} text-[10px] whitespace-nowrap">
                            {{ ucfirst($signal->impact_potential ?? '-') }}
                        </span>
                    </div>
                    <div class="grid grid-cols-3 gap-2 mt-3 pt-3 border-t border-gray-200">
                        <div class="text-center">
                            <p class="text-[10px] text-gray-600">Velocity</p>
                            <span class="badge {{ ($signal->velocity ?? '') === 'immediate' ? 'bg-red-100 text-red-700' : (($signal->velocity ?? '') === 'fast' ? 'bg-orange-100 text-orange-700' : 'bg-yellow-100 text-yellow-700') }} text-[10px] mt-1">
                                {{ ucfirst($signal->velocity ?? '-') }}
                            </span>
                        </div>
                        <div class="text-center">
                            <p class="text-[10px] text-gray-600">Confidence</p>
                            <div class="w-full bg-gray-200 rounded-full h-1 mt-2"><div class="h-1 rounded-full bg-[#1A365D]" style="width: {{ $signal->confidence ?? 0 }}%"></div></div>
                            <p class="text-[10px] font-semibold text-gray-700 mt-1">{{ $signal->confidence ?? 0 }}%</p>
                        </div>
                        <div class="text-center">
                            <p class="text-[10px] text-gray-600">Source</p>
                            <p class="text-[10px] font-semibold text-gray-700 mt-2 truncate">{{ Str::limit($signal->source ?? '-', 12) }}</p>
                        </div>
                    </div>
                </div>
            @empty
                <div class="col-span-2 bg-white rounded-xl border border-gray-200 p-8 text-center">
                    <span class="material-symbols-outlined text-4xl text-gray-300 mb-2 block">radar</span>
                    <p class="text-sm text-gray-500">No emerging risk signals detected on current radar scan</p>
                </div>
            @endforelse
        </div>
    </div>
@endsection

@php
    $chartRadarData = $radarChartData ?? ['labels' => ['Credit','Market','Operational','Liquidity','Strategic','Compliance','Technology','Reputational'], 'current' => [0,0,0,0,0,0,0,0], 'previous' => [0,0,0,0,0,0,0,0]];
@endphp

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const rData = @json($chartRadarData);
    new Chart(document.getElementById('radarChart'), {
        type: 'radar', data: { labels: rData.labels, datasets: [{ label: 'Current', data: rData.current, borderColor: '#C53030', backgroundColor: 'rgba(197,48,48,0.1)', pointBackgroundColor: '#C53030' }, { label: 'Previous Period', data: rData.previous, borderColor: '#1A365D', backgroundColor: 'rgba(26,54,93,0.1)', pointBackgroundColor: '#1A365D' }] },
        options: { responsive: true, maintainAspectRatio: false, scales: { r: { beginAtZero: true, max: 5, ticks: { font: { size: 9 }, stepSize: 1 }, pointLabels: { font: { size: 10 } } } }, plugins: { legend: { position: 'bottom', labels: { font: { size: 10 }, usePointStyle: true } } } }
    });
});
</script>
@endpush
