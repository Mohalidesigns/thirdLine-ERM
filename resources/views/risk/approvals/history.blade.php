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
    @if (session('success'))
        <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-xl text-sm text-green-800">
            {{ session('success') }}
        </div>
    @endif

    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-[#1A365D]">Approval History</h1>
            <p class="text-sm text-gray-500 mt-1">Historical record of all approval requests.</p>
        </div>
        @can('approval.view')
            <a href="{{ route('risk.approvals.dashboard') }}"
               class="px-4 py-2 border border-[#1A365D] text-[#1A365D] rounded-lg text-sm font-medium hover:bg-[#1A365D] hover:text-white transition inline-flex items-center gap-2">
                <span class="material-symbols-outlined" style="font-size: 18px;">arrow_back</span>
                Back to Dashboard
            </a>
        @endcan
    </div>

    {{-- WP-09: the history table is the shared grid — see
         App\Grids\Definitions\ApprovalsHistoryGrid, which carries the same
         approved/rejected/superseded scope ApprovalService::getHistoryPaginated
         applied. --}}
    <x-data-grid grid="approvals_history" />
</div>
@endsection
