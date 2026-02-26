@extends('layouts.app')

@section('title', 'Predictive Risk Scoring - GRC Risk Management')
@section('page-section', 'AI Intelligence')
@section('page-title', 'Predictive')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-500">AI Intelligence</span>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Predictive Scoring</span>
@endsection

@section('content')
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">Predictive Risk Scoring</h1>
            <p class="text-sm text-gray-500 mt-1">AI-powered risk predictions, trend forecasting, and early warning signals</p>
        </div>
        <div class="flex items-center gap-3">
            <span class="text-xs text-gray-500">Model: <span class="font-semibold text-[#1A365D]">{{ $modelVersion ?? 'v2.1' }}</span></span>
            <span class="text-xs text-gray-500">Last trained: <span class="font-semibold">{{ $lastTrainedAt ?? now()->subDays(3)->format('d M Y') }}</span></span>
            <button class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-xs font-semibold hover:bg-[#2D4A7A] flex items-center gap-2"><span class="material-symbols-outlined text-sm">refresh</span> Refresh Predictions</button>
        </div>
    </div>

    {{-- Prediction Summary --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <x-kpi-card title="Risks Predicted to Escalate" :value="($risksToEscalate ?? 0)" icon="trending_up" color="danger" subtitle="Next 30 days" />
        <x-kpi-card title="Risks Predicted to Improve" :value="($risksToImprove ?? 0)" icon="trending_down" color="success" subtitle="Next 30 days" />
        <x-kpi-card title="Model Accuracy" :value="($modelAccuracy ?? 87.3) . '%'" icon="psychology" color="info" />
        <x-kpi-card title="Early Warnings" :value="($earlyWarnings ?? 0)" icon="notifications_active" color="warning" />
    </div>

    {{-- Model Performance Metrics --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
            <p class="text-xs text-gray-600 mb-1">Accuracy</p>
            <p class="text-2xl font-bold text-[#1A365D]">87.3%</p>
            <div class="w-full bg-gray-200 rounded-full h-1.5 mt-2"><div class="h-1.5 rounded-full bg-[#2D7D46]" style="width: 87.3%"></div></div>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
            <p class="text-xs text-gray-600 mb-1">Precision</p>
            <p class="text-2xl font-bold text-[#1A365D]">84.1%</p>
            <div class="w-full bg-gray-200 rounded-full h-1.5 mt-2"><div class="h-1.5 rounded-full bg-[#2D7D46]" style="width: 84.1%"></div></div>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
            <p class="text-xs text-gray-600 mb-1">Recall</p>
            <p class="text-2xl font-bold text-[#1A365D]">89.7%</p>
            <div class="w-full bg-gray-200 rounded-full h-1.5 mt-2"><div class="h-1.5 rounded-full bg-[#2D7D46]" style="width: 89.7%"></div></div>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
            <p class="text-xs text-gray-600 mb-1">AUC-ROC</p>
            <p class="text-2xl font-bold text-[#1A365D]">0.912</p>
            <div class="w-full bg-gray-200 rounded-full h-1.5 mt-2"><div class="h-1.5 rounded-full bg-[#2D7D46]" style="width: 91.2%"></div></div>
        </div>
    </div>

    {{-- Prediction Chart --}}
    <div class="bg-white rounded-xl border border-gray-200 p-5 mb-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-semibold text-[#1A365D]">Risk Score Trend & Predictions (Next 90 Days)</h3>
            <span class="text-xs text-gray-500">12 months history + 3 month forecast</span>
        </div>
        <canvas id="predictionChart" height="150"></canvas>
    </div>

    {{-- Predicted Escalations --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 bg-red-50"><h3 class="text-sm font-semibold text-red-700">Top Risks - Predicted Escalations</h3></div>
            <div class="divide-y divide-gray-100">
                @forelse (($predictedEscalations ?? []) as $pred)
                    <div class="px-5 py-4 hover:bg-red-50 transition-colors border-l-4 border-l-red-500">
                        <div class="flex items-start justify-between mb-2">
                            <div>
                                <p class="text-xs font-semibold text-[#1A365D]">{{ $pred->risk_code ?? '-' }}</p>
                                <p class="text-xs text-gray-600 mt-1">{{ Str::limit($pred->title ?? '', 40) }}</p>
                            </div>
                            <span class="badge bg-red-100 text-red-700 whitespace-nowrap">{{ $pred->probability ?? 0 }}%</span>
                        </div>
                        <div class="flex items-center gap-2 mt-2">
                            <x-risk-badge :rating="$pred->current_rating ?? 'medium'" />
                            <span class="material-symbols-outlined text-xs text-gray-400">arrow_forward</span>
                            <x-risk-badge :rating="$pred->predicted_rating ?? 'high'" />
                        </div>
                        <div class="mt-2 p-2 bg-gray-50 rounded text-[10px] text-gray-600">
                            <span class="font-semibold text-gray-700">Drivers:</span> {{ $pred->key_drivers ?? '-' }}
                        </div>
                    </div>
                @empty
                    <div class="px-5 py-8 text-center text-gray-400 text-sm">
                        <span class="material-symbols-outlined text-2xl mb-2 block">check_circle</span>
                        No predicted escalations detected
                    </div>
                @endforelse
            </div>
        </div>

        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 bg-green-50"><h3 class="text-sm font-semibold text-green-700">Positive Trends - Predicted Improvements</h3></div>
            <div class="divide-y divide-gray-100">
                @forelse (($predictedImprovements ?? []) as $pred)
                    <div class="px-5 py-4 hover:bg-green-50 transition-colors border-l-4 border-l-green-500">
                        <div class="flex items-start justify-between mb-2">
                            <div>
                                <p class="text-xs font-semibold text-[#1A365D]">{{ $pred->risk_code ?? '-' }}</p>
                                <p class="text-xs text-gray-600 mt-1">{{ Str::limit($pred->title ?? '', 40) }}</p>
                            </div>
                            <span class="badge bg-green-100 text-green-700 whitespace-nowrap">{{ $pred->probability ?? 0 }}%</span>
                        </div>
                        <div class="flex items-center gap-2 mt-2">
                            <x-risk-badge :rating="$pred->current_rating ?? 'high'" />
                            <span class="material-symbols-outlined text-xs text-gray-400">arrow_forward</span>
                            <x-risk-badge :rating="$pred->predicted_rating ?? 'medium'" />
                        </div>
                        <div class="mt-2 p-2 bg-gray-50 rounded text-[10px] text-gray-600">
                            <span class="font-semibold text-gray-700">Contributing factors:</span> {{ $pred->factors ?? '-' }}
                        </div>
                    </div>
                @empty
                    <div class="px-5 py-8 text-center text-gray-400 text-sm">
                        <span class="material-symbols-outlined text-2xl mb-2 block">trending_up</span>
                        No predicted improvements at this time
                    </div>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Early Warning Signals --}}
    <div class="bg-white rounded-xl border border-gray-200 p-5">
        <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Early Warning Signals</h3>
        <div class="space-y-3">
            @forelse (($earlyWarningSignals ?? []) as $signal)
                <div class="flex items-start gap-3 p-3 rounded-lg border {{ ($signal->severity ?? '') === 'high' ? 'border-red-200 bg-red-50' : (($signal->severity ?? '') === 'medium' ? 'border-yellow-200 bg-yellow-50' : 'border-blue-200 bg-blue-50') }}">
                    <span class="material-symbols-outlined text-lg {{ ($signal->severity ?? '') === 'high' ? 'text-red-500' : (($signal->severity ?? '') === 'medium' ? 'text-yellow-500' : 'text-blue-500') }}">{{ ($signal->severity ?? '') === 'high' ? 'error' : 'warning' }}</span>
                    <div class="flex-1">
                        <p class="text-xs font-semibold text-gray-800">{{ $signal->title ?? '' }}</p>
                        <p class="text-xs text-gray-600 mt-1">{{ $signal->description ?? '' }}</p>
                        <div class="flex items-center gap-3 mt-2 text-[10px] text-gray-500">
                            <span>Affected risks: {{ $signal->affected_risks ?? 0 }}</span>
                            <span>Confidence: {{ $signal->confidence ?? 0 }}%</span>
                        </div>
                    </div>
                </div>
            @empty
                <div class="text-center py-6 text-gray-400 text-sm"><span class="material-symbols-outlined text-2xl mb-1 block">notifications_paused</span>No early warning signals detected</div>
            @endforelse
        </div>
    </div>
@endsection

@php
    $predictionDefaults = $predictionChartData ?? ['labels' => [], 'actual' => [], 'predicted' => [], 'upperBound' => [], 'lowerBound' => []];
@endphp

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const predData = @json($predictionDefaults);
    new Chart(document.getElementById('predictionChart'), {
        type: 'line',
        data: { labels: predData.labels, datasets: [
            { label: 'Actual', data: predData.actual, borderColor: '#1A365D', backgroundColor: 'transparent', tension: 0.3, pointRadius: 2 },
            { label: 'Predicted', data: predData.predicted, borderColor: '#C53030', borderDash: [5,5], backgroundColor: 'transparent', tension: 0.3, pointRadius: 2 },
            { label: 'Upper Bound', data: predData.upperBound, borderColor: 'rgba(197,48,48,0.2)', backgroundColor: 'rgba(197,48,48,0.05)', fill: '+1', tension: 0.3, pointRadius: 0 },
            { label: 'Lower Bound', data: predData.lowerBound, borderColor: 'rgba(197,48,48,0.2)', backgroundColor: 'rgba(197,48,48,0.05)', fill: '-1', tension: 0.3, pointRadius: 0 },
        ] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { font: { size: 10 }, usePointStyle: true } } }, scales: { x: { grid: { display: false }, ticks: { font: { size: 10 } } }, y: { grid: { color: '#F0F0F0' }, ticks: { font: { size: 10 } } } } }
    });
});
</script>
@endpush
