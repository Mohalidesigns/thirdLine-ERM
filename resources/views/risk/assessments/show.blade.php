@extends('layouts.app')

@section('title', 'Assessment Detail - GRC Risk Management')
@section('page-section', 'Assessments')
@section('page-title', 'Assessment Detail')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.assessments.index') }}" class="hover:text-[#1A365D]">Assessments</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">ASS-{{ str_pad($assessment->id ?? 0, 4, '0', STR_PAD_LEFT) }}</span>
@endsection

@section('content')
    @if (session('success'))
        <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-xl flex items-center gap-3">
            <span class="material-symbols-outlined text-green-600">check_circle</span>
            <span class="text-sm text-green-700">{{ session('success') }}</span>
            <button onclick="this.parentElement.remove()" class="ml-auto text-green-400 hover:text-green-600"><span class="material-symbols-outlined text-lg">close</span></button>
        </div>
    @endif

    <div class="mb-6">
        <div class="flex items-center justify-between">
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-2xl font-bold text-[#1A365D]">Assessment ASS-{{ str_pad($assessment->id ?? 0, 4, '0', STR_PAD_LEFT) }}</h1>
                    <x-risk-badge :rating="$assessment->rating ?? 'medium'" />
                    <x-status-badge :status="$assessment->status ?? 'completed'" />
                </div>
                <p class="text-sm text-gray-500 mt-1">
                    Risk: {{ $assessment->risk->risk_code ?? 'N/A' }} - {{ Str::limit($assessment->risk->title ?? '', 50) }}
                    &middot; Assessed: {{ $assessment->assessment_date?->format('d M Y') ?? '-' }}
                </p>
            </div>
            <a href="{{ route('risk.assessments.index') }}" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Back</a>
        </div>
    </div>

    {{-- Score Summary --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <x-kpi-card title="Overall Score" :value="($assessment->overall_score ?? 0) . '/25'" icon="analytics" color="primary" />
        <x-kpi-card title="Likelihood" :value="($assessment->likelihood ?? 0) . '/5'" icon="casino" color="info" />
        <x-kpi-card title="Max Impact" :value="($assessment->max_impact ?? 0) . '/5'" icon="priority_high" color="warning" />
        <x-kpi-card title="vs Previous" :value="($assessment->score_change ?? 0) > 0 ? '+' . $assessment->score_change : ($assessment->score_change ?? '0')" icon="{{ ($assessment->score_change ?? 0) > 0 ? 'trending_up' : (($assessment->score_change ?? 0) < 0 ? 'trending_down' : 'trending_flat') }}" :color="($assessment->score_change ?? 0) > 0 ? 'danger' : (($assessment->score_change ?? 0) < 0 ? 'success' : 'primary')" />
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        {{-- Impact Dimensions --}}
        <div class="lg:col-span-2 bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Impact Dimension Scores</h3>
            <canvas id="dimensionChart" height="250"></canvas>
        </div>

        {{-- Assessment Info --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Assessment Details</h3>
            <dl class="space-y-3">
                <div class="flex justify-between"><dt class="text-xs text-gray-500">Assessment Type</dt><dd class="text-xs font-medium">{{ ucfirst(str_replace('_', ' ', $assessment->assessment_type ?? '-')) }}</dd></div>
                <div class="flex justify-between"><dt class="text-xs text-gray-500">Assessor</dt><dd class="text-xs font-medium">{{ $assessment->assessor->name ?? '-' }}</dd></div>
                <div class="flex justify-between"><dt class="text-xs text-gray-500">Assessment Date</dt><dd class="text-xs">{{ $assessment->assessment_date?->format('d M Y') ?? '-' }}</dd></div>
                <div class="flex justify-between"><dt class="text-xs text-gray-500">Likelihood</dt><dd class="text-xs font-medium">{{ $assessment->likelihood ?? '-' }}/5</dd></div>
                <div class="flex justify-between"><dt class="text-xs text-gray-500">Financial Impact</dt><dd class="text-xs font-medium">{{ $assessment->impact_financial ?? '-' }}/5</dd></div>
                <div class="flex justify-between"><dt class="text-xs text-gray-500">Operational Impact</dt><dd class="text-xs font-medium">{{ $assessment->impact_operational ?? '-' }}/5</dd></div>
                <div class="flex justify-between"><dt class="text-xs text-gray-500">Reputational Impact</dt><dd class="text-xs font-medium">{{ $assessment->impact_reputational ?? '-' }}/5</dd></div>
                <div class="flex justify-between"><dt class="text-xs text-gray-500">Regulatory Impact</dt><dd class="text-xs font-medium">{{ $assessment->impact_regulatory ?? '-' }}/5</dd></div>
                <div class="flex justify-between"><dt class="text-xs text-gray-500">Strategic Impact</dt><dd class="text-xs font-medium">{{ $assessment->impact_strategic ?? '-' }}/5</dd></div>
                <div class="flex justify-between"><dt class="text-xs text-gray-500">People Impact</dt><dd class="text-xs font-medium">{{ $assessment->impact_people ?? '-' }}/5</dd></div>
            </dl>
        </div>
    </div>

    {{-- Comparison with Previous --}}
    @if ($previousAssessment ?? null)
        <div class="bg-white rounded-xl border border-gray-200 p-5 mb-6">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Comparison with Previous Assessment</h3>
            <canvas id="comparisonChart" height="150"></canvas>
        </div>
    @endif

    {{-- Rationale & Recommendations --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white rounded-xl border border-gray-200 p-6">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Assessment Rationale</h3>
            <p class="text-sm text-gray-700 leading-relaxed">{{ $assessment->rationale ?? 'No rationale provided.' }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-6">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Recommendations</h3>
            <p class="text-sm text-gray-700 leading-relaxed">{{ $assessment->recommendations ?? 'No recommendations provided.' }}</p>
        </div>
    </div>

    {{-- Assessment History --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden mt-6">
        <div class="px-5 py-4 border-b border-gray-100"><h3 class="text-sm font-semibold text-[#1A365D]">Assessment History for {{ $assessment->risk->risk_code ?? 'this risk' }}</h3></div>
        <table class="data-table">
            <thead><tr><th>Date</th><th>Assessor</th><th>Likelihood</th><th>Max Impact</th><th>Score</th><th>Rating</th><th>Change</th></tr></thead>
            <tbody>
                @forelse (($assessmentHistory ?? []) as $hist)
                    <tr class="{{ $hist->id === ($assessment->id ?? null) ? 'bg-blue-50' : '' }}">
                        <td class="text-xs {{ $hist->id === ($assessment->id ?? null) ? 'font-semibold text-[#1A365D]' : 'text-gray-500' }}">{{ $hist->assessment_date?->format('d M Y') ?? '-' }} {{ $hist->id === ($assessment->id ?? null) ? '(Current)' : '' }}</td>
                        <td class="text-xs">{{ $hist->assessor->name ?? '-' }}</td>
                        <td class="text-xs text-center">{{ $hist->likelihood ?? '-' }}</td>
                        <td class="text-xs text-center">{{ $hist->max_impact ?? '-' }}</td>
                        <td class="text-xs text-center font-bold">{{ $hist->overall_score ?? '-' }}</td>
                        <td><x-risk-badge :rating="$hist->rating ?? 'medium'" /></td>
                        <td class="text-xs {{ ($hist->score_change ?? 0) > 0 ? 'text-red-500' : (($hist->score_change ?? 0) < 0 ? 'text-green-500' : 'text-gray-400') }}">
                            {{ ($hist->score_change ?? 0) > 0 ? '+' : '' }}{{ $hist->score_change ?? '-' }}
                        </td>
                    </tr>
                @empty <tr><td colspan="7" class="text-center py-6 text-gray-400">No previous assessments</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const dimData = @json($dimensionData ?? ['labels' => ['Financial','Operational','Reputational','Regulatory','Strategic','People'], 'current' => [0,0,0,0,0,0], 'previous' => [0,0,0,0,0,0]]);
    new Chart(document.getElementById('dimensionChart'), {
        type: 'radar',
        data: { labels: dimData.labels, datasets: [
            { label: 'Current', data: dimData.current, borderColor: '#1A365D', backgroundColor: 'rgba(26,54,93,0.1)', pointBackgroundColor: '#1A365D' },
            { label: 'Previous', data: dimData.previous, borderColor: '#D4AF37', backgroundColor: 'rgba(212,175,55,0.1)', pointBackgroundColor: '#D4AF37', borderDash: [5,5] },
        ] },
        options: { responsive: true, maintainAspectRatio: false, scales: { r: { beginAtZero: true, max: 5, ticks: { font: { size: 9 }, stepSize: 1 }, pointLabels: { font: { size: 11 } } } }, plugins: { legend: { position: 'bottom', labels: { font: { size: 10 }, usePointStyle: true } } } }
    });

    @if ($previousAssessment ?? null)
    const compData = @json($comparisonData ?? ['labels' => [], 'current' => [], 'previous' => []]);
    if (document.getElementById('comparisonChart')) {
        new Chart(document.getElementById('comparisonChart'), {
            type: 'bar', data: { labels: compData.labels, datasets: [{ label: 'Previous', data: compData.previous, backgroundColor: '#D4AF37', borderRadius: 4 }, { label: 'Current', data: compData.current, backgroundColor: '#1A365D', borderRadius: 4 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { font: { size: 10 }, usePointStyle: true } } }, scales: { x: { grid: { display: false } }, y: { beginAtZero: true, max: 5, grid: { color: '#F0F0F0' }, ticks: { stepSize: 1 } } } }
        });
    }
    @endif
});
</script>
@endpush
