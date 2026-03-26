@extends('layouts.app')
@section('title', 'Schedule Control Test')
@section('breadcrumbs')
    <a href="/risk/dashboard" class="hover:text-primary">Dashboard</a>
    <span class="material-symbols-outlined text-[14px]">chevron_right</span>
    <a href="{{ route('risk.control-tests.index') }}" class="hover:text-primary">Control Tests</a>
    <span class="material-symbols-outlined text-[14px]">chevron_right</span>
    <span class="text-gray-700 font-medium">Schedule Test</span>
@endsection

@section('content')
<div class="max-w-3xl mx-auto">
    <h1 class="text-xl font-bold text-gray-900 mb-6">Schedule New Control Test</h1>

    <form method="POST" action="{{ route('risk.control-tests.store') }}" class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 space-y-5">
        @csrf

        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Control <span class="text-red-500">*</span></label>
                <select name="control_id" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                    <option value="">Select Control</option>
                    @foreach($controls as $control)
                        <option value="{{ $control->id }}" {{ old('control_id') == $control->id ? 'selected' : '' }}>{{ $control->control_code }} — {{ $control->name }}</option>
                    @endforeach
                </select>
                @error('control_id') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Test Type <span class="text-red-500">*</span></label>
                <select name="test_type" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                    <option value="operating_effectiveness">Operating Effectiveness</option>
                    <option value="design_effectiveness">Design Effectiveness</option>
                    <option value="walkthrough">Walkthrough</option>
                    <option value="substantive">Substantive</option>
                </select>
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Title <span class="text-red-500">*</span></label>
            <input type="text" name="title" value="{{ old('title') }}" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" placeholder="e.g., Q1 2026 Operating Effectiveness Test">
            @error('title') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
            <textarea name="description" rows="3" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" placeholder="Test objectives and scope...">{{ old('description') }}</textarea>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Tester <span class="text-red-500">*</span></label>
                <select name="tester_id" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                    <option value="">Select Tester</option>
                    @foreach($users as $user)
                        <option value="{{ $user->id }}">{{ $user->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Reviewer</label>
                <select name="reviewer_id" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                    <option value="">None</option>
                    @foreach($users as $user)
                        <option value="{{ $user->id }}">{{ $user->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Scheduled Date <span class="text-red-500">*</span></label>
                <input type="date" name="scheduled_date" value="{{ old('scheduled_date') }}" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
            </div>
        </div>

        <div class="flex justify-end gap-3 pt-4 border-t border-gray-100">
            <a href="{{ route('risk.control-tests.index') }}" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm hover:bg-gray-200">Cancel</a>
            <button type="submit" class="px-6 py-2 bg-primary text-white rounded-lg text-sm font-medium hover:bg-opacity-90">Schedule Test</button>
        </div>
    </form>
</div>
@endsection
