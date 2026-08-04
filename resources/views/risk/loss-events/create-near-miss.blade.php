@extends('layouts.app')

@section('title', 'Report Near Miss - GRC Platform')

@section('breadcrumbs')
    <span>Risk Management</span>
    <span class="text-gray-300">/</span>
    <span>Loss Events</span>
    <span class="text-gray-300">/</span>
    <span class="text-[#1A365D] font-semibold">Report Near Miss</span>
@endsection

@section('content')
    {{-- Page Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">Report Near Miss</h1>
            <p class="text-sm text-gray-500 mt-1">Capture events that could have resulted in a loss but were prevented or did not materialise</p>
        </div>
        <a href="{{ route('risk.loss-events.near-misses') }}" class="flex items-center gap-1 text-xs text-gray-500 hover:text-[#1A365D]">
            <span class="material-symbols-outlined text-sm">arrow_back</span>
            Back to Near Misses
        </a>
    </div>

    {{-- Validation Errors --}}
    @if ($errors->any())
        <div class="mb-6 px-4 py-3 bg-red-50 border border-red-200 rounded-lg">
            <div class="flex items-center gap-2 text-red-700 text-sm font-medium mb-2">
                <span class="material-symbols-outlined text-lg">error</span>
                Please correct the following errors:
            </div>
            <ul class="list-disc list-inside text-xs text-red-600 space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('risk.loss-events.store-near-miss') }}">
        @csrf

        {{-- Event Details --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <h2 class="text-sm font-bold text-[#1A365D] mb-4 flex items-center gap-2">
                <span class="material-symbols-outlined text-lg">report</span>
                Event Details
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Title <span class="text-red-500">*</span></label>
                    <input type="text" name="title" value="{{ old('title') }}" required maxlength="255"
                        class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 focus:ring-1 focus:ring-[#1A365D] focus:border-[#1A365D]"
                        placeholder="e.g. Potential duplicate payment caught before settlement">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Description <span class="text-red-500">*</span></label>
                    <textarea name="description" rows="4" required maxlength="5000"
                        class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 focus:ring-1 focus:ring-[#1A365D] focus:border-[#1A365D]"
                        placeholder="What happened, how it was detected, and why no loss occurred">{{ old('description') }}</textarea>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Date Occurred <span class="text-red-500">*</span></label>
                    <input type="date" name="date_occurred" value="{{ old('date_occurred') }}" required max="{{ now()->toDateString() }}"
                        class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 focus:ring-1 focus:ring-[#1A365D] focus:border-[#1A365D]">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Business Unit <span class="text-red-500">*</span></label>
                    <select name="business_unit_id" required
                        class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 focus:ring-1 focus:ring-[#1A365D] focus:border-[#1A365D]">
                        <option value="">Select business unit...</option>
                        @foreach ($businessUnits as $unit)
                            <option value="{{ $unit->id }}" {{ old('business_unit_id') == $unit->id ? 'selected' : '' }}>{{ $unit->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Severity <span class="text-red-500">*</span></label>
                    <select name="severity" required
                        class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 focus:ring-1 focus:ring-[#1A365D] focus:border-[#1A365D]">
                        <option value="">Select severity...</option>
                        @foreach (['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'] as $value => $label)
                            <option value="{{ $value }}" {{ old('severity') === $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Potential Loss Amount (₦)</label>
                    <input type="number" name="potential_loss_amount" value="{{ old('potential_loss_amount') }}" min="0" step="0.01"
                        class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 focus:ring-1 focus:ring-[#1A365D] focus:border-[#1A365D]"
                        placeholder="Estimated loss had the event materialised">
                </div>
            </div>
        </div>

        {{-- Linkages & Control Gap --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <h2 class="text-sm font-bold text-[#1A365D] mb-4 flex items-center gap-2">
                <span class="material-symbols-outlined text-lg">link</span>
                Linkages &amp; Control Gap
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Linked Risk</label>
                    <select name="risk_register_id"
                        class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 focus:ring-1 focus:ring-[#1A365D] focus:border-[#1A365D]">
                        <option value="">None</option>
                        @foreach ($risks as $risk)
                            <option value="{{ $risk->id }}" {{ old('risk_register_id') == $risk->id ? 'selected' : '' }}>{{ $risk->risk_code }} — {{ $risk->title }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Linked Control</label>
                    <select name="linked_control_id"
                        class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 focus:ring-1 focus:ring-[#1A365D] focus:border-[#1A365D]">
                        <option value="">None</option>
                        @foreach ($controls as $control)
                            <option value="{{ $control->id }}" {{ old('linked_control_id') == $control->id ? 'selected' : '' }}>{{ $control->control_code }} — {{ $control->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="inline-flex items-center gap-2 text-xs font-semibold text-gray-700">
                        <input type="hidden" name="control_gap_identified" value="0">
                        <input type="checkbox" name="control_gap_identified" value="1" {{ old('control_gap_identified') ? 'checked' : '' }}
                            class="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                            onchange="document.getElementById('gapDescriptionWrap').classList.toggle('hidden', !this.checked)">
                        A control gap was identified
                    </label>
                </div>
                <div id="gapDescriptionWrap" class="md:col-span-2 {{ old('control_gap_identified') ? '' : 'hidden' }}">
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Control Gap Description</label>
                    <textarea name="control_gap_description" rows="3" maxlength="2000"
                        class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 focus:ring-1 focus:ring-[#1A365D] focus:border-[#1A365D]"
                        placeholder="Describe the control weakness that allowed this near miss">{{ old('control_gap_description') }}</textarea>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Reported By <span class="text-red-500">*</span></label>
                    <select name="reported_by" required
                        class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 focus:ring-1 focus:ring-[#1A365D] focus:border-[#1A365D]">
                        <option value="">Select reporter...</option>
                        @foreach ($users as $user)
                            <option value="{{ $user->id }}" {{ (old('reported_by') ?? auth()->id()) == $user->id ? 'selected' : '' }}>{{ $user->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        {{-- Actions --}}
        <div class="flex items-center justify-end gap-3">
            <a href="{{ route('risk.loss-events.near-misses') }}"
                class="px-4 py-2 text-sm font-semibold text-gray-600 bg-white border border-gray-200 rounded-lg hover:bg-gray-50">Cancel</a>
            <button type="submit"
                class="px-4 py-2 text-sm font-semibold text-white bg-[#1A365D] rounded-lg hover:bg-[#142a4a] flex items-center gap-2">
                <span class="material-symbols-outlined text-sm">save</span>
                Report Near Miss
            </button>
        </div>
    </form>
@endsection
