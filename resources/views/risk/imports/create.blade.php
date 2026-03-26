@extends('layouts.app')
@section('title', 'New Import')
@section('content')
<div class="max-w-2xl mx-auto">
    <h1 class="text-xl font-bold text-gray-900 mb-6">Import Data</h1>
    <form method="POST" action="{{ route('risk.imports.upload') }}" enctype="multipart/form-data" class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 space-y-5">@csrf
        <div><label class="block text-sm font-medium text-gray-700 mb-1">Import Type</label>
            <select name="import_type" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                <option value="risks">Risks</option><option value="controls">Controls</option><option value="loss_events">Loss Events</option><option value="issues">Issues</option><option value="kris">Key Risk Indicators</option>
            </select></div>
        <div><label class="block text-sm font-medium text-gray-700 mb-1">CSV File</label><input type="file" name="file" accept=".csv,.xlsx,.xls" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"><p class="text-xs text-gray-400 mt-1">Supported: CSV, XLSX, XLS (max 10MB)</p></div>
        <div class="flex justify-end gap-3 pt-4 border-t"><a href="{{ route('risk.imports.index') }}" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm">Cancel</a><button type="submit" class="px-6 py-2 bg-primary text-white rounded-lg text-sm font-medium">Upload & Map</button></div>
    </form>
</div>
@endsection
