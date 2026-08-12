@extends('layouts.app')

@section('title', ($lossEvent->event_reference ?? 'Loss Event') . ' - GRC Platform')

@section('breadcrumbs')
    <span>Risk Management</span>
    <span class="text-gray-300">/</span>
    <span>Loss Events</span>
    <span class="text-gray-300">/</span>
    <span class="text-[#1A365D] font-semibold">{{ $lossEvent->event_reference ?? 'Detail' }}</span>
@endsection

@section('content')
    {{-- Flash Messages --}}
    @if (session('success'))
        <div class="mb-4 px-4 py-3 bg-green-50 border border-green-200 text-green-700 rounded-lg text-sm flex items-center gap-2">
            <span class="material-symbols-outlined text-lg">check_circle</span>
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="mb-4 px-4 py-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm flex items-center gap-2">
            <span class="material-symbols-outlined text-lg">error</span>
            {{ session('error') }}
        </div>
    @endif

    {{-- Header --}}
    <div class="flex items-start justify-between mb-6">
        <div>
            <div class="flex items-center gap-3 mb-2">
                <span class="text-xs font-mono text-gray-500 bg-gray-100 px-2 py-0.5 rounded">{{ $lossEvent->event_reference }}</span>
                <x-risk-badge :rating="$lossEvent->severity ?? 'low'" />
                <x-status-badge :status="$lossEvent->status ?? 'draft'" />
            </div>
            <h1 class="text-xl font-bold text-[#1A365D]">{{ $lossEvent->title }}</h1>
            <p class="text-sm text-gray-500 mt-1">
                Reported {{ $lossEvent->created_at?->format('d M Y') ?? '-' }} &middot;
                Loss Date: {{ $lossEvent->date_of_loss?->format('d M Y') ?? '-' }}
            </p>
        </div>
        <div class="flex items-center gap-2">
            @php
                $statusTransitions = [
                    'reported' => ['under_investigation' => 'Start Investigation', 'pending_approval' => 'Submit for Approval'],
                    'under_investigation' => ['pending_approval' => 'Submit for Approval'],
                    'pending_approval' => ['under_investigation' => 'Return to Investigation'],
                    'approved' => ['closed' => 'Close Event', 'reopened' => 'Reopen'],
                    'closed' => ['reopened' => 'Reopen'],
                    'reopened' => ['under_investigation' => 'Start Investigation'],
                ];
                $availableTransitions = $statusTransitions[$lossEvent->status] ?? [];
            @endphp
            @foreach ($availableTransitions as $newStatus => $label)
                <form method="POST" action="{{ route('risk.loss-events.update-status', $lossEvent) }}" class="inline">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="status" value="{{ $newStatus }}">
                    <button type="submit"
                            class="flex items-center gap-1 px-3 py-2 bg-[#1A365D] text-white rounded-lg text-xs font-semibold hover:bg-[#2D4A7A] transition">
                        <span class="material-symbols-outlined text-sm">arrow_forward</span>
                        {{ $label }}
                    </button>
                </form>
            @endforeach
            <a href="{{ url('/risk/loss-events/' . $lossEvent->id . '/edit') }}"
               class="flex items-center gap-1 px-3 py-2 border border-gray-200 rounded-lg text-xs font-medium text-gray-600 hover:bg-gray-50 transition">
                <span class="material-symbols-outlined text-sm">edit</span>
                Edit
            </a>
            <a href="{{ url('/risk/loss-events') }}"
               class="flex items-center gap-1 px-3 py-2 border border-gray-200 rounded-lg text-xs font-medium text-gray-600 hover:bg-gray-50 transition">
                <span class="material-symbols-outlined text-sm">arrow_back</span>
                Back
            </a>
        </div>
    </div>

    {{-- Top Summary Cards --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        {{-- Financial Impact Card --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-4 flex items-center gap-2">
                <span class="material-symbols-outlined text-sm text-[#D4AF37]">payments</span>
                Financial Impact
            </h3>
            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <span class="text-xs text-gray-500">Gross Loss</span>
                    <span class="text-sm font-bold text-red-600">₦{{ number_format($lossEvent->gross_loss_amount ?? 0, 2) }}</span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-xs text-gray-500">Insurance Recovery</span>
                    <span class="text-sm font-medium text-green-600">₦{{ number_format($lossEvent->insurance_recovery ?? 0, 2) }}</span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-xs text-gray-500">Other Recovery</span>
                    <span class="text-sm font-medium text-green-600">₦{{ number_format($lossEvent->other_recovery ?? 0, 2) }}</span>
                </div>
                <div class="h-px bg-gray-200"></div>
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold text-gray-700">Net Loss</span>
                    <span class="text-sm font-bold text-[#1A365D]">₦{{ number_format($lossEvent->net_loss_amount ?? 0, 2) }}</span>
                </div>
            </div>
        </div>

        {{-- Classification Card --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-4 flex items-center gap-2">
                <span class="material-symbols-outlined text-sm text-[#D4AF37]">category</span>
                Classification
            </h3>
            <div class="space-y-3">
                <div>
                    <span class="text-[10px] text-gray-400 uppercase">Basel L1</span>
                    <div class="text-xs font-medium text-gray-800">{{ $lossEvent->basel_l1_category ?? '-' }}</div>
                </div>
                <div>
                    <span class="text-[10px] text-gray-400 uppercase">Basel L2</span>
                    <div class="text-xs font-medium text-gray-800">{{ $lossEvent->basel_l2_category ?? '-' }}</div>
                </div>
                <div>
                    <span class="text-[10px] text-gray-400 uppercase">CBN ORMS Category</span>
                    <div class="text-xs font-medium text-gray-800">{{ $lossEvent->cbn_orms_event_type ?? '-' }}</div>
                </div>
                <div>
                    <span class="text-[10px] text-gray-400 uppercase">Business Line</span>
                    <div class="text-xs font-medium text-gray-800">{{ $lossEvent->cbn_product_line ?? '-' }}</div>
                </div>
            </div>
        </div>

        {{-- Regulatory Flags Card --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-4 flex items-center gap-2">
                <span class="material-symbols-outlined text-sm text-red-500">gavel</span>
                Regulatory Flags
            </h3>
            <div class="space-y-3">
                <div class="flex items-center justify-between p-2 rounded-lg {{ $lossEvent->cbn_reportable ? 'bg-red-50' : 'bg-gray-50' }}">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-sm {{ $lossEvent->cbn_reportable ? 'text-red-500' : 'text-gray-400' }}">flag</span>
                        <span class="text-xs font-medium text-gray-700">CBN Notification</span>
                    </div>
                    @if ($lossEvent->cbn_reportable)
                        <span class="text-[10px] font-bold text-red-600">REQUIRED</span>
                    @else
                        <span class="text-[10px] text-gray-400">N/A</span>
                    @endif
                </div>
                <div class="flex items-center justify-between p-2 rounded-lg {{ $lossEvent->nfiu_reportable ? 'bg-red-50' : 'bg-gray-50' }}">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-sm {{ $lossEvent->nfiu_reportable ? 'text-red-500' : 'text-gray-400' }}">flag</span>
                        <span class="text-xs font-medium text-gray-700">NFIU Filing</span>
                    </div>
                    @if ($lossEvent->nfiu_reportable)
                        <span class="text-[10px] font-bold text-red-600">REQUIRED</span>
                    @else
                        <span class="text-[10px] text-gray-400">N/A</span>
                    @endif
                </div>
                <div class="flex items-center justify-between p-2 rounded-lg {{ $lossEvent->ndic_reportable ? 'bg-red-50' : 'bg-gray-50' }}">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-sm {{ $lossEvent->ndic_reportable ? 'text-red-500' : 'text-gray-400' }}">flag</span>
                        <span class="text-xs font-medium text-gray-700">NDIC Report</span>
                    </div>
                    @if ($lossEvent->ndic_reportable)
                        <span class="text-[10px] font-bold text-red-600">REQUIRED</span>
                    @else
                        <span class="text-[10px] text-gray-400">N/A</span>
                    @endif
                </div>
                @if ($lossEvent->cbn_reportable && $lossEvent->cbn_deadline)
                    <div class="mt-2 p-2 bg-yellow-50 rounded-lg border border-yellow-200">
                        <div class="text-[10px] text-yellow-700 font-semibold">CBN Deadline: {{ $lossEvent->cbn_deadline->format('d M Y H:i') }}</div>
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Tabs --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="border-b border-gray-200 px-5">
            <nav class="flex gap-6 -mb-px" id="detailTabs">
                @foreach ([
                    'overview' => 'Overview',
                    'rca' => 'Root Cause Analysis',
                    'controls' => 'Controls Failed',
                    'regulatory' => 'Regulatory',
                    'approvals' => 'Approvals',
                    'attachments' => 'Attachments',
                ] as $tabId => $tabLabel)
                    <button type="button"
                            class="py-3 text-xs font-medium transition-all border-b-2 {{ $loop->first ? 'tab-active' : 'tab-inactive' }}"
                            data-tab="{{ $tabId }}"
                            onclick="switchTab('{{ $tabId }}')">
                        {{ $tabLabel }}
                    </button>
                @endforeach
            </nav>
        </div>

        {{-- Tab: Overview --}}
        <div id="tab-overview" class="tab-panel p-6">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div>
                    <h4 class="text-sm font-semibold text-[#1A365D] mb-3">Event Description</h4>
                    <p class="text-sm text-gray-600 leading-relaxed">{{ $lossEvent->description ?? 'No description provided.' }}</p>
                </div>
                <div>
                    <h4 class="text-sm font-semibold text-[#1A365D] mb-3">Key Details</h4>
                    <div class="space-y-2">
                        <div class="flex justify-between text-xs">
                            <span class="text-gray-500">Business Unit</span>
                            <span class="font-medium text-gray-800">{{ $lossEvent->businessUnit->name ?? '-' }}</span>
                        </div>
                        <div class="flex justify-between text-xs">
                            <span class="text-gray-500">Branch/Location</span>
                            <span class="font-medium text-gray-800">{{ $lossEvent->branch->name ?? '-' }}</span>
                        </div>
                        <div class="flex justify-between text-xs">
                            <span class="text-gray-500">Responsible Officer</span>
                            <span class="font-medium text-gray-800">{{ $lossEvent->responsibleOfficer->name ?? '-' }}</span>
                        </div>
                        <div class="flex justify-between text-xs">
                            <span class="text-gray-500">Date of Discovery</span>
                            <span class="font-medium text-gray-800">{{ $lossEvent->date_of_discovery?->format('d M Y') ?? '-' }}</span>
                        </div>
                        <div class="flex justify-between text-xs">
                            <span class="text-gray-500">Date of Loss</span>
                            <span class="font-medium text-gray-800">{{ $lossEvent->date_of_loss?->format('d M Y') ?? '-' }}</span>
                        </div>
                        <div class="flex justify-between text-xs">
                            <span class="text-gray-500">Date of Accounting</span>
                            <span class="font-medium text-gray-800">{{ $lossEvent->date_of_accounting?->format('d M Y') ?? '-' }}</span>
                        </div>
                        <div class="flex justify-between text-xs">
                            <span class="text-gray-500">Loss Category</span>
                            <span class="font-medium text-gray-800">{{ $lossEvent->loss_category ?? '-' }}</span>
                        </div>
                        <div class="flex justify-between text-xs">
                            <span class="text-gray-500">Cost Centre</span>
                            <span class="font-medium text-gray-800">{{ $lossEvent->cost_centre ?? '-' }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Tab: Root Cause Analysis --}}
        <div id="tab-rca" class="tab-panel p-6 hidden">
            @php $rca = $lossEvent->rca; @endphp
            <div class="flex items-center justify-between mb-4">
                <h4 class="text-sm font-semibold text-[#1A365D]">Root Cause Analysis</h4>
                @if ($lossEvent->status !== 'closed')
                    <div class="flex gap-2">
                        <button type="button" id="rcaCancelBtn" style="display:none;" class="flex items-center gap-1 px-3 py-1.5 border border-gray-300 text-gray-700 text-xs rounded-lg hover:bg-gray-50">Cancel</button>
                        <button type="button" id="rcaEditBtn" class="flex items-center gap-1 px-3 py-1.5 bg-[#1A365D] text-white text-xs rounded-lg hover:bg-[#2D4A7A]">
                            <span class="material-symbols-outlined text-sm">edit</span>
                            {{ $rca ? 'Update' : 'Start' }} RCA
                        </button>
                    </div>
                @endif
            </div>

            {{-- RCA Edit Form --}}
            <form id="rcaForm" method="POST" action="{{ route('risk.loss-events.store-rca', $lossEvent) }}" class="space-y-4" style="display:none;">
                @csrf
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Methodology <span class="text-red-500">*</span></label>
                        <select name="methodology" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                            @foreach (['five_whys' => '5 Whys', 'fishbone' => 'Fishbone', 'fault_tree' => 'Fault Tree', 'other' => 'Other'] as $v => $l)
                                <option value="{{ $v }}" {{ old('methodology', $rca->methodology ?? '') === $v ? 'selected' : '' }}>{{ $l }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Root Cause Category <span class="text-red-500">*</span></label>
                        <select name="root_cause_category" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                            <option value="">Select category</option>
                            @foreach (['people' => 'People', 'process' => 'Process', 'system' => 'System', 'external' => 'External'] as $v => $l)
                                <option value="{{ $v }}" {{ old('root_cause_category', $rca->root_cause_category ?? '') === $v ? 'selected' : '' }}>{{ $l }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Root Cause Description <span class="text-red-500">*</span></label>
                    <textarea name="root_cause_description" rows="3" required maxlength="5000" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]" placeholder="What is the underlying cause of this loss event?">{{ old('root_cause_description', $rca->root_cause_description ?? '') }}</textarea>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Contributing Factors</label>
                    <textarea name="contributing_factors" rows="2" maxlength="3000" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">{{ old('contributing_factors', $rca->contributing_factors_text ?? '') }}</textarea>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Analysis Details</label>
                    <textarea name="analysis_details" rows="3" maxlength="5000" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]" placeholder="5-Whys breakdown, fishbone notes, etc.">{{ old('analysis_details', $rca->analysis_details ?? '') }}</textarea>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Recommendations</label>
                        <textarea name="recommendations" rows="3" maxlength="3000" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">{{ old('recommendations', $rca->recommendations ?? '') }}</textarea>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Lessons Learned</label>
                        <textarea name="lessons_learned" rows="3" maxlength="3000" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">{{ old('lessons_learned', $rca->lessons_learned ?? '') }}</textarea>
                    </div>
                </div>
                @if ($errors->any())
                    <div class="p-3 bg-red-50 border border-red-200 rounded-lg text-xs text-red-700">
                        <ul class="list-disc list-inside space-y-1">
                            @foreach ($errors->all() as $err) <li>{{ $err }}</li> @endforeach
                        </ul>
                    </div>
                @endif
                <div class="flex justify-end">
                    <button type="submit" class="flex items-center gap-1 px-4 py-2 bg-[#1A365D] text-white text-xs rounded-lg hover:bg-[#2D4A7A]">
                        <span class="material-symbols-outlined text-sm">save</span> Save RCA
                    </button>
                </div>
            </form>

            {{-- RCA Display --}}
            <div id="rcaDisplay">
                @if ($rca)
                    <div class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="p-4 rounded-lg bg-gray-50 border border-gray-200">
                                <div class="text-[10px] font-semibold text-gray-400 uppercase mb-1">Methodology</div>
                                <p class="text-sm text-gray-700">{{ ucwords(str_replace('_', ' ', $rca->methodology ?? '-')) }}</p>
                            </div>
                            <div class="p-4 rounded-lg bg-gray-50 border border-gray-200">
                                <div class="text-[10px] font-semibold text-gray-400 uppercase mb-1">Category</div>
                                <p class="text-sm text-gray-700">{{ ucfirst($rca->root_cause_category ?? '-') }}</p>
                            </div>
                        </div>
                        <div class="p-4 rounded-lg bg-gray-50 border border-gray-200">
                            <div class="text-[10px] font-semibold text-gray-400 uppercase mb-1">Root Cause</div>
                            <p class="text-sm text-gray-700 whitespace-pre-line">{{ $rca->root_cause_description ?? $rca->root_cause_statement ?? '-' }}</p>
                        </div>
                        @if ($rca->contributing_factors_text)
                            <div class="p-4 rounded-lg bg-gray-50 border border-gray-200">
                                <div class="text-[10px] font-semibold text-gray-400 uppercase mb-1">Contributing Factors</div>
                                <p class="text-sm text-gray-700 whitespace-pre-line">{{ $rca->contributing_factors_text }}</p>
                            </div>
                        @endif
                        @if ($rca->analysis_details)
                            <div class="p-4 rounded-lg bg-gray-50 border border-gray-200">
                                <div class="text-[10px] font-semibold text-gray-400 uppercase mb-1">Analysis Details</div>
                                <p class="text-sm text-gray-700 whitespace-pre-line">{{ $rca->analysis_details }}</p>
                            </div>
                        @endif
                        @if ($rca->recommendations)
                            <div class="p-4 rounded-lg bg-green-50 border border-green-200">
                                <div class="text-[10px] font-semibold text-green-700 uppercase mb-1">Recommendations</div>
                                <p class="text-sm text-green-800 whitespace-pre-line">{{ $rca->recommendations }}</p>
                            </div>
                        @endif
                        @if ($rca->lessons_learned)
                            <div class="p-4 rounded-lg bg-blue-50 border border-blue-200">
                                <div class="text-[10px] font-semibold text-blue-700 uppercase mb-1">Lessons Learned</div>
                                <p class="text-sm text-blue-800 whitespace-pre-line">{{ $rca->lessons_learned }}</p>
                            </div>
                        @endif
                    </div>
                @else
                    <div class="text-center py-8 text-gray-400">
                        <span class="material-symbols-outlined text-3xl mb-2 block">psychology</span>
                        <p class="text-sm">No root cause analysis performed yet</p>
                    </div>
                @endif
            </div>
        </div>

        {{-- Tab: Controls Failed --}}
        <div id="tab-controls" class="tab-panel p-6 hidden">
            <h4 class="text-sm font-semibold text-[#1A365D] mb-4">Controls That Failed</h4>
            @if (($lossEvent->failedControls ?? collect())->isNotEmpty())
                <div class="space-y-3">
                    @foreach ($lossEvent->failedControls as $fc)
                        <div class="p-4 rounded-lg border border-gray-200 flex items-start gap-3">
                            <span class="material-symbols-outlined text-red-400 text-lg flex-shrink-0 mt-0.5">cancel</span>
                            <div class="flex-1">
                                <div class="text-sm font-medium text-gray-800">{{ $fc->control->control_name ?? $fc->control->name ?? '-' }}</div>
                                <div class="text-xs text-gray-500 mt-1">{{ $fc->failure_description ?? ($fc->control->description ?? '-') }}</div>
                                <div class="flex items-center gap-3 mt-2">
                                    <span class="text-[10px] font-medium text-gray-400">Failure Type: {{ $fc->failure_type ?? '-' }}</span>
                                    <span class="text-[10px] font-medium text-gray-400">Control Type: {{ $fc->control->control_type ?? '-' }}</span>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="text-center py-8 text-gray-400">
                    <span class="material-symbols-outlined text-3xl mb-2 block">security</span>
                    <p class="text-sm">No failed controls linked to this event</p>
                </div>
            @endif
        </div>

        {{-- Tab: Regulatory --}}
        <div id="tab-regulatory" class="tab-panel p-6 hidden">
            <h4 class="text-sm font-semibold text-[#1A365D] mb-4">Regulatory Reporting Status</h4>
            <div class="space-y-4">
                @foreach ([
                    ['flag' => 'cbn_reportable', 'label' => 'CBN Notification', 'icon' => 'account_balance', 'deadline_field' => 'cbn_deadline', 'status_field' => 'cbn_filing_status'],
                    ['flag' => 'nfiu_reportable', 'label' => 'NFIU STR Filing', 'icon' => 'policy', 'deadline_field' => 'nfiu_deadline', 'status_field' => 'nfiu_filing_status'],
                    ['flag' => 'ndic_reportable', 'label' => 'NDIC Notification', 'icon' => 'assured_workload', 'deadline_field' => 'ndic_deadline', 'status_field' => 'ndic_filing_status'],
                ] as $reg)
                    <div class="p-4 rounded-lg border {{ $lossEvent->{$reg['flag']} ? 'border-red-200 bg-red-50/50' : 'border-gray-200 bg-gray-50' }}">
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center gap-2">
                                <span class="material-symbols-outlined text-lg {{ $lossEvent->{$reg['flag']} ? 'text-red-500' : 'text-gray-400' }}">{{ $reg['icon'] }}</span>
                                <span class="text-sm font-semibold text-gray-800">{{ $reg['label'] }}</span>
                            </div>
                            @if ($lossEvent->{$reg['flag']})
                                <x-status-badge :status="$lossEvent->{$reg['status_field']} ?? 'pending'" />
                            @else
                                <span class="text-xs text-gray-400">Not Required</span>
                            @endif
                        </div>
                        @if ($lossEvent->{$reg['flag']} && $lossEvent->{$reg['deadline_field']})
                            <div class="text-xs text-gray-600">
                                Deadline: <span class="font-medium">{{ $lossEvent->{$reg['deadline_field']}->format('d M Y H:i') }}</span>
                                @if ($lossEvent->{$reg['deadline_field']}->isPast() && ($lossEvent->{$reg['status_field']} ?? 'pending') !== 'completed')
                                    <span class="ml-2 text-red-600 font-bold">OVERDUE</span>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Tab: Approvals --}}
        <div id="tab-approvals" class="tab-panel p-6 hidden">
            @if (! in_array($lossEvent->status, ['approved', 'closed']))
                @can('approve-loss-event', $lossEvent)
                <div class="mb-6 bg-white rounded-xl border border-blue-200 shadow-sm p-5" x-data="{ mode: 'approve' }">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="material-symbols-outlined text-blue-600">rate_review</span>
                        <h3 class="text-sm font-semibold text-gray-900">Record Decision</h3>
                    </div>
                    <form method="POST" action="{{ route('risk.loss-events.submit-approval', $lossEvent) }}" class="space-y-3">
                        @csrf
                        <div class="flex gap-3">
                            <label class="flex items-center gap-2 text-sm">
                                <input type="radio" name="decision" value="approved" x-model="mode" checked> <span>Approve</span>
                            </label>
                            <label class="flex items-center gap-2 text-sm">
                                <input type="radio" name="decision" value="rejected" x-model="mode"> <span class="text-red-600">Reject</span>
                            </label>
                            <label class="flex items-center gap-2 text-sm">
                                <input type="radio" name="decision" value="escalated" x-model="mode"> <span class="text-yellow-700">Escalate</span>
                            </label>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Approval Level</label>
                            <select name="approval_level" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                                <option value="level_1">Level 1 (Line Manager)</option>
                                <option value="level_2">Level 2 (Department Head)</option>
                                <option value="level_3">Level 3 (Executive)</option>
                            </select>
                        </div>
                        <div x-show="mode !== 'rejected'">
                            <label class="block text-xs font-medium text-gray-700 mb-1">Comments (optional)</label>
                            <textarea name="comments" rows="2" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"></textarea>
                        </div>
                        <div x-show="mode === 'rejected'">
                            <label class="block text-xs font-medium text-gray-700 mb-1">Reason for rejection <span class="text-red-500">*</span></label>
                            <textarea name="rejection_reason" rows="3" :required="mode === 'rejected'" maxlength="2000" class="w-full border border-red-200 rounded-lg px-3 py-2 text-sm focus:border-red-400" placeholder="Explain why this event is being rejected…"></textarea>
                        </div>
                        <button type="submit" class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-1">
                            <span class="material-symbols-outlined text-sm">save</span> Submit Decision
                        </button>
                    </form>
                </div>
                @else
                <div class="mb-6 bg-yellow-50 border border-yellow-200 rounded-xl p-4 flex items-start gap-3">
                    <span class="material-symbols-outlined text-yellow-600">hourglass_empty</span>
                    <div>
                        <p class="text-sm font-semibold text-yellow-800">Pending Approver Decision</p>
                        <p class="text-xs text-yellow-700 mt-1">Awaiting a loss-event-manager, compliance-officer, CRO, or the assigned handler to act.</p>
                    </div>
                </div>
                @endcan
            @endif

            <h4 class="text-sm font-semibold text-[#1A365D] mb-4">Approval History</h4>
            @if (($lossEvent->approvals ?? collect())->isNotEmpty())
                <div class="space-y-3">
                    @foreach ($lossEvent->approvals as $approval)
                        @php $apDecision = strtolower($approval->decision ?? 'pending'); @endphp
                        <div class="flex items-start gap-4 p-4 rounded-lg border border-gray-200">
                            <div class="w-8 h-8 rounded-full {{ $apDecision === 'approved' ? 'bg-green-100 text-green-600' : ($apDecision === 'rejected' ? 'bg-red-100 text-red-600' : 'bg-yellow-100 text-yellow-600') }} flex items-center justify-center flex-shrink-0">
                                <span class="material-symbols-outlined text-sm">
                                    {{ $apDecision === 'approved' ? 'check' : ($apDecision === 'rejected' ? 'close' : 'schedule') }}
                                </span>
                            </div>
                            <div class="flex-1">
                                <div class="flex items-center justify-between">
                                    <span class="text-sm font-medium text-gray-800">{{ $approval->actionedBy->name ?? 'Unknown' }}</span>
                                    <x-status-badge :status="$apDecision" />
                                </div>
                                <div class="text-xs text-gray-500 mt-1">
                                    {{ $approval->stage ?? '' }} &middot; {{ $approval->actioned_at?->format('d M Y H:i') ?? '-' }}
                                </div>
                                @if ($approval->comments)
                                    <p class="text-xs text-gray-600 mt-2">{{ $approval->comments }}</p>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="text-center py-8 text-gray-400">
                    <span class="material-symbols-outlined text-3xl mb-2 block">approval</span>
                    <p class="text-sm">No approval actions yet</p>
                </div>
            @endif
        </div>

        {{-- Tab: Attachments --}}
        <div id="tab-attachments" class="tab-panel p-6 hidden">
            <div class="flex items-center justify-between mb-4">
                <h4 class="text-sm font-semibold text-[#1A365D]">Attachments</h4>
                <div class="flex gap-2">
                    <button type="button" id="uploadCancelBtn" style="display:none;" class="flex items-center gap-1 px-3 py-1.5 border border-gray-300 text-gray-700 text-xs rounded-lg hover:bg-gray-50">Cancel</button>
                    <button type="button" id="uploadToggleBtn" class="flex items-center gap-1 px-3 py-1.5 bg-[#1A365D] text-white text-xs rounded-lg hover:bg-[#2D4A7A]">
                        <span class="material-symbols-outlined text-sm">upload</span>
                        Upload File
                    </button>
                </div>
            </div>

            {{-- Upload Form --}}
            <form id="uploadForm" method="POST" action="{{ route('risk.loss-events.upload-attachment', $lossEvent) }}" enctype="multipart/form-data" class="mb-6 p-4 border border-gray-200 rounded-lg bg-gray-50 space-y-3" style="display:none;">
                @csrf
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">File <span class="text-red-500">*</span></label>
                    <input type="file" name="file" required class="w-full text-sm text-gray-700 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-[#1A365D] file:text-white file:text-xs hover:file:bg-[#2D4A7A]">
                    <p class="text-[10px] text-gray-500 mt-1">Max 20MB.</p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Document Type</label>
                        <select name="document_type" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                            <option value="">—</option>
                            @foreach (['incident_report', 'evidence', 'correspondence', 'regulatory_filing', 'invoice', 'other'] as $dt)
                                <option value="{{ $dt }}">{{ ucwords(str_replace('_', ' ', $dt)) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex items-end">
                        <label class="flex items-center gap-2 text-xs text-gray-700">
                            <input type="checkbox" name="is_regulatory" value="1" class="rounded border-gray-300">
                            Regulatory / Compliance document
                        </label>
                    </div>
                </div>
                @error('file')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                <div class="flex justify-end">
                    <button type="submit" class="flex items-center gap-1 px-4 py-2 bg-[#1A365D] text-white text-xs rounded-lg hover:bg-[#2D4A7A]">
                        <span class="material-symbols-outlined text-sm">upload</span> Upload
                    </button>
                </div>
            </form>

            @if (($lossEvent->attachments ?? collect())->isNotEmpty())
                <div class="space-y-2">
                    @foreach ($lossEvent->attachments as $attachment)
                        <div class="flex items-center justify-between p-3 rounded-lg border border-gray-200 hover:bg-gray-50">
                            <div class="flex items-center gap-3">
                                <span class="material-symbols-outlined text-gray-400">description</span>
                                <div>
                                    <div class="text-sm font-medium text-gray-800">{{ $attachment->file_name }}</div>
                                    <div class="text-[10px] text-gray-400">
                                        {{ $attachment->created_at?->format('d M Y') }} &middot; {{ $attachment->size_formatted }}
                                        @if ($attachment->document_type)
                                            &middot; {{ ucwords(str_replace('_', ' ', $attachment->document_type)) }}
                                        @endif
                                        @if ($attachment->is_regulatory)
                                            <span class="ml-1 px-1.5 py-0.5 rounded bg-yellow-100 text-yellow-700 font-semibold">Regulatory</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center gap-1">
                                <a href="{{ route('risk.loss-events.download-attachment', [$lossEvent, $attachment]) }}" class="p-1 rounded hover:bg-gray-100" title="Download">
                                    <span class="material-symbols-outlined text-gray-500 text-lg">download</span>
                                </a>
                                <form method="POST" action="{{ route('risk.loss-events.delete-attachment', [$lossEvent, $attachment]) }}" onsubmit="return confirm('Delete this attachment?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="p-1 rounded hover:bg-red-50" title="Delete">
                                        <span class="material-symbols-outlined text-red-400 text-lg">delete</span>
                                    </button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="text-center py-8 text-gray-400">
                    <span class="material-symbols-outlined text-3xl mb-2 block">attach_file</span>
                    <p class="text-sm">No attachments uploaded</p>
                </div>
            @endif
        </div>
    </div>
@endsection

@push('scripts')
<script>
function switchTab(tabId) {
    // Hide all tab panels
    document.querySelectorAll('.tab-panel').forEach(function(panel) {
        panel.classList.add('hidden');
    });
    // Deactivate all tab buttons
    document.querySelectorAll('#detailTabs button').forEach(function(btn) {
        btn.classList.remove('tab-active');
        btn.classList.add('tab-inactive');
    });

    // Show selected panel
    const panel = document.getElementById('tab-' + tabId);
    if (panel) panel.classList.remove('hidden');

    // Activate selected tab button
    const btn = document.querySelector('#detailTabs button[data-tab="' + tabId + '"]');
    if (btn) {
        btn.classList.remove('tab-inactive');
        btn.classList.add('tab-active');
    }
}

window.onPageReady(function() {
    // RCA form toggle
    const rcaEdit = document.getElementById('rcaEditBtn');
    const rcaCancel = document.getElementById('rcaCancelBtn');
    const rcaForm = document.getElementById('rcaForm');
    const rcaDisplay = document.getElementById('rcaDisplay');
    if (rcaEdit && rcaForm) {
        rcaEdit.addEventListener('click', function() {
            rcaForm.style.display = 'block';
            rcaDisplay.style.display = 'none';
            rcaEdit.style.display = 'none';
            rcaCancel.style.display = 'inline-flex';
        });
        rcaCancel.addEventListener('click', function() {
            rcaForm.style.display = 'none';
            rcaDisplay.style.display = 'block';
            rcaEdit.style.display = 'inline-flex';
            rcaCancel.style.display = 'none';
        });
    }

    // Upload form toggle
    const uploadToggle = document.getElementById('uploadToggleBtn');
    const uploadCancel = document.getElementById('uploadCancelBtn');
    const uploadForm = document.getElementById('uploadForm');
    if (uploadToggle && uploadForm) {
        uploadToggle.addEventListener('click', function() {
            uploadForm.style.display = 'block';
            uploadToggle.style.display = 'none';
            uploadCancel.style.display = 'inline-flex';
        });
        uploadCancel.addEventListener('click', function() {
            uploadForm.style.display = 'none';
            uploadToggle.style.display = 'inline-flex';
            uploadCancel.style.display = 'none';
        });
    }

    // If there are validation errors on the RCA form, auto-open it and switch to that tab
    @if ($errors->any() && (old('root_cause_description') || old('methodology')))
        if (rcaEdit) rcaEdit.click();
        switchTab('rca');
    @endif
});
</script>
@endpush
