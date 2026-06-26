@extends('layouts.app')

@section('title', 'Approvals - GRC Risk Management')
@section('page-section', 'Risk Management')
@section('page-title', 'Approvals')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="text-gray-500 hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Approvals</span>
@endsection

@section('content')
<div x-data="{ approveOpen: null, rejectOpen: null }">

    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-[#1A365D]">Approval Dashboard</h1>
            <p class="text-sm text-gray-500 mt-1">Review and manage pending approvals across the platform.</p>
        </div>
        <a href="{{ route('risk.approvals.history') }}"
           class="px-4 py-2 border border-[#1A365D] text-[#1A365D] rounded-lg text-sm font-medium hover:bg-[#1A365D] hover:text-white transition inline-flex items-center gap-2">
            <span class="material-symbols-outlined" style="font-size: 18px;">history</span>
            View History
        </a>
    </div>

    {{-- KPI cards --}}
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs text-gray-500 uppercase tracking-wide">Pending</p>
                    <p class="text-3xl font-bold text-yellow-600 mt-1">{{ $stats['pending'] ?? 0 }}</p>
                </div>
                <span class="material-symbols-outlined text-yellow-500 opacity-50" style="font-size: 36px;">hourglass_empty</span>
            </div>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs text-gray-500 uppercase tracking-wide">Approved</p>
                    <p class="text-3xl font-bold text-green-600 mt-1">{{ $stats['approved'] ?? 0 }}</p>
                </div>
                <span class="material-symbols-outlined text-green-500 opacity-50" style="font-size: 36px;">check_circle</span>
            </div>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs text-gray-500 uppercase tracking-wide">Rejected</p>
                    <p class="text-3xl font-bold text-red-600 mt-1">{{ $stats['rejected'] ?? 0 }}</p>
                </div>
                <span class="material-symbols-outlined text-red-500 opacity-50" style="font-size: 36px;">cancel</span>
            </div>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs text-gray-500 uppercase tracking-wide">Total</p>
                    <p class="text-3xl font-bold text-[#1A365D] mt-1">{{ $stats['total'] ?? 0 }}</p>
                </div>
                <span class="material-symbols-outlined text-[#1A365D] opacity-50" style="font-size: 36px;">inventory_2</span>
            </div>
        </div>
    </div>

    @if ($errors->any())
        <div class="mb-4 p-4 rounded-lg bg-red-50 border border-red-200 text-sm text-red-700">
            <strong>Error:</strong> {{ $errors->first() }}
        </div>
    @endif
    @if (session('success'))
        <div class="mb-4 p-4 rounded-lg bg-green-50 border border-green-200 text-sm text-green-700">
            {{ session('success') }}
        </div>
    @endif

    @if ($pending->isEmpty())
        <div class="bg-white rounded-xl border border-gray-200 py-16 text-center">
            <span class="material-symbols-outlined text-green-500" style="font-size: 56px;">check_circle</span>
            <h5 class="mt-3 text-lg font-semibold text-[#1A365D]">All Caught Up</h5>
            <p class="text-sm text-gray-500">No pending approvals at this time.</p>
        </div>
    @else
        @foreach ($groupedByEntity as $entityType => $approvals)
            <div class="bg-white rounded-xl border border-gray-200 mb-6 overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-200 bg-gray-50 flex items-center gap-2">
                    <span class="px-2 py-0.5 rounded-full bg-yellow-100 text-yellow-700 text-xs font-semibold">{{ count($approvals) }}</span>
                    <h6 class="text-sm font-semibold text-[#1A365D]">{{ $entityType }} Approvals</h6>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-xs text-gray-600 uppercase">
                            <tr>
                                <th class="px-5 py-3 text-left">Entity</th>
                                <th class="px-5 py-3 text-left">Action</th>
                                <th class="px-5 py-3 text-left">Requested By</th>
                                <th class="px-5 py-3 text-left">Requested At</th>
                                <th class="px-5 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($approvals as $approval)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-5 py-3">
                                        <div class="font-semibold text-[#1A365D]">#{{ $approval->entity_id }}</div>
                                        <div class="text-xs text-gray-500">{{ $entityType }}</div>
                                    </td>
                                    <td class="px-5 py-3">
                                        <span class="inline-block px-2 py-0.5 rounded-full bg-blue-100 text-blue-700 text-xs font-medium">
                                            {{ ucfirst($approval->action) }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-3">
                                        <div class="text-gray-800">{{ $approval->requestedBy->name ?? 'Unknown' }}</div>
                                        <div class="text-xs text-gray-500">{{ $approval->requestedBy->email ?? '' }}</div>
                                    </td>
                                    <td class="px-5 py-3">
                                        <div class="text-gray-800">{{ $approval->requested_at?->format('d M Y H:i') ?? '-' }}</div>
                                        <div class="text-xs text-gray-500">{{ $approval->requested_at?->diffForHumans() }}</div>
                                    </td>
                                    <td class="px-5 py-3 text-right">
                                        <div class="inline-flex gap-2">
                                            <button type="button" @click="approveOpen = {{ $approval->id }}"
                                                    class="px-3 py-1 text-xs rounded-md border border-green-600 text-green-700 hover:bg-green-600 hover:text-white inline-flex items-center gap-1 transition">
                                                <span class="material-symbols-outlined" style="font-size:14px;">check</span> Approve
                                            </button>
                                            <button type="button" @click="rejectOpen = {{ $approval->id }}"
                                                    class="px-3 py-1 text-xs rounded-md border border-red-600 text-red-700 hover:bg-red-600 hover:text-white inline-flex items-center gap-1 transition">
                                                <span class="material-symbols-outlined" style="font-size:14px;">close</span> Reject
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Approve / Reject modals (Alpine) --}}
            @foreach ($approvals as $approval)
                <div x-cloak x-show="approveOpen === {{ $approval->id }}"
                     class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <div class="bg-white rounded-xl shadow-xl w-full max-w-lg" @click.away="approveOpen = null">
                        <form action="{{ route('risk.approvals.approve', $approval) }}" method="POST">
                            @csrf
                            <div class="px-5 py-4 border-b flex items-center justify-between">
                                <h5 class="text-base font-semibold text-[#1A365D]">Approve Request</h5>
                                <button type="button" @click="approveOpen = null" class="text-gray-400 hover:text-gray-600">
                                    <span class="material-symbols-outlined">close</span>
                                </button>
                            </div>
                            <div class="px-5 py-4">
                                <p class="text-sm text-gray-700 mb-3">Are you sure you want to approve this request?</p>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Comments (optional)</label>
                                <textarea name="comments" rows="3" placeholder="Add any comments…"
                                          class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"></textarea>
                            </div>
                            <div class="px-5 py-3 border-t bg-gray-50 flex justify-end gap-2">
                                <button type="button" @click="approveOpen = null" class="px-3 py-1.5 text-sm rounded-md border border-gray-300 text-gray-700 hover:bg-gray-100">Cancel</button>
                                <button type="submit" class="px-3 py-1.5 text-sm rounded-md bg-green-600 text-white hover:bg-green-700 inline-flex items-center gap-1">
                                    <span class="material-symbols-outlined" style="font-size:16px;">check</span> Approve
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <div x-cloak x-show="rejectOpen === {{ $approval->id }}"
                     class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <div class="bg-white rounded-xl shadow-xl w-full max-w-lg" @click.away="rejectOpen = null">
                        <form action="{{ route('risk.approvals.reject', $approval) }}" method="POST">
                            @csrf
                            <div class="px-5 py-4 border-b flex items-center justify-between">
                                <h5 class="text-base font-semibold text-[#1A365D]">Reject Request</h5>
                                <button type="button" @click="rejectOpen = null" class="text-gray-400 hover:text-gray-600">
                                    <span class="material-symbols-outlined">close</span>
                                </button>
                            </div>
                            <div class="px-5 py-4">
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Rejection Reason</label>
                                <textarea name="rejection_reason" rows="3" required placeholder="Explain why you're rejecting this request…"
                                          class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-red-500/20 focus:border-red-500 @error('rejection_reason') border-red-400 @enderror"></textarea>
                                @error('rejection_reason')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                            </div>
                            <div class="px-5 py-3 border-t bg-gray-50 flex justify-end gap-2">
                                <button type="button" @click="rejectOpen = null" class="px-3 py-1.5 text-sm rounded-md border border-gray-300 text-gray-700 hover:bg-gray-100">Cancel</button>
                                <button type="submit" class="px-3 py-1.5 text-sm rounded-md bg-red-600 text-white hover:bg-red-700 inline-flex items-center gap-1">
                                    <span class="material-symbols-outlined" style="font-size:16px;">close</span> Reject
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            @endforeach
        @endforeach
    @endif
</div>
@endsection
