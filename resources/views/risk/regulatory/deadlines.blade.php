@extends('layouts.app')
@section('title', 'Regulatory Deadlines')
@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-bold text-gray-900">Regulatory Deadlines</h1>
        <a href="{{ route('risk.regulatory.create-deadline') }}" class="px-4 py-2 bg-primary text-white rounded-lg text-sm font-medium">Add Deadline</a>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <table class="data-table"><thead><tr><th>Regulator</th><th>Title</th><th>Report Type</th><th>Deadline</th><th>Frequency</th><th>Status</th><th>Responsible</th></tr></thead><tbody>
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
            </tr>
            @empty
            <tr><td colspan="7" class="text-center py-8 text-gray-400">No deadlines</td></tr>
            @endforelse
        </tbody></table>
    </div>
    <div>{{ $deadlines->links() }}</div>
</div>
@endsection
