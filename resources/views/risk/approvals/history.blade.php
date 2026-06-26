@extends('layouts.app')

@section('title', 'Approval History - GRC Risk Management')
@section('page-section', 'Risk Management')
@section('page-title', 'Approval History')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="text-gray-500 hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.approvals.dashboard') }}" class="text-gray-500 hover:text-[#1A365D]">Approvals</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">History</span>
@endsection

@section('content')
<div>
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-[#1A365D]">Approval History</h1>
            <p class="text-sm text-gray-500 mt-1">Historical record of all approval requests.</p>
        </div>
        <a href="{{ route('risk.approvals.dashboard') }}"
           class="px-4 py-2 border border-[#1A365D] text-[#1A365D] rounded-lg text-sm font-medium hover:bg-[#1A365D] hover:text-white transition inline-flex items-center gap-2">
            <span class="material-symbols-outlined" style="font-size: 18px;">arrow_back</span>
            Back to Dashboard
        </a>
    </div>

    {{-- Filter --}}
    <div class="bg-white rounded-xl border border-gray-200 p-5 mb-6">
        <form method="GET" action="{{ route('risk.approvals.history') }}" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
            <div class="md:col-span-2">
                <label class="block text-xs font-semibold text-gray-600 mb-1">Entity Type</label>
                <select name="entity_type"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                    <option value="">All Entity Types</option>
                    @foreach ($entityTypes as $type)
                        <option value="{{ $type }}" @selected($entityType === $type)>{{ $type }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <button type="submit" class="w-full px-4 py-2 rounded-lg bg-[#1A365D] text-white text-sm font-medium hover:bg-[#2D4A7A] inline-flex items-center justify-center gap-2">
                    <span class="material-symbols-outlined" style="font-size: 18px;">filter_alt</span>
                    Filter
                </button>
            </div>
        </form>
    </div>

    @if ($history->isEmpty())
        <div class="bg-white rounded-xl border border-gray-200 py-16 text-center">
            <span class="material-symbols-outlined text-gray-400" style="font-size: 56px;">inbox</span>
            <h5 class="mt-3 text-lg font-semibold text-[#1A365D]">No History</h5>
            <p class="text-sm text-gray-500">No approval history available yet.</p>
        </div>
    @else
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-xs text-gray-600 uppercase">
                        <tr>
                            <th class="px-5 py-3 text-left">Entity</th>
                            <th class="px-5 py-3 text-left">Action</th>
                            <th class="px-5 py-3 text-left">Status</th>
                            <th class="px-5 py-3 text-left">Requested By</th>
                            <th class="px-5 py-3 text-left">Reviewed By</th>
                            <th class="px-5 py-3 text-left">Reviewed At</th>
                            <th class="px-5 py-3 text-left">Notes</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($history as $approval)
                            <tr class="hover:bg-gray-50">
                                <td class="px-5 py-3 font-semibold text-[#1A365D]">{{ $approval->entity_type }} #{{ $approval->entity_id }}</td>
                                <td class="px-5 py-3">
                                    <span class="inline-block px-2 py-0.5 rounded-full bg-blue-100 text-blue-700 text-xs font-medium">{{ ucfirst($approval->action) }}</span>
                                </td>
                                <td class="px-5 py-3">
                                    @if ($approval->isApproved())
                                        <span class="inline-block px-2 py-0.5 rounded-full bg-green-100 text-green-700 text-xs font-medium">Approved</span>
                                    @elseif ($approval->isRejected())
                                        <span class="inline-block px-2 py-0.5 rounded-full bg-red-100 text-red-700 text-xs font-medium">Rejected</span>
                                    @else
                                        <span class="inline-block px-2 py-0.5 rounded-full bg-gray-100 text-gray-700 text-xs font-medium">{{ ucfirst($approval->status) }}</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3">
                                    <div class="text-gray-800">{{ $approval->requestedBy->name ?? 'Unknown' }}</div>
                                    <div class="text-xs text-gray-500">{{ $approval->requested_at?->format('d M Y') ?? '-' }}</div>
                                </td>
                                <td class="px-5 py-3 text-gray-700">{{ $approval->reviewedBy->name ?? 'N/A' }}</td>
                                <td class="px-5 py-3 text-gray-600">
                                    {{ $approval->reviewed_at?->format('d M Y H:i') ?? '-' }}
                                </td>
                                <td class="px-5 py-3">
                                    @if ($approval->isRejected() && $approval->rejection_reason)
                                        <span class="text-xs text-red-600" title="{{ $approval->rejection_reason }}">{{ Str::limit($approval->rejection_reason, 40) }}</span>
                                    @elseif ($approval->comments)
                                        <span class="text-xs text-gray-600" title="{{ $approval->comments }}">{{ Str::limit($approval->comments, 40) }}</span>
                                    @else
                                        <span class="text-xs text-gray-400">-</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-4">
            {{ $history->links() }}
        </div>
    @endif
</div>
@endsection
