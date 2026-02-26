@extends('layouts.app')

@php
    $planTitle = $plan->treatment_title ?? $plan->action_title ?? 'Treatment Plan';
    $planDescription = $plan->treatment_description ?? $plan->action_description ?? 'No description provided.';
    $planProgress = $plan->progress_percentage ?? $plan->progress_pct ?? 0;
    $planBudget = $plan->estimated_cost ?? $plan->cost_estimate_ngn ?? 0;
    $planActualSpend = $plan->actual_cost ?? $plan->actual_cost_ngn ?? 0;
    $planStrategy = $plan->treatment_type ?? $plan->strategy ?? '-';
    $planCode = $plan->treatment_code ?? $plan->uuid ?? '-';
@endphp

@section('title', $planTitle . ' - GRC Risk Management')
@section('page-section', 'Treatment Plans')
@section('page-title', $planTitle)

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.treatments.index') }}" class="hover:text-[#1A365D]">Treatment Plans</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">{{ Str::limit($planTitle, 40) }}</span>
@endsection

@section('content')
    {{-- Flash Messages --}}
    @if (session('success'))
        <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-xl flex items-center gap-3">
            <span class="material-symbols-outlined text-green-600">check_circle</span>
            <span class="text-sm text-green-700">{{ session('success') }}</span>
            <button onclick="this.parentElement.remove()" class="ml-auto text-green-400 hover:text-green-600">
                <span class="material-symbols-outlined text-lg">close</span>
            </button>
        </div>
    @endif

    {{-- Page Header --}}
    <div class="mb-6">
        <div class="flex items-center justify-between">
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-2xl font-bold text-[#1A365D]">{{ $planTitle }}</h1>
                    <x-status-badge :status="$plan->status ?? 'in progress'" type="treatment" />
                    <x-risk-badge :rating="$plan->priority ?? 'medium'" />
                </div>
                <p class="text-sm text-gray-500 mt-1">Treatment plan for {{ $plan->risk->risk_code ?? 'N/A' }} &middot; Created {{ $plan->created_at?->format('M d, Y') }}</p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('risk.treatments.edit', $plan) }}" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2 transition-colors">
                    <span class="material-symbols-outlined text-lg">edit</span> Edit
                </a>
                <a href="{{ route('risk.treatments.index') }}" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 transition-colors">
                    <span class="material-symbols-outlined text-lg">arrow_back</span> Back
                </a>
            </div>
        </div>
    </div>

    {{-- Progress Overview --}}
    <div class="grid grid-cols-1 lg:grid-cols-4 gap-4 mb-6">
        <x-kpi-card title="Overall Progress" :value="$planProgress . '%'" icon="speed" color="primary" />
        <x-kpi-card title="Budget Allocated" :value="'₦' . number_format($planBudget)" icon="account_balance" color="warning" />
        <x-kpi-card title="Actual Spend" :value="'₦' . number_format($planActualSpend)" icon="payments" :color="$planActualSpend > $planBudget ? 'danger' : 'success'" />
        <x-kpi-card title="Days Remaining" :value="$plan->target_date ? (now()->gt($plan->target_date) ? 'Overdue by ' . now()->diffInDays($plan->target_date) : now()->diffInDays($plan->target_date)) : 'N/A'" icon="schedule" :color="$plan->target_date && now()->gt($plan->target_date) ? 'danger' : 'success'" />
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        {{-- Plan Details --}}
        <div class="lg:col-span-2 space-y-6">
            {{-- Description --}}
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Plan Description</h3>
                <p class="text-sm text-gray-700 leading-relaxed">{{ $planDescription }}</p>
            </div>

            {{-- Progress Bar --}}
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Progress Tracking</h3>
                <div class="mb-4">
                    <div class="flex justify-between text-xs text-gray-600 mb-2">
                        <span>Progress</span>
                        <span>{{ $planProgress }}%</span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-3">
                        <div class="h-3 rounded-full transition-all duration-500 {{ $planProgress >= 75 ? 'bg-green-500' : ($planProgress >= 50 ? 'bg-yellow-500' : ($planProgress >= 25 ? 'bg-orange-500' : 'bg-red-500')) }}"
                             style="width: {{ $planProgress }}%"></div>
                    </div>
                </div>
                @if ($plan->progress_notes)
                    <p class="text-xs text-gray-500 mt-2">{{ $plan->progress_notes }}</p>
                @endif
                <canvas id="progressChart" height="120"></canvas>
            </div>

            {{-- Milestones --}}
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Milestones</h3>
                @php
                    $milestones = $plan->milestones;
                    if (is_string($milestones)) {
                        $milestones = json_decode($milestones);
                    }
                @endphp
                @if (is_array($milestones) && count($milestones) > 0)
                    @foreach ($milestones as $milestone)
                        <div class="flex items-center gap-4 p-3 border-b border-gray-100 last:border-0">
                            <div class="flex-shrink-0">
                                @if (is_object($milestone) && ($milestone->completed ?? false))
                                    <span class="material-symbols-outlined text-green-500">check_circle</span>
                                @else
                                    <span class="material-symbols-outlined text-gray-300">radio_button_unchecked</span>
                                @endif
                            </div>
                            <div class="flex-1">
                                <p class="text-sm font-medium text-gray-700">{{ is_object($milestone) ? ($milestone->title ?? $milestone->name ?? '') : $milestone }}</p>
                            </div>
                        </div>
                    @endforeach
                @elseif (is_string($plan->milestones) && !empty($plan->milestones))
                    <p class="text-sm text-gray-600">{{ $plan->milestones }}</p>
                @else
                    <div class="text-center py-6 text-gray-400 text-sm">
                        <span class="material-symbols-outlined text-2xl mb-1 block">flag</span>
                        No milestones defined
                    </div>
                @endif
            </div>

            {{-- Cost vs Budget --}}
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Cost vs Budget</h3>
                <canvas id="costChart" height="120"></canvas>
            </div>
        </div>

        {{-- Sidebar --}}
        <div class="space-y-6">
            {{-- Plan Info --}}
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Plan Information</h3>
                <dl class="space-y-3">
                    <div class="flex justify-between">
                        <dt class="text-xs text-gray-500">Strategy</dt>
                        <dd class="text-xs font-medium">{{ ucfirst($planStrategy) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-xs text-gray-500">Priority</dt>
                        <dd><x-risk-badge :rating="$plan->priority ?? 'medium'" /></dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-xs text-gray-500">Owner</dt>
                        <dd class="text-xs font-medium">{{ $plan->owner->name ?? '-' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-xs text-gray-500">Target Date</dt>
                        <dd class="text-xs">{{ $plan->target_date?->format('d M Y') ?? ($plan->target_completion_date ? \Carbon\Carbon::parse($plan->target_completion_date)->format('d M Y') : '-') }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-xs text-gray-500">Completion Date</dt>
                        <dd class="text-xs">{{ $plan->completion_date?->format('d M Y') ?? ($plan->actual_completion_date ? \Carbon\Carbon::parse($plan->actual_completion_date)->format('d M Y') : '-') }}</dd>
                    </div>
                    @if ($plan->expected_residual_likelihood && $plan->expected_residual_impact)
                        @php
                            $residualScore = $plan->expected_residual_likelihood * $plan->expected_residual_impact;
                            $residualRating = $residualScore >= 20 ? 'critical' : ($residualScore >= 12 ? 'high' : ($residualScore >= 5 ? 'medium' : 'low'));
                        @endphp
                        <div class="flex justify-between">
                            <dt class="text-xs text-gray-500">Expected Residual</dt>
                            <dd><x-risk-badge :rating="$residualRating" /></dd>
                        </div>
                    @endif
                </dl>
            </div>

            {{-- Linked Risk --}}
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Linked Risk</h3>
                @if ($plan->risk)
                    <a href="{{ route('risk.register.show', $plan->risk) }}" class="block p-3 bg-blue-50 rounded-lg hover:bg-blue-100 transition-colors">
                        <p class="text-sm font-semibold text-[#1A365D]">{{ $plan->risk->risk_code }}</p>
                        <p class="text-xs text-gray-600 mt-1">{{ $plan->risk->title ?? $plan->risk->risk_title ?? '' }}</p>
                        <div class="flex items-center gap-2 mt-2">
                            <x-risk-badge :rating="$plan->risk->residual_rating ?? 'N/A'" />
                            <x-status-badge :status="$plan->risk->status ?? 'open'" />
                        </div>
                    </a>
                @else
                    <p class="text-sm text-gray-400">No linked risk</p>
                @endif
            </div>

            {{-- Dependencies --}}
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Dependencies</h3>
                <p class="text-sm text-gray-600">{{ $plan->dependencies ?? 'No dependencies defined.' }}</p>
            </div>

            {{-- Success Criteria --}}
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Success Criteria</h3>
                <p class="text-sm text-gray-600">{{ $plan->success_criteria ?? 'No success criteria defined.' }}</p>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Progress over time chart
    const progressData = @json($progressHistory ?? ['labels' => [], 'values' => []]);
    if (document.getElementById('progressChart') && progressData.labels.length > 0) {
        new Chart(document.getElementById('progressChart'), {
            type: 'line',
            data: {
                labels: progressData.labels,
                datasets: [{
                    label: 'Progress %',
                    data: progressData.values,
                    borderColor: '#1A365D',
                    backgroundColor: 'rgba(26,54,93,0.1)',
                    tension: 0.3,
                    fill: true,
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false }, ticks: { font: { size: 10 } } },
                    y: { beginAtZero: true, max: 100, grid: { color: '#F0F0F0' }, ticks: { font: { size: 10 }, callback: v => v + '%' } }
                }
            }
        });
    }

    // Cost vs Budget chart
    const costData = @json($costData ?? ['budget' => 0, 'actual' => 0]);
    if (document.getElementById('costChart')) {
        new Chart(document.getElementById('costChart'), {
            type: 'bar',
            data: {
                labels: ['Budget vs Actual'],
                datasets: [
                    { label: 'Budget', data: [costData.budget], backgroundColor: '#1A365D', borderRadius: 4 },
                    { label: 'Actual Spend', data: [costData.actual], backgroundColor: costData.actual > costData.budget ? '#C53030' : '#2D7D46', borderRadius: 4 }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false, indexAxis: 'y',
                plugins: {
                    legend: { position: 'bottom', labels: { font: { size: 10 }, usePointStyle: true } },
                    tooltip: { callbacks: { label: ctx => ctx.dataset.label + ': ₦' + ctx.parsed.x.toLocaleString() } }
                },
                scales: {
                    x: { beginAtZero: true, grid: { color: '#F0F0F0' }, ticks: { font: { size: 10 }, callback: v => '₦' + (v/1000000).toFixed(1) + 'M' } },
                    y: { grid: { display: false } }
                }
            }
        });
    }
});
</script>
@endpush
