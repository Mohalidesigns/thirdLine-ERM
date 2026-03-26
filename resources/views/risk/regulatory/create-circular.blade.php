@extends('layouts.app')
@section('title', 'Record Circular')
@section('content')
<div class="max-w-2xl mx-auto">
    <h1 class="text-xl font-bold text-gray-900 mb-6">Record Regulatory Circular</h1>
    <form method="POST" action="{{ route('risk.regulatory.store-circular') }}" class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 space-y-5">@csrf
        <div class="grid grid-cols-2 gap-5">
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Regulator</label><select name="regulator" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"><option value="CBN">CBN</option><option value="NFIU">NFIU</option><option value="SEC">SEC</option><option value="NDPA">NDPA</option><option value="NAICOM">NAICOM</option><option value="NDIC">NDIC</option></select></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Reference Number</label><input type="text" name="circular_ref" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" placeholder="e.g., BSD/DIR/GEN/LAB/15/079"></div>
        </div>
        <div><label class="block text-sm font-medium text-gray-700 mb-1">Title</label><input type="text" name="title" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"></div>
        <div><label class="block text-sm font-medium text-gray-700 mb-1">Summary</label><textarea name="summary" rows="3" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"></textarea></div>
        <div class="grid grid-cols-3 gap-5">
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Date Issued</label><input type="date" name="date_issued" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Effective Date</label><input type="date" name="effective_date" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Impact Level</label><select name="impact_level" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option><option value="critical">Critical</option></select></div>
        </div>
        <div><label class="block text-sm font-medium text-gray-700 mb-1">Action Required</label><textarea name="action_required" rows="2" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"></textarea></div>
        <div><label class="block text-sm font-medium text-gray-700 mb-1">Assigned To</label><select name="assigned_to" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"><option value="">Select</option>@foreach($users as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach</select></div>
        <div class="flex justify-end gap-3 pt-4 border-t"><a href="{{ route('risk.regulatory.circulars') }}" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm">Cancel</a><button type="submit" class="px-6 py-2 bg-primary text-white rounded-lg text-sm font-medium">Record</button></div>
    </form>
</div>
@endsection
