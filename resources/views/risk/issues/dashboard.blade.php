@extends('layouts.app')

@section('title', 'Issues Dashboard - GRC Platform')

@section('breadcrumbs')
    <span>Risk Management</span>
    <span class="text-gray-300">/</span>
    <span>Issues & Findings</span>
    <span class="text-gray-300">/</span>
    <span class="text-[#1A365D] font-semibold">Dashboard</span>
@endsection

@section('content')
    {{-- Page Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">Issues & Findings Dashboard</h1>
            <p class="text-sm text-gray-500 mt-1">Track audit findings, regulatory issues, and remediation progress</p>
        </div>
        <div class="flex items-center gap-3">
            <select class="text-xs border border-gray-200 rounded-lg px-3 py-2 bg-white text-gray-600 focus:ring-1 focus:ring-[#1A365D] focus:border-[#1A365D]">
                <option>Last 30 Days</option>
                <option>Last 90 Days</option>
                <option>Year to Date</option>
                <option>Last 12 Months</option>
            </select>
            <a href="{{ url('/risk/issues/create') }}" class="flex items-center gap-2 px-4 py-2 bg-[#1A365D] text-white rounded-lg text-xs font-semibold hover:bg-[#2D4A7A] transition">
                <span class="material-symbols-outlined text-sm">add</span>
                Log New Issue
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
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <x-kpi-card
            title="Open Issues"
            :value="$openIssues ?? 0"
            icon="bug_report"
            color="primary"
            :change="$openIssuesChange ?? null"
            :changeDirection="$openIssuesDirection ?? null"
        />
        <x-kpi-card
            title="Overdue"
            :value="$overdueIssues ?? 0"
            icon="schedule"
            color="danger"
            :subtitle="($overdueIssues ?? 0) > 0 ? 'Requires immediate attention' : 'All on track'"
        />
        <x-kpi-card
            title="CBN Examination Findings"
            :value="$cbnFindings ?? 0"
            icon="account_balance"
            color="warning"
            subtitle="Active regulatory findings"
        />
        <x-kpi-card
            title="Avg Days to Close"
            :value="($avgDaysToClose ?? 0) . ' days'"
            icon="timer"
            color="info"
            :change="$avgDaysChange ?? null"
            :changeDirection="$avgDaysDirection ?? null"
        />
    </div>

    {{-- Charts Row --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        {{-- Issues by Priority --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold text-[#1A365D]">Issues by Priority</h3>
                <span class="material-symbols-outlined text-gray-400 text-lg">donut_large</span>
            </div>
            <canvas id="priorityChart" height="220"></canvas>
        </div>

        {{-- Issues by Source --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold text-[#1A365D]">Issues by Source</h3>
                <span class="material-symbols-outlined text-gray-400 text-lg">bar_chart</span>
            </div>
            <canvas id="sourceChart" height="220"></canvas>
        </div>

        {{-- Ageing Distribution --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold text-[#1A365D]">Ageing Distribution</h3>
                <span class="material-symbols-outlined text-gray-400 text-lg">hourglass_empty</span>
            </div>
            <canvas id="ageingChart" height="220"></canvas>
        </div>
    </div>

    {{-- Overdue Issues List --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
            <h3 class="text-sm font-semibold text-[#1A365D] flex items-center gap-2">
                <span class="material-symbols-outlined text-red-400 text-lg">warning</span>
                Overdue Issues
            </h3>
            <a href="{{ url('/risk/issues?status=overdue') }}" class="text-xs text-[#1A365D] font-medium hover:underline">View All</a>
        </div>
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Title</th>
                        <th>Priority</th>
                        <th>Owner</th>
                        <th>Due Date</th>
                        <th>Days Overdue</th>
                        <th>Escalation</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (($overdueIssuesList ?? []) as $issue)
                        <tr class="bg-red-50/30">
                            <td>
                                <a href="{{ url('/risk/issues/' . $issue->id) }}" class="text-[#1A365D] font-semibold hover:underline text-xs">
                                    {{ $issue->reference }}
                                </a>
                            </td>
                            <td class="max-w-[200px]">
                                <div class="truncate text-sm font-medium text-gray-800">{{ $issue->title }}</div>
                            </td>
                            <td><x-risk-badge :rating="$issue->priority ?? 'medium'" /></td>
                            <td class="text-xs text-gray-600">{{ $issue->owner->name ?? '-' }}</td>
                            <td class="text-xs text-red-600 font-medium">{{ $issue->due_date?->format('d M Y') ?? '-' }}</td>
                            <td>
                                @php
                                    $daysOverdue = $issue->due_date ? $issue->due_date->diffInDays(now()) : 0;
                                @endphp
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold
                                    {{ $daysOverdue > 60 ? 'bg-red-100 text-red-700' : ($daysOverdue > 30 ? 'bg-orange-100 text-orange-700' : 'bg-yellow-100 text-yellow-700') }}">
                                    {{ $daysOverdue }}d
                                </span>
                            </td>
                            <td>
                                @php
                                    $escLevel = $issue->escalation_level ?? 0;
                                    $escColors = ['bg-gray-100 text-gray-600', 'bg-yellow-100 text-yellow-700', 'bg-orange-100 text-orange-700', 'bg-red-100 text-red-700'];
                                @endphp
                                <span class="badge {{ $escColors[$escLevel] ?? $escColors[0] }}">
                                    Level {{ $escLevel }}
                                </span>
                            </td>
                            <td>
                                <a href="{{ url('/risk/issues/' . $issue->id) }}" class="p-1 rounded hover:bg-gray-100" title="View">
                                    <span class="material-symbols-outlined text-gray-500 text-lg">visibility</span>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center py-8 text-gray-400">
                                <span class="material-symbols-outlined text-3xl mb-2 block">check_circle</span>
                                No overdue issues -- all issues are on track
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection

@php
    $priorityDataChart = $priorityData ?? ['labels' => ['Critical', 'High', 'Medium', 'Low'], 'values' => [0, 0, 0, 0]];
    $sourceDataChart = $sourceData ?? ['labels' => ['Internal Audit', 'External Audit', 'CBN Examination', 'Self-Identified', 'Regulatory Review', 'Customer Complaint'], 'values' => [0, 0, 0, 0, 0, 0]];
    $ageingDataChart = $ageingData ?? ['labels' => ['0-30 days', '31-60 days', '61-90 days', '90+ days'], 'values' => [0, 0, 0, 0]];
@endphp

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Issues by Priority (Doughnut)
    const priorityData = @json($priorityDataChart);
    new Chart(document.getElementById('priorityChart'), {
        type: 'doughnut',
        data: {
            labels: priorityData.labels,
            datasets: [{
                data: priorityData.values,
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
                legend: { position: 'bottom', labels: { font: { size: 10 }, usePointStyle: true, padding: 12 } }
            }
        }
    });

    // Issues by Source (Bar)
    const sourceData = @json($sourceDataChart);
    new Chart(document.getElementById('sourceChart'), {
        type: 'bar',
        data: {
            labels: sourceData.labels,
            datasets: [{
                label: 'Issues',
                data: sourceData.values,
                backgroundColor: ['#1A365D', '#2D7D46', '#C53030', '#D4AF37', '#553C9A', '#DD6B20'],
                borderRadius: 6,
                barThickness: 20,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: 'y',
            plugins: { legend: { display: false } },
            scales: {
                x: { beginAtZero: true, grid: { color: '#F0F0F0' }, ticks: { font: { size: 10 }, stepSize: 1 } },
                y: { grid: { display: false }, ticks: { font: { size: 10 } } }
            }
        }
    });

    // Ageing Distribution (Bar)
    const ageingData = @json($ageingDataChart);
    new Chart(document.getElementById('ageingChart'), {
        type: 'bar',
        data: {
            labels: ageingData.labels,
            datasets: [{
                label: 'Issues',
                data: ageingData.values,
                backgroundColor: ['#2D7D46', '#D4AF37', '#DD6B20', '#C53030'],
                borderRadius: 6,
                barThickness: 32,
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
});
</script>
@endpush
