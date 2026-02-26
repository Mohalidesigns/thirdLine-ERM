@extends('layouts.app')

@section('title', 'Loss Event Approvals - GRC Platform')

@section('breadcrumbs')
    <span>Risk Management</span>
    <span class="text-gray-300">/</span>
    <span>Loss Events</span>
    <span class="text-gray-300">/</span>
    <span class="text-[#1A365D] font-semibold">Approvals</span>
@endsection

@section('content')
    {{-- Page Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">Pending Approvals</h1>
            <p class="text-sm text-gray-500 mt-1">Review and approve loss events pending your authorization</p>
        </div>
        <div class="flex items-center gap-2">
            <span class="inline-flex items-center gap-1 px-3 py-1.5 bg-yellow-100 text-yellow-700 rounded-full text-xs font-semibold">
                <span class="material-symbols-outlined text-sm">pending</span>
                {{ ($pendingApprovals ?? collect())->count() }} Pending
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

    {{-- Pending Approvals List --}}
    <div class="space-y-4">
        @forelse (($pendingApprovals ?? []) as $approval)
            <div class="bg-white rounded-xl border border-gray-200 p-5 hover:shadow-sm transition">
                <div class="flex items-start justify-between">
                    <div class="flex-1">
                        <div class="flex items-center gap-3 mb-2">
                            <a href="{{ url('/risk/loss-events/' . $approval->lossEvent->id) }}" class="text-xs font-mono text-gray-500 bg-gray-100 px-2 py-0.5 rounded hover:bg-gray-200">
                                {{ $approval->lossEvent->reference ?? '-' }}
                            </a>
                            <x-risk-badge :rating="$approval->lossEvent->severity ?? 'low'" />
                            @if ($approval->lossEvent->cbn_reportable)
                                <span class="inline-flex items-center gap-0.5 px-2 py-0.5 rounded-full bg-red-50 text-red-600 text-[10px] font-bold">
                                    <span class="material-symbols-outlined text-xs">flag</span> CBN
                                </span>
                            @endif
                        </div>
                        <h3 class="text-sm font-semibold text-[#1A365D] mb-1">
                            <a href="{{ url('/risk/loss-events/' . $approval->lossEvent->id) }}" class="hover:underline">
                                {{ $approval->lossEvent->title }}
                            </a>
                        </h3>
                        <p class="text-xs text-gray-500 line-clamp-2 mb-3">{{ Str::limit($approval->lossEvent->description, 150) }}</p>

                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-xs">
                            <div>
                                <span class="text-gray-400 block">Gross Loss</span>
                                <span class="font-semibold text-red-600">₦{{ number_format($approval->lossEvent->gross_loss_amount ?? 0, 2) }}</span>
                            </div>
                            <div>
                                <span class="text-gray-400 block">Business Unit</span>
                                <span class="font-medium text-gray-700">{{ $approval->lossEvent->businessUnit->name ?? '-' }}</span>
                            </div>
                            <div>
                                <span class="text-gray-400 block">Submitted By</span>
                                <span class="font-medium text-gray-700">{{ $approval->submittedBy->name ?? '-' }}</span>
                            </div>
                            <div>
                                <span class="text-gray-400 block">Submitted</span>
                                <span class="font-medium text-gray-700">{{ $approval->created_at?->format('d M Y H:i') ?? '-' }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Action Buttons --}}
                <div class="flex items-center gap-3 mt-4 pt-4 border-t border-gray-100">
                    <form method="POST" action="{{ url('/risk/loss-events/approvals/' . $approval->id . '/approve') }}" class="inline">
                        @csrf
                        <button type="submit" class="flex items-center gap-1 px-4 py-2 bg-[#2D7D46] text-white text-xs font-semibold rounded-lg hover:bg-[#236B38] transition">
                            <span class="material-symbols-outlined text-sm">check</span>
                            Approve
                        </button>
                    </form>
                    <button type="button"
                            class="flex items-center gap-1 px-4 py-2 border border-yellow-300 text-yellow-700 text-xs font-semibold rounded-lg hover:bg-yellow-50 transition"
                            onclick="toggleReturnForm('return-{{ $approval->id }}')">
                        <span class="material-symbols-outlined text-sm">undo</span>
                        Return
                    </button>
                    <form method="POST" action="{{ url('/risk/loss-events/approvals/' . $approval->id . '/escalate') }}" class="inline">
                        @csrf
                        <button type="submit" class="flex items-center gap-1 px-4 py-2 border border-red-300 text-red-600 text-xs font-semibold rounded-lg hover:bg-red-50 transition">
                            <span class="material-symbols-outlined text-sm">arrow_upward</span>
                            Escalate
                        </button>
                    </form>
                    <a href="{{ url('/risk/loss-events/' . $approval->lossEvent->id) }}"
                       class="ml-auto flex items-center gap-1 text-xs text-[#1A365D] font-medium hover:underline">
                        View Full Details
                        <span class="material-symbols-outlined text-sm">arrow_forward</span>
                    </a>
                </div>

                {{-- Return Comment Form (hidden) --}}
                <div id="return-{{ $approval->id }}" class="hidden mt-4 p-4 bg-yellow-50 rounded-lg border border-yellow-200">
                    <form method="POST" action="{{ url('/risk/loss-events/approvals/' . $approval->id . '/return') }}">
                        @csrf
                        <label class="block text-xs font-semibold text-yellow-700 mb-1.5">Return Comments</label>
                        <textarea name="comments" rows="3" required
                                  class="w-full text-sm border border-yellow-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-yellow-200 focus:border-yellow-400"
                                  placeholder="Please specify the reason for returning this event..."></textarea>
                        <div class="flex items-center gap-2 mt-2">
                            <button type="submit" class="px-4 py-2 bg-yellow-500 text-white text-xs font-semibold rounded-lg hover:bg-yellow-600 transition">
                                Submit Return
                            </button>
                            <button type="button" class="px-4 py-2 text-xs text-gray-500 hover:text-gray-700" onclick="toggleReturnForm('return-{{ $approval->id }}')">
                                Cancel
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        @empty
            <div class="bg-white rounded-xl border border-gray-200 p-12 text-center">
                <span class="material-symbols-outlined text-4xl text-green-400 mb-3 block">task_alt</span>
                <h3 class="text-sm font-semibold text-gray-700 mb-1">All Caught Up</h3>
                <p class="text-xs text-gray-500">There are no loss events pending your approval</p>
            </div>
        @endforelse
    </div>
@endsection

@push('scripts')
<script>
function toggleReturnForm(id) {
    const el = document.getElementById(id);
    if (el) el.classList.toggle('hidden');
}
</script>
@endpush
