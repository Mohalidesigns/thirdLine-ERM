@extends('layouts.app')

@section('title', 'Root Cause Analysis - GRC Platform')

@section('breadcrumbs')
    <span>Risk Management</span>
    <span class="text-gray-300">/</span>
    <span>Loss Events</span>
    <span class="text-gray-300">/</span>
    <span class="text-[#1A365D] font-semibold">Root Cause Analysis</span>
@endsection

@section('content')
    {{-- Page Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">Root Cause Analysis</h1>
            <p class="text-sm text-gray-500 mt-1">Overview of root cause investigations for operational loss events</p>
        </div>
    </div>

    {{-- Flash Messages --}}
    @if (session('success'))
        <div class="mb-4 px-4 py-3 bg-green-50 border border-green-200 text-green-700 rounded-lg text-sm flex items-center gap-2">
            <span class="material-symbols-outlined text-lg">check_circle</span>
            {{ session('success') }}
        </div>
    @endif

    {{-- Summary Cards --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <x-kpi-card title="Total RCAs" :value="$totalRcas ?? 0" icon="psychology" color="primary" />
        <x-kpi-card title="In Progress" :value="$inProgressRcas ?? 0" icon="pending" color="warning" />
        <x-kpi-card title="Completed" :value="$completedRcas ?? 0" icon="check_circle" color="success" />
        <x-kpi-card title="Pending RCA" :value="$pendingRcas ?? 0" icon="schedule" color="danger" subtitle="Events without RCA" />
    </div>

    {{-- Root Cause Distribution Chart --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Root Cause Categories</h3>
            <canvas id="rcaCategoryChart" height="240"></canvas>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">RCA Completion Status</h3>
            <canvas id="rcaStatusChart" height="240"></canvas>
        </div>
    </div>

    {{-- Events Requiring RCA --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden mb-6">
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
            <h3 class="text-sm font-semibold text-[#1A365D]">Events Requiring Root Cause Analysis</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Event Title</th>
                        <th>Severity</th>
                        <th>Gross Loss</th>
                        <th>RCA Status</th>
                        <th>Root Cause</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (($rcaEvents ?? []) as $event)
                        <tr>
                            <td>
                                <a href="{{ route('risk.loss-events.show', $event) }}" class="text-[#1A365D] font-semibold hover:underline text-xs">
                                    {{ $event->event_reference }}
                                </a>
                            </td>
                            <td class="max-w-[200px]">
                                <div class="truncate text-sm font-medium text-gray-800">{{ $event->title }}</div>
                            </td>
                            <td><x-risk-badge :rating="$event->severity ?? 'low'" /></td>
                            <td class="font-semibold text-sm">₦{{ number_format($event->gross_loss_amount ?? 0, 2) }}</td>
                            <td><x-status-badge :status="$event->rca ? ($event->rca->status === 'approved' ? 'completed' : $event->rca->status) : 'pending'" /></td>
                            <td class="text-xs text-gray-600 max-w-[200px] truncate">{{ $event->rca->root_cause_statement ?? $event->root_cause_summary ?? 'Not yet determined' }}</td>
                            <td>
                                <a href="{{ route('risk.loss-events.show', ['loss_event' => $event, 'tab' => 'rca']) }}"
                                   class="flex items-center gap-1 text-xs text-[#1A365D] font-medium hover:underline">
                                    <span class="material-symbols-outlined text-sm">psychology</span>
                                    {{ $event->rca ? 'View RCA' : 'Start RCA' }}
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center py-12 text-gray-400">
                                <span class="material-symbols-outlined text-4xl mb-2 block">psychology</span>
                                <p class="text-sm font-medium">No events require root cause analysis</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Pagination --}}
    @if (($rcaEvents ?? collect()) instanceof \Illuminate\Pagination\LengthAwarePaginator && $rcaEvents->hasPages())
        <div class="mt-4 flex justify-center">
            {{ $rcaEvents->withQueryString()->links() }}
        </div>
    @endif
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Root Cause Categories
    const rcaCatData = {!! json_encode($rcaCategoryData ?? ['labels' => ['People', 'Process', 'Systems', 'External', 'Governance'], 'values' => [0, 0, 0, 0, 0]]) !!};
    new Chart(document.getElementById('rcaCategoryChart'), {
        type: 'bar',
        data: {
            labels: rcaCatData.labels,
            datasets: [{
                label: 'Root Causes',
                data: rcaCatData.values,
                backgroundColor: ['#1A365D', '#2D7D46', '#D4AF37', '#C53030', '#553C9A'],
                borderRadius: 6,
                barThickness: 32,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 11 } } },
                y: { beginAtZero: true, grid: { color: '#F0F0F0' }, ticks: { font: { size: 10 }, stepSize: 1 } }
            }
        }
    });

    // RCA Status
    const rcaStatusData = {!! json_encode($rcaStatusData ?? ['labels' => ['Completed', 'In Progress', 'Pending'], 'values' => [0, 0, 0]]) !!};
    new Chart(document.getElementById('rcaStatusChart'), {
        type: 'doughnut',
        data: {
            labels: rcaStatusData.labels,
            datasets: [{
                data: rcaStatusData.values,
                backgroundColor: ['#2D7D46', '#D4AF37', '#E2E8F0'],
                borderWidth: 0,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '65%',
            plugins: { legend: { position: 'bottom', labels: { font: { size: 11 }, usePointStyle: true } } }
        }
    });
});
</script>
@endpush
