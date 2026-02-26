@extends('layouts.app')

@section('title', 'ICAAP Assessment - GRC Risk Management')
@section('page-section', 'Quantification')
@section('page-title', 'ICAAP')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.quantification.dashboard') }}" class="hover:text-[#1A365D]">Quantification</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">ICAAP Assessment</span>
@endsection

@section('content')
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">Internal Capital Adequacy Assessment Process (ICAAP)</h1>
            <p class="text-sm text-gray-500 mt-1">Capital position, Pillar 1 & 2 requirements, and stress testing results</p>
        </div>
        <div class="flex gap-2">
            <button onclick="window.print()" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2"><span class="material-symbols-outlined text-lg">download</span> Export Report</button>
            <button onclick="window.print()" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2"><span class="material-symbols-outlined text-lg">print</span> Print</button>
        </div>
    </div>

    {{-- Capital Position Overview --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <x-kpi-card title="Total Capital" :value="'₦' . number_format($totalCapital ?? 0)" icon="account_balance" color="primary" />
        <x-kpi-card title="Capital Adequacy Ratio" :value="($capitalAdequacyRatio ?? 0) . '%'" icon="shield" :color="($capitalAdequacyRatio ?? 0) >= 15 ? 'success' : (($capitalAdequacyRatio ?? 0) >= 10 ? 'warning' : 'danger')" subtitle="CBN Min: 10%" />
        <x-kpi-card title="Tier 1 Capital" :value="'₦' . number_format($tier1Capital ?? 0)" icon="verified" color="success" />
        <x-kpi-card title="Tier 2 Capital" :value="'₦' . number_format($tier2Capital ?? 0)" icon="workspace_premium" color="info" />
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        {{-- Pillar 1 Requirements --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Pillar 1 Capital Requirements</h3>
            @php
                $pillar1Items = [
                    ['Credit Risk', $pillar1Credit ?? 0, 'credit_card'],
                    ['Market Risk', $pillar1Market ?? 0, 'trending_up'],
                    ['Operational Risk', $pillar1Operational ?? 0, 'settings'],
                ];
            @endphp
            <div class="space-y-4">
                @foreach ($pillar1Items as [$label, $amount, $icon])
                    <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined text-gray-400">{{ $icon }}</span>
                            <span class="text-sm font-medium">{{ $label }}</span>
                        </div>
                        <span class="text-sm font-bold text-[#1A365D]">₦{{ number_format($amount) }}</span>
                    </div>
                @endforeach
                <div class="flex items-center justify-between p-3 bg-blue-50 rounded-lg border border-blue-200">
                    <span class="text-sm font-semibold text-[#1A365D]">Total Pillar 1</span>
                    <span class="text-lg font-bold text-[#1A365D]">₦{{ number_format($totalPillar1 ?? 0) }}</span>
                </div>
            </div>
        </div>

        {{-- Pillar 2 Requirements --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Pillar 2 Capital Requirements</h3>
            @php
                $pillar2Items = [
                    ['Concentration Risk', $pillar2Concentration ?? 0],
                    ['Interest Rate Risk (Banking Book)', $pillar2InterestRate ?? 0],
                    ['Liquidity Risk', $pillar2Liquidity ?? 0],
                    ['Reputational Risk', $pillar2Reputational ?? 0],
                    ['Strategic Risk', $pillar2Strategic ?? 0],
                ];
            @endphp
            <div class="space-y-4">
                @foreach ($pillar2Items as [$label, $amount])
                    <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                        <span class="text-sm font-medium">{{ $label }}</span>
                        <span class="text-sm font-bold text-[#1A365D]">₦{{ number_format($amount) }}</span>
                    </div>
                @endforeach
                <div class="flex items-center justify-between p-3 bg-blue-50 rounded-lg border border-blue-200">
                    <span class="text-sm font-semibold text-[#1A365D]">Total Pillar 2</span>
                    <span class="text-lg font-bold text-[#1A365D]">₦{{ number_format($totalPillar2 ?? 0) }}</span>
                </div>
            </div>
        </div>
    </div>

    {{-- Capital Waterfall Chart --}}
    <div class="bg-white rounded-xl border border-gray-200 p-5 mb-6">
        <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Capital Waterfall</h3>
        <canvas id="waterfallChart" height="150"></canvas>
    </div>

    {{-- Stress Testing --}}
    <div class="bg-white rounded-xl border border-gray-200 p-6">
        <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Stress Testing Results</h3>
        <table class="data-table">
            <thead><tr><th>Stress Scenario</th><th>Impact on Capital</th><th>CAR After Stress</th><th>Breach?</th><th>Capital Shortfall</th></tr></thead>
            <tbody>
                @forelse (($stressResults ?? []) as $stress)
                    <tr>
                        <td class="font-medium">{{ $stress->scenario ?? '-' }}</td>
                        <td class="text-xs text-red-600 font-medium">-₦{{ number_format($stress->capital_impact ?? 0) }}</td>
                        <td class="text-xs font-bold {{ ($stress->car_after ?? 0) >= 10 ? 'text-green-600' : 'text-red-600' }}">{{ number_format($stress->car_after ?? 0, 1) }}%</td>
                        <td>
                            @if (($stress->car_after ?? 0) < 10)
                                <span class="badge bg-red-100 text-red-700">Yes</span>
                            @else
                                <span class="badge bg-green-100 text-green-700">No</span>
                            @endif
                        </td>
                        <td class="text-xs">{{ ($stress->shortfall ?? 0) > 0 ? '₦' . number_format($stress->shortfall) : '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center py-8 text-gray-400">No stress tests run yet</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection

@php
    $chartWaterfallData = $waterfallData ?? ['labels' => ['Total Capital', 'Pillar 1', 'Pillar 2', 'Buffer', 'Available Capital'], 'values' => [0,0,0,0,0]];
@endphp

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const wfData = @json($chartWaterfallData);
    new Chart(document.getElementById('waterfallChart'), {
        type: 'bar',
        data: { labels: wfData.labels, datasets: [{ data: wfData.values, backgroundColor: ['#1A365D','#C53030','#DD6B20','#D4AF37','#2D7D46'], borderRadius: 4, barThickness: 50 }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => '₦' + ctx.parsed.y.toLocaleString() } } }, scales: { x: { grid: { display: false }, ticks: { font: { size: 10 } } }, y: { grid: { color: '#F0F0F0' }, ticks: { font: { size: 10 }, callback: v => '₦' + (v/1000000000).toFixed(0) + 'B' } } } }
    });
});
</script>
@endpush
