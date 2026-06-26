@extends('layouts.app')
@section('title', 'Control Test: ' . $controlTest->test_code)
@section('breadcrumbs')
    <a href="/risk/dashboard" class="hover:text-primary">Dashboard</a>
    <span class="material-symbols-outlined text-[14px]">chevron_right</span>
    <a href="{{ route('risk.control-tests.index') }}" class="hover:text-primary">Control Tests</a>
    <span class="material-symbols-outlined text-[14px]">chevron_right</span>
    <span class="text-gray-700 font-medium">{{ $controlTest->test_code }}</span>
@endsection

@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-gray-900">{{ $controlTest->title }}</h1>
            <p class="text-sm text-gray-500 mt-1">{{ $controlTest->test_code }} &middot; {{ ucfirst(str_replace('_', ' ', $controlTest->test_type)) }}</p>
        </div>
        <div class="flex gap-2">
            @if($controlTest->status === 'scheduled')
                <form method="POST" action="{{ route('risk.control-tests.start', $controlTest) }}">
                    @csrf
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700">Start Test</button>
                </form>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Main Info --}}
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                <h3 class="text-sm font-semibold text-gray-900 mb-4">Test Details</h3>
                <div class="grid grid-cols-2 gap-4 text-sm">
                    <div><span class="text-gray-500">Control:</span> <span class="font-medium">{{ $controlTest->control?->name }}</span></div>
                    <div><span class="text-gray-500">Test Type:</span> <span class="font-medium">{{ ucfirst(str_replace('_', ' ', $controlTest->test_type)) }}</span></div>
                    <div><span class="text-gray-500">Tester:</span> <span class="font-medium">{{ $controlTest->tester?->name ?? '—' }}</span></div>
                    <div><span class="text-gray-500">Reviewer:</span> <span class="font-medium">{{ $controlTest->reviewer?->name ?? '—' }}</span></div>
                    <div><span class="text-gray-500">Scheduled:</span> <span class="font-medium">{{ $controlTest->scheduled_date->format('M d, Y') }}</span></div>
                    <div><span class="text-gray-500">Completed:</span> <span class="font-medium">{{ $controlTest->completed_date?->format('M d, Y') ?? '—' }}</span></div>
                </div>
                @if($controlTest->description)
                    <div class="mt-4 pt-4 border-t border-gray-100">
                        <p class="text-sm text-gray-700">{{ $controlTest->description }}</p>
                    </div>
                @endif
            </div>

            {{-- Complete Test Form --}}
            @if($controlTest->status === 'in_progress')
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                <h3 class="text-sm font-semibold text-gray-900 mb-4">Record Test Results</h3>
                <form method="POST" action="{{ route('risk.control-tests.complete', $controlTest) }}" class="space-y-4">
                    @csrf
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Result <span class="text-red-500">*</span></label>
                        <select name="result" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                            <option value="effective">Effective</option>
                            <option value="partially_effective">Partially Effective</option>
                            <option value="ineffective">Ineffective</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Score (0-100)</label>
                        <input type="number" name="score" min="0" max="100" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Findings</label>
                        <textarea name="findings" rows="4" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" placeholder="Describe test findings..."></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Recommendations</label>
                        <textarea name="recommendations" rows="3" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" placeholder="Recommendations for improvement..."></textarea>
                    </div>
                    <button type="submit" class="px-6 py-2 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700">Complete Test</button>
                </form>
            </div>
            @endif

            {{-- Review panel: reviewer sees approve/reject; tester sees "pending" status --}}
            @if($controlTest->status === 'pending_review')
                @can('review-control-test', $controlTest)
                <div class="bg-white rounded-xl border border-blue-200 shadow-sm p-6" x-data="{ rejecting: false }">
                    <div class="flex items-center gap-2 mb-4">
                        <span class="material-symbols-outlined text-blue-600">rate_review</span>
                        <h3 class="text-sm font-semibold text-gray-900">Review Required</h3>
                    </div>
                    <p class="text-sm text-gray-600 mb-4">You are the assigned reviewer for this test. Approve to finalise, or reject with a reason so the tester can rework.</p>

                    {{-- Approve form --}}
                    <form method="POST" action="{{ route('risk.control-tests.review', $controlTest) }}" class="space-y-3 mb-4" x-show="!rejecting">
                        @csrf
                        <input type="hidden" name="action" value="approve">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Reviewer Notes (optional)</label>
                            <textarea name="reviewer_notes" rows="2" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" placeholder="Any observations…"></textarea>
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

                    {{-- Reject form --}}
                    <form method="POST" action="{{ route('risk.control-tests.review', $controlTest) }}" class="space-y-3" x-show="rejecting" x-cloak>
                        @csrf
                        <input type="hidden" name="action" value="reject">
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
                <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-4 flex items-start gap-3">
                    <span class="material-symbols-outlined text-yellow-600">hourglass_empty</span>
                    <div>
                        <p class="text-sm font-semibold text-yellow-800">Pending Reviewer Approval</p>
                        <p class="text-xs text-yellow-700 mt-1">Awaiting review by <strong>{{ $controlTest->reviewer?->name ?? 'the assigned reviewer' }}</strong>. They have been notified by email.</p>
                    </div>
                </div>
                @endcan
            @endif

            {{-- Rejected banner + resubmit --}}
            @if($controlTest->status === 'rejected')
                <div class="bg-red-50 border border-red-200 rounded-xl p-4">
                    <div class="flex items-start gap-3 mb-3">
                        <span class="material-symbols-outlined text-red-600">block</span>
                        <div class="flex-1">
                            <p class="text-sm font-semibold text-red-800">Test Rejected</p>
                            @if($controlTest->reviewer_notes)
                                <p class="text-xs text-red-700 mt-1 whitespace-pre-line"><strong>Reviewer comment:</strong> {{ $controlTest->reviewer_notes }}</p>
                            @endif
                            @php $pendingReq = \App\Models\ApprovalRequest::where('entity_type','ControlTest')->where('entity_id',$controlTest->id)->where('status','rejected')->latest('reviewed_at')->first(); @endphp
                            @if($pendingReq && $pendingReq->rejection_reason)
                                <p class="text-xs text-red-700 mt-1 whitespace-pre-line"><strong>Reason:</strong> {{ $pendingReq->rejection_reason }}</p>
                            @endif
                            <p class="text-[11px] text-red-600 mt-2">Reviewed by {{ $controlTest->reviewer?->name ?? 'Reviewer' }} on {{ $controlTest->reviewed_at?->format('M d, Y H:i') }}</p>
                        </div>
                    </div>
                    @can('resubmit-control-test', $controlTest)
                    <form method="POST" action="{{ route('risk.control-tests.resubmit', $controlTest) }}">
                        @csrf
                        <button type="submit" class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-1">
                            <span class="material-symbols-outlined text-sm">refresh</span> Resubmit for rework
                        </button>
                    </form>
                    @endcan
                </div>
            @endif

            {{-- Results Display --}}
            @if(in_array($controlTest->status, ['completed', 'pending_review', 'rejected']))
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                <h3 class="text-sm font-semibold text-gray-900 mb-4">Test Results</h3>
                <div class="space-y-3">
                    <div class="flex items-center gap-3">
                        <span class="text-sm text-gray-500">Result:</span>
                        @if($controlTest->result === 'effective')
                            <span class="badge bg-green-100 text-green-700 text-sm">Effective</span>
                        @elseif($controlTest->result === 'partially_effective')
                            <span class="badge bg-yellow-100 text-yellow-700 text-sm">Partially Effective</span>
                        @else
                            <span class="badge bg-red-100 text-red-700 text-sm">Ineffective</span>
                        @endif
                        @if($controlTest->score)
                            <span class="text-sm font-semibold ml-2">Score: {{ $controlTest->score }}/100</span>
                        @endif
                    </div>
                    @if($controlTest->findings)
                        <div class="pt-3 border-t border-gray-100">
                            <h4 class="text-xs font-semibold text-gray-500 uppercase mb-2">Findings</h4>
                            <p class="text-sm text-gray-700">{{ $controlTest->findings }}</p>
                        </div>
                    @endif
                    @if($controlTest->recommendations)
                        <div class="pt-3 border-t border-gray-100">
                            <h4 class="text-xs font-semibold text-gray-500 uppercase mb-2">Recommendations</h4>
                            <p class="text-sm text-gray-700">{{ $controlTest->recommendations }}</p>
                        </div>
                    @endif
                </div>
            </div>
            @endif
        </div>

        {{-- Sidebar --}}
        <div class="space-y-6">
            {{-- Status Card --}}
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                <h3 class="text-sm font-semibold text-gray-900 mb-3">Status</h3>
                @php $statusColors = ['scheduled'=>'gray','in_progress'=>'blue','pending_review'=>'yellow','completed'=>'green','rejected'=>'red','cancelled'=>'red']; @endphp
                <span class="badge bg-{{ $statusColors[$controlTest->status] ?? 'gray' }}-100 text-{{ $statusColors[$controlTest->status] ?? 'gray' }}-700 text-sm">
                    {{ ucfirst(str_replace('_', ' ', $controlTest->status)) }}
                </span>
            </div>

            {{-- Evidence --}}
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                <h3 class="text-sm font-semibold text-gray-900 mb-3">Evidence</h3>
                @foreach($controlTest->evidence as $ev)
                    <div class="flex items-center gap-2 py-2 {{ !$loop->last ? 'border-b border-gray-50' : '' }}">
                        <span class="material-symbols-outlined text-gray-400 text-lg">attach_file</span>
                        <div>
                            <p class="text-xs font-medium text-gray-700">{{ $ev->file_name }}</p>
                            <p class="text-[10px] text-gray-400">{{ $ev->uploader?->name }} &middot; {{ $ev->created_at->format('M d') }}</p>
                        </div>
                    </div>
                @endforeach

                @if(in_array($controlTest->status, ['in_progress', 'pending_review']))
                <form method="POST" action="{{ route('risk.control-tests.upload-evidence', $controlTest) }}" enctype="multipart/form-data" class="mt-3 pt-3 border-t border-gray-100">
                    @csrf
                    <input type="file" name="file" required class="text-xs w-full">
                    <input type="text" name="description" placeholder="Description..." class="w-full border border-gray-200 rounded px-2 py-1 text-xs mt-2">
                    <button type="submit" class="mt-2 px-3 py-1 bg-primary text-white text-xs rounded hover:bg-opacity-90">Upload</button>
                </form>
                @endif
            </div>

            {{-- Related Risks --}}
            @if($controlTest->control?->risks?->count())
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                <h3 class="text-sm font-semibold text-gray-900 mb-3">Related Risks</h3>
                @foreach($controlTest->control->risks as $risk)
                    <a href="{{ route('risk.register.show', $risk) }}" class="block py-2 text-xs text-primary hover:underline {{ !$loop->last ? 'border-b border-gray-50' : '' }}">
                        {{ $risk->risk_code }} — {{ Str::limit($risk->title, 35) }}
                    </a>
                @endforeach
            </div>
            @endif
        </div>
    </div>
</div>
@endsection
