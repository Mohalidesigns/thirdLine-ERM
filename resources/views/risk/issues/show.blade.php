@extends('layouts.app')

@section('title', ($issue->issue_reference ?? 'Issue') . ' - GRC Platform')

@section('breadcrumbs')
    <span>Risk Management</span>
    <span class="text-gray-300">/</span>
    <span>Issues & Findings</span>
    <span class="text-gray-300">/</span>
    <span class="text-[#1A365D] font-semibold">{{ $issue->issue_reference ?? 'Detail' }}</span>
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
                <span class="text-xs font-mono text-gray-500 bg-gray-100 px-2 py-0.5 rounded">{{ $issue->issue_reference }}</span>
                <x-risk-badge :rating="$issue->priority ?? 'medium'" />
                <x-status-badge :status="str_replace('_', ' ', $issue->issue_status ?? 'open')" />
                @if ($issue->is_overdue)
                    <span class="badge bg-red-100 text-red-700">OVERDUE</span>
                @endif
                @if ($issue->cbn_examination_finding)
                    <span class="badge bg-red-100 text-red-700">CBN Finding</span>
                @endif
            </div>
            <h1 class="text-xl font-bold text-[#1A365D]">{{ $issue->title }}</h1>
            <p class="text-sm text-gray-500 mt-1">
                Source: {{ $issue->issue_source ? ucwords(str_replace('_', ' ', $issue->issue_source)) : '-' }} &middot;
                Identified: {{ $issue->created_at?->format('d M Y') ?? '-' }} &middot;
                Owner: {{ $issue->issueOwner->name ?? '-' }}
            </p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ url('/risk/issues/' . $issue->id . '/edit') }}"
               class="flex items-center gap-1 px-3 py-2 border border-gray-200 rounded-lg text-xs font-medium text-gray-600 hover:bg-gray-50 transition">
                <span class="material-symbols-outlined text-sm">edit</span>
                Edit
            </a>
            <a href="{{ url('/risk/issues') }}"
               class="flex items-center gap-1 px-3 py-2 border border-gray-200 rounded-lg text-xs font-medium text-gray-600 hover:bg-gray-50 transition">
                <span class="material-symbols-outlined text-sm">arrow_back</span>
                Back
            </a>
        </div>
    </div>

    {{-- Summary Cards --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        {{-- Issue Details Card --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-4 flex items-center gap-2">
                <span class="material-symbols-outlined text-sm text-[#D4AF37]">info</span>
                Issue Details
            </h3>
            <div class="space-y-3">
                <div class="flex justify-between text-xs">
                    <span class="text-gray-500">Category</span>
                    <span class="font-medium text-gray-800">{{ $issue->issue_category ? ucwords(strtolower(str_replace('_', ' ', $issue->issue_category))) : '-' }}</span>
                </div>
                <div class="flex justify-between text-xs">
                    <span class="text-gray-500">Business Unit</span>
                    <span class="font-medium text-gray-800">{{ $issue->businessUnit->name ?? '-' }}</span>
                </div>
                <div class="flex justify-between text-xs">
                    <span class="text-gray-500">Days Open</span>
                    <span class="font-medium text-gray-800">{{ $issue->created_at ? (int) $issue->created_at->diffInDays(now()) . ' days' : '-' }}</span>
                </div>
                <div class="flex justify-between text-xs">
                    <span class="text-gray-500">Escalation Level</span>
                    @php
                        $escLevel = $issue->escalation_level ?? 0;
                        $escColors = ['bg-gray-100 text-gray-600', 'bg-yellow-100 text-yellow-700', 'bg-orange-100 text-orange-700', 'bg-red-100 text-red-700'];
                    @endphp
                    <span class="badge {{ $escColors[$escLevel] ?? $escColors[0] }}">Level {{ $escLevel }}</span>
                </div>
            </div>
        </div>

        {{-- Remediation Status Card --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-4 flex items-center gap-2">
                <span class="material-symbols-outlined text-sm text-[#D4AF37]">healing</span>
                Remediation Status
            </h3>
            <div class="space-y-3">
                <div class="flex justify-between text-xs">
                    <span class="text-gray-500">Due Date</span>
                    <span class="font-medium {{ $issue->is_overdue ? 'text-red-600' : 'text-gray-800' }}">
                        {{ ($issue->remediation_due_date ?? $issue->target_resolution_date)?->format('d M Y') ?? '-' }}
                    </span>
                </div>
                <div class="flex justify-between text-xs">
                    <span class="text-gray-500">Target Completion</span>
                    <span class="font-medium text-gray-800">{{ $issue->target_resolution_date?->format('d M Y') ?? '-' }}</span>
                </div>
                <div class="flex justify-between text-xs">
                    <span class="text-gray-500">Completion %</span>
                    <span class="font-medium text-gray-800">{{ $issue->progress_percentage ?? 0 }}%</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2 mt-1">
                    <div class="h-2 rounded-full {{ ($issue->progress_percentage ?? 0) >= 100 ? 'bg-green-500' : 'bg-[#1A365D]' }}"
                         style="width: {{ min($issue->progress_percentage ?? 0, 100) }}%"></div>
                </div>
            </div>
        </div>

        {{-- Regulatory Panel --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-4 flex items-center gap-2">
                <span class="material-symbols-outlined text-sm text-red-500">gavel</span>
                Regulatory
            </h3>
            <div class="space-y-3">
                <div class="flex items-center justify-between p-2 rounded-lg {{ $issue->cbn_examination_finding ? 'bg-red-50' : 'bg-gray-50' }}">
                    <span class="text-xs font-medium text-gray-700">CBN Examination Finding</span>
                    @if ($issue->cbn_examination_finding)
                        <span class="text-[10px] font-bold text-red-600">YES</span>
                    @else
                        <span class="text-[10px] text-gray-400">No</span>
                    @endif
                </div>
                @if ($issue->bofia_section)
                    <div>
                        <span class="text-[10px] text-gray-400 uppercase">BOFIA Section</span>
                        <div class="text-xs font-medium text-gray-800">{{ $issue->bofia_section }}</div>
                    </div>
                @endif
                @if ($issue->ndpa_breach_type)
                    <div>
                        <span class="text-[10px] text-gray-400 uppercase">NDPA Breach Type</span>
                        <div class="text-xs font-medium text-gray-800">{{ $issue->ndpa_breach_type }}</div>
                    </div>
                @endif
                @if ($issue->cbn_regulatory_deadline)
                    <div class="p-2 bg-yellow-50 rounded-lg border border-yellow-200">
                        <div class="text-[10px] text-yellow-700 font-semibold">CBN Deadline: {{ $issue->cbn_regulatory_deadline->format('d M Y') }}</div>
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Tabs --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="border-b border-gray-200 px-5">
            <nav class="flex gap-6 -mb-px" id="issueTabs">
                @foreach ([
                    'overview' => 'Overview',
                    'remediation' => 'Remediation Actions',
                    'progress' => 'Progress Updates',
                    'escalation' => 'Escalation History',
                    'attachments' => 'Attachments',
                ] as $tabId => $tabLabel)
                    <button type="button"
                            class="py-3 text-xs font-medium transition-all border-b-2 {{ $loop->first ? 'tab-active' : 'tab-inactive' }}"
                            data-tab="{{ $tabId }}"
                            onclick="switchIssueTab('{{ $tabId }}')">
                        {{ $tabLabel }}
                    </button>
                @endforeach
            </nav>
        </div>

        {{-- Tab: Overview --}}
        <div id="itab-overview" class="issue-tab-panel p-6">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div>
                    <h4 class="text-sm font-semibold text-[#1A365D] mb-3">Issue Description</h4>
                    <p class="text-sm text-gray-600 leading-relaxed">{{ $issue->description ?? 'No description provided.' }}</p>
                </div>
                <div>
                    <h4 class="text-sm font-semibold text-[#1A365D] mb-3">Action Plan</h4>
                    <p class="text-sm text-gray-600 leading-relaxed">{{ $issue->action_plan ?? 'No action plan defined.' }}</p>
                </div>
            </div>
            @if ($issue->interim_controls)
                <div class="mt-6 p-4 bg-blue-50 rounded-lg border border-blue-200">
                    <h5 class="text-xs font-semibold text-blue-700 mb-1">Interim Controls</h5>
                    <p class="text-sm text-blue-800">{{ $issue->interim_controls }}</p>
                </div>
            @endif
        </div>

        {{-- Tab: Remediation Actions --}}
        <div id="itab-remediation" class="issue-tab-panel p-6 hidden">
            <div class="flex items-center justify-between mb-4">
                <h4 class="text-sm font-semibold text-[#1A365D]">Remediation Actions</h4>
                @if ($issue->issue_status !== 'CLOSED')
                    <button type="button" onclick="document.getElementById('addActionForm').classList.toggle('hidden')"
                            class="flex items-center gap-1 px-3 py-1.5 bg-[#1A365D] text-white text-xs rounded-lg hover:bg-[#2D4A7A]">
                        <span class="material-symbols-outlined text-sm">add</span>
                        Add Action
                    </button>
                @endif
            </div>

            @if ($issue->issue_status !== 'CLOSED')
                <div id="addActionForm" class="hidden mb-4 p-4 bg-gray-50 rounded-lg border border-gray-200">
                    <form method="POST" action="{{ route('risk.issues.add-action', $issue) }}">
                        @csrf
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div class="md:col-span-2">
                                <label class="block text-xs font-semibold text-gray-700 mb-1">Action Description <span class="text-red-500">*</span></label>
                                <textarea name="description" rows="2" required maxlength="3000"
                                    class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2"
                                    placeholder="Describe the remediation action..."></textarea>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1">Owner <span class="text-red-500">*</span></label>
                                <select name="owner_id" required class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2">
                                    @foreach (\App\Models\User::where('organization_id', $issue->organization_id)->orderBy('name')->get() as $u)
                                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1">Target Date <span class="text-red-500">*</span></label>
                                <input type="date" name="target_date" required min="{{ now()->addDay()->toDateString() }}"
                                    class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1">Priority <span class="text-red-500">*</span></label>
                                <select name="priority" required class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2">
                                    @foreach (['critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'] as $v => $l)
                                        <option value="{{ $v }}">{{ $l }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1">Department</label>
                                <input type="text" name="department" maxlength="255"
                                    class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2">
                            </div>
                        </div>
                        <div class="flex justify-end gap-2 mt-3">
                            <button type="button" class="px-3 py-1.5 text-xs text-gray-500"
                                onclick="document.getElementById('addActionForm').classList.add('hidden')">Cancel</button>
                            <button type="submit" class="px-4 py-1.5 bg-[#1A365D] text-white text-xs font-semibold rounded-lg hover:bg-[#2D4A7A]">Save Action</button>
                        </div>
                    </form>
                </div>
            @endif

            @if (($issue->remediationActions ?? collect())->isNotEmpty())
                <div class="space-y-3">
                    @foreach ($issue->remediationActions as $action)
                        <div class="p-4 rounded-lg border border-gray-200">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-sm font-medium text-gray-800">{{ $action->description }}</span>
                                <x-status-badge :status="$action->status ?? 'pending'" />
                            </div>
                            <p class="text-xs text-gray-600 mb-2">{{ $action->description }}</p>
                            <div class="flex items-center gap-4 text-[10px] text-gray-400">
                                <span>Assigned: {{ $action->owner->name ?? '-' }}</span>
                                <span>Due: {{ $action->target_date?->format('d M Y') ?? '-' }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="text-center py-8 text-gray-400">
                    <span class="material-symbols-outlined text-3xl mb-2 block">checklist</span>
                    <p class="text-sm">No remediation actions defined</p>
                </div>
            @endif
        </div>

        {{-- Tab: Progress Updates --}}
        <div id="itab-progress" class="issue-tab-panel p-6 hidden">
            <div class="flex items-center justify-between mb-4">
                <h4 class="text-sm font-semibold text-[#1A365D]">Progress Updates</h4>
                @if ($issue->issue_status !== 'CLOSED')
                    <button type="button" onclick="document.getElementById('addUpdateForm').classList.toggle('hidden')"
                            class="flex items-center gap-1 px-3 py-1.5 bg-[#1A365D] text-white text-xs rounded-lg hover:bg-[#2D4A7A]">
                        <span class="material-symbols-outlined text-sm">add</span>
                        Add Update
                    </button>
                @endif
            </div>

            @if ($issue->issue_status !== 'CLOSED')
                <div id="addUpdateForm" class="hidden mb-4 p-4 bg-gray-50 rounded-lg border border-gray-200">
                    <form method="POST" action="{{ route('risk.issues.add-update', $issue) }}">
                        @csrf
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div class="md:col-span-2">
                                <label class="block text-xs font-semibold text-gray-700 mb-1">Update <span class="text-red-500">*</span></label>
                                <textarea name="description" rows="2" required maxlength="3000"
                                    class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2"
                                    placeholder="Describe the progress made..."></textarea>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1">Update Type <span class="text-red-500">*</span></label>
                                <select name="update_type" required class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2">
                                    @foreach (['progress' => 'Progress', 'milestone' => 'Milestone', 'escalation' => 'Escalation', 'note' => 'Note'] as $v => $l)
                                        <option value="{{ $v }}">{{ $l }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1">Progress (%)</label>
                                <input type="number" name="progress_pct" min="0" max="100" value="{{ $issue->progress_percentage }}"
                                    class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2">
                            </div>
                        </div>
                        <div class="flex justify-end gap-2 mt-3">
                            <button type="button" class="px-3 py-1.5 text-xs text-gray-500"
                                onclick="document.getElementById('addUpdateForm').classList.add('hidden')">Cancel</button>
                            <button type="submit" class="px-4 py-1.5 bg-[#1A365D] text-white text-xs font-semibold rounded-lg hover:bg-[#2D4A7A]">Save Update</button>
                        </div>
                    </form>
                </div>
            @endif

            @if (($issue->progressUpdates ?? collect())->isNotEmpty())
                <div class="space-y-4">
                    @foreach ($issue->progressUpdates as $update)
                        <div class="flex gap-4">
                            <div class="flex flex-col items-center">
                                <div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center flex-shrink-0">
                                    <span class="material-symbols-outlined text-sm">edit_note</span>
                                </div>
                                @if (!$loop->last)
                                    <div class="w-px h-full bg-gray-200 mt-2"></div>
                                @endif
                            </div>
                            <div class="flex-1 pb-4">
                                <div class="flex items-center justify-between mb-1">
                                    <span class="text-xs font-semibold text-gray-800">{{ $update->user->name ?? 'Unknown' }}</span>
                                    <span class="text-[10px] text-gray-400">{{ $update->created_at?->format('d M Y H:i') }}</span>
                                </div>
                                <p class="text-sm text-gray-600">{{ $update->content }}</p>
                                @if ($update->completion_percentage !== null)
                                    <div class="mt-2 flex items-center gap-2">
                                        <div class="w-24 bg-gray-200 rounded-full h-1.5">
                                            <div class="h-1.5 rounded-full bg-[#2D7D46]" style="width: {{ $update->completion_percentage }}%"></div>
                                        </div>
                                        <span class="text-[10px] text-gray-500">{{ $update->completion_percentage }}%</span>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="text-center py-8 text-gray-400">
                    <span class="material-symbols-outlined text-3xl mb-2 block">update</span>
                    <p class="text-sm">No progress updates yet</p>
                </div>
            @endif
        </div>

        {{-- Tab: Escalation History --}}
        <div id="itab-escalation" class="issue-tab-panel p-6 hidden">
            <h4 class="text-sm font-semibold text-[#1A365D] mb-4">Escalation History</h4>

            @if (($issue->escalationLogs ?? collect())->isNotEmpty())
                <div class="space-y-3">
                    @foreach ($issue->escalationLogs as $escalation)
                        @php $escLvl = $escalation->escalation_level ?? 0; @endphp
                        <div class="flex items-start gap-4 p-4 rounded-lg border border-gray-200 {{ $escLvl >= 3 ? 'bg-red-50/50 border-red-200' : '' }}">
                            <div class="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0
                                {{ $escLvl >= 3 ? 'bg-red-100 text-red-600' : ($escLvl >= 2 ? 'bg-orange-100 text-orange-600' : 'bg-yellow-100 text-yellow-600') }}">
                                <span class="material-symbols-outlined text-sm">arrow_upward</span>
                            </div>
                            <div class="flex-1">
                                <div class="flex items-center justify-between">
                                    <span class="text-sm font-medium text-gray-800">Escalated to Level {{ $escLvl }}</span>
                                    <span class="text-[10px] text-gray-400">{{ $escalation->escalated_at?->format('d M Y H:i') }}</span>
                                </div>
                                <div class="text-xs text-gray-600 mt-1">
                                    Escalated by: {{ $escalation->escalatedBy->name ?? '-' }} &middot;
                                    To: {{ $escalation->escalatedToUser->name ?? '-' }}
                                </div>
                                @if ($escalation->reason)
                                    <p class="text-xs text-gray-500 mt-2">{{ $escalation->reason }}</p>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="text-center py-8 text-gray-400">
                    <span class="material-symbols-outlined text-3xl mb-2 block">trending_up</span>
                    <p class="text-sm">No escalation history</p>
                </div>
            @endif
        </div>

        {{-- Tab: Attachments --}}
        <div id="itab-attachments" class="issue-tab-panel p-6 hidden">
            <div class="flex items-center justify-between mb-4">
                <h4 class="text-sm font-semibold text-[#1A365D]">Attachments</h4>
                <button type="button" onclick="document.getElementById('uploadFileForm').classList.toggle('hidden')"
                        class="flex items-center gap-1 px-3 py-1.5 bg-[#1A365D] text-white text-xs rounded-lg hover:bg-[#2D4A7A]">
                    <span class="material-symbols-outlined text-sm">upload</span>
                    Upload File
                </button>
            </div>

            <div id="uploadFileForm" class="hidden mb-4 p-4 bg-gray-50 rounded-lg border border-gray-200">
                <form method="POST" action="{{ route('risk.issues.upload-attachment', $issue) }}" enctype="multipart/form-data">
                    @csrf
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">File <span class="text-red-500">*</span></label>
                            <input type="file" name="file" required
                                class="w-full text-xs border border-gray-200 rounded-lg px-3 py-2 bg-white">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Document Type</label>
                            <select name="document_type" class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2">
                                @foreach (['evidence' => 'Evidence', 'report' => 'Report', 'remediation_plan' => 'Remediation Plan', 'correspondence' => 'Correspondence', 'other' => 'Other'] as $v => $l)
                                    <option value="{{ $v }}">{{ $l }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="flex items-end pb-2">
                            <label class="inline-flex items-center gap-2 text-xs font-semibold text-gray-700">
                                <input type="hidden" name="is_regulatory" value="0">
                                <input type="checkbox" name="is_regulatory" value="1" class="rounded border-gray-300 text-[#1A365D]">
                                Regulatory document
                            </label>
                        </div>
                    </div>
                    <div class="flex justify-end gap-2 mt-3">
                        <button type="button" class="px-3 py-1.5 text-xs text-gray-500"
                            onclick="document.getElementById('uploadFileForm').classList.add('hidden')">Cancel</button>
                        <button type="submit" class="px-4 py-1.5 bg-[#1A365D] text-white text-xs font-semibold rounded-lg hover:bg-[#2D4A7A]">Upload</button>
                    </div>
                </form>
            </div>
            @if (($issue->attachments ?? collect())->isNotEmpty())
                <div class="space-y-2">
                    @foreach ($issue->attachments as $attachment)
                        <div class="flex items-center justify-between p-3 rounded-lg border border-gray-200 hover:bg-gray-50">
                            <div class="flex items-center gap-3">
                                <span class="material-symbols-outlined text-gray-400">description</span>
                                <div>
                                    <div class="text-sm font-medium text-gray-800">{{ $attachment->file_name }}</div>
                                    <div class="text-[10px] text-gray-400">{{ $attachment->created_at?->format('d M Y') }} &middot; {{ $attachment->size_formatted ?? '' }}</div>
                                </div>
                            </div>
                            <a href="{{ route('risk.issues.download-attachment', [$issue, $attachment]) }}" class="p-1 rounded hover:bg-gray-100">
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
function switchIssueTab(tabId) {
    document.querySelectorAll('.issue-tab-panel').forEach(function(panel) {
        panel.classList.add('hidden');
    });
    document.querySelectorAll('#issueTabs button').forEach(function(btn) {
        btn.classList.remove('tab-active');
        btn.classList.add('tab-inactive');
    });

    const panel = document.getElementById('itab-' + tabId);
    if (panel) panel.classList.remove('hidden');

    const btn = document.querySelector('#issueTabs button[data-tab="' + tabId + '"]');
    if (btn) {
        btn.classList.remove('tab-inactive');
        btn.classList.add('tab-active');
    }
}
</script>
@endpush
