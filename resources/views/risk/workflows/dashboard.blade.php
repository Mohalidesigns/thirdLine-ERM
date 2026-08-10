@extends('layouts.app')
@section('title', 'Workflow Dashboard')
@section('breadcrumbs')
    <a href="/risk/dashboard" class="hover:text-primary">Dashboard</a><span class="material-symbols-outlined text-[14px]">chevron_right</span><span class="text-gray-700 font-medium">Workflows</span>
@endsection
@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-bold text-gray-900">Workflow Dashboard</h1>
        <div class="flex gap-2">
            <a href="{{ route('risk.my-tasks.index') }}" class="inline-flex items-center gap-2 px-4 py-2 border border-gray-300 text-gray-700 rounded-lg text-sm font-medium hover:bg-gray-50">
                <span class="material-symbols-outlined text-lg">task_alt</span> My tasks
            </a>
            <a href="{{ route('risk.workflows.create-definition') }}" class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-white rounded-lg text-sm font-medium hover:bg-opacity-90"><span class="material-symbols-outlined text-lg">add_circle</span> New Workflow</a>
        </div>
    </div>

    {{-- WP-06: the two counters the platform could not produce before are the
         two that matter — how much is late, and how much has escalated. --}}
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
        <x-kpi-card title="Running" :value="$activeWorkflows" icon="sync" color="blue" />
        <x-kpi-card title="Completed Today" :value="$completedToday" icon="check_circle" color="green" />
        <x-kpi-card title="Waiting On Me" :value="$pendingMyAction" icon="pending_actions" color="yellow" />
        <x-kpi-card title="Overdue Tasks" :value="$overdueTasks" icon="schedule" color="red" />
        <x-kpi-card title="Escalated" :value="$escalatedTasks" icon="trending_up" color="yellow" />
        <x-kpi-card title="Published" :value="$totalDefinitions" icon="schema" color="gray" />
    </div>

    <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
        <div class="px-5 py-4 border-b border-gray-100"><h3 class="text-sm font-semibold text-gray-900">Recent Workflow Activity</h3></div>
        <table class="data-table"><thead><tr><th>Workflow</th><th>Record</th><th>Waiting on</th><th>Status</th><th>Initiated By</th><th>Started</th></tr></thead><tbody>
            @forelse($recentInstances as $inst)
            <tr class="cursor-pointer" onclick="window.location='{{ route('risk.workflows.show-instance', $inst) }}'">
                <td class="font-medium">{{ $inst->definition?->name }}</td>
                <td class="text-xs">{{ str_replace('_', ' ', $inst->entity_type) }} #{{ $inst->entity_id }}</td>
                <td class="text-xs">
                    @php $open = $inst->tasks->filter->isOpen(); @endphp
                    @forelse ($open as $task)
                        <span class="block">
                            {{ $task->node_name ?? $task->node_code }} —
                            {{ $task->assignee?->name ?? ($task->assignee_role ? 'any '.$task->assignee_role : 'unassigned') }}
                            @if ($task->isOverdue())
                                <span class="text-red-600 font-medium">({{ $task->hoursOverdue() }}h over)</span>
                            @endif
                        </span>
                    @empty
                        <span class="text-gray-400">—</span>
                    @endforelse
                </td>
                <td>
                    @php
                        $badge = match ($inst->status->color()) {
                            'green' => 'bg-green-100 text-green-700',
                            'red' => 'bg-red-100 text-red-700',
                            'amber' => 'bg-amber-100 text-amber-700',
                            'blue' => 'bg-blue-100 text-blue-700',
                            default => 'bg-gray-100 text-gray-700',
                        };
                    @endphp
                    <span class="badge {{ $badge }}">{{ $inst->status->label() }}</span>
                </td>
                <td class="text-xs">{{ $inst->initiator?->name }}</td>
                <td class="text-xs text-gray-500">{{ $inst->started_at?->diffForHumans() }}</td>
            </tr>
            @empty
            <tr><td colspan="6" class="text-center py-8 text-gray-400">No workflows yet</td></tr>
            @endforelse
        </tbody></table>
    </div>

    {{-- Who is carrying what. The input to an SLA conversation that is about
         capacity rather than about individuals. --}}
    @if ($workload->isNotEmpty())
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
            <div class="px-5 py-4 border-b border-gray-100">
                <h3 class="text-sm font-semibold text-gray-900">Open decisions by assignee</h3>
            </div>
            <table class="data-table"><thead><tr><th>Assignee</th><th>Open</th><th>Overdue</th></tr></thead><tbody>
                @foreach ($workload as $row)
                    <tr>
                        <td>
                            {{ optional(\App\Models\User::find($row->assignee_id))->name
                                ?? ($row->assignee_role ? 'Unclaimed — '.$row->assignee_role : 'Unassigned') }}
                        </td>
                        <td>{{ $row->open_count }}</td>
                        <td class="{{ $row->overdue_count > 0 ? 'text-red-600 font-medium' : 'text-gray-500' }}">{{ $row->overdue_count }}</td>
                    </tr>
                @endforeach
            </tbody></table>
        </div>
    @endif
</div>
@endsection
