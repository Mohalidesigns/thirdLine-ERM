@extends('layouts.app')

@section('title', 'Background jobs')
@section('page-section', 'Administration')
@section('page-title', 'Background jobs')

@section('content')
    <div class="mb-6">
        <h2 class="text-2xl font-bold text-gray-900">Background jobs</h2>
        <p class="mt-1 max-w-3xl text-sm text-gray-500">
            Long work runs on a queue: simulations, imports, board packs, connector syncs.
            {{ $canSeeAll ? 'You can see everything running in this organization.' : 'These are the jobs you started.' }}
            @if ($active > 0) <strong>{{ $active }} still running.</strong> @endif
        </p>
    </div>

    @foreach (['success' => 'green', 'error' => 'red'] as $key => $tone)
        @if (session($key))
            <div class="mb-4 rounded-lg border border-{{ $tone }}-200 bg-{{ $tone }}-50 px-4 py-3 text-sm text-{{ $tone }}-800">
                {{ session($key) }}
            </div>
        @endif
    @endforeach

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-[11px] uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-4 py-2 text-left font-medium">What</th>
                    <th class="px-4 py-2 text-left font-medium">Status</th>
                    <th class="px-4 py-2 text-left font-medium">Progress</th>
                    <th class="px-4 py-2 text-left font-medium">Started</th>
                    <th class="px-4 py-2 text-left font-medium">Took</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($runs as $run)
                    @php
                        $badge = match ($run->statusColor()) {
                            'green' => 'bg-green-100 text-green-800',
                            'red' => 'bg-red-100 text-red-800',
                            'blue' => 'bg-blue-100 text-blue-800',
                            'amber' => 'bg-amber-100 text-amber-800',
                            default => 'bg-slate-100 text-slate-700',
                        };
                    @endphp
                    <tr @if (! $run->isFinished()) wire:poll @endif>
                        <td class="px-4 py-3">
                            <p class="font-medium text-gray-900">{{ $run->label }}</p>
                            @if ($canSeeAll && $run->creator)
                                <p class="text-[11px] text-gray-400">{{ $run->creator->name }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2 py-0.5 text-[11px] font-medium {{ $badge }}">{{ ucfirst($run->status) }}</span>
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-600">
                            @if ($run->isFinished())
                                {{ $run->message ?? '—' }}
                            @else
                                {{ $run->progress }}%
                                @if ($run->total) ({{ number_format($run->processed) }} / {{ number_format($run->total) }}) @endif
                            @endif
                            @if ($run->error)
                                <span class="block text-[11px] text-red-600">{{ Str::limit($run->error, 140) }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-500">{{ optional($run->started_at ?? $run->queued_at)->diffForHumans() }}</td>
                        <td class="px-4 py-3 text-xs text-gray-500">
                            {{ $run->durationSeconds() !== null ? $run->durationSeconds().'s' : '—' }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            @unless ($run->isFinished())
                                <form method="POST" action="{{ route('admin.jobs.cancel', $run) }}" class="inline">
                                    @csrf
                                    <button @disabled($run->isCancelRequested())
                                            class="rounded-lg border border-gray-300 px-3 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-40">
                                        {{ $run->isCancelRequested() ? 'Stopping…' : 'Cancel' }}
                                    </button>
                                </form>
                            @endunless
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-sm text-gray-500">Nothing has run yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $runs->links() }}</div>
@endsection
