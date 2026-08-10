@extends('layouts.app')

@section('title', 'Regulatory Pulse - GRC Risk Management')
@section('page-section', 'Risk Intelligence')
@section('page-title', 'Regulatory Pulse')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-500">Risk Intelligence</span>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Regulatory Pulse</span>
@endsection

@section('content')
    <div class="flex items-start justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">Regulatory Pulse</h1>
            <p class="text-sm text-gray-500 mt-1">
                Circulars and filing deadlines recorded against this organisation.
            </p>
        </div>
        <div class="text-right text-xs text-gray-500">
            <p>As at <span class="font-semibold text-[#1A365D]">{{ \Carbon\Carbon::parse($asAt)->format('d M Y H:i') }}</span></p>
            <a href="{{ route('risk.regulatory.circulars') }}" class="text-[#1A365D] hover:underline mt-1 inline-block">
                Manage circulars
            </a>
        </div>
    </div>

    <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-6 flex items-start gap-3">
        <span class="material-symbols-outlined text-blue-600 text-lg">info</span>
        <p class="text-xs text-blue-900 leading-relaxed">
            This screen reads your own <span class="font-medium">regulatory circulars</span> and
            <span class="font-medium">filing deadlines</span> tables. It does not scan external sources, and it
            shows nothing your team has not recorded. Compliance percentages are the values entered against each
            circular.
        </p>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <x-kpi-card title="Circulars on file" :value="$summary['circulars_total']" icon="description" color="primary"
                    subtitle="{{ $summary['circulars_recent'] }} issued in last {{ $window['recent_window_days'] }} days" />
        <x-kpi-card title="High or critical impact" :value="$summary['high_impact']" icon="priority_high" color="danger" />
        <x-kpi-card title="Past effective date, not compliant" :value="$summary['past_effective_date_not_compliant']"
                    icon="event_busy" color="warning" />
        <x-kpi-card title="Filings overdue" :value="$summary['deadlines_overdue']" icon="assignment_late" color="danger"
                    subtitle="{{ $summary['deadlines_due_30d'] }} due in {{ $window['deadline_horizon_days'] }} days" />
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-1">Recorded compliance</h3>
            <p class="text-[11px] text-gray-500 mb-4">Mean of the compliance percentages entered against circulars.</p>
            @if ($summary['mean_compliance_pct'] === null)
                <p class="text-sm text-gray-400 py-4">
                    No circular has a compliance percentage recorded, so there is no figure to report.
                </p>
            @else
                <p class="text-3xl font-bold text-[#1A365D]">{{ number_format($summary['mean_compliance_pct'], 1) }}%</p>
                <p class="text-xs text-gray-500 mt-1">
                    across {{ $summary['scored_circulars'] }} of {{ $summary['circulars_total'] }} circulars
                </p>
                @if ($summary['scored_circulars'] < $summary['circulars_total'])
                    <p class="text-[11px] text-amber-700 mt-3 bg-amber-50 border border-amber-200 rounded p-2">
                        {{ $summary['circulars_total'] - $summary['scored_circulars'] }} circular(s) carry no score and
                        are excluded from this average.
                    </p>
                @endif
            @endif
        </div>

        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Impact mix</h3>
            @if ($summary['circulars_total'] === 0)
                <p class="text-sm text-gray-400 py-4">No circulars recorded.</p>
            @else
                <canvas id="impactChart" height="180"></canvas>
            @endif
        </div>

        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100">
                <h3 class="text-sm font-semibold text-[#1A365D]">Due in the next {{ $window['deadline_horizon_days'] }} days</h3>
            </div>
            <div class="divide-y divide-gray-100 max-h-72 overflow-y-auto">
                @forelse ($upcomingDeadlines as $deadline)
                    <div class="px-5 py-3 {{ $deadline->is_urgent ? 'bg-red-50' : '' }}">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="text-xs font-semibold text-gray-800">{{ Str::limit($deadline->title, 40) }}</p>
                                <p class="text-[10px] text-gray-500 mt-0.5">
                                    {{ $deadline->regulator }}
                                    @if ($deadline->responsible) · {{ $deadline->responsible }} @endif
                                </p>
                            </div>
                            <span class="badge whitespace-nowrap {{ $deadline->is_urgent ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-700' }}">
                                {{ $deadline->days_remaining }}d
                            </span>
                        </div>
                        <p class="text-[10px] text-gray-500 mt-1">{{ $deadline->due_display }}</p>
                    </div>
                @empty
                    <div class="px-5 py-10 text-center text-gray-400 text-sm">
                        <span class="material-symbols-outlined text-2xl mb-1 block">event_available</span>
                        Nothing due in this window
                    </div>
                @endforelse
            </div>
        </div>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-[#1A365D]">Circulars</h3>
            <span class="text-xs text-gray-500">Most recently issued first</span>
        </div>
        <div class="divide-y divide-gray-100">
            @forelse ($feed as $item)
                <div class="px-5 py-4 {{ $item->is_overdue ? 'border-l-4 border-l-red-500' : '' }}">
                    <div class="flex items-start justify-between gap-4 mb-2">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="badge bg-[#1A365D] text-white text-[10px]">{{ $item->regulator }}</span>
                                <span class="text-[10px] font-mono text-gray-500">{{ $item->reference }}</span>
                                @if ($item->is_overdue)
                                    <span class="badge bg-red-100 text-red-700 text-[10px]">Past effective date</span>
                                @endif
                            </div>
                            <p class="text-sm font-semibold text-gray-800 mt-1.5">{{ $item->title }}</p>
                            @if ($item->summary)
                                <p class="text-xs text-gray-600 mt-1">{{ Str::limit($item->summary, 220) }}</p>
                            @endif
                        </div>
                        <span class="badge whitespace-nowrap
                            {{ $item->impact === 'critical' ? 'bg-red-100 text-red-700' : ($item->impact === 'high' ? 'bg-orange-100 text-orange-700' : ($item->impact === 'medium' ? 'bg-yellow-100 text-yellow-700' : 'bg-gray-100 text-gray-700')) }}">
                            {{ ucfirst($item->impact) }} impact
                        </span>
                    </div>

                    <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mt-3 pt-3 border-t border-gray-100 text-[11px]">
                        <div>
                            <p class="text-gray-500">Issued</p>
                            <p class="font-medium text-gray-800 mt-0.5">
                                {{ $item->issued_at ? \Carbon\Carbon::parse($item->issued_at)->format('d M Y') : '—' }}
                            </p>
                        </div>
                        <div>
                            <p class="text-gray-500">Effective</p>
                            <p class="font-medium text-gray-800 mt-0.5">
                                {{ $item->effective_at ? \Carbon\Carbon::parse($item->effective_at)->format('d M Y') : '—' }}
                            </p>
                        </div>
                        <div>
                            <p class="text-gray-500">Status</p>
                            <p class="font-medium text-gray-800 mt-0.5">{{ ucwords(str_replace('_', ' ', $item->compliance_status)) }}</p>
                        </div>
                        <div>
                            <p class="text-gray-500">Compliance</p>
                            <p class="font-medium text-gray-800 mt-0.5">
                                {{ $item->compliance_pct !== null ? number_format($item->compliance_pct, 0).'%' : 'Not recorded' }}
                            </p>
                        </div>
                        <div>
                            <p class="text-gray-500">Linked register items</p>
                            <p class="font-medium text-gray-800 mt-0.5">
                                {{ $item->linked_risks }} risk{{ $item->linked_risks === 1 ? '' : 's' }},
                                {{ $item->linked_controls }} control{{ $item->linked_controls === 1 ? '' : 's' }}
                            </p>
                        </div>
                    </div>

                    @if ($item->action_required)
                        <p class="text-[11px] text-gray-700 mt-3 bg-gray-50 rounded p-2">
                            <span class="font-semibold">Action required:</span> {{ $item->action_required }}
                            @if ($item->assigned_to)
                                <span class="text-gray-500">— {{ $item->assigned_to }}</span>
                            @endif
                        </p>
                    @endif
                </div>
            @empty
                <div class="px-5 py-12 text-center">
                    <span class="material-symbols-outlined text-3xl text-gray-300 mb-2 block">gavel</span>
                    <p class="text-sm text-gray-500">No regulatory circulars recorded for this organisation.</p>
                    <a href="{{ route('risk.regulatory.circulars') }}" class="text-xs text-[#1A365D] hover:underline mt-2 inline-block">
                        Record the first circular
                    </a>
                </div>
            @endforelse
        </div>
    </div>
@endsection

@push('scripts')
@if ($summary['circulars_total'] > 0)
<script>
document.addEventListener('DOMContentLoaded', function () {
    const mix = @json($impactMix);

    new Chart(document.getElementById('impactChart'), {
        type: 'doughnut',
        data: {
            labels: ['Critical', 'High', 'Medium', 'Low'],
            datasets: [{
                data: [mix.critical, mix.high, mix.medium, mix.low],
                backgroundColor: ['#C53030', '#DD6B20', '#D69E2E', '#2D7D46'],
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom', labels: { font: { size: 10 }, usePointStyle: true } } },
        },
    });
});
</script>
@endif
@endpush
