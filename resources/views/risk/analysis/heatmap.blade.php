@extends('layouts.app')

@section('title', 'Risk Heatmap - GRC Risk Management')
@section('page-section', 'Analysis')
@section('page-title', 'Heatmap')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-500">Analysis</span>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Risk Heatmap</span>
@endsection

@section('content')
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">Risk Heatmap</h1>
            <p class="text-sm text-gray-500 mt-1">Interactive 5x5 risk matrix showing likelihood vs impact</p>
        </div>
        <div class="flex items-center gap-3">
            <div class="flex bg-white rounded-lg border border-gray-200 p-0.5">
                <button id="btnInherent" class="px-4 py-1.5 text-xs font-medium rounded-md bg-[#1A365D] text-white" onclick="toggleHeatmap('inherent')">Inherent</button>
                <button id="btnResidual" class="px-4 py-1.5 text-xs font-medium rounded-md text-gray-600 hover:bg-gray-100" onclick="toggleHeatmap('residual')">Residual</button>
            </div>
            <select id="categoryFilter" class="text-xs border border-gray-200 rounded-lg px-3 py-2 bg-white text-gray-600">
                <option value="">All Categories</option>
                @foreach (($categories ?? []) as $cat)
                    <option value="{{ $cat }}">{{ $cat }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Heatmap Grid --}}
        <div class="lg:col-span-2 bg-white rounded-xl border border-gray-200 p-6">
            <div class="flex">
                {{-- Y-axis label --}}
                <div class="flex flex-col justify-center items-center mr-2 -rotate-0">
                    <span class="text-xs font-semibold text-gray-500 writing-mode-vertical" style="writing-mode: vertical-rl; transform: rotate(180deg);">LIKELIHOOD</span>
                </div>

                <div class="flex-1">
                    {{-- Y-axis labels --}}
                    <div class="flex">
                        <div class="w-24 flex-shrink-0"></div>
                        <div class="flex-1 grid grid-cols-5 gap-1 mb-1">
                            @foreach (['Insignificant', 'Minor', 'Moderate', 'Major', 'Catastrophic'] as $label)
                                <div class="text-center text-[10px] text-gray-500 font-medium">{{ $label }}</div>
                            @endforeach
                        </div>
                    </div>

                    @php
                        $likelihoodLabels = [5 => 'Almost Certain', 4 => 'Likely', 3 => 'Possible', 2 => 'Unlikely', 1 => 'Rare'];
                        $cellColors = [
                            '5-5' => 'bg-red-600', '5-4' => 'bg-red-600', '5-3' => 'bg-red-500', '5-2' => 'bg-orange-500', '5-1' => 'bg-yellow-500',
                            '4-5' => 'bg-red-600', '4-4' => 'bg-red-500', '4-3' => 'bg-orange-500', '4-2' => 'bg-yellow-500', '4-1' => 'bg-yellow-400',
                            '3-5' => 'bg-red-500', '3-4' => 'bg-orange-500', '3-3' => 'bg-yellow-500', '3-2' => 'bg-yellow-400', '3-1' => 'bg-green-400',
                            '2-5' => 'bg-orange-500', '2-4' => 'bg-yellow-500', '2-3' => 'bg-yellow-400', '2-2' => 'bg-green-400', '2-1' => 'bg-green-500',
                            '1-5' => 'bg-yellow-500', '1-4' => 'bg-yellow-400', '1-3' => 'bg-green-400', '1-2' => 'bg-green-500', '1-1' => 'bg-green-500',
                        ];
                    @endphp

                    @foreach ($likelihoodLabels as $lScore => $lLabel)
                        <div class="flex mb-1">
                            <div class="w-24 flex-shrink-0 flex items-center">
                                <span class="text-[10px] text-gray-500 font-medium text-right w-full pr-2">{{ $lLabel }} ({{ $lScore }})</span>
                            </div>
                            <div class="flex-1 grid grid-cols-5 gap-1">
                                @for ($iScore = 1; $iScore <= 5; $iScore++)
                                    @php
                                        $key = $lScore . '-' . $iScore;
                                        $risksInCell = collect($risks ?? [])->filter(function($r) use ($lScore, $iScore) {
                                            return ($r->inherent_likelihood ?? 0) == $lScore && ($r->inherent_impact ?? 0) == $iScore;
                                        });
                                    @endphp
                                    <div class="relative {{ $cellColors[$key] ?? 'bg-gray-200' }} rounded-lg min-h-[70px] p-1 cursor-pointer hover:opacity-80 transition-opacity heatmap-cell"
                                         data-likelihood="{{ $lScore }}" data-impact="{{ $iScore }}"
                                         onclick="showCellRisks({{ $lScore }}, {{ $iScore }})">
                                        <div class="text-[9px] text-white/70 font-bold">{{ $lScore * $iScore }}</div>
                                        <div class="flex flex-wrap gap-0.5 mt-0.5">
                                            @foreach ($risksInCell->take(6) as $r)
                                                <div class="w-4 h-4 rounded-full bg-white/30 border border-white/50 flex items-center justify-center text-[7px] text-white font-bold" title="{{ $r->risk_code }}: {{ $r->title }}">
                                                    {{ substr($r->risk_code ?? '', -2) }}
                                                </div>
                                            @endforeach
                                            @if ($risksInCell->count() > 6)
                                                <div class="w-4 h-4 rounded-full bg-white/50 flex items-center justify-center text-[7px] text-gray-700 font-bold">+{{ $risksInCell->count() - 6 }}</div>
                                            @endif
                                        </div>
                                    </div>
                                @endfor
                            </div>
                        </div>
                    @endforeach

                    {{-- X-axis label --}}
                    <div class="flex mt-2">
                        <div class="w-24"></div>
                        <div class="flex-1 text-center text-xs font-semibold text-gray-500">IMPACT</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Risk Details Panel --}}
        <div class="space-y-4">
            <div class="bg-white rounded-xl border border-gray-200 p-5">
                <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Risk Summary</h3>
                @php
                    $riskSummaryLevels = ['Critical' => $criticalCount ?? 0, 'High' => $highCount ?? 0, 'Medium' => $mediumCount ?? 0, 'Low' => $lowCount ?? 0];
                @endphp
                <div class="space-y-2">
                    @foreach ($riskSummaryLevels as $level => $count)
                        <div class="flex items-center justify-between p-2 rounded-lg bg-gray-50">
                            <x-risk-badge :rating="strtolower($level)" />
                            <span class="text-sm font-bold">{{ $count }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            <div id="cellDetails" class="bg-white rounded-xl border border-gray-200 p-5">
                <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Cell Details</h3>
                <div class="text-center py-6 text-gray-400 text-xs">
                    <span class="material-symbols-outlined text-2xl mb-1 block">touch_app</span>
                    Click a cell to view risks
                </div>
            </div>

            <div class="bg-white rounded-xl border border-gray-200 p-5">
                <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Risk Movement</h3>
                <canvas id="movementChart" height="200"></canvas>
            </div>
        </div>
    </div>
@endsection

@php
    $chartMovementData = $movementData ?? ['labels' => ['Q1','Q2','Q3','Q4'], 'critical' => [0,0,0,0], 'high' => [0,0,0,0], 'medium' => [0,0,0,0], 'low' => [0,0,0,0]];
@endphp

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const movData = @json($chartMovementData);
    new Chart(document.getElementById('movementChart'), {
        type: 'line',
        data: {
            labels: movData.labels,
            datasets: [
                { label: 'Critical', data: movData.critical, borderColor: '#C53030', tension: 0.3, pointRadius: 3 },
                { label: 'High', data: movData.high, borderColor: '#DD6B20', tension: 0.3, pointRadius: 3 },
                { label: 'Medium', data: movData.medium, borderColor: '#D4AF37', tension: 0.3, pointRadius: 3 },
                { label: 'Low', data: movData.low, borderColor: '#2D7D46', tension: 0.3, pointRadius: 3 },
            ]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { font: { size: 9 }, usePointStyle: true } } }, scales: { x: { grid: { display: false }, ticks: { font: { size: 10 } } }, y: { beginAtZero: true, grid: { color: '#F0F0F0' }, ticks: { font: { size: 10 }, stepSize: 1 } } } }
    });
});

function toggleHeatmap(type) {
    document.getElementById('btnInherent').className = type === 'inherent' ? 'px-4 py-1.5 text-xs font-medium rounded-md bg-[#1A365D] text-white' : 'px-4 py-1.5 text-xs font-medium rounded-md text-gray-600 hover:bg-gray-100';
    document.getElementById('btnResidual').className = type === 'residual' ? 'px-4 py-1.5 text-xs font-medium rounded-md bg-[#1A365D] text-white' : 'px-4 py-1.5 text-xs font-medium rounded-md text-gray-600 hover:bg-gray-100';
    // In production, this would reload data via AJAX
}

function showCellRisks(likelihood, impact) {
    const panel = document.getElementById('cellDetails');
    panel.innerHTML = '<h3 class="text-sm font-semibold text-[#1A365D] mb-4">Cell: L=' + likelihood + ', I=' + impact + ' (Score: ' + (likelihood*impact) + ')</h3><div class="text-xs text-gray-500">Loading risks...</div>';
    // In production, this would load cell risks via AJAX
}
</script>
@endpush
