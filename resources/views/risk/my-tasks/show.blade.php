@extends('layouts.app')

@section('title', 'Task — ' . ($task->node_name ?? $task->node_code))
@section('page-section', 'My work')
@section('page-title', $task->node_name ?? $task->node_code)

@section('breadcrumbs')
    <a href="{{ route('risk.my-tasks.index') }}" class="hover:text-[#1A365D]">My tasks</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">{{ $task->node_name ?? $task->node_code }}</span>
@endsection

@section('content')
    @if (session('error'))
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ session('error') }}</div>
    @endif

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        {{-- Decision --}}
        <div class="space-y-4 lg:col-span-2">
            <div class="rounded-xl border border-gray-200 bg-white p-5">
                <div class="flex items-start justify-between">
                    <div>
                        <h2 class="text-lg font-bold text-gray-900">{{ $task->node_name ?? $task->node_code }}</h2>
                        <p class="text-sm text-gray-500">{{ $task->instance?->definition?->name }}</p>
                    </div>
                    @if ($task->due_at)
                        <div class="text-right">
                            <p class="text-[11px] uppercase tracking-wide text-gray-500">Due</p>
                            <p class="text-sm font-semibold {{ $task->isOverdue() ? 'text-red-600' : 'text-gray-900' }}">
                                {{ $task->due_at->format('d M Y H:i') }}
                            </p>
                            @if ($task->isOverdue())
                                <p class="text-[11px] text-red-500">{{ $task->hoursOverdue() }} hours over</p>
                            @endif
                        </div>
                    @endif
                </div>

                @if (! empty($node['instructions']))
                    <p class="mt-4 rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-700">{{ $node['instructions'] }}</p>
                @endif

                @if ($subject)
                    <div class="mt-4 rounded-lg border border-gray-200 p-3">
                        <p class="text-[11px] uppercase tracking-wide text-gray-500">The record</p>
                        <p class="text-sm font-medium text-gray-900">
                            {{ $subject->getAttribute('event_reference')
                                ?? $subject->getAttribute('issue_reference')
                                ?? $subject->getAttribute('risk_code')
                                ?? $subject->getAttribute('treatment_code')
                                ?? $subject->getAttribute('code')
                                ?? class_basename($subject) . ' #' . $subject->getKey() }}
                        </p>
                        <p class="text-sm text-gray-600">
                            {{ $subject->getAttribute('title') ?? $subject->getAttribute('name') ?? '' }}
                        </p>
                    </div>
                @endif

                @if ($canAct)
                    <form method="POST" action="{{ route('risk.my-tasks.act', $task) }}" class="mt-5 space-y-3">
                        @csrf
                        <label class="block">
                            <span class="text-[11px] font-medium uppercase tracking-wide text-gray-500">
                                Comments
                                @if (in_array('comments', (array) ($node['required_fields'] ?? []), true))
                                    <span class="text-red-600">(required)</span>
                                @endif
                            </span>
                            <textarea name="comments" rows="3"
                                      @if (in_array('comments', (array) ($node['required_fields'] ?? []), true)) required @endif
                                      class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-[#1A365D] focus:ring-[#1A365D]"></textarea>
                        </label>

                        <div class="flex flex-wrap gap-2">
                            <button type="submit" name="outcome" value="approve"
                                    class="rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700">
                                Approve
                            </button>
                            <button type="submit" name="outcome" value="reject"
                                    class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">
                                Reject
                            </button>
                        </div>
                    </form>

                    <div class="mt-4 grid grid-cols-1 gap-3 border-t border-gray-100 pt-4 md:grid-cols-2">
                        @if (($node['allow_delegate'] ?? true) !== false)
                            <form method="POST" action="{{ route('risk.my-tasks.delegate', $task) }}" class="space-y-2">
                                @csrf
                                <p class="text-[11px] font-medium uppercase tracking-wide text-gray-500">Delegate</p>
                                <select name="delegate_to" required class="w-full rounded-lg border-gray-300 text-sm">
                                    <option value="">Choose a colleague…</option>
                                    @foreach ($delegates as $user)
                                        <option value="{{ $user->id }}">{{ $user->name }}</option>
                                    @endforeach
                                </select>
                                <input type="text" name="reason" required placeholder="Why"
                                       class="w-full rounded-lg border-gray-300 text-sm">
                                <button type="submit"
                                        class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                    Hand it over
                                </button>
                                <p class="text-[11px] text-gray-400">
                                    It moves onto their list and off yours.
                                </p>
                            </form>
                        @endif

                        @if (($node['allow_return'] ?? true) !== false)
                            <form method="POST" action="{{ route('risk.my-tasks.return', $task) }}" class="space-y-2">
                                @csrf
                                <p class="text-[11px] font-medium uppercase tracking-wide text-gray-500">Return for rework</p>
                                <textarea name="reason" rows="2" required placeholder="What needs to change"
                                          class="w-full rounded-lg border-gray-300 text-sm"></textarea>
                                <button type="submit"
                                        class="w-full rounded-lg border border-amber-300 px-3 py-1.5 text-sm font-medium text-amber-700 hover:bg-amber-50">
                                    Send back
                                </button>
                                <p class="text-[11px] text-gray-400">
                                    The process moves back to the previous step; any parallel review is abandoned.
                                </p>
                            </form>
                        @endif
                    </div>
                @else
                    <p class="mt-5 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-600">
                        This decision is not yours to make.
                    </p>
                @endif
            </div>
        </div>

        {{-- History --}}
        <div class="space-y-4">
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Steps</h3>
                <ol class="mt-3 space-y-2">
                    @foreach ($task->instance?->tasks ?? [] as $step)
                        <li class="flex items-start gap-2 text-xs">
                            <span class="mt-1 h-2 w-2 shrink-0 rounded-full {{ $step->isOpen() ? 'bg-amber-500' : 'bg-green-500' }}"></span>
                            <div>
                                <p class="font-medium text-gray-800">{{ $step->node_name ?? $step->node_code }}</p>
                                <p class="text-gray-500">
                                    {{ $step->status->label() }}
                                    @if ($step->outcome) — {{ $step->outcome }} @endif
                                    @if ($step->assignee) · {{ $step->assignee->name }}
                                    @elseif ($step->assignee_role) · offered to {{ $step->assignee_role }}
                                    @endif
                                </p>
                            </div>
                        </li>
                    @endforeach
                </ol>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">History</h3>
                <ul class="mt-3 space-y-2 text-xs">
                    @foreach ($task->instance?->actions ?? [] as $action)
                        <li>
                            <span class="font-medium text-gray-800">{{ $action->action }}</span>
                            <span class="text-gray-500">
                                — {{ $action->actorLabel() }}, {{ $action->acted_at?->diffForHumans() }}
                            </span>
                            @if ($action->comments)
                                <p class="text-gray-500">{{ $action->comments }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
@endsection
