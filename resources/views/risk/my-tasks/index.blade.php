@extends('layouts.app')

@section('title', 'My tasks')
@section('page-section', 'My work')
@section('page-title', 'My tasks')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">My tasks</span>
@endsection

@section('content')
    <div class="mb-6">
        <h2 class="text-2xl font-bold text-gray-900">My tasks</h2>
        <p class="mt-1 max-w-3xl text-sm text-gray-500">
            Every decision waiting on you, from every module, in one queue. Deciding here does exactly what
            deciding on the record's own screen does.
        </p>
    </div>

    @if (session('success'))
        <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ session('error') }}</div>
    @endif

    {{-- Counters --}}
    <div class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-5">
        @php
            $tiles = [
                ['label' => 'Open', 'value' => $counts['open'], 'tone' => 'text-gray-900', 'query' => []],
                ['label' => 'Overdue', 'value' => $counts['overdue'], 'tone' => 'text-red-600', 'query' => ['overdue' => 1]],
                ['label' => 'Due today', 'value' => $counts['due_today'], 'tone' => 'text-amber-600', 'query' => []],
                ['label' => 'Escalated to me', 'value' => $counts['escalated'], 'tone' => 'text-orange-600', 'query' => ['status' => 'escalated']],
                ['label' => 'Delegated to me', 'value' => $counts['delegated_to_me'], 'tone' => 'text-blue-600', 'query' => ['status' => 'delegated']],
            ];
        @endphp
        @foreach ($tiles as $tile)
            <a href="{{ route('risk.my-tasks.index', $tile['query']) }}"
               class="rounded-xl border border-gray-200 bg-white p-4 hover:border-[#1A365D]">
                <p class="text-[11px] font-medium uppercase tracking-wide text-gray-500">{{ $tile['label'] }}</p>
                <p class="mt-1 text-2xl font-bold {{ $tile['tone'] }}">{{ $tile['value'] }}</p>
            </a>
        @endforeach
    </div>

    {{-- Queue --}}
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-[11px] uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-4 py-2 text-left font-medium">What</th>
                    <th class="px-4 py-2 text-left font-medium">Record</th>
                    <th class="px-4 py-2 text-left font-medium">Process</th>
                    <th class="px-4 py-2 text-left font-medium">Due</th>
                    <th class="px-4 py-2 text-left font-medium">Status</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($tasks as $task)
                    @php
                        $key = $task->instance?->entity_type.':'.$task->instance?->entity_id;
                        $subject = $subjects[$key] ?? null;
                    @endphp
                    <tr class="{{ $task->isOverdue() ? 'bg-red-50/50' : '' }}">
                        <td class="px-4 py-3">
                            <p class="font-medium text-gray-900">{{ $task->node_name ?? $task->node_code }}</p>
                            @if ($task->delegated_from)
                                <p class="text-[11px] text-blue-600">
                                    Delegated to you by {{ $task->delegatedFrom?->name ?? 'a colleague' }}
                                </p>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-700">
                            @if ($subject)
                                {{ $subject->getAttribute('event_reference')
                                    ?? $subject->getAttribute('issue_reference')
                                    ?? $subject->getAttribute('risk_code')
                                    ?? $subject->getAttribute('treatment_code')
                                    ?? $subject->getAttribute('code')
                                    ?? class_basename($subject).' #'.$subject->getKey() }}
                                <span class="block text-[11px] text-gray-400">
                                    {{ Str::limit($subject->getAttribute('title') ?? $subject->getAttribute('name') ?? '', 60) }}
                                </span>
                            @else
                                <span class="text-gray-400">{{ $task->instance?->entity_type }} #{{ $task->instance?->entity_id }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-600">{{ $task->instance?->definition?->name ?? '—' }}</td>
                        <td class="px-4 py-3">
                            @if ($task->due_at)
                                <span class="{{ $task->isOverdue() ? 'font-semibold text-red-600' : 'text-gray-700' }}">
                                    {{ $task->due_at->format('d M H:i') }}
                                </span>
                                @if ($task->isOverdue())
                                    <span class="block text-[11px] text-red-500">{{ $task->hoursOverdue() }}h over</span>
                                @endif
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @php
                                // Written out rather than interpolated: Tailwind
                                // scans source for literal class names, and a
                                // composed one is purged from the build.
                                $badge = match ($task->status->color()) {
                                    'green' => 'bg-green-100 text-green-800',
                                    'amber' => 'bg-amber-100 text-amber-800',
                                    'red' => 'bg-red-100 text-red-800',
                                    'blue' => 'bg-blue-100 text-blue-800',
                                    default => 'bg-slate-100 text-slate-700',
                                };
                            @endphp
                            <span class="rounded-full px-2 py-0.5 text-[11px] font-medium {{ $badge }}">
                                {{ $task->status->label() }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('risk.my-tasks.show', $task) }}"
                               class="rounded-lg bg-[#1A365D] px-3 py-1.5 text-xs font-medium text-white hover:bg-[#12263f]">
                                Open
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-sm text-gray-500">
                            Nothing is waiting on you.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $tasks->links() }}</div>
@endsection
