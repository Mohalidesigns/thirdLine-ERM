@extends('layouts.app')

@section('title', 'RCSA Worksheet - GRC Risk Management')
@section('page-section', 'RCSA')
@section('page-title', 'Worksheet')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.rcsa.dashboard') }}" class="hover:text-[#1A365D]">RCSA</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Self-Assessment Worksheet</span>
@endsection

@section('content')
    @if ($errors->any())
        <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl">
            <div class="flex items-center gap-2 mb-2"><span class="material-symbols-outlined text-red-600">error</span><span class="text-sm font-semibold text-red-700">Please correct the following errors:</span></div>
            <ul class="list-disc list-inside text-sm text-red-600 space-y-1">@foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach</ul>
        </div>
    @endif

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-[#1A365D]">Risk & Control Self-Assessment Worksheet</h1>
        <p class="text-sm text-gray-500 mt-1">Assess risks and controls for your business unit processes</p>
    </div>

    <form method="POST" action="{{ route('risk.rcsa.worksheet.store') }}">
        @csrf

        {{-- Assessment Context --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6"><div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">1</div><h2 class="text-lg font-semibold text-[#1A365D]">Assessment Context</h2></div>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <div>
                    <label for="campaign_id" class="block text-sm font-medium text-gray-700 mb-2">Assessment Campaign</label>
                    <select id="campaign_id" name="campaign_id" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        <option value="">Current open RCSA campaign</option>
                        @foreach (($campaigns ?? []) as $campaign)
                            <option value="{{ $campaign->id }}" {{ old('campaign_id') == $campaign->id ? 'selected' : '' }}>
                                {{ $campaign->campaign_code }} — {{ $campaign->title }}
                            </option>
                        @endforeach
                    </select>
                    <p class="text-xs text-gray-500 mt-1">
                        Leave as-is to file against the open RCSA campaign. If none is open, one is created for you —
                        your worksheet is never discarded.
                    </p>
                </div>
                <div>
                    <label for="assessment_date" class="block text-sm font-medium text-gray-700 mb-2">Assessment Date <span class="text-red-500">*</span></label>
                    <input type="date" id="assessment_date" name="assessment_date" value="{{ old('assessment_date', now()->format('Y-m-d')) }}" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]" required>
                </div>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div>
                    <label for="business_unit_id" class="block text-sm font-medium text-gray-700 mb-2">Business Unit <span class="text-red-500">*</span></label>
                    <select id="business_unit_id" name="business_unit_id" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]" required>
                        <option value="">Select Unit</option>
                        @foreach (($businessUnits ?? []) as $unit)
                            <option value="{{ $unit->id ?? $unit }}" {{ old('business_unit_id') == ($unit->id ?? $unit) ? 'selected' : '' }}>{{ $unit->name ?? $unit }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="process_id" class="block text-sm font-medium text-gray-700 mb-2">Process <span class="text-red-500">*</span></label>
                    <select id="process_id" name="process_id" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]" required>
                        <option value="">Select Process</option>
                        @foreach (($processes ?? []) as $process)
                            <option value="{{ $process->id ?? $process }}" {{ old('process_id') == ($process->id ?? $process) ? 'selected' : '' }}>{{ $process->name ?? $process }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        {{-- Risk Assessments --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6"><div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">2</div><h2 class="text-lg font-semibold text-[#1A365D]">Risk Assessment</h2></div>

            <div id="riskAssessments">
                <div class="risk-assessment-row border border-gray-200 rounded-lg p-4 mb-4">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Risk Description <span class="text-red-500">*</span></label>
                            <textarea name="risks[0][description]" rows="2" placeholder="Describe the risk..." class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]" required>{{ old('risks.0.description') }}</textarea>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Risk Category</label>
                            <select name="risks[0][category]" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                                <option value="">Select</option>
                                @foreach (['Credit', 'Market', 'Operational', 'Liquidity', 'Compliance', 'Strategic', 'Technology'] as $cat)
                                    <option value="{{ $cat }}">{{ $cat }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Inherent Likelihood (1-5)</label>
                            <select name="risks[0][inherent_likelihood]" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" required>
                                @foreach ([1=>'Rare',2=>'Unlikely',3=>'Possible',4=>'Likely',5=>'Almost Certain'] as $v => $l) <option value="{{ $v }}">{{ $v }}: {{ $l }}</option> @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Inherent Impact (1-5)</label>
                            <select name="risks[0][inherent_impact]" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" required>
                                @foreach ([1=>'Insignificant',2=>'Minor',3=>'Moderate',4=>'Major',5=>'Catastrophic'] as $v => $l) <option value="{{ $v }}">{{ $v }}: {{ $l }}</option> @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Residual Likelihood (1-5)</label>
                            <select name="risks[0][residual_likelihood]" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" required>
                                @foreach ([1=>'Rare',2=>'Unlikely',3=>'Possible',4=>'Likely',5=>'Almost Certain'] as $v => $l) <option value="{{ $v }}">{{ $v }}: {{ $l }}</option> @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Residual Impact (1-5)</label>
                            <select name="risks[0][residual_impact]" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" required>
                                @foreach ([1=>'Insignificant',2=>'Minor',3=>'Moderate',4=>'Major',5=>'Catastrophic'] as $v => $l) <option value="{{ $v }}">{{ $v }}: {{ $l }}</option> @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="block text-xs font-medium text-gray-600 mb-1">Control Effectiveness</label>
                        <select name="risks[0][control_effectiveness]" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                            <option value="">Not assessed</option>
                            @foreach (['effective' => 'Effective', 'partially_effective' => 'Partially effective', 'ineffective' => 'Ineffective', 'not_tested' => 'Not tested'] as $v => $l)
                                <option value="{{ $v }}">{{ $l }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Existing Controls</label>
                            <textarea name="risks[0][existing_controls]" rows="2" placeholder="List existing controls..." class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"></textarea>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Action Plan</label>
                            <textarea name="risks[0][action_plan]" rows="2" placeholder="Recommended actions..." class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"></textarea>
                        </div>
                    </div>
                    <div class="mt-3 flex justify-end">
                        <button type="button" class="remove-risk text-xs text-red-500 hover:text-red-700 flex items-center gap-1"><span class="material-symbols-outlined text-sm">delete</span> Remove</button>
                    </div>
                </div>
            </div>

            <button type="button" id="addRisk" class="flex items-center gap-2 px-4 py-2 border border-dashed border-gray-300 rounded-lg text-sm text-gray-600 hover:border-[#1A365D] hover:text-[#1A365D] transition-colors">
                <span class="material-symbols-outlined text-lg">add</span> Add Another Risk
            </button>
        </div>

        <div class="flex items-center justify-between">
            <a href="{{ route('risk.rcsa.dashboard') }}" class="px-6 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</a>
            <div class="flex gap-2">
                <button type="submit" name="action" value="draft" class="px-6 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">Save Draft</button>
                <button type="submit" name="action" value="submit" class="px-6 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-2"><span class="material-symbols-outlined text-lg">send</span> Submit Assessment</button>
            </div>
        </div>
    </form>
@endsection

@push('scripts')
<script>
window.onPageReady(function() {
    let riskIndex = 1;
    document.getElementById('addRisk').addEventListener('click', function() {
        const container = document.getElementById('riskAssessments');
        const first = container.querySelector('.risk-assessment-row');
        const clone = first.cloneNode(true);
        clone.querySelectorAll('textarea, select').forEach(el => { el.name = el.name.replace(/\[\d+\]/, '[' + riskIndex + ']'); el.value = ''; });
        container.appendChild(clone);
        riskIndex++;
    });
    document.addEventListener('click', function(e) {
        if (e.target.closest('.remove-risk')) {
            const rows = document.querySelectorAll('.risk-assessment-row');
            if (rows.length > 1) e.target.closest('.risk-assessment-row').remove();
        }
    });
});
</script>
@endpush
