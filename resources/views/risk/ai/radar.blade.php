@extends('layouts.app')

@section('title', 'Emerging Risk Radar - GRC Risk Management')
@section('page-section', 'Risk Intelligence')
@section('page-title', 'Emerging Risk Radar')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-500">Risk Intelligence</span>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Emerging Risk Radar</span>
@endsection

@section('content')
    <div class="flex items-start justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">Emerging Risk Radar</h1>
            <p class="text-sm text-gray-500 mt-1">
                Your organisation's emerging risk register, plotted by proximity and velocity.
            </p>
        </div>
        <a href="{{ route('risk.emerging.create') }}"
           class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-xs font-semibold hover:bg-[#2D4A7A] flex items-center gap-2">
            <span class="material-symbols-outlined text-sm">add</span> Add emerging risk
        </a>
    </div>

    <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-6">
        <div class="flex items-start gap-3">
            <span class="material-symbols-outlined text-blue-600 text-lg">info</span>
            <p class="text-xs text-blue-900 leading-relaxed">
                Every entry here was recorded by a named person in
                <a href="{{ route('risk.emerging.index') }}" class="underline font-medium">the emerging risk register</a>.
                Velocity and proximity are analyst judgements on a 1–5 scale, attributable to their author — they are
                not model outputs and carry no confidence score.
            </p>
        </div>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <x-kpi-card title="On radar" :value="$totalOnRadar" icon="radar" color="primary" subtitle="Monitoring, assessing or escalated" />
        <x-kpi-card title="Fast moving" :value="$fastMoving" icon="speed" color="danger" subtitle="Velocity 4–5" />
        <x-kpi-card title="Imminent" :value="$imminent" icon="schedule" color="warning" subtitle="Proximity 4–5" />
        <x-kpi-card title="High or critical impact" :value="$highImpact" icon="priority_high" color="danger" />
    </div>

    @if ($totalOnRadar === 0)
        <div class="bg-white rounded-xl border border-gray-200 p-10 text-center">
            <span class="material-symbols-outlined text-4xl text-gray-300 mb-3 block">radar</span>
            <p class="text-sm font-semibold text-gray-700">The emerging risk register is empty</p>
            <p class="text-xs text-gray-500 mt-2 max-w-md mx-auto">
                This radar plots what your team records. Nothing is generated automatically — add the first entry to
                start building the horizon view.
            </p>
            <a href="{{ route('risk.emerging.create') }}"
               class="inline-flex items-center gap-2 mt-5 px-4 py-2 bg-[#1A365D] text-white rounded-lg text-xs font-semibold hover:bg-[#2D4A7A]">
                <span class="material-symbols-outlined text-sm">add</span> Add emerging risk
            </a>
        </div>
    @else
        @if ($neverReviewed > 0 || $staleReviews > 0)
            <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 mb-6 flex items-start gap-3">
                <span class="material-symbols-outlined text-amber-600 text-lg">warning</span>
                <p class="text-xs text-amber-900">
                    @if ($neverReviewed > 0)
                        {{ $neverReviewed }} entr{{ $neverReviewed === 1 ? 'y has' : 'ies have' }} never been reviewed.
                    @endif
                    @if ($staleReviews > 0)
                        {{ $staleReviews }} entr{{ $staleReviews === 1 ? 'y was' : 'ies were' }} last reviewed more than 90 days ago.
                    @endif
                    A horizon view is only as current as its last review date.
                </p>
            </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <div class="bg-white rounded-xl border border-gray-200 p-5">
                <h3 class="text-sm font-semibold text-[#1A365D] mb-1">Proximity against velocity</h3>
                <p class="text-[11px] text-gray-500 mb-4">Upper right is fast moving and close at hand.</p>
                <canvas id="radarScatter" height="320"></canvas>
            </div>

            <div class="bg-white rounded-xl border border-gray-200 p-5">
                <h3 class="text-sm font-semibold text-[#1A365D] mb-4">By time horizon</h3>
                <div class="space-y-3 mb-6">
                    @foreach ($byHorizon as $horizon => $count)
                        <div>
                            <div class="flex justify-between text-xs mb-1">
                                <span class="text-gray-700 font-medium">{{ $horizon }}</span>
                                <span class="text-gray-500">{{ $count }}</span>
                            </div>
                            <div class="w-full bg-gray-100 rounded-full h-2">
                                <div class="h-2 rounded-full bg-[#1A365D]"
                                     style="width: {{ $totalOnRadar > 0 ? round($count / $totalOnRadar * 100) : 0 }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <h3 class="text-sm font-semibold text-[#1A365D] mb-4 pt-4 border-t border-gray-100">By category</h3>
                <canvas id="categoryChart" height="160"></canvas>
            </div>
        </div>

        <div class="mb-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold text-[#1A365D]">Register entries</h3>
                <a href="{{ route('risk.emerging.index') }}" class="text-xs text-[#1A365D] hover:underline">Manage register</a>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @foreach ($register as $entry)
                    <div class="bg-white rounded-xl border p-4
                        {{ $entry->potential_impact === 'Critical' ? 'border-red-300' : ($entry->potential_impact === 'High' ? 'border-orange-300' : 'border-gray-200') }}">
                        <div class="flex items-start justify-between mb-2">
                            <div class="flex-1 min-w-0">
                                <p class="text-[10px] font-semibold text-gray-500">{{ $entry->reference }}</p>
                                <a href="{{ route('risk.emerging.edit', $entry) }}"
                                   class="text-xs font-bold text-[#1A365D] hover:underline block mt-0.5">{{ $entry->title }}</a>
                                <p class="text-[10px] text-gray-600 mt-1">
                                    {{ $entry->category?->name ?? 'Uncategorised' }} · {{ $entry->horizon }}
                                </p>
                            </div>
                            <span class="badge text-[10px] whitespace-nowrap ml-2
                                {{ $entry->potential_impact === 'Critical' ? 'bg-red-100 text-red-700' : ($entry->potential_impact === 'High' ? 'bg-orange-100 text-orange-700' : 'bg-gray-100 text-gray-700') }}">
                                {{ $entry->potential_impact }}
                            </span>
                        </div>

                        @if ($entry->description)
                            <p class="text-[11px] text-gray-600 mt-2">{{ Str::limit($entry->description, 140) }}</p>
                        @endif

                        <div class="grid grid-cols-3 gap-2 mt-3 pt-3 border-t border-gray-100 text-center">
                            <div>
                                <p class="text-[10px] text-gray-500">Velocity</p>
                                <p class="text-xs font-semibold text-gray-800 mt-1">
                                    {{ $entry->velocity_score }}/5 <span class="font-normal text-gray-500">{{ $entry->velocity_label }}</span>
                                </p>
                            </div>
                            <div>
                                <p class="text-[10px] text-gray-500">Proximity</p>
                                <p class="text-xs font-semibold text-gray-800 mt-1">
                                    {{ $entry->proximity_score }}/5 <span class="font-normal text-gray-500">{{ $entry->proximity_label }}</span>
                                </p>
                            </div>
                            <div>
                                <p class="text-[10px] text-gray-500">Reviewed</p>
                                <p class="text-xs font-semibold text-gray-800 mt-1">
                                    {{ $entry->last_reviewed_at?->format('d M y') ?? '—' }}
                                </p>
                            </div>
                        </div>

                        <div class="mt-3 pt-3 border-t border-gray-100 text-[10px] text-gray-500 flex justify-between">
                            <span>{{ $entry->source ? 'Source: '.Str::limit($entry->source, 30) : 'Source not recorded' }}</span>
                            <span>{{ $entry->owner?->name ?? 'Unowned' }}</span>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
@endsection

@push('scripts')
@if ($totalOnRadar > 0)
<script>
document.addEventListener('DOMContentLoaded', function () {
    const points = @json($points);
    const impactColour = {
        Critical: '#C53030',
        High: '#DD6B20',
        Medium: '#D69E2E',
        Low: '#2D7D46',
    };

    new Chart(document.getElementById('radarScatter'), {
        type: 'scatter',
        data: {
            datasets: [{
                label: 'Emerging risks',
                data: points,
                pointRadius: 7,
                pointHoverRadius: 9,
                backgroundColor: points.map(p => impactColour[p.impact] || '#1A365D'),
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (ctx) => ctx.raw.label,
                    },
                },
            },
            scales: {
                x: { min: 0, max: 6, title: { display: true, text: 'Proximity (1 distant → 5 imminent)', font: { size: 10 } }, ticks: { stepSize: 1, font: { size: 10 } }, grid: { color: '#F0F0F0' } },
                y: { min: 0, max: 6, title: { display: true, text: 'Velocity (1 slow → 5 fast)', font: { size: 10 } }, ticks: { stepSize: 1, font: { size: 10 } }, grid: { color: '#F0F0F0' } },
            },
        },
    });

    const categoryData = @json($categoryChart);
    new Chart(document.getElementById('categoryChart'), {
        type: 'bar',
        data: {
            labels: categoryData.labels,
            datasets: [{ label: 'Entries', data: categoryData.values, backgroundColor: '#1A365D' }],
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { beginAtZero: true, ticks: { stepSize: 1, font: { size: 10 } }, grid: { color: '#F0F0F0' } },
                y: { ticks: { font: { size: 10 } }, grid: { display: false } },
            },
        },
    });
});
</script>
@endif
@endpush
