@extends('layouts.app')

@section('title', 'My Responsibilities')
@section('page-section', 'My Responsibilities')
@section('page-title', 'My Responsibilities')

@section('content')
@php
    $bucketMeta = [
        'overdue' => ['label' => 'Overdue', 'tone' => 'text-red-700 bg-red-50 border-red-200', 'icon' => 'warning'],
        'today' => ['label' => 'Due today', 'tone' => 'text-amber-700 bg-amber-50 border-amber-200', 'icon' => 'today'],
        'this_week' => ['label' => 'This week', 'tone' => 'text-blue-700 bg-blue-50 border-blue-200', 'icon' => 'date_range'],
        'later' => ['label' => 'Later / no deadline', 'tone' => 'text-gray-600 bg-gray-50 border-gray-200', 'icon' => 'schedule'],
    ];
    $typeIcons = [
        'task' => 'approval',
        'treatment_overdue' => 'construction',
        'risk_review_due' => 'policy',
        'control_test_due' => 'fact_check',
        'kri_reading_due' => 'monitoring',
        'breach' => 'notification_important',
        'delegation_in' => 'move_to_inbox',
        'delegation_out' => 'outbox',
    ];
@endphp

<div class="mx-auto max-w-4xl">
    <div class="mb-4 flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-3 shadow-sm">
        <div>
            <p class="text-sm font-semibold text-gray-800">
                {{ $queue['total_items'] }} {{ Str::plural('item', $queue['total_items']) }} on your list
            </p>
            @if($queue['total_items'] > 0)
                <p class="text-xs text-gray-500">
                    Estimated effort ~{{ $queue['total_minutes'] }} min — most items complete in under 3 minutes.
                </p>
            @endif
        </div>
        <a href="{{ route('risk.my-tasks.index') }}" class="text-xs font-medium text-[--color-primary] hover:underline">
            Full task inbox →
        </a>
    </div>

    @if($queue['total_items'] === 0)
        <div class="rounded-lg border border-dashed border-gray-300 bg-white p-12 text-center">
            <span class="material-symbols-outlined text-4xl text-emerald-400">task_alt</span>
            <h2 class="mt-2 text-sm font-semibold text-gray-700">Nothing on your list</h2>
            <p class="mt-1 text-xs text-gray-500">You owe the risk process nothing today. That is the report.</p>
        </div>
    @endif

    @foreach($bucketMeta as $bucket => $meta)
        @php $items = $queue['buckets'][$bucket] ?? []; @endphp
        @if($items !== [])
            <section class="mb-4">
                <h2 class="mb-2 inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold {{ $meta['tone'] }}">
                    <span class="material-symbols-outlined text-[15px] leading-none">{{ $meta['icon'] }}</span>
                    {{ $meta['label'] }} · {{ count($items) }}
                </h2>
                <ul class="divide-y divide-gray-100 overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                    @foreach($items as $item)
                        <li>
                            <a href="{{ $item['url'] }}" class="flex items-center gap-3 px-4 py-2.5 hover:bg-gray-50">
                                <span class="material-symbols-outlined shrink-0 rounded-md bg-gray-100 p-1.5 text-[18px] leading-none text-gray-500">
                                    {{ $typeIcons[$item['type']] ?? 'assignment' }}
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-medium text-gray-800">{{ $item['title'] }}</span>
                                    @if($item['subtitle'])
                                        <span class="block truncate text-xs text-gray-400">{{ $item['subtitle'] }}</span>
                                    @endif
                                </span>
                                @if($item['badge'])
                                    <span class="shrink-0 rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-semibold text-red-700">{{ $item['badge'] }}</span>
                                @endif
                                @if($item['due_at'])
                                    <span class="shrink-0 text-xs tabular-nums text-gray-400">{{ \Carbon\Carbon::parse($item['due_at'])->format('d M') }}</span>
                                @endif
                                <span class="shrink-0 rounded bg-gray-50 px-1.5 py-0.5 text-[10px] text-gray-400" title="Estimated effort">~{{ $item['minutes'] }} min</span>
                                <span class="material-symbols-outlined shrink-0 text-[18px] text-gray-300">chevron_right</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    @endforeach
</div>
@endsection
