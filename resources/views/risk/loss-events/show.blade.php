@extends('layouts.app')

@section('title', ($lossEvent->reference ?? 'Loss Event') . ' - GRC Platform')

@section('breadcrumbs')
    <span>Risk Management</span>
    <span class="text-gray-300">/</span>
    <span>Loss Events</span>
    <span class="text-gray-300">/</span>
    <span class="text-[#1A365D] font-semibold">{{ $lossEvent->reference ?? 'Detail' }}</span>
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
                <span class="text-xs font-mono text-gray-500 bg-gray-100 px-2 py-0.5 rounded">{{ $lossEvent->reference }}</span>
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
            <div class="flex items-center justify-between mb-4">
                <h4 class="text-sm font-semibold text-[#1A365D]">Root Cause Analysis - 5 Whys</h4>
                @if ($lossEvent->status !== 'closed')
                    <button type="button" class="flex items-center gap-1 px-3 py-1.5 bg-[#1A365D] text-white text-xs rounded-lg hover:bg-[#2D4A7A]">
                        <span class="material-symbols-outlined text-sm">edit</span>
                        Update RCA
                    </button>
                @endif
            </div>

            @if ($lossEvent->rca)
                <div class="space-y-4">
                    <div class="p-4 rounded-lg bg-gray-50 border border-gray-200">
                        <div class="text-[10px] font-semibold text-gray-400 uppercase mb-1">Methodology</div>
                        <p class="text-sm text-gray-700">{{ $lossEvent->rca->methodology ?? '-' }}</p>
                    </div>
                    <div class="p-4 rounded-lg bg-gray-50 border border-gray-200">
                        <div class="text-[10px] font-semibold text-gray-400 uppercase mb-1">Root Cause</div>
                        <p class="text-sm text-gray-700">{{ $lossEvent->rca->root_cause ?? '-' }}</p>
                    </div>
                    @if ($lossEvent->rca->immediate_cause)
                        <div class="p-4 rounded-lg bg-gray-50 border border-gray-200">
                            <div class="text-[10px] font-semibold text-gray-400 uppercase mb-1">Immediate Cause</div>
                            <p class="text-sm text-gray-700">{{ $lossEvent->rca->immediate_cause }}</p>
                        </div>
                    @endif
                    @if ($lossEvent->rca->systemic_issues)
                        <div class="p-4 rounded-lg bg-gray-50 border border-gray-200">
                            <div class="text-[10px] font-semibold text-gray-400 uppercase mb-1">Systemic Issues</div>
                            <p class="text-sm text-gray-700">{{ $lossEvent->rca->systemic_issues }}</p>
                        </div>
                    @endif
                    @if ($lossEvent->rca->findings)
                        <div class="p-4 rounded-lg bg-blue-50 border border-blue-200">
                            <div class="text-[10px] font-semibold text-blue-700 uppercase mb-1">Findings</div>
                            <p class="text-sm text-blue-800">{{ $lossEvent->rca->findings }}</p>
                        </div>
                    @endif
                    @if ($lossEvent->rca->recommendations)
                        <div class="p-4 rounded-lg bg-green-50 border border-green-200">
                            <div class="text-[10px] font-semibold text-green-700 uppercase mb-1">Recommendations</div>
                            <p class="text-sm text-green-800">{{ $lossEvent->rca->recommendations }}</p>
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
                <button type="button" class="flex items-center gap-1 px-3 py-1.5 bg-[#1A365D] text-white text-xs rounded-lg hover:bg-[#2D4A7A]">
                    <span class="material-symbols-outlined text-sm">upload</span>
                    Upload File
                </button>
            </div>
            @if (($lossEvent->attachments ?? collect())->isNotEmpty())
                <div class="space-y-2">
                    @foreach ($lossEvent->attachments as $attachment)
                        <div class="flex items-center justify-between p-3 rounded-lg border border-gray-200 hover:bg-gray-50">
                            <div class="flex items-center gap-3">
                                <span class="material-symbols-outlined text-gray-400">description</span>
                                <div>
                                    <div class="text-sm font-medium text-gray-800">{{ $attachment->filename }}</div>
                                    <div class="text-[10px] text-gray-400">{{ $attachment->created_at?->format('d M Y') }} &middot; {{ $attachment->size_formatted ?? '' }}</div>
                                </div>
                            </div>
                            <a href="{{ $attachment->download_url ?? '#' }}" class="p-1 rounded hover:bg-gray-100">
                                <span class="material-symbols-outlined text-gray-500 text-lg">download</span>
                            </a>
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
</script>
@endpush
