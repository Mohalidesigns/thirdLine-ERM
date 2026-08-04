@extends('layouts.app')

@section('title', 'Issue Closure Verification - GRC Platform')

@section('breadcrumbs')
    <span>Risk Management</span>
    <span class="text-gray-300">/</span>
    <span>Issues & Findings</span>
    <span class="text-gray-300">/</span>
    <span class="text-[#1A365D] font-semibold">Closure Verification</span>
@endsection

@section('content')
    {{-- Page Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">Issue Closure Verification</h1>
            <p class="text-sm text-gray-500 mt-1">Review and verify issues pending closure approval</p>
        </div>
        <div class="flex items-center gap-2">
            <span class="inline-flex items-center gap-1 px-3 py-1.5 bg-yellow-100 text-yellow-700 rounded-full text-xs font-semibold">
                <span class="material-symbols-outlined text-sm">pending</span>
                {{ ($pendingClosures ?? collect())->count() }} Awaiting Closure
            </span>
        </div>
    </div>

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

    {{-- Summary Stats --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <x-kpi-card title="Pending Closure" :value="$pendingClosureCount ?? 0" icon="hourglass_top" color="warning" />
        <x-kpi-card title="Closed This Month" :value="$closedThisMonth ?? 0" icon="check_circle" color="success" />
        <x-kpi-card title="Returned for Rework" :value="$returnedCount ?? 0" icon="undo" color="danger" />
        <x-kpi-card title="Avg Closure Time" :value="($avgClosureTime ?? 0) . ' days'" icon="timer" color="info" />
    </div>

    {{-- Pending Closures List --}}
    <div class="space-y-4">
        @forelse (($pendingClosures ?? []) as $issue)
            <div class="bg-white rounded-xl border border-gray-200 p-5 hover:shadow-sm transition">
                <div class="flex items-start justify-between">
                    <div class="flex-1">
                        <div class="flex items-center gap-3 mb-2">
                            <a href="{{ url('/risk/issues/' . $issue->id) }}" class="text-xs font-mono text-gray-500 bg-gray-100 px-2 py-0.5 rounded hover:bg-gray-200">
                                {{ $issue->issue_reference }}
                            </a>
                            <x-risk-badge :rating="$issue->priority ?? 'medium'" />
                            @if ($issue->cbn_examination_finding)
                                <span class="inline-flex items-center gap-0.5 px-2 py-0.5 rounded-full bg-red-50 text-red-600 text-[10px] font-bold">
                                    CBN Finding
                                </span>
                            @endif
                        </div>
                        <h3 class="text-sm font-semibold text-[#1A365D] mb-1">
                            <a href="{{ url('/risk/issues/' . $issue->id) }}" class="hover:underline">{{ $issue->title }}</a>
                        </h3>
                        <p class="text-xs text-gray-500 line-clamp-2 mb-3">{{ Str::limit($issue->description, 150) }}</p>

                        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 text-xs">
                            <div>
                                <span class="text-gray-400 block">Source</span>
                                <span class="font-medium text-gray-700">{{ $issue->issue_source ?? '-' }}</span>
                            </div>
                            <div>
                                <span class="text-gray-400 block">Owner</span>
                                <span class="font-medium text-gray-700">{{ $issue->owner->name ?? '-' }}</span>
                            </div>
                            <div>
                                <span class="text-gray-400 block">Business Unit</span>
                                <span class="font-medium text-gray-700">{{ $issue->businessUnit->name ?? '-' }}</span>
                            </div>
                            <div>
                                <span class="text-gray-400 block">Days Open</span>
                                <span class="font-medium text-gray-700">{{ $issue->created_at ? $issue->created_at->diffInDays(now()) . 'd' : '-' }}</span>
                            </div>
                            <div>
                                <span class="text-gray-400 block">Completion</span>
                                <div class="flex items-center gap-2 mt-0.5">
                                    <div class="w-16 bg-gray-200 rounded-full h-1.5">
                                        <div class="h-1.5 rounded-full bg-green-500" style="width: {{ min($issue->progress_percentage ?? 0, 100) }}%"></div>
                                    </div>
                                    <span class="font-medium text-gray-700">{{ $issue->progress_percentage ?? 0 }}%</span>
                                </div>
                            </div>
                        </div>

                        {{-- Closure Evidence --}}
                        @if ($issue->closure_justification)
                            <div class="mt-3 p-3 bg-blue-50 rounded-lg border border-blue-100">
                                <div class="text-[10px] font-semibold text-blue-700 uppercase mb-1">Closure Evidence / Notes</div>
                                <p class="text-xs text-blue-800">{{ Str::limit($issue->closure_justification, 200) }}</p>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Action Buttons --}}
                <div class="flex items-center gap-3 mt-4 pt-4 border-t border-gray-100">
                    <form method="POST" action="{{ route('risk.issues.approve-closure', $issue) }}" class="inline">
                        @csrf
                        <button type="submit" class="flex items-center gap-1 px-4 py-2 bg-[#2D7D46] text-white text-xs font-semibold rounded-lg hover:bg-[#236B38] transition"
                                onclick="return confirm('Verify and close this issue?')">
                            <span class="material-symbols-outlined text-sm">check</span>
                            Verify & Close
                        </button>
                    </form>
                    <button type="button"
                            class="flex items-center gap-1 px-4 py-2 border border-yellow-300 text-yellow-700 text-xs font-semibold rounded-lg hover:bg-yellow-50 transition"
                            onclick="toggleClosureReturn('return-{{ $issue->id }}')">
                        <span class="material-symbols-outlined text-sm">undo</span>
                        Return for Rework
                    </button>
                    <a href="{{ url('/risk/issues/' . $issue->id) }}"
                       class="ml-auto flex items-center gap-1 text-xs text-[#1A365D] font-medium hover:underline">
                        View Full Details
                        <span class="material-symbols-outlined text-sm">arrow_forward</span>
                    </a>
                </div>

                {{-- Return Comment Form (hidden) --}}
                <div id="return-{{ $issue->id }}" class="hidden mt-4 p-4 bg-yellow-50 rounded-lg border border-yellow-200">
                    <form method="POST" action="{{ route('risk.issues.reject-closure', $issue) }}">
                        @csrf
                        <label class="block text-xs font-semibold text-yellow-700 mb-1.5">Return Comments</label>
                        <textarea name="rejection_reason" rows="3" required
                                  class="w-full text-sm border border-yellow-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-yellow-200 focus:border-yellow-400"
                                  placeholder="Explain why this issue is being returned for rework..."></textarea>
                        <div class="flex items-center gap-2 mt-2">
                            <button type="submit" class="px-4 py-2 bg-yellow-500 text-white text-xs font-semibold rounded-lg hover:bg-yellow-600 transition">
                                Submit Return
                            </button>
                            <button type="button" class="px-4 py-2 text-xs text-gray-500 hover:text-gray-700" onclick="toggleClosureReturn('return-{{ $issue->id }}')">
                                Cancel
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        @empty
            <div class="bg-white rounded-xl border border-gray-200 p-12 text-center">
                <span class="material-symbols-outlined text-4xl text-green-400 mb-3 block">task_alt</span>
                <h3 class="text-sm font-semibold text-gray-700 mb-1">All Clear</h3>
                <p class="text-xs text-gray-500">There are no issues pending closure verification</p>
            </div>
        @endforelse
    </div>

    {{-- Pagination --}}
    @if (($pendingClosures ?? collect()) instanceof \Illuminate\Pagination\LengthAwarePaginator && $pendingClosures->hasPages())
        <div class="mt-4 flex justify-center">
            {{ $pendingClosures->withQueryString()->links() }}
        </div>
    @endif
@endsection

@push('scripts')
<script>
function toggleClosureReturn(id) {
    const el = document.getElementById(id);
    if (el) el.classList.toggle('hidden');
}
</script>
@endpush
