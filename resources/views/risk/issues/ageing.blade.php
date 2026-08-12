@extends('layouts.app')

@section('title', 'Issues Ageing Report - GRC Platform')

@section('breadcrumbs')
    <span>Risk Management</span>
    <span class="text-gray-300">/</span>
    <span>Issues & Findings</span>
    <span class="text-gray-300">/</span>
    <span class="text-[#1A365D] font-semibold">Ageing Report</span>
@endsection

@section('content')
    {{-- Page Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">Issues Ageing Analysis</h1>
            <p class="text-sm text-gray-500 mt-1">Analyse issue ageing distribution by priority and time bands</p>
        </div>
        <div class="flex items-center gap-3">
            <button onclick="window.print()" class="flex items-center gap-2 px-3 py-2 border border-gray-200 rounded-lg text-xs font-medium text-gray-600 hover:bg-gray-50 transition">
                <span class="material-symbols-outlined text-sm">print</span>
                Print Report
            </button>
            <a href="{{ route('risk.export.issues-ageing') }}" class="flex items-center gap-2 px-3 py-2 border border-gray-200 rounded-lg text-xs font-medium text-gray-600 hover:bg-gray-50 transition">
                <span class="material-symbols-outlined text-sm">download</span>
                Export
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

    {{-- Charts --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        {{-- Ageing by Priority --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Ageing Distribution by Priority</h3>
            <canvas id="ageingByPriorityChart" height="260"></canvas>
        </div>

        {{-- Ageing Trend --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Ageing Trend Over Time</h3>
            <canvas id="ageingTrendChart" height="260"></canvas>
        </div>
    </div>

    {{-- Ageing Matrix Table --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden mb-6">
        <div class="px-5 py-4 border-b border-gray-100">
            <h3 class="text-sm font-semibold text-[#1A365D]">Ageing Matrix - Open Issues by Priority and Time Band</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Priority</th>
                        <th class="text-center bg-green-50">0-30 Days</th>
                        <th class="text-center bg-yellow-50">31-60 Days</th>
                        <th class="text-center bg-orange-50">61-90 Days</th>
                        <th class="text-center bg-red-50">90+ Days</th>
                        <th class="text-center font-bold">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @php
                        $priorities = ['Critical', 'High', 'Medium', 'Low'];
                        $grandTotal = [0, 0, 0, 0, 0];
                    @endphp

                    @foreach ($priorities as $priority)
                        @php
                            $key = strtolower($priority);
                            $band030 = $ageingMatrix[$key]['0-30'] ?? 0;
                            $band3160 = $ageingMatrix[$key]['31-60'] ?? 0;
                            $band6190 = $ageingMatrix[$key]['61-90'] ?? 0;
                            $band90plus = $ageingMatrix[$key]['90+'] ?? 0;
                            $rowTotal = $band030 + $band3160 + $band6190 + $band90plus;

                            $grandTotal[0] += $band030;
                            $grandTotal[1] += $band3160;
                            $grandTotal[2] += $band6190;
                            $grandTotal[3] += $band90plus;
                            $grandTotal[4] += $rowTotal;
                        @endphp
                        <tr>
                            <td><x-risk-badge :rating="$key" /></td>
                            <td class="text-center text-sm {{ $band030 > 0 ? 'font-semibold text-green-700' : 'text-gray-400' }}">
                                {{ $band030 ?: '-' }}
                            </td>
                            <td class="text-center text-sm {{ $band3160 > 0 ? 'font-semibold text-yellow-700' : 'text-gray-400' }}">
                                {{ $band3160 ?: '-' }}
                            </td>
                            <td class="text-center text-sm {{ $band6190 > 0 ? 'font-semibold text-orange-700' : 'text-gray-400' }}">
                                {{ $band6190 ?: '-' }}
                            </td>
                            <td class="text-center text-sm {{ $band90plus > 0 ? 'font-bold text-red-700' : 'text-gray-400' }}">
                                {{ $band90plus ?: '-' }}
                            </td>
                            <td class="text-center text-sm font-bold text-[#1A365D]">{{ $rowTotal ?: '-' }}</td>
                        </tr>
                    @endforeach

                    {{-- Grand Total Row --}}
                    <tr class="bg-gray-50 font-bold">
                        <td class="text-xs font-bold text-gray-700 uppercase">Total</td>
                        <td class="text-center text-sm text-green-700">{{ $grandTotal[0] ?: '-' }}</td>
                        <td class="text-center text-sm text-yellow-700">{{ $grandTotal[1] ?: '-' }}</td>
                        <td class="text-center text-sm text-orange-700">{{ $grandTotal[2] ?: '-' }}</td>
                        <td class="text-center text-sm text-red-700">{{ $grandTotal[3] ?: '-' }}</td>
                        <td class="text-center text-sm text-[#1A365D]">{{ $grandTotal[4] ?: '-' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    {{-- Detailed Aged Issues (90+ days) --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
            <h3 class="text-sm font-semibold text-[#1A365D] flex items-center gap-2">
                <span class="material-symbols-outlined text-red-400 text-lg">error</span>
                Critically Aged Issues (90+ Days)
            </h3>
        </div>
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Title</th>
                        <th>Priority</th>
                        <th>Owner</th>
                        <th>Days Open</th>
                        <th>Due Date</th>
                        <th>Escalation</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (($agedIssues ?? []) as $issue)
                        <tr class="bg-red-50/30">
                            <td>
                                <a href="{{ url('/risk/issues/' . $issue->id) }}" class="text-[#1A365D] font-semibold hover:underline text-xs">
                                    {{ $issue->issue_reference }}
                                </a>
                            </td>
                            <td class="max-w-[200px]">
                                <div class="truncate text-sm font-medium text-gray-800">{{ $issue->title }}</div>
                            </td>
                            <td><x-risk-badge :rating="$issue->priority ?? 'medium'" /></td>
                            <td class="text-xs text-gray-600">{{ $issue->owner->name ?? '-' }}</td>
                            <td>
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-red-100 text-red-700 text-[10px] font-bold">
                                    {{ $issue->created_at ? $issue->created_at->diffInDays(now()) : 0 }}d
                                </span>
                            </td>
                            <td class="text-xs text-red-600 font-medium">{{ $issue->target_resolution_date?->format('d M Y') ?? '-' }}</td>
                            <td>
                                @php
                                    $escLevel = $issue->escalation_level ?? 0;
                                    $escColors = ['bg-gray-100 text-gray-600', 'bg-yellow-100 text-yellow-700', 'bg-orange-100 text-orange-700', 'bg-red-100 text-red-700'];
                                @endphp
                                <span class="badge {{ $escColors[$escLevel] ?? $escColors[0] }}">L{{ $escLevel }}</span>
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
                                No critically aged issues found
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection

@php
    $ageingByPriorityDataChart = $ageingByPriorityData ?? [
        'labels' => ['0-30 Days', '31-60 Days', '61-90 Days', '90+ Days'],
        'critical' => [0, 0, 0, 0],
        'high' => [0, 0, 0, 0],
        'medium' => [0, 0, 0, 0],
        'low' => [0, 0, 0, 0],
    ];
    $ageingTrendDataChart = $ageingTrendData ?? [
        'labels' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
        'avg_age' => [0, 0, 0, 0, 0, 0],
        'overdue_count' => [0, 0, 0, 0, 0, 0],
    ];
@endphp

@push('scripts')
<script>
window.onPageReady(function() {
    // Ageing by Priority (Stacked Bar)
    const ageingPriorityData = @json($ageingByPriorityDataChart);

    new Chart(document.getElementById('ageingByPriorityChart'), {
        type: 'bar',
        data: {
            labels: ageingPriorityData.labels,
            datasets: [
                { label: 'Critical', data: ageingPriorityData.critical, backgroundColor: '#C53030', borderRadius: 4 },
                { label: 'High', data: ageingPriorityData.high, backgroundColor: '#DD6B20', borderRadius: 4 },
                { label: 'Medium', data: ageingPriorityData.medium, backgroundColor: '#D4AF37', borderRadius: 4 },
                { label: 'Low', data: ageingPriorityData.low, backgroundColor: '#2D7D46', borderRadius: 4 },
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { font: { size: 10 }, usePointStyle: true } }
            },
            scales: {
                x: { stacked: true, grid: { display: false }, ticks: { font: { size: 11 } } },
                y: { stacked: true, beginAtZero: true, grid: { color: '#F0F0F0' }, ticks: { font: { size: 10 }, stepSize: 1 } }
            }
        }
    });

    // Ageing Trend (Line)
    const ageingTrendData = @json($ageingTrendDataChart);

    new Chart(document.getElementById('ageingTrendChart'), {
        type: 'line',
        data: {
            labels: ageingTrendData.labels,
            datasets: [
                {
                    label: 'Avg Age (Days)',
                    data: ageingTrendData.avg_age,
                    borderColor: '#1A365D',
                    backgroundColor: 'rgba(26, 54, 93, 0.1)',
                    tension: 0.3,
                    fill: true,
                    yAxisID: 'y',
                },
                {
                    label: 'Overdue Count',
                    data: ageingTrendData.overdue_count,
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
                    title: { display: true, text: 'Avg Days', font: { size: 10 } }
                },
                y1: {
                    beginAtZero: true,
                    position: 'right',
                    grid: { drawOnChartArea: false },
                    ticks: { font: { size: 10 }, stepSize: 1 },
                    title: { display: true, text: 'Overdue Count', font: { size: 10 } }
                }
            }
        }
    });
});
</script>
@endpush
