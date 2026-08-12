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

@php
    $profile = app(App\Services\RiskScoringService::class)->profileForRisk($assessment->risk);
    $maxScore = $profile->maxScore();
    $rows = $profile->matrix_rows;
    $cols = $profile->matrix_cols;
@endphp

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
            <div class="flex gap-2">
                @if (in_array($assessment->status, ['draft', 'rejected']) && auth()->id() === $assessment->assessor_id)
                    <a href="{{ route('risk.assessments.edit', $assessment) }}" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-1">
                        <span class="material-symbols-outlined text-sm">edit</span> Edit
                    </a>
                @endif
                <a href="{{ route('risk.assessments.index') }}" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Back</a>
            </div>
        </div>
    </div>

    {{-- Approval Workflow --}}
    @if ($assessment->status === 'draft')
        @if (auth()->id() === $assessment->assessor_id)
            <div class="mb-6 bg-blue-50 border border-blue-200 rounded-xl p-4 flex items-center justify-between">
                <div>
                    <p class="text-sm font-semibold text-blue-800">Ready for review?</p>
                    <p class="text-xs text-blue-700 mt-0.5">Submit this assessment to notify the reviewer.</p>
                </div>
                <form method="POST" action="{{ route('risk.assessments.submit', $assessment) }}">
                    @csrf
                    <button type="submit" class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-1">
                        <span class="material-symbols-outlined text-sm">send</span> Submit for Review
                    </button>
                </form>
            </div>
        @endif
    @endif

    @if ($assessment->status === 'in_review')
        @can('approve-risk-assessment', $assessment)
            <div class="mb-6 bg-white rounded-xl border border-blue-200 shadow-sm p-5" x-data="{ rejecting: false }">
                <div class="flex items-center gap-2 mb-3">
                    <span class="material-symbols-outlined text-blue-600">rate_review</span>
                    <h3 class="text-sm font-semibold text-gray-900">Review Required</h3>
                </div>
                <p class="text-sm text-gray-600 mb-4">Approve to finalise this assessment and update the parent risk scores, or reject with a reason so the assessor can rework.</p>

                <form method="POST" action="{{ route('risk.assessments.approve', $assessment) }}" x-show="!rejecting" class="space-y-3">
                    @csrf
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Comments (optional)</label>
                        <textarea name="comments" rows="2" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"></textarea>
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700 flex items-center gap-1">
                            <span class="material-symbols-outlined text-sm">check</span> Approve
                        </button>
                        <button type="button" @click="rejecting = true" class="px-4 py-2 border border-red-300 text-red-600 rounded-lg text-sm font-medium hover:bg-red-50 flex items-center gap-1">
                            <span class="material-symbols-outlined text-sm">close</span> Reject
                        </button>
                    </div>
                </form>

                <form method="POST" action="{{ route('risk.assessments.reject', $assessment) }}" x-show="rejecting" x-cloak class="space-y-3">
                    @csrf
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Reason for rejection <span class="text-red-500">*</span></label>
                        <textarea name="rejection_reason" rows="3" required maxlength="2000" class="w-full border border-red-200 rounded-lg px-3 py-2 text-sm focus:border-red-400" placeholder="Explain what needs to change…"></textarea>
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg text-sm font-medium hover:bg-red-700 flex items-center gap-1">
                            <span class="material-symbols-outlined text-sm">close</span> Confirm Rejection
                        </button>
                        <button type="button" @click="rejecting = false" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg text-sm font-medium hover:bg-gray-50">Cancel</button>
                    </div>
                </form>
            </div>
        @else
            <div class="mb-6 bg-yellow-50 border border-yellow-200 rounded-xl p-4 flex items-start gap-3">
                <span class="material-symbols-outlined text-yellow-600">hourglass_empty</span>
                <div>
                    <p class="text-sm font-semibold text-yellow-800">Pending Reviewer Approval</p>
                    <p class="text-xs text-yellow-700 mt-1">Awaiting review by {{ $assessment->reviewer?->name ?? 'a risk manager' }}.</p>
                </div>
            </div>
        @endcan
    @endif

    @if ($assessment->status === 'rejected')
        <div class="mb-6 bg-red-50 border border-red-200 rounded-xl p-4">
            <div class="flex items-start gap-3 mb-3">
                <span class="material-symbols-outlined text-red-600">block</span>
                <div class="flex-1">
                    <p class="text-sm font-semibold text-red-800">Assessment Rejected</p>
                    @if ($assessment->review_comments)
                        <p class="text-xs text-red-700 mt-1 whitespace-pre-line"><strong>Reason:</strong> {{ $assessment->review_comments }}</p>
                    @endif
                </div>
            </div>
            @can('resubmit-risk-assessment', $assessment)
                <form method="POST" action="{{ route('risk.assessments.resubmit', $assessment) }}">
                    @csrf
                    <button type="submit" class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-1">
                        <span class="material-symbols-outlined text-sm">refresh</span> Return to Draft for rework
                    </button>
                </form>
            @endcan
        </div>
    @endif

    {{--
        The chain, read left to right: inherent risk, what the controls take off
        it, and what is left. Before WP-10a the middle column did not exist and
        the third was typed in by hand.
    --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <x-kpi-card title="Inherent Score" :value="($assessment->overall_score ?? 0) . '/' . $maxScore" icon="analytics" color="primary" />
        <x-kpi-card title="Control Effectiveness"
                    :value="$assessment->control_effectiveness_pct === null ? 'Not rated' : rtrim(rtrim(number_format((float) $assessment->control_effectiveness_pct, 1), '0'), '.') . '%'"
                    icon="shield" color="info" />
        <x-kpi-card title="Residual Score" :value="($assessment->residual_score ?? '—') . '/' . $maxScore" icon="shield_moon" color="warning" />
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
                <div class="flex justify-between"><dt class="text-xs text-gray-500">Likelihood</dt><dd class="text-xs font-medium">{{ $assessment->likelihood ?? '-' }}/{{ $rows }}</dd></div>
                {{--
                    Driven by the scoring profile's declared dimensions. This
                    list used to be six hardcoded rows, the last of which —
                    People Impact — had no column behind it and always read "-".
                --}}
                @foreach ($profile->dimensions() as $dimension)
                    <div class="flex justify-between">
                        <dt class="text-xs text-gray-500">{{ Str::headline($dimension) }} Impact</dt>
                        <dd class="text-xs font-medium">{{ $assessment->{'impact_'.$dimension} ?? '-' }}/{{ $cols }}</dd>
                    </div>
                @endforeach
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

    {{-- ============================================================== --}}
    {{--  Step 2 — Root causes considered                                --}}
    {{-- ============================================================== --}}
    @php $causesConsidered = $assessment->causesConsidered(); @endphp
    <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-semibold text-[#1A365D]">Root Causes <span class="font-normal text-gray-400">· step 2</span></h3>
            @if (empty($assessment->cause_snapshot) && $causesConsidered->isNotEmpty())
                <span class="text-xs text-gray-400">Showing the risk's current causes — this assessment predates cause capture</span>
            @endif
        </div>

        @forelse ($causesConsidered as $cause)
            <div class="flex items-start gap-3 py-2.5 {{ ! $loop->last ? 'border-b border-gray-100' : '' }}">
                <span class="material-symbols-outlined text-base text-gray-400 mt-0.5">{{ ($cause['is_primary'] ?? false) ? 'star' : 'arrow_right' }}</span>
                <div class="min-w-0">
                    <p class="text-sm text-gray-800">{{ $cause['description'] }}</p>
                    <p class="text-xs text-gray-500 mt-0.5">
                        {{ $cause['category'] ?? 'Unclassified' }}
                        @if (! empty($cause['source']))
                            &middot; from {{ App\Models\RiskCause::SOURCES[$cause['source']] ?? $cause['source'] }}
                        @endif
                        @if ($cause['is_primary'] ?? false) &middot; <span class="text-[#D4AF37] font-medium">primary</span> @endif
                    </p>
                </div>
            </div>
        @empty
            <p class="text-sm text-gray-400 py-4">No root causes recorded. Without them the bow-tie has nothing to draw and treatment addresses symptoms.</p>
        @endforelse
    </div>

    {{-- ============================================================== --}}
    {{--  Steps 6-8 — Controls, effectiveness, and the residual derived  --}}
    {{-- ============================================================== --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden mb-6">
        <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-[#1A365D]">Existing Controls &amp; Effectiveness <span class="font-normal text-gray-400">· steps 6–7</span></h3>
            <span class="text-xs text-gray-500">{{ $effectiveness['rated'] }} of {{ $effectiveness['total'] }} rated</span>
        </div>

        @if ($assessment->assessedControls->isEmpty())
            <p class="px-5 py-6 text-sm text-gray-400">
                No controls were rated in this assessment, so the residual score below is not derived from control evidence.
            </p>
        @else
            <table class="data-table">
                <thead><tr><th>Control</th><th>Acts on</th><th>Design</th><th>Operating</th><th class="text-right">Effective</th><th>Weight</th></tr></thead>
                <tbody>
                    @foreach ($assessment->assessedControls as $rated)
                        <tr>
                            <td class="text-xs">
                                <span class="font-mono text-gray-500">{{ $rated->control_code }}</span>
                                <span class="ml-2 text-gray-900">{{ $rated->control_name }}</span>
                                @if ($rated->is_key_control)
                                    <span class="material-symbols-outlined text-sm text-[#D4AF37] align-middle" title="Key control">star</span>
                                @endif
                                @if ($rated->finding)
                                    <p class="text-[11px] text-amber-600 mt-0.5">{{ $rated->finding }}</p>
                                @endif
                            </td>
                            <td class="text-xs text-gray-600">{{ in_array($rated->control?->control_type, ['detective', 'corrective']) ? 'Impact' : 'Likelihood' }}</td>
                            <td class="text-xs">{{ $rated->design_label }}</td>
                            <td class="text-xs">{{ $rated->operating_label }}</td>
                            <td class="text-xs text-right font-semibold">{{ $rated->effectiveness_pct === null ? '—' : (int) $rated->effectiveness_pct.'%' }}</td>
                            <td class="text-xs text-gray-500">{{ rtrim(rtrim((string) $rated->control_weight, '0'), '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <div class="px-5 py-4 bg-gray-50 border-t border-gray-100">
            <h4 class="text-xs font-semibold text-[#1A365D] uppercase tracking-wide mb-3">Residual Risk <span class="font-normal text-gray-400 normal-case">· step 8</span></h4>
            <div class="flex flex-wrap items-center gap-x-6 gap-y-3 text-sm">
                <span class="text-gray-700">
                    Inherent <strong class="text-[#1A365D]">{{ $assessment->overall_score ?? '—' }}</strong>
                </span>
                <span class="material-symbols-outlined text-gray-400 text-base">arrow_forward</span>
                <span class="text-gray-700">
                    less <strong>{{ $assessment->control_effectiveness_pct === null ? 'no rated controls' : rtrim(rtrim(number_format((float) $assessment->control_effectiveness_pct, 1), '0'), '.').'%' }}</strong> control effectiveness
                </span>
                <span class="material-symbols-outlined text-gray-400 text-base">arrow_forward</span>
                <span class="text-gray-700">
                    Residual <strong class="text-[#1A365D]">{{ $assessment->residual_score ?? '—' }}</strong>
                    <span class="text-xs text-gray-500">(L{{ $assessment->residual_likelihood ?? '—' }} × I{{ $assessment->residual_impact ?? '—' }})</span>
                </span>
                @if ($assessment->residual_rating)
                    <x-risk-badge :rating="$assessment->residual_rating" />
                @endif

                @if ($assessment->residualWasOverridden())
                    <span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-800 border border-amber-200">Assessor override</span>
                @elseif ($assessment->residual_source === App\Models\RiskAssessment::RESIDUAL_DERIVED)
                    <span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-green-100 text-green-800 border border-green-200">Derived from controls</span>
                @endif
            </div>

            @if ($assessment->residual_justification)
                <p class="mt-3 text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg p-3">
                    <strong class="block text-xs uppercase tracking-wide mb-1">Override justification</strong>
                    {{ $assessment->residual_justification }}
                </p>
            @endif
        </div>
    </div>

    {{-- ============================================================== --}}
    {{--  Steps 9-13 — Treatment, actions, owners, dates, KRIs           --}}
    {{-- ============================================================== --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        <div class="bg-white rounded-xl border border-gray-200 p-6">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Risk Treatment <span class="font-normal text-gray-400">· step 9</span></h3>
            @if ($assessment->treatment_strategy)
                @php $strategy = App\Models\RiskAssessment::TREATMENT_STRATEGIES[$assessment->treatment_strategy] ?? $assessment->treatment_strategy; @endphp
                @php [$name, $explanation] = array_pad(explode(' — ', $strategy, 2), 2, ''); @endphp
                <p class="text-base font-semibold text-[#1A365D]">{{ Str::headline($name) }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ $explanation }}</p>
            @else
                <p class="text-sm text-gray-400">No treatment strategy recorded.</p>
            @endif
        </div>

        <div class="lg:col-span-2 bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100">
                <h3 class="text-sm font-semibold text-[#1A365D]">Action Plan <span class="font-normal text-gray-400">· steps 10–12</span></h3>
            </div>
            @if ($actionPlans->isEmpty())
                <p class="px-5 py-6 text-sm text-gray-400">No actions on this risk.</p>
            @else
                <table class="data-table">
                    <thead><tr><th>Action</th><th>Owner</th><th>Due</th><th>Status</th><th>Progress</th></tr></thead>
                    <tbody>
                        @foreach ($actionPlans as $plan)
                            <tr>
                                <td class="text-xs">
                                    <a href="{{ route('risk.treatments.show', $plan) }}" class="text-[#1A365D] hover:underline">{{ $plan->action_title }}</a>
                                    <span class="block font-mono text-[11px] text-gray-400">{{ $plan->treatment_code }}</span>
                                </td>
                                <td class="text-xs">{{ $plan->owner?->name ?? 'Unassigned' }}</td>
                                <td class="text-xs {{ $plan->target_date && $plan->target_date->isPast() && $plan->status !== 'completed' ? 'text-red-600 font-medium' : 'text-gray-600' }}">
                                    {{ $plan->target_date?->format('d M Y') ?? '—' }}
                                </td>
                                <td><x-status-badge :status="$plan->status" /></td>
                                <td class="text-xs">{{ $plan->progress_pct }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden mb-6">
        <div class="px-5 py-4 border-b border-gray-100">
            <h3 class="text-sm font-semibold text-[#1A365D]">Key Risk Indicators <span class="font-normal text-gray-400">· step 13</span></h3>
        </div>
        @if ($kris->isEmpty())
            <p class="px-5 py-6 text-sm text-amber-700 bg-amber-50/50">
                No KRI monitors this risk — the chain stops at the action plan, so nothing will signal movement before the next assessment.
            </p>
        @else
            <table class="data-table">
                <thead><tr><th>KRI</th><th>Current</th><th>Status</th><th>Last measured</th></tr></thead>
                <tbody>
                    @foreach ($kris as $kri)
                        <tr>
                            <td class="text-xs">
                                <a href="{{ route('risk.kri.show', $kri) }}" class="text-[#1A365D] hover:underline">{{ $kri->name }}</a>
                                <span class="block font-mono text-[11px] text-gray-400">{{ $kri->kri_code }}</span>
                            </td>
                            <td class="text-xs">{{ $kri->current_value ?? '—' }} {{ $kri->unit_of_measure }}</td>
                            <td><x-status-badge :status="$kri->current_status ?? 'unknown'" /></td>
                            <td class="text-xs text-gray-500">{{ $kri->last_measurement_at?->format('d M Y') ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- Rationale --}}
    <div class="bg-white rounded-xl border border-gray-200 p-6">
        <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Assessment Rationale</h3>
        <p class="text-sm text-gray-700 leading-relaxed whitespace-pre-line">{{ $assessment->assessment_notes ?: 'No rationale provided.' }}</p>
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
@php
    $dimDataDefault = [
        'labels' => ['Financial','Operational','Reputational','Regulatory','Strategic','People'],
        'current' => [0,0,0,0,0,0],
        'previous' => [0,0,0,0,0,0],
    ];
    $compDataDefault = ['labels' => [], 'current' => [], 'previous' => []];
    $dimDataResolved = $dimensionData ?? $dimDataDefault;
    $compDataResolved = $comparisonData ?? $compDataDefault;
@endphp
<script>
window.onPageReady(function() {
    const dimData = @json($dimDataResolved);
    new Chart(document.getElementById('dimensionChart'), {
        type: 'radar',
        data: { labels: dimData.labels, datasets: [
            { label: 'Current', data: dimData.current, borderColor: '#1A365D', backgroundColor: 'rgba(26,54,93,0.1)', pointBackgroundColor: '#1A365D' },
            { label: 'Previous', data: dimData.previous, borderColor: '#D4AF37', backgroundColor: 'rgba(212,175,55,0.1)', pointBackgroundColor: '#D4AF37', borderDash: [5,5] },
        ] },
        options: { responsive: true, maintainAspectRatio: false, scales: { r: { beginAtZero: true, max: 5, ticks: { font: { size: 9 }, stepSize: 1 }, pointLabels: { font: { size: 11 } } } }, plugins: { legend: { position: 'bottom', labels: { font: { size: 10 }, usePointStyle: true } } } }
    });

    @if ($previousAssessment ?? null)
    const compData = @json($compDataResolved);
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
