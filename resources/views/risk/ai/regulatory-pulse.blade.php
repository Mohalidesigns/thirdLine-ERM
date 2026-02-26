@extends('layouts.app')

@section('title', 'Regulatory Pulse - GRC Risk Management')
@section('page-section', 'AI Intelligence')
@section('page-title', 'Regulatory Pulse')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-500">AI Intelligence</span>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Regulatory Pulse</span>
@endsection

@section('content')
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">Regulatory Intelligence Pulse</h1>
            <p class="text-sm text-gray-500 mt-1">AI-curated regulatory updates, impact assessments, and compliance intelligence</p>
        </div>
        <div class="flex items-center gap-3">
            <span class="text-xs text-gray-500">Last scan: {{ $lastScan ?? now()->format('d M Y H:i') }}</span>
            <button class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-xs font-semibold hover:bg-[#2D4A7A] flex items-center gap-2"><span class="material-symbols-outlined text-sm">radar</span> Scan Updates</button>
        </div>
    </div>

    {{-- Summary --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <x-kpi-card title="Active Regulations" :value="($newRegulations ?? 0)" icon="gavel" color="primary" />
        <x-kpi-card title="Critical Impact" :value="($highImpact ?? 0)" icon="priority_high" color="danger" />
        <x-kpi-card title="Action Required" :value="($actionRequired ?? 0)" icon="assignment_turned_in" color="warning" />
        <x-kpi-card title="Compliance Score" :value="($complianceScore ?? 75) . '%'" icon="verified" color="success" />
    </div>

    {{-- Regulatory Feed --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-4">
            <h3 class="text-sm font-semibold text-[#1A365D]">Regulatory Intelligence Updates</h3>
            @forelse (($regulatoryFeed ?? []) as $item)
                <div class="bg-white rounded-xl border {{ ($item->impact ?? '') === 'critical' ? 'border-red-300 bg-red-50' : (($item->impact ?? '') === 'high' ? 'border-orange-300' : 'border-gray-200') }} p-5 hover:shadow-md transition-shadow">
                    <div class="flex items-start justify-between mb-3">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="badge {{ ($item->impact ?? '') === 'critical' ? 'bg-red-100 text-red-700' : (($item->impact ?? '') === 'high' ? 'bg-orange-100 text-orange-700' : 'bg-green-100 text-green-700') }}">
                                {{ ucfirst($item->impact ?? '-') }} Impact
                            </span>
                            <span class="badge bg-blue-100 text-blue-700">{{ $item->regulator ?? 'CBN' }}</span>
                            @if($item->compliance_status === 'Overdue')
                                <span class="badge bg-red-100 text-red-700 animate-pulse">OVERDUE</span>
                            @elseif($item->compliance_status === 'Partially Compliant')
                                <span class="badge bg-yellow-100 text-yellow-700">In Progress</span>
                            @else
                                <span class="badge bg-green-100 text-green-700">{{ ucfirst($item->compliance_status) }}</span>
                            @endif
                        </div>
                        <span class="text-[10px] text-gray-500 whitespace-nowrap ml-2">{{ $item->date ?? '-' }}</span>
                    </div>
                    <h4 class="text-sm font-semibold text-gray-800 mb-1">{{ $item->title ?? '' }}</h4>
                    <p class="text-[10px] text-gray-600 mb-2">Ref: {{ $item->reference ?? 'N/A' }}</p>
                    <p class="text-xs text-gray-600 mb-3">{{ Str::limit($item->summary ?? '', 180) }}</p>
                    <div class="grid grid-cols-3 gap-3 mb-3 pt-3 border-t border-gray-200">
                        <div>
                            <p class="text-[10px] text-gray-600 mb-1">Compliance Progress</p>
                            <div class="w-full bg-gray-200 rounded-full h-2"><div class="h-2 rounded-full {{ $item->completion_pct >= 80 ? 'bg-green-500' : ($item->completion_pct >= 50 ? 'bg-yellow-500' : 'bg-red-500') }}" style="width: {{ $item->completion_pct ?? 0 }}%"></div></div>
                            <p class="text-[10px] font-semibold text-gray-700 mt-1">{{ $item->completion_pct ?? 0 }}%</p>
                        </div>
                        <div>
                            <p class="text-[10px] text-gray-600 mb-1">Deadline</p>
                            @if($item->compliance_deadline)
                                <p class="text-[10px] font-semibold text-gray-700">{{ Carbon\Carbon::parse($item->compliance_deadline)->format('d M Y') }}</p>
                                <p class="text-[10px] text-gray-500 mt-1">{{ Carbon\Carbon::parse($item->compliance_deadline)->diffForHumans(null, true, true) }} left</p>
                            @else
                                <p class="text-[10px] text-gray-500">Draft - TBD</p>
                            @endif
                        </div>
                        <div>
                            <p class="text-[10px] text-gray-600 mb-1">Affected Areas</p>
                            <div class="flex flex-wrap gap-1 mt-1">
                                @foreach(array_slice($item->affected_modules ?? [], 0, 2) as $module)
                                    <span class="badge bg-gray-100 text-gray-700 text-[9px]">{{ $module }}</span>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 text-[10px] text-gray-600">
                        <span class="material-symbols-outlined text-xs">tasks</span>
                        <span>{{ $item->actions_required ?? 0 }} actions required</span>
                    </div>
                </div>
            @empty
                <div class="bg-white rounded-xl border border-gray-200 p-12 text-center">
                    <span class="material-symbols-outlined text-4xl text-gray-300 mb-3 block">newspaper</span>
                    <p class="text-sm text-gray-500">No regulatory updates to display</p>
                </div>
            @endforelse
        </div>

        {{-- Sidebar --}}
        <div class="space-y-6">
            <div class="bg-white rounded-xl border border-gray-200 p-5">
                <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Impact Assessment Summary</h3>
                <canvas id="impactChart" height="200"></canvas>
            </div>
            <div class="bg-white rounded-xl border border-gray-200 p-5">
                <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Upcoming Deadlines</h3>
                <div class="space-y-2">
                    @forelse (($upcomingDeadlines ?? []) as $deadline)
                        <div class="flex items-center gap-3 p-2 bg-gray-50 rounded-lg">
                            <span class="material-symbols-outlined text-sm {{ $deadline->is_urgent ? 'text-red-500' : 'text-gray-400' }}">schedule</span>
                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-medium text-gray-700 truncate">{{ $deadline->title ?? '' }}</p>
                                <p class="text-[10px] text-gray-500">{{ $deadline->due_date ?? '-' }}</p>
                            </div>
                        </div>
                    @empty
                        <p class="text-xs text-gray-400 text-center py-4">No upcoming deadlines</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
@endsection

@php
    $impactChartDataChart = $impactChartData ?? ['labels' => ['High','Medium','Low'], 'values' => [0,0,0]];
@endphp

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const impData = @json($impactChartDataChart);
    new Chart(document.getElementById('impactChart'), {
        type: 'doughnut', data: { labels: impData.labels, datasets: [{ data: impData.values, backgroundColor: ['#C53030','#D4AF37','#2D7D46'], borderWidth: 0 }] },
        options: { responsive: true, maintainAspectRatio: false, cutout: '60%', plugins: { legend: { position: 'bottom', labels: { font: { size: 10 }, usePointStyle: true } } } }
    });
});
</script>
@endpush
