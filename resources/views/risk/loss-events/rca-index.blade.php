@extends('layouts.app')

@section('title', 'Root Cause Analyses - GRC Platform')

@section('breadcrumbs')
    <span>Risk Management</span>
    <span class="text-gray-300">/</span>
    <span>Loss Events</span>
    <span class="text-gray-300">/</span>
    <span class="text-[#1A365D] font-semibold">Root Cause Analyses</span>
@endsection

@section('content')
    {{-- Page Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">Root Cause Analyses</h1>
            <p class="text-sm text-gray-500 mt-1">View all root cause analyses across loss events</p>
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

    {{-- RCA Listing --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-200">
                <tr>
                    <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Loss Event</th>
                    <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Root Cause Category</th>
                    <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Methodology</th>
                    <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Analysis Date</th>
                    <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="text-right px-5 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse (($rcas ?? []) as $rca)
                    <tr class="hover:bg-gray-50 transition">
                        <td class="px-5 py-4">
                            @if ($rca->lossEvent)
                                <a href="{{ route('risk.loss-events.show', $rca->lossEvent) }}" class="text-[#1A365D] font-medium hover:underline">
                                    {{ $rca->lossEvent->event_reference ?? '-' }}
                                </a>
                                <p class="text-xs text-gray-500 mt-0.5">{{ Str::limit($rca->lossEvent->event_title ?? '', 50) }}</p>
                            @else
                                <span class="text-gray-400">-</span>
                            @endif
                        </td>
                        <td class="px-5 py-4">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                                @if ($rca->root_cause_category === 'people') bg-blue-100 text-blue-700
                                @elseif ($rca->root_cause_category === 'process') bg-purple-100 text-purple-700
                                @elseif ($rca->root_cause_category === 'system') bg-orange-100 text-orange-700
                                @elseif ($rca->root_cause_category === 'external') bg-gray-100 text-gray-700
                                @else bg-gray-100 text-gray-600
                                @endif">
                                {{ ucfirst($rca->root_cause_category ?? '-') }}
                            </span>
                        </td>
                        <td class="px-5 py-4 text-gray-700">
                            {{ str_replace('_', ' ', ucfirst($rca->methodology ?? '-')) }}
                        </td>
                        <td class="px-5 py-4 text-gray-600">
                            {{ $rca->analysis_date ? \Carbon\Carbon::parse($rca->analysis_date)->format('d M Y') : '-' }}
                        </td>
                        <td class="px-5 py-4">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                                @if ($rca->status === 'approved') bg-green-100 text-green-700
                                @elseif ($rca->status === 'draft') bg-yellow-100 text-yellow-700
                                @else bg-gray-100 text-gray-600
                                @endif">
                                {{ ucfirst($rca->status ?? 'draft') }}
                            </span>
                        </td>
                        <td class="px-5 py-4 text-right">
                            @if ($rca->lossEvent)
                                <a href="{{ route('risk.loss-events.show-rca', $rca->lossEvent) }}" class="text-xs text-[#1A365D] font-medium hover:underline">
                                    View Details
                                </a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-5 py-12 text-center">
                            <span class="material-symbols-outlined text-4xl text-gray-300 mb-3 block">search_off</span>
                            <h3 class="text-sm font-semibold text-gray-700 mb-1">No Root Cause Analyses Found</h3>
                            <p class="text-xs text-gray-500">Root cause analyses will appear here once they are created for loss events.</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Pagination --}}
    @if (method_exists($rcas ?? collect(), 'links'))
        <div class="mt-6">
            {{ $rcas->links() }}
        </div>
    @endif
@endsection
