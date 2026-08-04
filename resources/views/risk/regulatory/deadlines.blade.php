@extends('layouts.app')
@section('title', 'Regulatory Deadlines')
@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-bold text-gray-900">Regulatory Deadlines</h1>
        <a href="{{ route('risk.regulatory.create-deadline') }}" class="px-4 py-2 bg-primary text-white rounded-lg text-sm font-medium">Add Deadline</a>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <table class="data-table"><thead><tr><th>Regulator</th><th>Title</th><th>Report Type</th><th>Deadline</th><th>Frequency</th><th>Status</th><th>Responsible</th><th>Actions</th></tr></thead><tbody>
            @forelse($deadlines as $dl)
            <tr>
                <td><span class="badge bg-blue-50 text-blue-700">{{ $dl->regulator }}</span></td>
                <td class="font-medium">{{ Str::limit($dl->title, 40) }}</td>
                <td class="text-xs">{{ $dl->report_type }}</td>
                <td class="{{ $dl->isOverdue() ? 'text-red-600 font-semibold' : '' }}">{{ $dl->deadline_date->format('M d, Y') }}</td>
                <td class="text-xs">{{ ucfirst($dl->frequency) }}</td>
                <td>@php $dc = ['upcoming'=>'blue','in_progress'=>'yellow','submitted'=>'green','overdue'=>'red','not_applicable'=>'gray']; @endphp
                    <span class="badge bg-{{ $dc[$dl->status] ?? 'gray' }}-100 text-{{ $dc[$dl->status] ?? 'gray' }}-700">{{ ucfirst(str_replace('_',' ',$dl->status)) }}</span></td>
                <td class="text-xs">{{ $dl->responsible?->name ?? '—' }}</td>
                <td>
                    @if ($dl->status !== 'submitted')
                        <button type="button" onclick="document.getElementById('file-dl-{{ $dl->id }}').classList.toggle('hidden')"
                                class="inline-flex items-center gap-1 px-3 py-1.5 bg-[#1A365D] text-white text-xs font-semibold rounded-lg hover:bg-[#2D4A7A]">
                            <span class="material-symbols-outlined text-sm">upload_file</span>
                            File Return
                        </button>
                    @else
                        <span class="text-xs text-green-600 font-medium">Filed</span>
                    @endif
                </td>
            </tr>
            @if ($dl->status !== 'submitted')
                <tr id="file-dl-{{ $dl->id }}" class="hidden">
                    <td colspan="8" class="bg-gray-50">
                        <form method="POST" action="{{ route('risk.regulatory.submit-filing', $dl) }}" class="flex items-end gap-3 p-3">
                            @csrf
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1">Filing Date</label>
                                <input type="date" name="filing_date" required value="{{ now()->toDateString() }}"
                                    class="text-sm border border-gray-200 rounded-lg px-3 py-2">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1">Document Ref</label>
                                <input type="text" name="document_ref" placeholder="e.g. CBN/RET/2026/0193"
                                    class="text-sm border border-gray-200 rounded-lg px-3 py-2">
                            </div>
                            <div class="flex-1">
                                <label class="block text-xs font-semibold text-gray-700 mb-1">Notes</label>
                                <input type="text" name="notes" placeholder="Optional notes"
                                    class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2">
                            </div>
                            <button type="submit" class="px-4 py-2 bg-[#2D7D46] text-white text-xs font-semibold rounded-lg hover:bg-[#236B38]">Submit Filing</button>
                            <button type="button" class="px-3 py-2 text-xs text-gray-500"
                                onclick="document.getElementById('file-dl-{{ $dl->id }}').classList.add('hidden')">Cancel</button>
                        </form>
                    </td>
                </tr>
            @endif
            @empty
            <tr><td colspan="8" class="text-center py-8 text-gray-400">No deadlines</td></tr>
            @endforelse
        </tbody></table>
    </div>
    <div>{{ $deadlines->links() }}</div>
</div>
@endsection
