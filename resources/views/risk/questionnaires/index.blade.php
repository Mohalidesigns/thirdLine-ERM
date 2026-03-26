@extends('layouts.app')
@section('title', 'Questionnaires')
@section('breadcrumbs')
    <a href="/risk/dashboard" class="hover:text-primary">Dashboard</a><span class="material-symbols-outlined text-[14px]">chevron_right</span><span class="text-gray-700 font-medium">Questionnaires</span>
@endsection
@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-bold text-gray-900">Questionnaire Library</h1>
        <a href="{{ route('risk.questionnaires.create') }}" class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-white rounded-lg text-sm font-medium hover:bg-opacity-90"><span class="material-symbols-outlined text-lg">add_circle</span> New Questionnaire</a>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <table class="data-table">
            <thead><tr><th>Title</th><th>Type</th><th>Sections</th><th>Status</th><th>Version</th><th>Created</th></tr></thead>
            <tbody>
                @forelse($questionnaires as $q)
                <tr class="cursor-pointer" onclick="window.location='{{ route('risk.questionnaires.edit', $q) }}'">
                    <td class="font-medium">{{ $q->title }}</td>
                    <td><span class="badge bg-blue-50 text-blue-700">{{ ucfirst(str_replace('_', ' ', $q->questionnaire_type)) }}</span></td>
                    <td>{{ $q->sections_count }}</td>
                    <td>
                        @php $sc = ['draft'=>'gray','published'=>'green','archived'=>'red']; @endphp
                        <span class="badge bg-{{ $sc[$q->status] ?? 'gray' }}-100 text-{{ $sc[$q->status] ?? 'gray' }}-700">{{ ucfirst($q->status) }}</span>
                    </td>
                    <td>v{{ $q->version }}</td>
                    <td class="text-xs text-gray-500">{{ $q->created_at->format('M d, Y') }}</td>
                </tr>
                @empty
                <tr><td colspan="6" class="text-center py-8 text-gray-400">No questionnaires yet</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div>{{ $questionnaires->links() }}</div>
</div>
@endsection
