@extends('layouts.app')
@section('title', 'Workflow Dashboard')
@section('breadcrumbs')
    <a href="/risk/dashboard" class="hover:text-primary">Dashboard</a><span class="material-symbols-outlined text-[14px]">chevron_right</span><span class="text-gray-700 font-medium">Workflows</span>
@endsection
@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-bold text-gray-900">Workflow Dashboard</h1>
        <a href="{{ route('risk.workflows.create-definition') }}" class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-white rounded-lg text-sm font-medium hover:bg-opacity-90"><span class="material-symbols-outlined text-lg">add_circle</span> New Workflow</a>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <x-kpi-card title="Active Workflows" :value="$activeWorkflows" icon="sync" color="blue" />
        <x-kpi-card title="Completed Today" :value="$completedToday" icon="check_circle" color="green" />
        <x-kpi-card title="Pending My Action" :value="$pendingMyAction" icon="pending_actions" color="yellow" />
        <x-kpi-card title="Definitions" :value="$totalDefinitions" icon="schema" color="gray" />
    </div>
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
        <div class="px-5 py-4 border-b border-gray-100"><h3 class="text-sm font-semibold text-gray-900">Recent Workflow Activity</h3></div>
        <table class="data-table"><thead><tr><th>Workflow</th><th>Entity</th><th>Stage</th><th>Status</th><th>Initiated By</th><th>Started</th></tr></thead><tbody>
            @forelse($recentInstances as $inst)
            <tr class="cursor-pointer" onclick="window.location='{{ route('risk.workflows.show-instance', $inst) }}'">
                <td class="font-medium">{{ $inst->definition?->name }}</td>
                <td class="text-xs">{{ $inst->entity_type }} #{{ $inst->entity_id }}</td>
                <td>{{ $inst->current_stage + 1 }} / {{ is_array($inst->definition?->stages) ? count($inst->definition->stages) : 0 }}</td>
                <td>
                    @php $wc = ['active'=>'blue','completed'=>'green','rejected'=>'red','cancelled'=>'gray','escalated'=>'yellow']; @endphp
                    <span class="badge bg-{{ $wc[$inst->status] ?? 'gray' }}-100 text-{{ $wc[$inst->status] ?? 'gray' }}-700">{{ ucfirst($inst->status) }}</span>
                </td>
                <td class="text-xs">{{ $inst->initiator?->name }}</td>
                <td class="text-xs text-gray-500">{{ $inst->started_at?->diffForHumans() }}</td>
            </tr>
            @empty
            <tr><td colspan="6" class="text-center py-8 text-gray-400">No workflows yet</td></tr>
            @endforelse
        </tbody></table>
    </div>
</div>
@endsection
