@extends('layouts.app')
@section('title', 'Respond to Assessment')
@section('breadcrumbs')
    <a href="/risk/dashboard" class="hover:text-primary">Dashboard</a>
    <span class="material-symbols-outlined text-[14px]">chevron_right</span>
    <a href="{{ route('risk.campaigns.show', $assignment->campaign) }}" class="hover:text-primary">{{ $assignment->campaign->campaign_code }}</a>
    <span class="material-symbols-outlined text-[14px]">chevron_right</span>
    <span class="text-gray-700 font-medium">Respond</span>
@endsection

@section('content')
<div class="max-w-4xl mx-auto space-y-6">
    <div>
        <h1 class="text-xl font-bold text-gray-900">{{ $assignment->campaign->title }}</h1>
        <p class="text-sm text-gray-500 mt-1">Business Unit: {{ $assignment->businessUnit?->name }} &middot; Due: {{ $assignment->due_date->format('M d, Y') }}</p>
    </div>

    {{-- This form is built from the business unit's register risks, so it shows
         nothing of a submission whose lines are free text — an RCSA worksheet
         reads as "no risks found" here even when it recorded plenty. Point at
         the read-back rather than letting the page imply the work is gone. --}}
    @if($assignment->responses->isNotEmpty())
        <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 flex items-center justify-between gap-4">
            <p class="text-sm text-blue-800">
                {{ $assignment->responses->count() }} {{ Str::plural('line', $assignment->responses->count()) }}
                already recorded against this assignment. Submitting below replaces them.
            </p>
            @can('campaign.view')
                <a href="{{ route('risk.campaigns.submission', $assignment) }}"
                   class="text-sm font-medium text-blue-700 hover:underline whitespace-nowrap">View submission</a>
            @endcan
        </div>
    @endif

    <form method="POST" action="{{ route('risk.campaigns.submit-response', $assignment) }}" class="space-y-6">
        @csrf

        {{-- Risk Assessment Section --}}
        @foreach($risks as $idx => $risk)
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
            <h3 class="text-sm font-semibold text-gray-900 mb-4">{{ $risk->risk_code }} — {{ $risk->title }}</h3>
            <p class="text-xs text-gray-500 mb-4">{{ Str::limit($risk->description, 200) }}</p>

            <input type="hidden" name="responses[{{ $idx }}][risk_id]" value="{{ $risk->id }}">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="text-xs font-medium text-gray-600">Likelihood (1-5)</label>
                    <select name="responses[{{ $idx }}][likelihood_score]" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm mt-1">
                        <option value="">Select</option>
                        @for($i = 1; $i <= 5; $i++)
                            <option value="{{ $i }}">{{ $i }} - {{ ['','Rare','Unlikely','Possible','Likely','Almost Certain'][$i] }}</option>
                        @endfor
                    </select>
                </div>
                <div>
                    <label class="text-xs font-medium text-gray-600">Impact (1-5)</label>
                    <select name="responses[{{ $idx }}][impact_score]" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm mt-1">
                        <option value="">Select</option>
                        @for($i = 1; $i <= 5; $i++)
                            <option value="{{ $i }}">{{ $i }} - {{ ['','Insignificant','Minor','Moderate','Major','Catastrophic'][$i] }}</option>
                        @endfor
                    </select>
                </div>
                <div>
                    <label class="text-xs font-medium text-gray-600">Control Effectiveness</label>
                    <select name="responses[{{ $idx }}][control_effectiveness]" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm mt-1">
                        <option value="">Select</option>
                        <option value="effective">Effective</option>
                        <option value="partially_effective">Partially Effective</option>
                        <option value="ineffective">Ineffective</option>
                        <option value="not_applicable">N/A</option>
                    </select>
                </div>
            </div>
            <div class="mt-3">
                <label class="text-xs font-medium text-gray-600">Comments / Observations</label>
                <textarea name="responses[{{ $idx }}][comments]" rows="2" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm mt-1" placeholder="Additional observations..."></textarea>
            </div>
        </div>
        @endforeach

        @if($risks->isEmpty())
        <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-6 text-center">
            <p class="text-sm text-yellow-700">No risks found for this business unit. Please add at least one assessment response.</p>
            <div class="bg-white rounded-lg border border-gray-200 p-4 mt-4 max-w-xl mx-auto">
                <input type="hidden" name="responses[0][risk_id]" value="">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-xs font-medium text-gray-600">Likelihood</label>
                        <select name="responses[0][likelihood_score]" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm mt-1">
                            @for($i = 1; $i <= 5; $i++)<option value="{{ $i }}">{{ $i }}</option>@endfor
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-medium text-gray-600">Impact</label>
                        <select name="responses[0][impact_score]" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm mt-1">
                            @for($i = 1; $i <= 5; $i++)<option value="{{ $i }}">{{ $i }}</option>@endfor
                        </select>
                    </div>
                </div>
                <textarea name="responses[0][comments]" rows="2" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm mt-3" placeholder="General assessment comments..."></textarea>
            </div>
        </div>
        @endif

        <div class="flex justify-end gap-3">
            <a href="{{ route('risk.campaigns.show', $assignment->campaign) }}" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm">Cancel</a>
            <button type="submit" class="px-6 py-2 bg-primary text-white rounded-lg text-sm font-medium hover:bg-opacity-90">Submit Assessment</button>
        </div>
    </form>
</div>
@endsection
