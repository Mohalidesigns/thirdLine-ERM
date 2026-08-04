@extends('layouts.app')
@section('title', 'Workflow Definitions')
@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-bold text-gray-900">Workflow Definitions</h1>
        <a href="{{ route('risk.workflows.create-definition') }}" class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-white rounded-lg text-sm font-medium">New Definition</a>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <table class="data-table"><thead><tr><th>Name</th><th>Entity Type</th><th>Stages</th><th>Active</th><th>Created By</th><th>Actions</th></tr></thead><tbody>
            @forelse($definitions as $def)
            <tr>
                <td class="font-medium">{{ $def->name }}</td>
                <td>{{ $def->entity_type }}</td>
                <td>{{ is_array($def->stages) ? count($def->stages) : 0 }} stages</td>
                <td><span class="badge bg-{{ $def->is_active ? 'green' : 'red' }}-100 text-{{ $def->is_active ? 'green' : 'red' }}-700">{{ $def->is_active ? 'Active' : 'Inactive' }}</span></td>
                <td class="text-xs">{{ $def->creator?->name }}</td>
                <td>
                    @if ($def->is_active && isset($entityOptions[$def->entity_type]))
                        <button type="button" onclick="document.getElementById('start-wf-{{ $def->id }}').classList.toggle('hidden')"
                                class="inline-flex items-center gap-1 px-3 py-1.5 bg-[#1A365D] text-white text-xs font-semibold rounded-lg hover:bg-[#2D4A7A]">
                            <span class="material-symbols-outlined text-sm">play_arrow</span>
                            Start
                        </button>
                    @endif
                </td>
            </tr>
            @if ($def->is_active && isset($entityOptions[$def->entity_type]))
                <tr id="start-wf-{{ $def->id }}" class="hidden">
                    <td colspan="6" class="bg-gray-50">
                        <form method="POST" action="{{ route('risk.workflows.start') }}" class="flex items-end gap-3 p-3">
                            @csrf
                            <input type="hidden" name="definition_id" value="{{ $def->id }}">
                            <input type="hidden" name="entity_type" value="{{ $entityOptions[$def->entity_type]['class'] }}">
                            <div class="flex-1">
                                <label class="block text-xs font-semibold text-gray-700 mb-1">Select {{ str_replace('_', ' ', $def->entity_type) }}</label>
                                <select name="entity_id" required class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2">
                                    @foreach ($entityOptions[$def->entity_type]['items'] as $opt)
                                        <option value="{{ $opt['id'] }}">{{ $opt['label'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <button type="submit" class="px-4 py-2 bg-[#2D7D46] text-white text-xs font-semibold rounded-lg hover:bg-[#236B38]">Start Workflow</button>
                            <button type="button" class="px-3 py-2 text-xs text-gray-500"
                                onclick="document.getElementById('start-wf-{{ $def->id }}').classList.add('hidden')">Cancel</button>
                        </form>
                    </td>
                </tr>
            @endif
            @empty
            <tr><td colspan="6" class="text-center py-8 text-gray-400">No workflow definitions</td></tr>
            @endforelse
        </tbody></table>
    </div>
</div>
@endsection
