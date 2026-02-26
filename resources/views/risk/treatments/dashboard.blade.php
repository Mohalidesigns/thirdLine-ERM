@extends('layouts.app')

@section('title', 'Treatment Plans Dashboard - GRC Risk Management')
@section('page-section', 'Treatment Plans')
@section('page-title', 'Dashboard')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.treatments.index') }}" class="hover:text-[#1A365D]">Treatment Plans</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Dashboard</span>
@endsection

@section('content')
    {{-- Page Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">Treatment Plans Dashboard</h1>
            <p class="text-sm text-gray-500 mt-1">Monitor treatment plan progress, effectiveness, and resource allocation</p>
        </div>
        <div class="flex items-center gap-3">
            <select id="periodFilter" class="text-xs border border-gray-200 rounded-lg px-3 py-2 bg-white text-gray-600 focus:ring-1 focus:ring-[#1A365D]">
                <option value="30">Last 30 Days</option>
                <option value="90">Last 90 Days</option>
                <option value="ytd" selected>Year to Date</option>
                <option value="365">Last 12 Months</option>
            </select>
            <a href="{{ route('risk.treatments.create') }}" class="flex items-center gap-2 px-4 py-2 bg-[#1A365D] text-white rounded-lg text-xs font-semibold hover:bg-[#2D4A7A] transition">
                <span class="material-symbols-outlined text-sm">add</span>
                New Plan
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

    {{-- KPI Cards --}}
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
        <x-kpi-card
            title="Total Plans"
            :value="$totalPlans ?? 0"
            icon="assignment"
            color="primary"
            :change="$totalPlansChange ?? null"
            :changeDirection="$totalPlansDirection ?? null"
        />
        <x-kpi-card
            title="Active Plans"
            :value="$activePlans ?? 0"
            icon="play_circle"
            color="info"
            subtitle="Currently in progress"
        />
        <x-kpi-card
            title="Completed"
            :value="$completedPlans ?? 0"
            icon="check_circle"
            color="success"
            :change="$completedChange ?? null"
            :changeDirection="$completedDirection ?? null"
        />
        <x-kpi-card
            title="Overdue"
            :value="$overduePlans ?? 0"
            icon="schedule"
            color="danger"
            subtitle="Past target date"
        />
        <x-kpi-card
            title="Total Budget"
            :value="'₦' . number_format($totalBudget ?? 0)"
            icon="account_balance"
            color="warning"
            subtitle="Allocated resources"
        />
        <x-kpi-card
            title="Avg. Effectiveness"
            :value="($avgEffectiveness ?? 0) . '%'"
            icon="speed"
            color="success"
            :change="$effectivenessChange ?? null"
            :changeDirection="$effectivenessDirection ?? null"
        />
    </div>

    {{-- Charts Row --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        {{-- Treatment Progress by Strategy --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold text-[#1A365D]">Plans by Strategy</h3>
                <span class="material-symbols-outlined text-gray-400 text-lg">donut_large</span>
            </div>
            <canvas id="strategyChart" height="220"></canvas>
        </div>

        {{-- Monthly Completion Trend --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold text-[#1A365D]">Monthly Completion Trend</h3>
                <span class="material-symbols-outlined text-gray-400 text-lg">show_chart</span>
            </div>
            <canvas id="completionTrendChart" height="220"></canvas>
        </div>

        {{-- Plans by Status --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold text-[#1A365D]">Plans by Status</h3>
                <span class="material-symbols-outlined text-gray-400 text-lg">bar_chart</span>
            </div>
            <canvas id="statusChart" height="220"></canvas>
        </div>
    </div>

    {{-- Bottom Row: Progress Overview + Recent Activity --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Treatment Progress Overview --}}
        <div class="lg:col-span-2 bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                <h3 class="text-sm font-semibold text-[#1A365D]">Active Treatment Plans Progress</h3>
                <a href="{{ route('risk.treatments.index') }}" class="text-xs text-[#1A365D] font-medium hover:underline">View All</a>
            </div>
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Plan</th>
                            <th>Linked Risk</th>
                            <th>Strategy</th>
                            <th>Progress</th>
                            <th>Owner</th>
                            <th>Target Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse (($activeTreatments ?? []) as $plan)
                            <tr>
                                <td>
                                    <a href="{{ route('risk.treatments.show', $plan->id) }}" class="text-[#1A365D] font-medium hover:underline">
                                        {{ $plan->title }}
                                    </a>
                                </td>
                                <td class="text-xs text-gray-600">{{ $plan->risk->risk_code ?? '-' }}</td>
                                <td>
                                    <span class="badge {{ $plan->strategy === 'mitigate' ? 'bg-blue-100 text-blue-700' : ($plan->strategy === 'transfer' ? 'bg-purple-100 text-purple-700' : ($plan->strategy === 'avoid' ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700')) }}">
                                        {{ ucfirst($plan->strategy ?? 'N/A') }}
                                    </span>
                                </td>
                                <td>
                                    <div class="flex items-center gap-2">
                                        <div class="w-20 bg-gray-200 rounded-full h-2">
                                            <div class="h-2 rounded-full {{ ($plan->progress ?? 0) >= 75 ? 'bg-green-500' : (($plan->progress ?? 0) >= 50 ? 'bg-yellow-500' : 'bg-red-500') }}"
                                                 style="width: {{ $plan->progress ?? 0 }}%"></div>
                                        </div>
                                        <span class="text-xs text-gray-600">{{ $plan->progress ?? 0 }}%</span>
                                    </div>
                                </td>
                                <td class="text-xs">{{ $plan->owner->name ?? '-' }}</td>
                                <td class="text-xs text-gray-500">{{ $plan->target_date?->format('d M Y') ?? '-' }}</td>
                                <td><x-status-badge :status="$plan->status ?? 'in progress'" type="treatment" /></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center py-8 text-gray-400">
                                    <span class="material-symbols-outlined text-3xl mb-2 block">assignment</span>
                                    No active treatment plans found
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Recent Activity --}}
        <div class="bg-white rounded-xl border border-gray-200">
            <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                <h3 class="text-sm font-semibold text-[#1A365D]">Recent Activity</h3>
                <span class="material-symbols-outlined text-gray-400 text-lg">history</span>
            </div>
            <div class="p-4 space-y-3 max-h-[400px] overflow-y-auto">
                @forelse (($recentActivities ?? []) as $activity)
                    <div class="flex items-start gap-3 p-3 rounded-lg bg-gray-50">
                        <div class="w-8 h-8 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center flex-shrink-0">
                            <span class="material-symbols-outlined text-sm">{{ $activity->icon ?? 'update' }}</span>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-xs text-gray-700 font-medium">{{ $activity->description }}</p>
                            <p class="text-[10px] text-gray-500 mt-1">{{ $activity->user->name ?? 'System' }} &middot; {{ $activity->created_at?->diffForHumans() }}</p>
                        </div>
                    </div>
                @empty
                    <div class="text-center py-6 text-gray-400 text-sm">
                        <span class="material-symbols-outlined text-2xl mb-1 block">history</span>
                        No recent activity
                    </div>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Budget vs Spend --}}
    <div class="mt-6 bg-white rounded-xl border border-gray-200 p-5">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-semibold text-[#1A365D]">Budget vs Actual Spend by Strategy</h3>
            <span class="material-symbols-outlined text-gray-400 text-lg">account_balance_wallet</span>
        </div>
        <canvas id="budgetChart" height="100"></canvas>
    </div>
@endsection

@php
    $strategyChartDataChart = $strategyChartData ?? ['labels' => ['Mitigate', 'Transfer', 'Accept', 'Avoid'], 'values' => [0, 0, 0, 0]];
    $completionTrendDataChart = $completionTrendData ?? ['labels' => ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'], 'completed' => [0,0,0,0,0,0,0,0,0,0,0,0], 'created' => [0,0,0,0,0,0,0,0,0,0,0,0]];
    $statusChartDataChart = $statusChartData ?? ['labels' => ['Not Started', 'In Progress', 'Completed', 'Overdue', 'On Hold'], 'values' => [0, 0, 0, 0, 0]];
    $budgetChartDataChart = $budgetChartData ?? ['labels' => ['Mitigate', 'Transfer', 'Accept', 'Avoid'], 'budget' => [0,0,0,0], 'actual' => [0,0,0,0]];
@endphp

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Strategy Doughnut Chart
    const strategyData = @json($strategyChartDataChart);
    new Chart(document.getElementById('strategyChart'), {
        type: 'doughnut',
        data: {
            labels: strategyData.labels,
            datasets: [{
                data: strategyData.values,
                backgroundColor: ['#1A365D', '#553C9A', '#2D7D46', '#C53030'],
                borderWidth: 0,
                spacing: 2,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '65%',
            plugins: {
                legend: { position: 'bottom', labels: { font: { size: 10 }, usePointStyle: true, padding: 12 } }
            }
        }
    });

    // Completion Trend Line Chart
    const trendData = @json($completionTrendDataChart);
    new Chart(document.getElementById('completionTrendChart'), {
        type: 'line',
        data: {
            labels: trendData.labels,
            datasets: [
                {
                    label: 'Created',
                    data: trendData.created,
                    borderColor: '#1A365D',
                    backgroundColor: 'rgba(26, 54, 93, 0.1)',
                    tension: 0.3,
                    fill: true,
                },
                {
                    label: 'Completed',
                    data: trendData.completed,
                    borderColor: '#2D7D46',
                    backgroundColor: 'rgba(45, 125, 70, 0.1)',
                    tension: 0.3,
                    fill: true,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom', labels: { font: { size: 10 }, usePointStyle: true } } },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 10 } } },
                y: { beginAtZero: true, grid: { color: '#F0F0F0' }, ticks: { font: { size: 10 }, stepSize: 1 } }
            }
        }
    });

    // Status Bar Chart
    const statusData = @json($statusChartDataChart);
    new Chart(document.getElementById('statusChart'), {
        type: 'bar',
        data: {
            labels: statusData.labels,
            datasets: [{
                data: statusData.values,
                backgroundColor: ['#6B7280', '#3B82F6', '#2D7D46', '#C53030', '#D4AF37'],
                borderRadius: 6,
                barThickness: 28,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 10 } } },
                y: { beginAtZero: true, grid: { color: '#F0F0F0' }, ticks: { font: { size: 10 }, stepSize: 1 } }
            }
        }
    });

    // Budget vs Spend Bar Chart
    const budgetData = @json($budgetChartDataChart);
    new Chart(document.getElementById('budgetChart'), {
        type: 'bar',
        data: {
            labels: budgetData.labels,
            datasets: [
                { label: 'Budget (₦)', data: budgetData.budget, backgroundColor: '#1A365D', borderRadius: 4 },
                { label: 'Actual Spend (₦)', data: budgetData.actual, backgroundColor: '#D4AF37', borderRadius: 4 }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { font: { size: 10 }, usePointStyle: true } },
                tooltip: { callbacks: { label: ctx => ctx.dataset.label + ': ₦' + ctx.parsed.y.toLocaleString() } }
            },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 10 } } },
                y: { beginAtZero: true, grid: { color: '#F0F0F0' }, ticks: { font: { size: 10 }, callback: v => '₦' + (v/1000000).toFixed(0) + 'M' } }
            }
        }
    });
});
</script>
@endpush
