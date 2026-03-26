@extends('layouts.app')
@section('title', 'Map Import Columns')
@section('content')
<div class="max-w-3xl mx-auto">
    <h1 class="text-xl font-bold text-gray-900 mb-2">Map Import Columns</h1>
    <p class="text-sm text-gray-500 mb-6">File: {{ $import->file_name }} ({{ $import->total_rows }} rows) &middot; Type: {{ ucfirst($import->import_type) }}</p>

    <form method="POST" action="{{ route('risk.imports.process', $import) }}" class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 space-y-4">@csrf
        <p class="text-sm text-gray-600 mb-4">Map each system field to the corresponding CSV column.</p>
        @foreach($systemFields as $field)
        <div class="flex items-center gap-4">
            <label class="w-48 text-sm font-medium text-gray-700">{{ ucfirst(str_replace('_', ' ', $field)) }}</label>
            <select name="column_mapping[{{ $field }}]" class="flex-1 border border-gray-200 rounded-lg px-3 py-2 text-sm">
                <option value="">— Skip —</option>
                @foreach($headers as $idx => $header)
                    <option value="{{ $idx }}">{{ $header }}</option>
                @endforeach
            </select>
        </div>
        @endforeach
        <div class="flex justify-end gap-3 pt-4 border-t"><a href="{{ route('risk.imports.index') }}" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm">Cancel</a><button type="submit" class="px-6 py-2 bg-green-600 text-white rounded-lg text-sm font-medium">Process Import</button></div>
    </form>
</div>
@endsection
