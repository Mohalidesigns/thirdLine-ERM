@extends('layouts.app')

@section('title', 'Loss Events Dashboard - GRC Platform')

@section('breadcrumbs')
    <span>Risk Management</span>
    <span class="text-gray-300">/</span>
    <span>Loss Events</span>
    <span class="text-gray-300">/</span>
    <span class="text-[#1A365D] font-semibold">Dashboard</span>
@endsection

@section('content')
    {{-- Page Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">Loss Event Dashboard</h1>
            <p class="text-sm text-gray-500 mt-1">Monitor operational loss events, trends, and regulatory reporting status</p>
        </div>
        <div class="flex items-center gap-3">
            <select class="text-xs border border-gray-200 rounded-lg px-3 py-2 bg-white text-gray-600 focus:ring-1 focus:ring-[#1A365D] focus:border-[#1A365D]">
                <option>Last 30 Days</option>
                <option>Last 90 Days</option>
                <option>Year to Date</option>
                <option>Last 12 Months</option>
            </select>
            <a href="{{ url('/risk/loss-events/create') }}" class="flex items-center gap-2 px-4 py-2 bg-[#1A365D] text-white rounded-lg text-xs font-semibold hover:bg-[#2D4A7A] transition">
                <span class="material-symbols-outlined text-sm">add</span>
                Log New Event
            </a>
        </div>
    </div>

    {{-- Flash Messages --}}
    @if (session('success'))
        <div class="mb-4 px-4 py-3 bg-green-50 border border-green-200 text-green-700 rounded-lg text-sm flex items-center gap-2">
            <span class="material-symbols-outlined text-lg">check_circle</span>
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="mb-4 px-4 py-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm flex items-center gap-2">
            <span class="material-symbols-outlined text-lg">error</span>
            {{ session('error') }}
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
        <x-kpi-card
            title="Total Events"
            :value="$totalEvents ?? 0"
            icon="report_problem"
            color="primary"
            :change="$totalEventsChange ?? null"
            :changeDirection="$totalEventsDirection ?? null"
        />
        <x-kpi-card
            title="Total Gross Loss"
            :value="$compactNaira($totalGrossLoss ?? 0)"
            icon="payments"
            color="danger"
            :change="$grossLossChange ?? null"
            :changeDirection="$grossLossDirection ?? null"
        />
        <x-kpi-card
            title="Pending CBN Notifications"
            :value="$pendingCbnNotifications ?? 0"
            icon="gavel"
            color="warning"
            subtitle="Regulatory deadline"
        />
        <x-kpi-card
            title="Pending NFIU Filings"
            :value="$pendingNfiuFilings ?? 0"
            icon="description"
            color="warning"
            subtitle="Requires filing"
        />
        <x-kpi-card
            title="Open Investigations"
            :value="$openInvestigations ?? 0"
            icon="search"
            color="info"
        />
        <x-kpi-card
            title="Near Misses"
            :value="$nearMisses ?? 0"
            icon="warning"
            color="success"
            :change="$nearMissChange ?? null"
            :changeDirection="$nearMissDirection ?? null"
        />
    </div>

    {{-- Charts Row --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        {{-- Loss Events by Basel L1 Category --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold text-[#1A365D]">Events by Basel L1 Category</h3>
                <span class="material-symbols-outlined text-gray-400 text-lg">bar_chart</span>
            </div>
            <canvas id="baselCategoryChart" height="220"></canvas>
        </div>

        {{-- Monthly Loss Trend --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold text-[#1A365D]">Monthly Loss Trend</h3>
                <span class="material-symbols-outlined text-gray-400 text-lg">show_chart</span>
            </div>
            <canvas id="monthlyTrendChart" height="220"></canvas>
        </div>

        {{-- Events by Severity --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold text-[#1A365D]">Events by Severity</h3>
                <span class="material-symbols-outlined text-gray-400 text-lg">donut_large</span>
            </div>
            <canvas id="severityChart" height="220"></canvas>
        </div>
    </div>

    {{-- Bottom Row: Recent Events + Regulatory Alerts --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Recent Loss Events --}}
        <div class="lg:col-span-2 bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                <h3 class="text-sm font-semibold text-[#1A365D]">Recent Loss Events</h3>
                <a href="{{ url('/risk/loss-events') }}" class="text-xs text-[#1A365D] font-medium hover:underline">View All</a>
            </div>
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Title</th>
                            <th>Date</th>
                            <th>Gross Loss</th>
                            <th>Severity</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse (($recentEvents ?? []) as $event)
                            <tr>
                                <td>
                                    <a href="{{ url('/risk/loss-events/' . $event->id) }}" class="text-[#1A365D] font-medium hover:underline">
                                        {{ $event->reference }}
                                    </a>
                                </td>
                                <td class="max-w-[200px] truncate">{{ $event->title }}</td>
                                <td class="text-gray-500">{{ $event->date_of_loss?->format('d M Y') ?? '-' }}</td>
                                <td class="font-medium">₦{{ number_format($event->gross_loss_amount ?? 0, 2) }}</td>
                                <td><x-risk-badge :rating="$event->severity ?? 'low'" /></td>
                                <td><x-status-badge :status="$event->status ?? 'draft'" /></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center py-8 text-gray-400">
                                    <span class="material-symbols-outlined text-3xl mb-2 block">inventory_2</span>
                                    No recent loss events found
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Regulatory Alerts --}}
        <div class="bg-white rounded-xl border border-gray-200">
            <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                <h3 class="text-sm font-semibold text-[#1A365D]">Regulatory Alerts</h3>
                <span class="material-symbols-outlined text-red-400 text-lg">notifications_active</span>
            </div>
            <div class="p-4 space-y-3">
                @forelse (($regulatoryAlerts ?? []) as $alert)
                    <div class="flex items-start gap-3 p-3 rounded-lg border {{ $alert->is_overdue ? 'border-red-200 bg-red-50' : 'border-yellow-200 bg-yellow-50' }}">
                        <span class="material-symbols-outlined text-lg {{ $alert->is_overdue ? 'text-red-500' : 'text-yellow-500' }}">
                            {{ $alert->is_overdue ? 'error' : 'schedule' }}
                        </span>
                        <div class="flex-1 min-w-0">
                            <div class="text-xs font-semibold {{ $alert->is_overdue ? 'text-red-700' : 'text-yellow-700' }}">
                                {{ $alert->type }}
                            </div>
                            <div class="text-xs text-gray-600 mt-0.5 truncate">{{ $alert->event_reference }}</div>
                            <div class="text-[10px] text-gray-500 mt-1">
                                Deadline: {{ $alert->deadline?->format('d M Y') ?? 'N/A' }}
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="text-center py-6 text-gray-400 text-sm">
                        <span class="material-symbols-outlined text-2xl mb-1 block">verified</span>
                        No pending regulatory alerts
                    </div>
                @endforelse
            </div>
        </div>
    </div>
@endsection

@php
    $baselChartDefaults = $baselCategoryData ?? [
        'labels' => ['Internal Fraud', 'External Fraud', 'Employment Practices', 'Clients & Products', 'Damage to Assets', 'System Failures', 'Execution & Delivery'],
        'values' => [0, 0, 0, 0, 0, 0, 0],
    ];
    $trendChartDefaults = $monthlyTrendData ?? [
        'labels' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
        'events' => [0,0,0,0,0,0,0,0,0,0,0,0],
        'losses' => [0,0,0,0,0,0,0,0,0,0,0,0],
    ];
    $severityChartDefaults = $severityData ?? [
        'labels' => ['Critical', 'High', 'Medium', 'Low'],
        'values' => [0, 0, 0, 0],
    ];
@endphp

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Basel L1 Category Bar Chart
    const baselData = @json($baselChartDefaults);
    new Chart(document.getElementById('baselCategoryChart'), {
        type: 'bar',
        data: {
            labels: baselData.labels,
            datasets: [{
                label: 'Loss Events',
                data: baselData.values,
                backgroundColor: [
                    '#C53030', '#DD6B20', '#B7791F', '#2D7D46', '#1A365D', '#553C9A', '#6B7280'
                ],
                borderRadius: 6,
                barThickness: 24,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(ctx) {
                            return ctx.parsed.y + ' events';
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { font: { size: 10 }, maxRotation: 45 }
                },
                y: {
                    beginAtZero: true,
                    grid: { color: '#F0F0F0' },
                    ticks: { font: { size: 10 }, stepSize: 1 }
                }
            }
        }
    });

    // Monthly Loss Trend Line Chart
    const trendData = @json($trendChartDefaults);
    new Chart(document.getElementById('monthlyTrendChart'), {
        type: 'line',
        data: {
            labels: trendData.labels,
            datasets: [
                {
                    label: 'Event Count',
                    data: trendData.events,
                    borderColor: '#1A365D',
                    backgroundColor: 'rgba(26, 54, 93, 0.1)',
                    tension: 0.3,
                    fill: true,
                    yAxisID: 'y',
                },
                {
                    label: 'Gross Loss (₦M)',
                    data: trendData.losses,
                    borderColor: '#C53030',
                    backgroundColor: 'transparent',
                    borderDash: [5, 5],
                    tension: 0.3,
                    fill: false,
                    yAxisID: 'y1',
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom', labels: { font: { size: 10 }, usePointStyle: true } }
            },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 10 } } },
                y: {
                    beginAtZero: true,
                    position: 'left',
                    grid: { color: '#F0F0F0' },
                    ticks: { font: { size: 10 } },
                    title: { display: true, text: 'Count', font: { size: 10 } }
                },
                y1: {
                    beginAtZero: true,
                    position: 'right',
                    grid: { drawOnChartArea: false },
                    ticks: { font: { size: 10 } },
                    title: { display: true, text: 'Loss (₦M)', font: { size: 10 } }
                }
            }
        }
    });

    // Severity Doughnut Chart
    const severityData = @json($severityChartDefaults);
    new Chart(document.getElementById('severityChart'), {
        type: 'doughnut',
        data: {
            labels: severityData.labels,
            datasets: [{
                data: severityData.values,
                backgroundColor: ['#C53030', '#DD6B20', '#D4AF37', '#2D7D46'],
                borderWidth: 0,
                spacing: 2,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '65%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { font: { size: 10 }, usePointStyle: true, padding: 12 }
                }
            }
        }
    });
});
</script>
@endpush
