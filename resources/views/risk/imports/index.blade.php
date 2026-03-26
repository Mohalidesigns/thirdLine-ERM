@extends('layouts.app')
@section('title', 'Data Imports')
@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-bold text-gray-900">Data Import History</h1>
        <a href="{{ route('risk.imports.create') }}" class="px-4 py-2 bg-primary text-white rounded-lg text-sm font-medium">New Import</a>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <table class="data-table"><thead><tr><th>File</th><th>Type</th><th>Total</th><th>Success</th><th>Errors</th><th>Status</th><th>Imported By</th><th>Date</th></tr></thead><tbody>
            @forelse($imports as $imp)
            <tr>
                <td class="text-xs font-medium">{{ $imp->file_name }}</td>
                <td><span class="badge bg-blue-50 text-blue-700">{{ ucfirst($imp->import_type) }}</span></td>
                <td>{{ $imp->total_rows }}</td>
                <td class="text-green-600">{{ $imp->success_count }}</td>
                <td class="text-red-600">{{ $imp->error_count }}</td>
                <td>@php $ic = ['pending'=>'gray','processing'=>'yellow','completed'=>'green','failed'=>'red']; @endphp<span class="badge bg-{{ $ic[$imp->status] ?? 'gray' }}-100 text-{{ $ic[$imp->status] ?? 'gray' }}-700">{{ ucfirst($imp->status) }}</span></td>
                <td class="text-xs">{{ $imp->importer?->name }}</td>
                <td class="text-xs text-gray-500">{{ $imp->created_at->format('M d, Y H:i') }}</td>
            </tr>
            @empty
            <tr><td colspan="8" class="text-center py-8 text-gray-400">No imports yet</td></tr>
            @endforelse
        </tbody></table>
    </div>
    <div>{{ $imports->links() }}</div>
</div>
@endsection
