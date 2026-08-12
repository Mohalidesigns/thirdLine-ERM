@extends('layouts.app')

@section('title', 'Risk Quantification Dashboard - GRC Risk Management')
@section('page-section', 'Quantification')
@section('page-title', 'Dashboard')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.quantification.dashboard') }}" class="hover:text-[#1A365D]">Quantification</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Dashboard</span>
@endsection

@section('content')
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">Risk Quantification Engine</h1>
            <p class="text-sm text-gray-500 mt-1">Monte Carlo simulations, capital adequacy, and ICAAP assessments</p>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ route('risk.quantification.simulate') }}" class="flex items-center gap-2 px-4 py-2 bg-[#1A365D] text-white rounded-lg text-xs font-semibold hover:bg-[#2D4A7A] transition">
                <span class="material-symbols-outlined text-sm">play_arrow</span> New Simulation
            </a>
            <a href="{{ route('risk.quantification.icaap') }}" class="flex items-center gap-2 px-4 py-2 border border-gray-300 rounded-lg text-xs font-medium text-gray-700 hover:bg-gray-50 transition">
                <span class="material-symbols-outlined text-sm">assessment</span> ICAAP
            </a>
        </div>
    </div>

    @if (session('success'))
        <div class="mb-4 px-4 py-3 bg-green-50 border border-green-200 text-green-700 rounded-lg text-sm flex items-center gap-2">
            <span class="material-symbols-outlined text-lg">check_circle</span>{{ session('success') }}
        </div>
    @endif

    {{-- KPI Cards --}}
    @php
        // Compact Naira so large amounts fit inside narrow KPI cards.
        $compactNaira = function ($amount) {
            $a = abs((float) $amount);
            if ($a >= 1e9) return '₦' . number_format($amount / 1e9, 2) . 'B';
            if ($a >= 1e6) return '₦' . number_format($amount / 1e6, 2) . 'M';
            if ($a >= 1e3) return '₦' . number_format($amount / 1e3, 1) . 'K';
            return '₦' . number_format($amount, 0);
        };
    @endphp
    <style>
        /* Compact variant so 6 KPI cards stack comfortably without overflow. */
        .kpi-strip .kpi-card { padding: 0.85rem 1rem; }
        .kpi-strip .kpi-card .text-2xl { font-size: 1.25rem; line-height: 1.75rem; }
    </style>
    <div class="kpi-strip grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
        <x-kpi-card title="Total Economic Capital" :value="$compactNaira($totalEconomicCapital ?? 0)" icon="account_balance" color="primary" subtitle="99.9% confidence" />
        <x-kpi-card title="Capital Adequacy Ratio" :value="($capitalAdequacyRatio ?? 0) . '%'" icon="shield" :color="($capitalAdequacyRatio ?? 0) >= 15 ? 'success' : (($capitalAdequacyRatio ?? 0) >= 10 ? 'warning' : 'danger')" subtitle="CBN minimum: 10%" />
        <x-kpi-card title="Active Scenarios" :value="$activeScenarios ?? 0" icon="category" color="info" />
        <x-kpi-card title="Simulations Run" :value="$simulationsRun ?? 0" icon="calculate" color="primary" />
        <x-kpi-card title="VaR (95%)" :value="$compactNaira($var95 ?? 0)" icon="trending_up" color="warning" />
        <x-kpi-card title="Expected Shortfall" :value="$compactNaira($expectedShortfall ?? 0)" icon="priority_high" color="danger" />
    </div>

    {{-- Charts Row --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold text-[#1A365D]">Capital by Risk Type</h3>
                <span class="material-symbols-outlined text-gray-400 text-lg">donut_large</span>
            </div>
            <canvas id="capitalByTypeChart" height="250"></canvas>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold text-[#1A365D]">Loss Distribution (Latest Simulation)</h3>
                <span class="material-symbols-outlined text-gray-400 text-lg">bar_chart</span>
            </div>
            <canvas id="lossDistChart" height="250"></canvas>
        </div>
    </div>

    {{-- Recent Simulations & ICAAP Status --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                <h3 class="text-sm font-semibold text-[#1A365D]">Recent Simulations</h3>
                <a href="{{ route('risk.quantification.results') }}" class="text-xs text-[#1A365D] font-medium hover:underline">View All</a>
            </div>
            <table class="data-table">
                <thead><tr><th>Date</th><th>Name</th><th>Iterations</th><th>VaR (95%)</th><th>VaR (99.5%)</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    @forelse (($recentSimulations ?? []) as $sim)
                        <tr>
                            <td class="text-xs text-gray-500">{{ $sim->created_at?->format('d M Y') ?? '-' }}</td>
                            <td class="font-medium text-[#1A365D]"><a href="{{ route('risk.quantification.show-results', $sim) }}" class="hover:underline">{{ $sim->name }}</a></td>
                            <td class="text-xs">{{ number_format($sim->iterations ?? 0) }}</td>
                            <td class="text-xs font-medium">₦{{ number_format($sim->var_95 ?? 0) }}</td>
                            <td class="text-xs font-medium">₦{{ number_format($sim->var_995 ?? 0) }}</td>
                            <td><x-status-badge :status="$sim->status ?? 'completed'" /></td>
                            <td><a href="{{ route('risk.quantification.show-results', $sim) }}" class="p-1 hover:bg-gray-100 rounded"><span class="material-symbols-outlined text-gray-400 text-lg">visibility</span></a></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center py-8 text-gray-400"><span class="material-symbols-outlined text-3xl mb-2 block">calculate</span>No simulations run yet</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- ICAAP Status --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">ICAAP Summary</h3>
            <div class="space-y-4">
                <div class="p-3 rounded-lg {{ ($capitalAdequacyRatio ?? 0) >= 15 ? 'bg-green-50 border border-green-200' : (($capitalAdequacyRatio ?? 0) >= 10 ? 'bg-yellow-50 border border-yellow-200' : 'bg-red-50 border border-red-200') }}">
                    <p class="text-xs font-medium text-gray-600">Capital Adequacy Ratio</p>
                    <p class="text-2xl font-bold {{ ($capitalAdequacyRatio ?? 0) >= 15 ? 'text-green-700' : (($capitalAdequacyRatio ?? 0) >= 10 ? 'text-yellow-700' : 'text-red-700') }}">{{ $capitalAdequacyRatio ?? 0 }}%</p>
                    <p class="text-xs text-gray-500 mt-1">CBN Minimum: 10% | Target: 15%</p>
                </div>
                <div>
                    <p class="text-xs text-gray-500 mb-1">Pillar 1 Capital</p>
                    <p class="text-sm font-semibold">₦{{ number_format($pillar1Capital ?? 0) }}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-500 mb-1">Pillar 2 Capital</p>
                    <p class="text-sm font-semibold">₦{{ number_format($pillar2Capital ?? 0) }}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-500 mb-1">Capital Buffer</p>
                    <p class="text-sm font-semibold">₦{{ number_format($capitalBuffer ?? 0) }}</p>
                </div>
                <a href="{{ route('risk.quantification.icaap') }}" class="block text-center text-xs text-[#1A365D] font-medium hover:underline mt-4">View Full ICAAP Report</a>
            </div>
        </div>
    </div>
@endsection

@php
    $chartTypeData = $capitalByTypeData ?? ['labels' => ['Credit Risk', 'Market Risk', 'Operational Risk', 'Liquidity Risk', 'Other'], 'values' => [0,0,0,0,0]];
    $chartDistData = $lossDistData ?? ['labels' => [], 'values' => []];
@endphp

@push('scripts')
<script>
window.onPageReady(function() {
    const typeData = @json($chartTypeData);
    new Chart(document.getElementById('capitalByTypeChart'), {
        type: 'doughnut',
        data: { labels: typeData.labels, datasets: [{ data: typeData.values, backgroundColor: ['#1A365D','#C53030','#D4AF37','#2D7D46','#553C9A'], borderWidth: 0, spacing: 2 }] },
        options: { responsive: true, maintainAspectRatio: false, cutout: '60%', plugins: { legend: { position: 'bottom', labels: { font: { size: 10 }, usePointStyle: true } }, tooltip: { callbacks: { label: ctx => ctx.label + ': ₦' + ctx.parsed.toLocaleString() } } } }
    });

    const distData = @json($chartDistData);
    new Chart(document.getElementById('lossDistChart'), {
        type: 'bar',
        data: { labels: distData.labels, datasets: [{ label: 'Frequency', data: distData.values, backgroundColor: '#1A365D', borderRadius: 2 }] },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { x: { grid: { display: false }, ticks: { font: { size: 9 }, maxRotation: 45 }, title: { display: true, text: 'Loss Amount (₦M)', font: { size: 10 } } }, y: { beginAtZero: true, grid: { color: '#F0F0F0' }, ticks: { font: { size: 10 } }, title: { display: true, text: 'Frequency', font: { size: 10 } } } }
        }
    });
});
</script>
@endpush
