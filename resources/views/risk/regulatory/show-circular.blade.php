@extends('layouts.app')
@section('title', 'Circular: ' . $circular->circular_ref)
@section('content')
<div class="max-w-3xl mx-auto space-y-6">
    <h1 class="text-xl font-bold text-gray-900">{{ $circular->title }}</h1>
    <p class="text-sm text-gray-500">{{ $circular->regulator }} &middot; {{ $circular->circular_ref }} &middot; Issued {{ $circular->date_issued->format('M d, Y') }}</p>

    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 space-y-4">
        <div class="grid grid-cols-2 gap-4 text-sm">
            <div><span class="text-gray-500">Impact Level:</span> <span class="font-medium">{{ ucfirst($circular->impact_level) }}</span></div>
            <div><span class="text-gray-500">Effective Date:</span> <span class="font-medium">{{ $circular->effective_date?->format('M d, Y') ?? '—' }}</span></div>
            <div><span class="text-gray-500">Assigned To:</span> <span class="font-medium">{{ $circular->assignee?->name ?? '—' }}</span></div>
            <div><span class="text-gray-500">Compliance:</span> <span class="font-medium">{{ ucfirst(str_replace('_',' ',$circular->compliance_status)) }} ({{ $circular->compliance_pct }}%)</span></div>
        </div>
        @if($circular->summary)<div class="pt-4 border-t border-gray-100"><h4 class="text-xs font-semibold text-gray-500 uppercase mb-2">Summary</h4><p class="text-sm text-gray-700">{{ $circular->summary }}</p></div>@endif
        @if($circular->action_required)<div class="pt-4 border-t border-gray-100"><h4 class="text-xs font-semibold text-gray-500 uppercase mb-2">Action Required</h4><p class="text-sm text-gray-700">{{ $circular->action_required }}</p></div>@endif
    </div>

    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
        <h3 class="text-sm font-semibold text-gray-900 mb-4">Update Compliance Status</h3>
        <form method="POST" action="{{ route('risk.regulatory.update-compliance', $circular) }}" class="space-y-3">@csrf @method('PATCH')
            <div class="grid grid-cols-2 gap-4">
                <div><select name="compliance_status" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"><option value="not_assessed" {{ $circular->compliance_status === 'not_assessed' ? 'selected' : '' }}>Not Assessed</option><option value="compliant" {{ $circular->compliance_status === 'compliant' ? 'selected' : '' }}>Compliant</option><option value="partially_compliant" {{ $circular->compliance_status === 'partially_compliant' ? 'selected' : '' }}>Partially Compliant</option><option value="non_compliant" {{ $circular->compliance_status === 'non_compliant' ? 'selected' : '' }}>Non-Compliant</option><option value="not_applicable" {{ $circular->compliance_status === 'not_applicable' ? 'selected' : '' }}>Not Applicable</option></select></div>
                <div><input type="number" name="compliance_pct" value="{{ $circular->compliance_pct }}" min="0" max="100" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" placeholder="Compliance %"></div>
            </div>
            <button type="submit" class="px-4 py-2 bg-primary text-white rounded-lg text-sm font-medium">Update</button>
        </form>
    </div>
</div>
@endsection
