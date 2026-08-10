@extends('layouts.app')
@section('title', 'Workflow Instance')
@section('content')
<div class="max-w-4xl mx-auto space-y-6">
    <div>
        <h1 class="text-xl font-bold text-gray-900">{{ $instance->definition?->name }}</h1>
        <p class="text-sm text-gray-500">
            {{ str_replace('_', ' ', $instance->entity_type) }} #{{ $instance->entity_id }}
            @if ($subject)
                — {{ $subject->getAttribute('title') ?? $subject->getAttribute('name') ?? '' }}
            @endif
            &middot; version {{ $instance->definition_version }}
            &middot; started {{ $instance->started_at?->diffForHumans() }}
        </p>
    </div>

    @if (session('error'))
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ session('error') }}</div>
    @endif

    {{-- Progress.
         A step list rather than a numbered bar: a graph process can be in two
         places at once, and a "3 of 5" that cannot say which two is worse than
         no progress indicator at all. --}}
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
        <div class="flex items-center justify-between">
            <h3 class="text-sm font-semibold text-gray-900">Steps</h3>
            @php
                $badge = match ($instance->status->color()) {
                    'green' => 'bg-green-100 text-green-700',
                    'red' => 'bg-red-100 text-red-700',
                    'amber' => 'bg-amber-100 text-amber-700',
                    'blue' => 'bg-blue-100 text-blue-700',
                    default => 'bg-gray-100 text-gray-700',
                };
            @endphp
            <span class="badge {{ $badge }}">{{ $instance->status->label() }}</span>
        </div>

        <ol class="mt-4 space-y-2">
            @foreach ($graph->nodes() as $node)
                @php
                    $type = \App\Enums\WorkflowNodeType::tryFrom($node['type'] ?? '');
                    $task = $instance->tasks->firstWhere('node_code', $node['code']);
                    $isCurrent = in_array($node['code'], $instance->currentNodeCodes(), true);
                @endphp
                @continue(! $type?->waitsForHuman() && $type !== \App\Enums\WorkflowNodeType::End)
                <li class="flex items-start gap-3 text-sm">
                    <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full
                        {{ $task && ! $task->isOpen() ? 'bg-green-500' : ($isCurrent ? 'bg-blue-500' : 'bg-gray-300') }}"></span>
                    <div>
                        <p class="font-medium text-gray-800">{{ $node['name'] ?? $node['code'] }}</p>
                        @if ($task)
                            <p class="text-xs text-gray-500">
                                {{ $task->status->label() }}
                                @if ($task->outcome) — {{ $task->outcome }} @endif
                                @if ($task->assignee) · {{ $task->assignee->name }}
                                @elseif ($task->assignee_role) · offered to {{ $task->assignee_role }}
                                @endif
                                @if ($task->isOverdue())
                                    <span class="text-red-600 font-medium">· {{ $task->hoursOverdue() }}h overdue</span>
                                @endif
                                @if ($task->delegated_from)
                                    <span class="text-blue-600">· delegated by {{ $task->delegatedFrom?->name }}</span>
                                @endif
                            </p>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    </div>

    {{-- Action history --}}
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
        <h3 class="text-sm font-semibold text-gray-900 mb-4">Action History</h3>
        @forelse($instance->actions as $action)
        <div class="flex gap-4 py-3 {{ !$loop->last ? 'border-b border-gray-50' : '' }}">
            <div class="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 {{ $action->action === 'approve' ? 'bg-green-100' : ($action->action === 'reject' ? 'bg-red-100' : 'bg-gray-100') }}">
                <span class="material-symbols-outlined text-sm {{ $action->action === 'approve' ? 'text-green-600' : ($action->action === 'reject' ? 'text-red-600' : 'text-gray-500') }}">{{ $action->action === 'approve' ? 'check' : ($action->action === 'reject' ? 'close' : 'comment') }}</span>
            </div>
            <div>
                <p class="text-sm">
                    <span class="font-medium">{{ $action->actorLabel() }}</span>
                    <span class="text-gray-500">{{ $action->action }}</span>
                    <span class="text-gray-400">{{ $action->stage_name ?? $action->node_code }}</span>
                </p>
                @if($action->comments)<p class="text-xs text-gray-600 mt-1">{{ $action->comments }}</p>@endif
                <p class="text-xs text-gray-400 mt-1">{{ $action->acted_at?->diffForHumans() }}</p>
            </div>
        </div>
        @empty
            <p class="text-sm text-gray-400">Nothing has happened yet.</p>
        @endforelse
    </div>

    {{-- Act --}}
    @if($instance->isOpen() && $actionableTasks->isNotEmpty())
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
        <h3 class="text-sm font-semibold text-gray-900 mb-1">Take Action</h3>
        <p class="mb-4 text-xs text-gray-500">
            {{ $actionableTasks->pluck('node_name')->filter()->implode(', ') }}
        </p>
        <form method="POST" action="{{ route('risk.workflows.act', $instance) }}" class="space-y-3">@csrf
            <textarea name="comments" rows="2" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" placeholder="Comments..."></textarea>
            <div class="flex flex-wrap gap-2">
                <button type="submit" name="action" value="approve" class="px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-medium">Approve</button>
                <button type="submit" name="action" value="reject" class="px-4 py-2 bg-red-600 text-white rounded-lg text-sm font-medium">Reject</button>
                <button type="submit" name="action" value="return" class="px-4 py-2 bg-amber-500 text-white rounded-lg text-sm font-medium">Return for rework</button>
                <button type="submit" name="action" value="escalate" class="px-4 py-2 bg-orange-600 text-white rounded-lg text-sm font-medium">Escalate</button>
                <button type="submit" name="action" value="comment" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg text-sm font-medium">Comment Only</button>
            </div>
        </form>
    </div>
    @elseif ($instance->isOpen())
    <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm text-gray-600">
        There is no open step here that you can act on.
    </div>
    @endif
</div>
@endsection
