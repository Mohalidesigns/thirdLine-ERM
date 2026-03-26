@extends('layouts.app')
@section('title', 'Edit Control Test')
@section('content')
<div class="max-w-3xl mx-auto">
    <h1 class="text-xl font-bold text-gray-900 mb-6">Edit Control Test</h1>
    <form method="POST" action="{{ route('risk.control-tests.update', $controlTest) }}" class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 space-y-5">@csrf @method('PUT')
        <div class="grid grid-cols-2 gap-5">
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Control</label><select name="control_id" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">@foreach($controls as $c)<option value="{{ $c->id }}" {{ $controlTest->control_id == $c->id ? 'selected' : '' }}>{{ $c->control_code }} — {{ $c->name }}</option>@endforeach</select></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Test Type</label><select name="test_type" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">@foreach(['operating_effectiveness','design_effectiveness','walkthrough','substantive'] as $t)<option value="{{ $t }}" {{ $controlTest->test_type === $t ? 'selected' : '' }}>{{ ucfirst(str_replace('_',' ',$t)) }}</option>@endforeach</select></div>
        </div>
        <div><label class="block text-sm font-medium text-gray-700 mb-1">Title</label><input type="text" name="title" value="{{ $controlTest->title }}" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"></div>
        <div><label class="block text-sm font-medium text-gray-700 mb-1">Description</label><textarea name="description" rows="3" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">{{ $controlTest->description }}</textarea></div>
        <div class="grid grid-cols-3 gap-5">
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Tester</label><select name="tester_id" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">@foreach($users as $u)<option value="{{ $u->id }}" {{ $controlTest->tester_id == $u->id ? 'selected' : '' }}>{{ $u->name }}</option>@endforeach</select></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Reviewer</label><select name="reviewer_id" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"><option value="">None</option>@foreach($users as $u)<option value="{{ $u->id }}" {{ $controlTest->reviewer_id == $u->id ? 'selected' : '' }}>{{ $u->name }}</option>@endforeach</select></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Scheduled Date</label><input type="date" name="scheduled_date" value="{{ $controlTest->scheduled_date->format('Y-m-d') }}" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"></div>
        </div>
        <div class="flex justify-end gap-3 pt-4 border-t"><a href="{{ route('risk.control-tests.show', $controlTest) }}" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm">Cancel</a><button type="submit" class="px-6 py-2 bg-primary text-white rounded-lg text-sm font-medium">Update</button></div>
    </form>
</div>
@endsection
