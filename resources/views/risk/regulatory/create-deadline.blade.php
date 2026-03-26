@extends('layouts.app')
@section('title', 'Create Regulatory Deadline')
@section('content')
<div class="max-w-2xl mx-auto">
    <h1 class="text-xl font-bold text-gray-900 mb-6">Add Regulatory Deadline</h1>
    <form method="POST" action="{{ route('risk.regulatory.store-deadline') }}" class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 space-y-5">@csrf
        <div class="grid grid-cols-2 gap-5">
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Regulator</label>
                <select name="regulator" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                    <option value="CBN">CBN</option><option value="NFIU">NFIU</option><option value="SEC">SEC Nigeria</option><option value="NDPA">NDPA</option><option value="NAICOM">NAICOM</option><option value="NDIC">NDIC</option><option value="FIRS">FIRS</option>
                </select></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Report Type</label><input type="text" name="report_type" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" placeholder="e.g., Monthly Returns"></div>
        </div>
        <div><label class="block text-sm font-medium text-gray-700 mb-1">Title</label><input type="text" name="title" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"></div>
        <div><label class="block text-sm font-medium text-gray-700 mb-1">Description</label><textarea name="description" rows="2" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"></textarea></div>
        <div class="grid grid-cols-3 gap-5">
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Deadline Date</label><input type="date" name="deadline_date" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Frequency</label><select name="frequency" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"><option value="monthly">Monthly</option><option value="quarterly">Quarterly</option><option value="semi_annual">Semi-Annual</option><option value="annual">Annual</option><option value="ad_hoc">Ad Hoc</option></select></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Responsible</label><select name="responsible_id" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"><option value="">Select</option>@foreach($users as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach</select></div>
        </div>
        <div class="flex justify-end gap-3 pt-4 border-t"><a href="{{ route('risk.regulatory.deadlines') }}" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm">Cancel</a><button type="submit" class="px-6 py-2 bg-primary text-white rounded-lg text-sm font-medium">Create</button></div>
    </form>
</div>
@endsection
