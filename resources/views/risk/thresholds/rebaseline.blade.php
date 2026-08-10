@extends('layouts.app')

@section('title', 'Threshold Re-baselining')
@section('page-section', 'Governance')
@section('page-title', 'Threshold Re-baselining')

@section('breadcrumbs')
    <a href="{{ route('risk.dashboard') }}" class="hover:text-[#1A365D]">Dashboard</a>
    <span>/</span>
    <span class="text-gray-700">Threshold Re-baselining</span>
@endsection

@section('content')
    <div class="mb-6">
        <h1 class="text-xl font-semibold text-[#1A365D]">Threshold Re-baselining</h1>
        <p class="text-sm text-gray-500 mt-1 max-w-3xl">
            Limits defined as a formula &mdash; a share of qualifying capital, an inflation-indexed naira
            figure &mdash; are re-evaluated when a period closes. Where the computed band has moved further than
            the configured tolerance from the band in force, it is queued here. Approving writes a
            <strong>new effective-dated band set</strong>; the band that was in force is retained, so breaches
            already recorded keep reading against the limit that applied when they happened.
        </p>
    </div>

    @forelse ($pending as $approval)
        @php
            $threshold = $thresholds[$approval->entity_id] ?? null;
            $payload = $approval->payload ?? [];
            $changes = $payload['changes'] ?? [];
        @endphp

        <div class="bg-white rounded-xl border border-gray-200 p-5 mb-4">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-sm font-semibold text-[#1A365D]">
                        {{ $threshold?->measure?->name ?? $payload['measure_code'] ?? 'Measure' }}
                        <span class="font-mono text-xs text-gray-400">{{ $payload['measure_code'] ?? '' }}</span>
                    </h2>
                    <p class="text-xs text-gray-500 mt-0.5">
                        Computed at the close of {{ $payload['period_code'] ?? 'the period' }}
                        &middot; tolerance {{ number_format(($payload['tolerance'] ?? 0) * 100, 1) }}%
                        &middot; raised {{ $approval->requested_at?->diffForHumans() }}
                    </p>
                </div>
                <span class="badge bg-amber-100 text-amber-700">Pending approval</span>
            </div>

            <table class="w-full mt-4 text-xs">
                <thead>
                    <tr class="text-left text-gray-400 uppercase tracking-wide text-[10px]">
                        <th class="pb-2">Band</th>
                        <th class="pb-2">Bound</th>
                        <th class="pb-2">Expression</th>
                        <th class="pb-2 text-right">In force</th>
                        <th class="pb-2 text-right">Computed</th>
                        <th class="pb-2 text-right">Change</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($changes as $change)
                        <tr class="border-t border-gray-100">
                            <td class="py-2 font-medium">{{ ucfirst($change['band'] ?? '—') }}</td>
                            <td class="py-2">{{ $change['bound'] ?? '—' }}</td>
                            <td class="py-2 font-mono text-[11px] text-gray-500">{{ $change['formula'] ?? '' }}</td>
                            <td class="py-2 text-right">
                                {{ $change['in_force'] === null ? 'unset' : number_format((float) $change['in_force'], 2) }}
                            </td>
                            <td class="py-2 text-right font-semibold text-[#1A365D]">
                                {{ number_format((float) ($change['computed'] ?? 0), 2) }}
                            </td>
                            <td class="py-2 text-right {{ ($change['relative_change'] ?? 0) > 0 ? 'text-amber-600' : 'text-gray-500' }}">
                                {{ $change['relative_change'] === null
                                    ? 'no prior bound'
                                    : number_format(((float) $change['relative_change']) * 100, 1) . '%' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @can('threshold.rebaseline_approve')
                <div class="flex flex-wrap gap-3 mt-4 pt-4 border-t border-gray-100">
                    <form method="POST" action="{{ route('risk.thresholds.rebaseline.approve', $approval) }}"
                          class="flex items-center gap-2">
                        @csrf
                        <input type="text" name="comments" maxlength="1000" placeholder="Comment (optional)"
                               class="border border-gray-300 rounded px-2 py-1.5 text-xs w-64">
                        <button type="submit" class="px-3 py-1.5 rounded-lg bg-[#1A365D] text-white text-xs font-medium">
                            Approve re-baselining
                        </button>
                    </form>

                    <form method="POST" action="{{ route('risk.thresholds.rebaseline.reject', $approval) }}"
                          class="flex items-center gap-2">
                        @csrf
                        <input type="text" name="reason" required minlength="5" maxlength="1000" placeholder="Reason for rejecting"
                               class="border border-gray-300 rounded px-2 py-1.5 text-xs w-64">
                        <button type="submit" class="px-3 py-1.5 rounded-lg border border-gray-300 text-gray-700 text-xs font-medium">
                            Reject
                        </button>
                    </form>
                </div>
            @endcan
        </div>
    @empty
        <div class="bg-white rounded-xl border border-gray-200 text-center py-12">
            <span class="material-symbols-outlined text-4xl text-green-300 mb-2 block">verified</span>
            <p class="text-sm text-gray-500">No thresholds have drifted past tolerance.</p>
        </div>
    @endforelse

    @if ($history->isNotEmpty())
        <h2 class="text-sm font-semibold text-[#1A365D] mt-8 mb-3">Recent decisions</h2>
        <x-data-table>
            <x-slot name="head">
                <th>Measure</th>
                <th>Period</th>
                <th>Decision</th>
                <th>Decided by</th>
                <th>When</th>
                <th>Note</th>
            </x-slot>
            @foreach ($history as $decision)
                <tr>
                    <td class="text-xs font-mono">{{ $decision->payload['measure_code'] ?? '—' }}</td>
                    <td class="text-xs">{{ $decision->payload['period_code'] ?? '—' }}</td>
                    <td>
                        <span class="badge {{ $decision->status === 'approved' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' }}">
                            {{ ucfirst($decision->status) }}
                        </span>
                    </td>
                    <td class="text-xs">{{ $decision->reviewedBy?->name ?? '—' }}</td>
                    <td class="text-xs text-gray-500">{{ $decision->reviewed_at?->format('d M Y') }}</td>
                    <td class="text-xs text-gray-500">{{ Str::limit($decision->comments ?? $decision->rejection_reason ?? '', 60) }}</td>
                </tr>
            @endforeach
        </x-data-table>
    @endif
@endsection
