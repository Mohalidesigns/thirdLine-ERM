@extends('layouts.app')

@section('title', ($risk->risk_code ?? 'Risk') . ': ' . ($risk->title ?? 'Detail') . ' - GRC Risk Management')
@section('page-section', 'Risk Register')
@section('page-title', $risk->risk_code ?? 'Risk Detail')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.register.index') }}" class="hover:text-[#1A365D]">Risk Register</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">{{ $risk->risk_code ?? 'Detail' }}</span>
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
                    <h1 class="text-2xl font-bold text-[#1A365D]">{{ $risk->risk_code }}</h1>
                    <x-risk-badge :rating="$risk->residual_rating ?? 'N/A'" />
                    <x-status-badge :status="$risk->status ?? 'Open'" />
                </div>
                <p class="text-sm text-gray-600 mt-1">{{ $risk->title }}</p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('risk.register.edit', $risk) }}"
                   class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-2 transition-colors">
                    <span class="material-symbols-outlined text-lg">edit</span> Edit
                </a>
                <button onclick="window.print()" class="px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 flex items-center gap-2 transition-colors">
                    <span class="material-symbols-outlined text-lg">download</span> Export
                </button>
            </div>
        </div>
    </div>

    {{-- Risk Status KPI Cards --}}
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <x-kpi-card
            title="Inherent Score"
            :value="($risk->inherent_score ?? 0)"
            icon="error"
            color="danger"
            :subtitle="$risk->inherent_rating ?? 'N/A'"
        />
        <x-kpi-card
            title="Residual Score"
            :value="($risk->residual_score ?? 0)"
            icon="warning"
            color="warning"
            :subtitle="$risk->residual_rating ?? 'N/A'"
        />
        <x-kpi-card
            title="Control Effectiveness"
            :value="($risk->control_effectiveness ?? 0) . '%'"
            icon="trending_down"
            color="success"
            :subtitle="($risk->control_effectiveness ?? 0) >= 75 ? 'Strong' : (($risk->control_effectiveness ?? 0) >= 50 ? 'Adequate' : 'Weak')"
        />
        <x-kpi-card
            title="Treatment Progress"
            :value="($risk->treatment_progress ?? 0) . '%'"
            icon="build_circle"
            color="info"
            :subtitle="($risk->treatment_progress ?? 0) >= 75 ? 'On Track' : (($risk->treatment_progress ?? 0) >= 40 ? 'In Progress' : 'Behind Schedule')"
        />
    </div>

    {{-- Tab Navigation --}}
    <div class="bg-white rounded-t-xl border-b border-gray-200 sticky top-16 z-10">
        <div class="flex gap-8 px-6 overflow-x-auto">
            @foreach ([
                'overview' => 'Overview',
                'assessments' => 'Assessments',
                'controls' => 'Controls',
                'treatment' => 'Treatment Plans',
                'kris' => 'KRIs',
                'audit' => 'Audit Trail',
            ] as $tabId => $tabLabel)
                <button class="tab-btn whitespace-nowrap py-4 text-sm border-b-2 transition-colors {{ $loop->first ? 'border-[#1A365D] text-[#1A365D] font-semibold' : 'border-transparent text-gray-600 hover:text-[#1A365D]' }}"
                        onclick="switchTab('{{ $tabId }}')">
                    {{ $tabLabel }}
                </button>
            @endforeach
        </div>
    </div>

    {{-- Tab Content Container --}}
    <div class="bg-white rounded-b-xl border border-t-0 border-gray-200">

        {{-- ===== OVERVIEW TAB ===== --}}
        <div id="tab-overview" class="tab-pane p-6">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                {{-- Risk Summary (left column) --}}
                <div class="lg:col-span-2">
                    <div class="mb-6">
                        <h3 class="text-sm font-semibold text-gray-700 mb-4">Risk Summary</h3>
                        <div class="space-y-4">
                            <div>
                                <p class="text-xs text-gray-500 font-medium">RISK ID</p>
                                <p class="text-sm font-semibold text-[#1A365D]">{{ $risk->risk_code }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500 font-medium">RISK STATEMENT</p>
                                <p class="text-sm text-gray-700">{{ $risk->description }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500 font-medium">RISK OWNER</p>
                                @if ($risk->owner)
                                    <div class="flex items-center gap-2 mt-1">
                                        <div class="w-8 h-8 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center text-[10px] font-bold">
                                            {{ strtoupper(substr($risk->owner->name ?? '', 0, 1)) }}{{ strtoupper(substr($risk->owner->name ?? '', strpos($risk->owner->name ?? ' ', ' ') + 1, 1)) }}
                                        </div>
                                        <span class="text-sm">{{ $risk->owner->name }}{{ $risk->owner->role ? ', ' . $risk->owner->role : '' }}</span>
                                    </div>
                                @else
                                    <p class="text-sm text-gray-400">Unassigned</p>
                                @endif
                            </div>
                            <div>
                                <p class="text-xs text-gray-500 font-medium">CATEGORY & BUSINESS UNIT</p>
                                <div class="flex gap-2 mt-1">
                                    <span class="badge bg-blue-100 text-blue-700">{{ $risk->category->name ?? $risk->category ?? '-' }}</span>
                                    <span class="badge bg-gray-100 text-gray-700">{{ $risk->businessUnit->name ?? $risk->business_unit ?? '-' }}</span>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <p class="text-xs text-gray-500 font-medium">DATE IDENTIFIED</p>
                                    <p class="text-sm text-gray-700">{{ $risk->date_identified ?? ($risk->created_at ? $risk->created_at->format('Y-m-d') : '-') }}</p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500 font-medium">RISK SOURCE</p>
                                    <p class="text-sm text-gray-700">{{ $risk->risk_source ?? '-' }}</p>
                                </div>
                            </div>
                            @if ($risk->steward)
                                <div>
                                    <p class="text-xs text-gray-500 font-medium">RISK STEWARD</p>
                                    <p class="text-sm text-gray-700">{{ $risk->steward->name ?? '-' }}</p>
                                </div>
                            @endif
                        </div>
                    </div>

                    {{-- Risk Appetite Gauge --}}
                    <div class="bg-gray-50 rounded-lg p-4">
                        <p class="text-xs text-gray-500 font-medium mb-3">RISK APPETITE STATUS</p>
                        <div class="flex items-center gap-4">
                            <div class="flex-1">
                                @php
                                    $appetiteValue = $risk->appetite_value ?? 0;
                                    $exposureValue = $risk->financial_exposure ?? 0;
                                    $appetiteExceeds = $exposureValue > $appetiteValue && $appetiteValue > 0;
                                    $appetitePct = $appetiteValue > 0 ? min(round(($exposureValue / $appetiteValue) * 100), 200) : 0;
                                @endphp
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-xs font-semibold">
                                        @if ($appetiteValue > 0)
                                            Appetite: &#8358;{{ number_format($appetiteValue) }} | Exposure: &#8358;{{ number_format($exposureValue) }}
                                        @else
                                            No appetite threshold set
                                        @endif
                                    </span>
                                    @if ($appetiteValue > 0)
                                        <span class="badge {{ $appetiteExceeds ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700' }}">
                                            {{ $appetiteExceeds ? 'Exceeds' : 'Within' }}
                                        </span>
                                    @endif
                                </div>
                                <div class="w-full bg-gray-300 rounded-full h-2">
                                    <div class="{{ $appetiteExceeds ? 'bg-red-500' : 'bg-green-500' }} h-2 rounded-full" style="width:{{ min($appetitePct, 100) }}%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Key Metrics Card (right column) --}}
                <div class="bg-gradient-to-br from-[#1A365D] to-[#2D4A7A] text-white rounded-lg p-6">
                    <h3 class="text-sm font-semibold mb-4">Key Metrics</h3>
                    <div class="space-y-4">
                        <div>
                            <p class="text-xs text-white/70">Inherent Score</p>
                            <p class="text-2xl font-bold">{{ $risk->inherent_score ?? 0 }}</p>
                            <p class="text-xs text-white/70">{{ $risk->inherent_rating ?? 'N/A' }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-white/70">Residual Score</p>
                            <p class="text-2xl font-bold">{{ $risk->residual_score ?? 0 }}</p>
                            <p class="text-xs text-white/70">{{ $risk->residual_rating ?? 'N/A' }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-white/70">Control Effectiveness</p>
                            <p class="text-2xl font-bold">{{ $risk->control_effectiveness ?? 0 }}%</p>
                            <p class="text-xs text-white/70">{{ ($risk->control_effectiveness ?? 0) >= 75 ? 'Strong' : (($risk->control_effectiveness ?? 0) >= 50 ? 'Adequate' : 'Weak') }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-white/70">Risk Velocity</p>
                            <p class="text-2xl font-bold">{{ ucfirst(str_replace('_', ' ', $risk->risk_velocity ?? 'N/A')) }}</p>
                        </div>
                        @if ($risk->target_score ?? null)
                            <div>
                                <p class="text-xs text-white/70">Target Score</p>
                                <p class="text-2xl font-bold">{{ $risk->target_score }}</p>
                                <p class="text-xs text-white/70">{{ $risk->target_rating ?? '' }}</p>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- ===== ASSESSMENTS TAB ===== --}}
        <div id="tab-assessments" class="tab-pane p-6 hidden">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                {{-- 5x5 Risk Matrix --}}
                <div>
                    <h3 class="text-sm font-semibold text-gray-700 mb-4">Risk Position (5x5 Matrix)</h3>
                    <div class="overflow-x-auto">
                        @php
                            $inherentL = $risk->inherent_likelihood ?? 0;
                            $inherentI = $risk->inherent_impact ?? 0;
                            $residualL = $risk->residual_likelihood ?? 0;
                            $residualI = $risk->residual_impact ?? 0;
                            $matrixColors = [
                                5 => ['bg-yellow-300', 'bg-orange-400', 'bg-red-500', 'bg-red-600', 'bg-red-800'],
                                4 => ['bg-green-400', 'bg-yellow-400', 'bg-orange-400', 'bg-red-500', 'bg-red-600'],
                                3 => ['bg-green-400', 'bg-green-400', 'bg-yellow-400', 'bg-orange-400', 'bg-red-500'],
                                2 => ['bg-green-400', 'bg-green-400', 'bg-green-400', 'bg-yellow-400', 'bg-orange-400'],
                                1 => ['bg-green-300', 'bg-green-400', 'bg-green-400', 'bg-yellow-400', 'bg-orange-400'],
                            ];
                        @endphp
                        <table class="w-full">
                            <thead>
                                <tr>
                                    <th class="w-24 p-2 text-xs text-gray-500"></th>
                                    @for ($i = 1; $i <= 5; $i++)
                                        <th class="p-2 text-xs text-center text-gray-500">{{ $i }}</th>
                                    @endfor
                                </tr>
                            </thead>
                            <tbody>
                                @for ($l = 5; $l >= 1; $l--)
                                    <tr>
                                        <td class="p-2 text-xs font-medium text-gray-600">{{ $l }}</td>
                                        @for ($i = 1; $i <= 5; $i++)
                                            @php
                                                $cellColor = $matrixColors[$l][$i - 1];
                                                $isInherent = ($l == $inherentL && $i == $inherentI);
                                                $isResidual = ($l == $residualL && $i == $residualI);
                                                $marker = '';
                                                if ($isInherent && $isResidual) $marker = 'I/R';
                                                elseif ($isInherent) $marker = 'I';
                                                elseif ($isResidual) $marker = 'R';
                                            @endphp
                                            <td class="p-1">
                                                <div class="{{ $cellColor }} rounded p-1 h-8 text-center text-xs font-bold text-white flex items-center justify-center {{ $marker ? 'ring-2 ring-white ring-offset-1' : '' }}">
                                                    {{ $marker }}
                                                </div>
                                            </td>
                                        @endfor
                                    </tr>
                                @endfor
                            </tbody>
                        </table>
                        <p class="text-xs text-gray-500 mt-3">I = Inherent ({{ $inherentL }},{{ $inherentI }}) | R = Residual ({{ $residualL }},{{ $residualI }})</p>
                    </div>
                </div>

                {{-- Assessment History --}}
                <div>
                    <h3 class="text-sm font-semibold text-gray-700 mb-4">Assessment History</h3>
                    @if ($risk->assessments && $risk->assessments->count() > 0)
                        <div class="space-y-3">
                            @foreach ($risk->assessments->sortByDesc('assessment_date')->take(10) as $assessment)
                                <div class="border border-gray-200 rounded-lg p-3">
                                    <p class="text-xs text-gray-500">{{ $assessment->period ?? ($assessment->assessment_date ?? '') }}</p>
                                    <p class="font-semibold text-sm">
                                        Inherent: {{ $assessment->inherent_score ?? '-' }} | Residual: {{ $assessment->residual_score ?? '-' }}
                                    </p>
                                    <p class="text-xs text-gray-600 mt-1">{{ $assessment->notes ?? $assessment->assessor ?? '' }}</p>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="flex flex-col items-center gap-2 py-8">
                            <span class="material-symbols-outlined text-3xl text-gray-300">assessment</span>
                            <p class="text-sm text-gray-400">No assessment history available.</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- ===== CONTROLS TAB ===== --}}
        <div id="tab-controls" class="tab-pane p-6 hidden">
            <h3 class="text-sm font-semibold text-gray-700 mb-4">Linked Controls</h3>
            @if ($risk->controls && $risk->controls->count() > 0)
                <div class="overflow-x-auto">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Control ID</th>
                                <th>Control Name</th>
                                <th>Type</th>
                                <th>Effectiveness</th>
                                <th>Reliance</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($risk->controls as $control)
                                <tr>
                                    <td class="font-medium text-[#1A365D]">{{ $control->control_code ?? $control->id }}</td>
                                    <td>{{ $control->name ?? $control->title ?? '-' }}</td>
                                    <td>{{ $control->type ?? '-' }}</td>
                                    <td>
                                        <div class="flex items-center gap-2">
                                            <div class="flex-1 bg-gray-200 rounded-full h-2 max-w-[100px]">
                                                <div class="bg-[#1A365D] h-2 rounded-full" style="width:{{ $control->effectiveness ?? 0 }}%"></div>
                                            </div>
                                            <span class="text-xs text-gray-600">{{ $control->effectiveness ?? 0 }}%</span>
                                        </div>
                                    </td>
                                    <td>
                                        @php
                                            $reliance = $control->reliance ?? 'medium';
                                            $relianceClass = match(strtolower($reliance)) {
                                                'high' => 'bg-green-100 text-green-700',
                                                'medium' => 'bg-blue-100 text-blue-700',
                                                'low' => 'bg-yellow-100 text-yellow-700',
                                                default => 'bg-gray-100 text-gray-700',
                                            };
                                        @endphp
                                        <span class="badge {{ $relianceClass }}">{{ ucfirst($reliance) }}</span>
                                    </td>
                                    <td><x-status-badge :status="$control->status ?? 'Active'" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="flex flex-col items-center gap-2 py-8">
                    <span class="material-symbols-outlined text-3xl text-gray-300">verified_user</span>
                    <p class="text-sm text-gray-400">No controls linked to this risk.</p>
                </div>
            @endif
        </div>

        {{-- ===== TREATMENT PLANS TAB ===== --}}
        <div id="tab-treatment" class="tab-pane p-6 hidden">
            @if ($risk->treatmentPlans && $risk->treatmentPlans->count() > 0)
                @foreach ($risk->treatmentPlans as $plan)
                    <div class="mb-8">
                        <h3 class="text-sm font-semibold text-gray-700 mb-4">Treatment Plan {{ $plan->plan_code ?? $plan->id }}</h3>
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                            <div class="bg-blue-50 rounded-lg p-4">
                                <p class="text-xs text-gray-600 mb-1">Strategy</p>
                                <p class="font-semibold text-sm">{{ ucfirst($plan->strategy ?? '-') }}</p>
                            </div>
                            <div class="bg-orange-50 rounded-lg p-4">
                                <p class="text-xs text-gray-600 mb-1">Overall Progress</p>
                                <p class="font-semibold text-sm">{{ $plan->progress ?? 0 }}%</p>
                            </div>
                            <div class="bg-green-50 rounded-lg p-4">
                                <p class="text-xs text-gray-600 mb-1">Budget</p>
                                <p class="font-semibold text-sm">{{ $plan->budget_display ?? '-' }}</p>
                            </div>
                            <div class="bg-yellow-50 rounded-lg p-4">
                                <p class="text-xs text-gray-600 mb-1">Target Residual</p>
                                <p class="font-semibold text-sm">{{ $plan->target_score ?? '-' }}</p>
                            </div>
                        </div>

                        @if ($plan->actions && $plan->actions->count() > 0)
                            <h4 class="text-sm font-semibold text-gray-700 mb-3">Action Items</h4>
                            <div class="overflow-x-auto">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Action ID</th>
                                            <th>Description</th>
                                            <th>Owner</th>
                                            <th>Due Date</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($plan->actions as $action)
                                            <tr>
                                                <td class="font-medium text-[#1A365D]">{{ $action->action_code ?? $action->id }}</td>
                                                <td>{{ $action->description ?? $action->title ?? '-' }}</td>
                                                <td>{{ $action->owner->name ?? $action->owner ?? '-' }}</td>
                                                <td>{{ $action->due_date ?? '-' }}</td>
                                                <td><x-status-badge :status="$action->status ?? 'Pending'" type="treatment" /></td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                @endforeach
            @else
                <div class="flex flex-col items-center gap-2 py-8">
                    <span class="material-symbols-outlined text-3xl text-gray-300">healing</span>
                    <p class="text-sm text-gray-400">No treatment plans created for this risk.</p>
                    <a href="{{ url('/risk/treatments/create?risk_id=' . $risk->id) }}"
                       class="text-sm text-[#1A365D] font-medium hover:underline">Create Treatment Plan</a>
                </div>
            @endif
        </div>

        {{-- ===== KRIs TAB ===== --}}
        <div id="tab-kris" class="tab-pane p-6 hidden">
            <h3 class="text-sm font-semibold text-gray-700 mb-4">Key Risk Indicators</h3>
            @if ($risk->kris && $risk->kris->count() > 0)
                <div class="overflow-x-auto">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>KRI ID</th>
                                <th>KRI Name</th>
                                <th>Current Value</th>
                                <th>Threshold</th>
                                <th>Status</th>
                                <th>Trend</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($risk->kris as $kri)
                                <tr>
                                    <td class="font-medium text-[#1A365D]">{{ $kri->kri_code ?? $kri->id }}</td>
                                    <td>{{ $kri->name ?? $kri->title ?? '-' }}</td>
                                    <td class="font-semibold">{{ $kri->current_value ?? '-' }}</td>
                                    <td class="text-xs">{{ $kri->threshold_display ?? $kri->threshold ?? '-' }}</td>
                                    <td>
                                        @php
                                            $kriStatus = strtolower($kri->breach_status ?? $kri->status ?? 'green');
                                            $kriDotColor = match($kriStatus) {
                                                'red', 'breach' => 'bg-red-500',
                                                'amber', 'warning' => 'bg-orange-500',
                                                default => 'bg-green-500',
                                            };
                                        @endphp
                                        <div class="flex items-center gap-2">
                                            <span class="w-3 h-3 rounded-full {{ $kriDotColor }} inline-block"></span>
                                            <span class="text-xs">{{ ucfirst($kriStatus) }}</span>
                                        </div>
                                    </td>
                                    <td>
                                        @if (($kri->trend ?? null) === 'up')
                                            <span class="material-symbols-outlined text-sm text-red-500">arrow_upward</span>
                                        @elseif (($kri->trend ?? null) === 'down')
                                            <span class="material-symbols-outlined text-sm text-green-500">arrow_downward</span>
                                        @else
                                            <span class="material-symbols-outlined text-sm text-gray-400">remove</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="flex flex-col items-center gap-2 py-8">
                    <span class="material-symbols-outlined text-3xl text-gray-300">speed</span>
                    <p class="text-sm text-gray-400">No KRIs linked to this risk.</p>
                    <a href="{{ url('/risk/kri?risk_id=' . $risk->id) }}"
                       class="text-sm text-[#1A365D] font-medium hover:underline">Link a KRI</a>
                </div>
            @endif
        </div>

        {{-- ===== AUDIT TRAIL TAB ===== --}}
        <div id="tab-audit" class="tab-pane p-6 hidden">
            <h3 class="text-sm font-semibold text-gray-700 mb-4">Activity Timeline</h3>
            @if ($risk->auditTrail && $risk->auditTrail->count() > 0)
                <div class="space-y-4">
                    @foreach ($risk->auditTrail->sortByDesc('created_at') as $entry)
                        @php
                            $auditType = strtolower($entry->action ?? $entry->type ?? 'update');
                            $auditConfig = match(true) {
                                str_contains($auditType, 'create') => ['bg-purple-100', 'text-purple-600', 'add_circle'],
                                str_contains($auditType, 'update') || str_contains($auditType, 'edit') => ['bg-blue-100', 'text-blue-600', 'edit'],
                                str_contains($auditType, 'complet') => ['bg-green-100', 'text-green-600', 'check_circle'],
                                str_contains($auditType, 'escalat') || str_contains($auditType, 'warn') => ['bg-orange-100', 'text-orange-600', 'warning'],
                                str_contains($auditType, 'delete') || str_contains($auditType, 'close') => ['bg-red-100', 'text-red-600', 'cancel'],
                                default => ['bg-gray-100', 'text-gray-600', 'info'],
                            };
                        @endphp
                        <div class="flex gap-4 pb-4 border-b border-gray-200">
                            <div class="w-8 h-8 rounded-full {{ $auditConfig[0] }} flex items-center justify-center flex-shrink-0 {{ $auditConfig[1] }}">
                                <span class="material-symbols-outlined text-sm">{{ $auditConfig[2] }}</span>
                            </div>
                            <div>
                                <p class="font-semibold text-sm">{{ $entry->title ?? ucfirst($entry->action ?? 'Activity') }}</p>
                                <p class="text-xs text-gray-600">{{ $entry->description ?? $entry->details ?? '' }}</p>
                                <p class="text-xs text-gray-500 mt-1">
                                    {{ $entry->created_at ? $entry->created_at->format('Y-m-d H:i') : '' }}
                                    @if ($entry->user)
                                        by {{ $entry->user->name ?? $entry->user }}
                                    @endif
                                </p>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="flex flex-col items-center gap-2 py-8">
                    <span class="material-symbols-outlined text-3xl text-gray-300">history</span>
                    <p class="text-sm text-gray-400">No audit trail entries recorded yet.</p>
                </div>
            @endif
        </div>
    </div>

@endsection

@push('scripts')
<script>
    /**
     * Tab switching logic for the risk detail view.
     */
    function switchTab(tabName) {
        // Hide all tab panes
        document.querySelectorAll('.tab-pane').forEach(function(pane) {
            pane.classList.add('hidden');
        });

        // Show selected tab pane
        var targetPane = document.getElementById('tab-' + tabName);
        if (targetPane) {
            targetPane.classList.remove('hidden');
        }

        // Update button styling
        document.querySelectorAll('.tab-btn').forEach(function(btn) {
            btn.classList.remove('border-[#1A365D]', 'text-[#1A365D]', 'font-semibold');
            btn.classList.add('border-transparent', 'text-gray-600');
        });

        // Activate the clicked button
        if (event && event.target) {
            event.target.classList.remove('border-transparent', 'text-gray-600');
            event.target.classList.add('border-[#1A365D]', 'text-[#1A365D]', 'font-semibold');
        }
    }

    // Handle direct URL with hash
    document.addEventListener('DOMContentLoaded', function() {
        var hash = window.location.hash.replace('#', '');
        if (hash && document.getElementById('tab-' + hash)) {
            switchTab(hash);
        }
    });
</script>
@endpush
