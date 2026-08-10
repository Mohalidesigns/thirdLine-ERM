@extends('layouts.app')
@section('title', 'Workflow Definitions')
@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-gray-900">Workflow Definitions</h1>
            <p class="mt-1 max-w-3xl text-xs text-gray-500">
                Publishing writes a new version. Anything already running stays on the version it started with,
                so a change here cannot alter a decision already in progress.
            </p>
        </div>
        <a href="{{ route('risk.workflows.create-definition') }}" class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-white rounded-lg text-sm font-medium">New Definition</a>
    </div>

    @if (session('error'))
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm whitespace-pre-line text-red-800">{{ session('error') }}</div>
    @endif
    @if (session('success'))
        <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('success') }}</div>
    @endif

    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <table class="data-table"><thead><tr><th>Name</th><th>Runs over</th><th>Steps</th><th>Version</th><th>State</th><th>Actions</th></tr></thead><tbody>
            @forelse($definitions as $def)
            @php $steps = count($def->graph()->nodes()); @endphp
            <tr>
                <td class="font-medium">
                    {{ $def->name }}
                    @if ($def->is_system)
                        <span class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium text-gray-600">shipped</span>
                    @endif
                    <span class="block font-mono text-[11px] text-gray-400">{{ $def->code }}</span>
                </td>
                <td>{{ str_replace('_', ' ', $def->entity_type) }}</td>
                <td>{{ $steps }} {{ Str::plural('step', $steps) }}</td>
                <td>v{{ $def->version }}</td>
                <td>
                    @if ($def->is_published)
                        <span class="badge bg-green-100 text-green-700">Published</span>
                    @else
                        <span class="badge bg-amber-100 text-amber-700">Draft</span>
                    @endif
                </td>
                <td class="whitespace-nowrap">
                    <a href="{{ route('risk.workflows.edit-definition', $def) }}"
                       class="inline-flex items-center gap-1 px-3 py-1.5 border border-gray-300 text-gray-700 text-xs font-semibold rounded-lg hover:bg-gray-50">
                        <span class="material-symbols-outlined text-sm">edit</span>
                        {{ $def->is_published ? 'New version' : 'Edit' }}
                    </a>

                    @unless ($def->is_published)
                        <form method="POST" action="{{ route('risk.workflows.publish-definition', $def) }}" class="inline">
                            @csrf
                            <button type="submit" class="inline-flex items-center gap-1 px-3 py-1.5 bg-[#2D7D46] text-white text-xs font-semibold rounded-lg hover:bg-[#236B38]">
                                Publish
                            </button>
                        </form>
                    @endunless

                    @if ($def->is_published && isset($entityOptions[$def->entity_type]))
                        <button type="button" onclick="document.getElementById('start-wf-{{ $def->id }}').classList.toggle('hidden')"
                                class="inline-flex items-center gap-1 px-3 py-1.5 bg-[#1A365D] text-white text-xs font-semibold rounded-lg hover:bg-[#2D4A7A]">
                            <span class="material-symbols-outlined text-sm">play_arrow</span>
                            Start
                        </button>
                    @endif
                </td>
            </tr>
            @if ($def->is_published && isset($entityOptions[$def->entity_type]))
                <tr id="start-wf-{{ $def->id }}" class="hidden">
                    <td colspan="6" class="bg-gray-50">
                        <form method="POST" action="{{ route('risk.workflows.start') }}" class="flex items-end gap-3 p-3">
                            @csrf
                            <input type="hidden" name="definition_id" value="{{ $def->id }}">
                            <input type="hidden" name="entity_type" value="{{ $def->entity_type }}">
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

    <div>{{ $definitions->links() }}</div>
</div>
@endsection
