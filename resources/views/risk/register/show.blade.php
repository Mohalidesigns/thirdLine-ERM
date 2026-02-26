@extends('layouts.app')

@section('title', $risk->risk_code . ' - Risk Detail - GRC Risk Management')
@section('page-section', 'Risk Register')
@section('page-title', $risk->risk_code)

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="text-gray-500 hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.register.index') }}" class="text-gray-500 hover:text-[#1A365D]">Risk Register</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">{{ $risk->risk_code }}</span>
@endsection

@section('content')
    {{-- Success Flash --}}
    @if (session('success'))
        <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-xl flex items-center gap-3">
            <span class="material-symbols-outlined text-green-600">check_circle</span>
            <span class="text-sm text-green-700">{{ session('success') }}</span>
        </div>
    @endif

    {{-- Page Header --}}
    <div class="mb-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-[#1A365D]">{{ $risk->risk_code }}</h1>
                <p class="text-sm text-gray-600 mt-1">{{ $risk->title }}</p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('risk.register.edit', $risk) }}" class="px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">edit</span> Edit
                </a>
                <a href="{{ route('risk.register.index') }}" class="px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Back to Register
                </a>
            </div>
        </div>
    </div>

    {{-- KPI Cards --}}
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <x-kpi-card
            icon="error"
            title="Inherent Score"
            :value="($risk->inherent_score ?? 0) . '/25'"
            :subtitle="$risk->inherent_rating ?? 'Not Assessed'"
        />
        <x-kpi-card
            icon="warning"
            title="Residual Score"
            :value="($risk->residual_score ?? 0) . '/25'"
            :subtitle="$risk->residual_rating ?? 'Not Assessed'"
        />
        <x-kpi-card
            icon="trending_down"
            title="Control Effectiveness"
            :value="($risk->control_effectiveness_pct ?? 0) . '%'"
            subtitle="Effectiveness"
        />
        <x-kpi-card
            icon="build_circle"
            title="Treatment"
            :value="ucfirst($risk->treatment_strategy ?? 'None')"
            :subtitle="ucfirst($risk->status ?? 'active')"
        />
    </div>

    {{-- Tabbed Interface --}}
    <div x-data="{ activeTab: 'overview' }" x-cloak>
        {{-- Tab Navigation --}}
        <div class="bg-white rounded-t-xl border-b border-gray-200">
            <div class="flex gap-8 px-6">
                @foreach (['overview' => 'Overview', 'assessment' => 'Assessment', 'controls' => 'Controls', 'treatment' => 'Treatment', 'kris' => 'KRIs', 'history' => 'History'] as $tab => $label)
                    <button @click="activeTab = '{{ $tab }}'"
                            :class="activeTab === '{{ $tab }}' ? 'border-[#1A365D] text-[#1A365D] font-semibold' : 'border-transparent text-gray-600 hover:text-[#1A365D]'"
                            class="border-b-2 py-4 text-sm transition-colors">
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Tab Content --}}
        <div class="bg-white rounded-b-xl border border-t-0 border-gray-200 p-6">

            {{-- Overview Tab --}}
            <div x-show="activeTab === 'overview'">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    {{-- Risk Summary --}}
                    <div class="lg:col-span-2">
                        <h3 class="text-sm font-semibold text-gray-700 mb-4">Risk Summary</h3>
                        <div class="space-y-4">
                            <div>
                                <p class="text-xs text-gray-500 font-medium">RISK ID</p>
                                <p class="text-sm font-semibold text-[#1A365D]">{{ $risk->risk_code }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500 font-medium">RISK DESCRIPTION</p>
                                <p class="text-sm text-gray-700">{{ $risk->description }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500 font-medium">RISK OWNER</p>
                                @if ($risk->riskOwner)
                                    <div class="flex items-center gap-2 mt-1">
                                        <div class="w-8 h-8 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center text-[10px] font-bold">
                                            {{ collect(explode(' ', $risk->riskOwner->name))->map(fn($n) => strtoupper(substr($n, 0, 1)))->take(2)->join('') }}
                                        </div>
                                        <span class="text-sm">{{ $risk->riskOwner->name }}</span>
                                    </div>
                                @else
                                    <p class="text-sm text-gray-400 mt-1">Unassigned</p>
                                @endif
                            </div>
                            <div>
                                <p class="text-xs text-gray-500 font-medium">CATEGORY & BUSINESS UNIT</p>
                                <div class="flex gap-2 mt-1 flex-wrap">
                                    @if ($risk->category)
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-blue-100 text-blue-700">{{ $risk->category->name }}</span>
                                    @endif
                                    @if ($risk->businessUnit)
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-700">{{ $risk->businessUnit->name }}</span>
                                    @endif
                                </div>
                            </div>
                            @if ($risk->date_identified)
                                <div>
                                    <p class="text-xs text-gray-500 font-medium">DATE IDENTIFIED</p>
                                    <p class="text-sm text-gray-700">{{ $risk->date_identified instanceof \Carbon\Carbon ? $risk->date_identified->format('M d, Y') : $risk->date_identified }}</p>
                                </div>
                            @endif
                            @if ($risk->entity)
                                <div>
                                    <p class="text-xs text-gray-500 font-medium">ENTITY</p>
                                    <p class="text-sm text-gray-700">{{ $risk->entity->name }} ({{ $risk->entity->entity_code }})</p>
                                </div>
                            @endif
                        </div>
                    </div>

                    {{-- Key Metrics --}}
                    <div>
                        <div class="bg-gradient-to-br from-[#1A365D] to-[#2D4A7A] rounded-xl p-5 text-white mb-4">
                            <h4 class="text-xs font-semibold text-white/60 uppercase tracking-wide mb-4">Key Metrics</h4>
                            <div class="space-y-4">
                                <div>
                                    <p class="text-xs text-white/60">Inherent Score</p>
                                    <p class="text-xl font-bold">{{ $risk->inherent_score ?? 0 }} <span class="text-sm font-normal text-white/60">({{ $risk->inherent_rating ?? 'N/A' }})</span></p>
                                </div>
                                <div>
                                    <p class="text-xs text-white/60">Residual Score</p>
                                    <p class="text-xl font-bold">{{ $risk->residual_score ?? 0 }} <span class="text-sm font-normal text-white/60">({{ $risk->residual_rating ?? 'N/A' }})</span></p>
                                </div>
                                <div>
                                    <p class="text-xs text-white/60">Control Effectiveness</p>
                                    <p class="text-xl font-bold">{{ $risk->control_effectiveness_pct ?? 0 }}%</p>
                                </div>
                                <div>
                                    <p class="text-xs text-white/60">Treatment Strategy</p>
                                    <p class="text-xl font-bold">{{ ucfirst($risk->treatment_strategy ?? 'None') }}</p>
                                </div>
                            </div>
                        </div>

                        {{-- Impact Dimensions --}}
                        <div class="bg-gray-50 rounded-xl p-4">
                            <h4 class="text-xs font-semibold text-gray-600 uppercase tracking-wide mb-3">Impact Dimensions</h4>
                            @php
                                $dims = [
                                    'Financial' => $risk->inherent_impact_financial,
                                    'Operational' => $risk->inherent_impact_operational,
                                    'Reputational' => $risk->inherent_impact_reputational,
                                    'Regulatory' => $risk->inherent_impact_regulatory,
                                ];
                            @endphp
                            <div class="space-y-2">
                                @foreach ($dims as $label => $val)
                                    <div>
                                        <div class="flex justify-between text-xs mb-1">
                                            <span class="text-gray-600">{{ $label }}</span>
                                            <span class="font-semibold">{{ $val ?? 0 }}/5</span>
                                        </div>
                                        <div class="w-full bg-gray-200 rounded-full h-1.5">
                                            <div class="h-1.5 rounded-full {{ ($val ?? 0) >= 4 ? 'bg-red-500' : (($val ?? 0) >= 3 ? 'bg-yellow-500' : 'bg-green-500') }}"
                                                 style="width: {{ (($val ?? 0) / 5) * 100 }}%"></div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Additional Details --}}
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mt-6 pt-6 border-t border-gray-200">
                    @if ($risk->risk_source)
                        <div>
                            <p class="text-xs text-gray-500 font-medium">RISK SOURCE</p>
                            <p class="text-sm text-gray-700">{{ $risk->risk_source }}</p>
                        </div>
                    @endif
                    @if ($risk->risk_velocity)
                        <div>
                            <p class="text-xs text-gray-500 font-medium">RISK VELOCITY</p>
                            <p class="text-sm text-gray-700">{{ ucfirst($risk->risk_velocity) }}</p>
                        </div>
                    @endif
                    @if ($risk->review_frequency)
                        <div>
                            <p class="text-xs text-gray-500 font-medium">REVIEW FREQUENCY</p>
                            <p class="text-sm text-gray-700">{{ ucfirst($risk->review_frequency) }}</p>
                        </div>
                    @endif
                    @if ($risk->financial_exposure_ngn)
                        <div>
                            <p class="text-xs text-gray-500 font-medium">FINANCIAL EXPOSURE</p>
                            <p class="text-sm text-gray-700">NGN {{ number_format($risk->financial_exposure_ngn, 2) }}</p>
                        </div>
                    @endif
                    @if ($risk->regulatory_mapping && count($risk->regulatory_mapping))
                        <div>
                            <p class="text-xs text-gray-500 font-medium">REGULATORY ALIGNMENT</p>
                            <div class="flex flex-wrap gap-1 mt-1">
                                @foreach ($risk->regulatory_mapping as $fw)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-blue-50 text-blue-700 border border-blue-200">{{ $fw }}</span>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Assessment Tab --}}
            <div x-show="activeTab === 'assessment'" style="display: none;">
                <h3 class="text-sm font-semibold text-gray-700 mb-4">Assessment History</h3>
                @if ($risk->assessments && $risk->assessments->count())
                    <x-data-table id="assessmentTable">
                        <x-slot name="head">
                            <th>Date</th>
                            <th>Inherent Score</th>
                            <th>Residual Score</th>
                            <th>Assessor</th>
                            <th>Notes</th>
                        </x-slot>
                        @foreach ($risk->assessments as $assessment)
                            <tr class="hover:bg-blue-50/50">
                                <td class="text-sm">{{ $assessment->assessment_date ? \Carbon\Carbon::parse($assessment->assessment_date)->format('M d, Y') : '-' }}</td>
                                <td>
                                    <span class="text-sm font-semibold">{{ $assessment->inherent_score ?? '-' }}</span>
                                    @if ($assessment->inherent_rating)
                                        <x-risk-badge :rating="$assessment->inherent_rating" />
                                    @endif
                                </td>
                                <td>
                                    <span class="text-sm font-semibold">{{ $assessment->residual_score ?? '-' }}</span>
                                    @if ($assessment->residual_rating)
                                        <x-risk-badge :rating="$assessment->residual_rating" />
                                    @endif
                                </td>
                                <td class="text-sm">{{ $assessment->assessor->name ?? '-' }}</td>
                                <td class="text-sm max-w-[300px]"><div class="truncate">{{ $assessment->notes ?? '-' }}</div></td>
                            </tr>
                        @endforeach
                    </x-data-table>
                @else
                    <div class="text-center py-8">
                        <span class="material-symbols-outlined text-4xl text-gray-300">fact_check</span>
                        <p class="text-sm text-gray-500 mt-2">No assessments recorded yet.</p>
                    </div>
                @endif
            </div>

            {{-- Controls Tab --}}
            <div x-show="activeTab === 'controls'" style="display: none;">
                <h3 class="text-sm font-semibold text-gray-700 mb-4">Mapped Controls</h3>
                @if ($risk->controlMappings && $risk->controlMappings->count())
                    <x-data-table id="controlsTable">
                        <x-slot name="head">
                            <th>Control Code</th>
                            <th>Control Name</th>
                            <th>Type</th>
                            <th>Effectiveness</th>
                            <th>Key Control</th>
                            <th>Status</th>
                        </x-slot>
                        @foreach ($risk->controlMappings as $control)
                            <tr class="hover:bg-blue-50/50">
                                <td class="font-medium text-[#1A365D]">{{ $control->control_code ?? '-' }}</td>
                                <td class="text-sm">{{ $control->title ?? $control->name ?? '-' }}</td>
                                <td class="text-sm">{{ ucfirst($control->control_type ?? '-') }}</td>
                                <td>
                                    @if ($control->effectiveness_rating)
                                        <div class="flex items-center gap-2">
                                            <div class="w-16 bg-gray-200 rounded-full h-1.5">
                                                <div class="h-1.5 rounded-full bg-green-500" style="width: {{ $control->effectiveness_rating }}%"></div>
                                            </div>
                                            <span class="text-xs">{{ $control->effectiveness_rating }}%</span>
                                        </div>
                                    @else
                                        <span class="text-xs text-gray-400">N/A</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($control->pivot->is_key_control)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-green-100 text-green-700">Key</span>
                                    @else
                                        <span class="text-xs text-gray-400">No</span>
                                    @endif
                                </td>
                                <td><x-status-badge :status="$control->status ?? 'active'" /></td>
                            </tr>
                        @endforeach
                    </x-data-table>
                @else
                    <div class="text-center py-8">
                        <span class="material-symbols-outlined text-4xl text-gray-300">shield</span>
                        <p class="text-sm text-gray-500 mt-2">No controls mapped to this risk.</p>
                    </div>
                @endif
            </div>

            {{-- Treatment Tab --}}
            <div x-show="activeTab === 'treatment'" style="display: none;">
                <h3 class="text-sm font-semibold text-gray-700 mb-4">Treatment Plans</h3>
                @if ($risk->treatmentPlans && $risk->treatmentPlans->count())
                    @foreach ($risk->treatmentPlans as $plan)
                        <div class="bg-gray-50 rounded-xl p-4 mb-4">
                            <div class="flex items-center justify-between mb-3">
                                <div>
                                    <p class="text-sm font-semibold text-[#1A365D]">{{ $plan->plan_code ?? 'Treatment Plan' }}</p>
                                    <p class="text-xs text-gray-500">Strategy: {{ ucfirst($plan->strategy ?? $risk->treatment_strategy ?? '-') }}</p>
                                </div>
                                <x-status-badge :status="$plan->status ?? 'active'" />
                            </div>
                            @if ($plan->description)
                                <p class="text-sm text-gray-600">{{ $plan->description }}</p>
                            @endif
                        </div>
                    @endforeach
                @else
                    <div class="text-center py-8">
                        <span class="material-symbols-outlined text-4xl text-gray-300">healing</span>
                        <p class="text-sm text-gray-500 mt-2">No treatment plans created yet.</p>
                    </div>
                @endif
            </div>

            {{-- KRIs Tab --}}
            <div x-show="activeTab === 'kris'" style="display: none;">
                <h3 class="text-sm font-semibold text-gray-700 mb-4">Key Risk Indicators</h3>
                @if ($risk->keyRiskIndicators && $risk->keyRiskIndicators->count())
                    <x-data-table id="krisTable">
                        <x-slot name="head">
                            <th>KRI Code</th>
                            <th>KRI Name</th>
                            <th>Current Value</th>
                            <th>Threshold</th>
                            <th>Status</th>
                        </x-slot>
                        @foreach ($risk->keyRiskIndicators as $kri)
                            <tr class="hover:bg-blue-50/50">
                                <td class="font-medium text-[#1A365D]">{{ $kri->kri_code ?? '-' }}</td>
                                <td class="text-sm">{{ $kri->kri_name ?? $kri->name ?? '-' }}</td>
                                <td class="text-sm font-semibold">{{ $kri->current_value ?? '-' }}</td>
                                <td class="text-sm">
                                    @if ($kri->amber_threshold || $kri->red_threshold)
                                        <span class="text-yellow-600">{{ $kri->amber_threshold ?? '-' }}</span> /
                                        <span class="text-red-600">{{ $kri->red_threshold ?? '-' }}</span>
                                    @else
                                        <span class="text-gray-400">N/A</span>
                                    @endif
                                </td>
                                <td><x-status-badge :status="$kri->status ?? 'active'" /></td>
                            </tr>
                        @endforeach
                    </x-data-table>
                @else
                    <div class="text-center py-8">
                        <span class="material-symbols-outlined text-4xl text-gray-300">speed</span>
                        <p class="text-sm text-gray-500 mt-2">No KRIs linked to this risk.</p>
                    </div>
                @endif
            </div>

            {{-- History Tab --}}
            <div x-show="activeTab === 'history'" style="display: none;">
                <h3 class="text-sm font-semibold text-gray-700 mb-4">Audit Trail</h3>
                @if ($risk->auditTrails && $risk->auditTrails->count())
                    <div class="space-y-4">
                        @foreach ($risk->auditTrails as $trail)
                            <div class="flex gap-4 items-start">
                                <div class="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0
                                    {{ $trail->action_type === 'created' ? 'bg-green-100 text-green-700' : ($trail->action_type === 'deleted' ? 'bg-red-100 text-red-700' : 'bg-blue-100 text-blue-700') }}">
                                    <span class="material-symbols-outlined text-sm">
                                        {{ $trail->action_type === 'created' ? 'add_circle' : ($trail->action_type === 'deleted' ? 'delete' : 'edit') }}
                                    </span>
                                </div>
                                <div class="flex-1">
                                    <p class="text-sm font-medium text-gray-700">{{ ucfirst($trail->action_type) }} &mdash; {{ $trail->field_changed }}</p>
                                    <p class="text-xs text-gray-500">{{ $trail->changed_at ? \Carbon\Carbon::parse($trail->changed_at)->format('M d, Y H:i') : '-' }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="text-center py-8">
                        <span class="material-symbols-outlined text-4xl text-gray-300">history</span>
                        <p class="text-sm text-gray-500 mt-2">No audit trail records.</p>
                    </div>
                @endif
            </div>

        </div>
    </div>
@endsection
