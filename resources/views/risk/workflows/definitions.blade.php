@extends('layouts.app')
@section('title', 'Workflow Definitions')
@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-bold text-gray-900">Workflow Definitions</h1>
        <a href="{{ route('risk.workflows.create-definition') }}" class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-white rounded-lg text-sm font-medium">New Definition</a>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <table class="data-table"><thead><tr><th>Name</th><th>Entity Type</th><th>Stages</th><th>Active</th><th>Created By</th></tr></thead><tbody>
            @forelse($definitions as $def)
            <tr><td class="font-medium">{{ $def->name }}</td><td>{{ $def->entity_type }}</td><td>{{ is_array($def->stages) ? count($def->stages) : 0 }} stages</td><td><span class="badge bg-{{ $def->is_active ? 'green' : 'red' }}-100 text-{{ $def->is_active ? 'green' : 'red' }}-700">{{ $def->is_active ? 'Active' : 'Inactive' }}</span></td><td class="text-xs">{{ $def->creator?->name }}</td></tr>
            @empty
            <tr><td colspan="5" class="text-center py-8 text-gray-400">No workflow definitions</td></tr>
            @endforelse
        </tbody></table>
    </div>
</div>
@endsection
