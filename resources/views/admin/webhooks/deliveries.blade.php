@extends('layouts.app')

@section('title', 'Deliveries — ' . $subscription->name)
@section('page-section', 'Administration')
@section('page-title', 'Delivery log')

@section('breadcrumbs')
    <a href="{{ route('admin.webhooks.index') }}" class="hover:text-[#1A365D]">Webhooks</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">{{ $subscription->name }}</span>
@endsection

@section('content')
    <div class="mb-6">
        <h2 class="text-2xl font-bold text-gray-900">{{ $subscription->name }}</h2>
        <p class="font-mono text-xs text-gray-500">{{ $subscription->url }}</p>
        <p class="mt-1 max-w-3xl text-sm text-gray-500">
            Every attempt, with what came back. “Did you send it?” is the first question in an integration
            incident, and this is the answer.
        </p>
    </div>

    @if (session('success'))
        <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('success') }}</div>
    @endif

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-[11px] uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-4 py-2 text-left font-medium">When</th>
                    <th class="px-4 py-2 text-left font-medium">Event</th>
                    <th class="px-4 py-2 text-left font-medium">Attempt</th>
                    <th class="px-4 py-2 text-left font-medium">Result</th>
                    <th class="px-4 py-2 text-left font-medium">Took</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($deliveries as $delivery)
                    @php
                        $badge = match ($delivery->statusColor()) {
                            'green' => 'bg-green-100 text-green-800',
                            'red' => 'bg-red-100 text-red-800',
                            'amber' => 'bg-amber-100 text-amber-800',
                            default => 'bg-slate-100 text-slate-700',
                        };
                    @endphp
                    <tr>
                        <td class="px-4 py-3 text-xs text-gray-500">
                            {{ $delivery->created_at->format('d M H:i:s') }}
                            @if ($delivery->replay_of_id)
                                <span class="block text-[10px] text-blue-600">
                                    replay{{ $delivery->replayer ? ' by '.$delivery->replayer->name : '' }}
                                </span>
                            @endif
                        </td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $delivery->event }}</td>
                        <td class="px-4 py-3 text-xs">{{ $delivery->attempt }}</td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2 py-0.5 text-[11px] font-medium {{ $badge }}">
                                {{ ucfirst($delivery->status) }}{{ $delivery->status_code ? ' '.$delivery->status_code : '' }}
                            </span>
                            @if ($delivery->error)
                                <span class="block text-[11px] text-red-600">{{ Str::limit($delivery->error, 120) }}</span>
                            @endif
                            @if ($delivery->next_retry_at)
                                <span class="block text-[11px] text-amber-600">
                                    retrying {{ $delivery->next_retry_at->diffForHumans() }}
                                </span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-500">
                            {{ $delivery->duration_ms !== null ? $delivery->duration_ms.' ms' : '—' }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            @can('webhook.manage')
                                <form method="POST" action="{{ route('admin.webhooks.replay', $delivery) }}" class="inline">
                                    @csrf
                                    <button class="rounded-lg border border-gray-300 px-3 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                        Replay
                                    </button>
                                </form>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-sm text-gray-500">Nothing delivered yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $deliveries->links() }}</div>
@endsection
