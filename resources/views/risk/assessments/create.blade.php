@extends('layouts.app')

@section('title', 'Create Assessment - GRC Risk Management')
@section('page-section', 'Assessments')
@section('page-title', 'New Assessment')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.assessments.index') }}" class="hover:text-[#1A365D]">Assessments</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">New Assessment</span>
@endsection

@section('content')
    @if ($errors->any())
        <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl">
            <div class="flex items-center gap-2 mb-2"><span class="material-symbols-outlined text-red-600">error</span><span class="text-sm font-semibold text-red-700">Please correct the following errors:</span></div>
            <ul class="list-disc list-inside text-sm text-red-600 space-y-1">@foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach</ul>
        </div>
    @endif

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-[#1A365D]">Create Risk Assessment</h1>
        <p class="text-sm text-gray-500 mt-1">Perform a multi-dimensional risk assessment</p>
    </div>

    <form method="POST" action="{{ route('risk.assessments.store') }}">
        @csrf

        {{-- Assessment Context --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6"><div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">1</div><h2 class="text-lg font-semibold text-[#1A365D]">Assessment Context</h2></div>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div>
                    <label for="risk_id" class="block text-sm font-medium text-gray-700 mb-2">Risk Being Assessed <span class="text-red-500">*</span></label>
                    <select id="risk_id" name="risk_id" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('risk_id') border-red-500 @enderror" required>
                        <option value="">Select Risk</option>
                        @foreach (($risks ?? []) as $risk) <option value="{{ $risk->id }}" {{ old('risk_id', request('risk_id')) == $risk->id ? 'selected' : '' }}>{{ $risk->risk_code }} - {{ Str::limit($risk->title, 50) }}</option> @endforeach
                    </select>
                    @error('risk_id')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="assessment_date" class="block text-sm font-medium text-gray-700 mb-2">Assessment Date <span class="text-red-500">*</span></label>
                    <input type="date" id="assessment_date" name="assessment_date" value="{{ old('assessment_date', now()->format('Y-m-d')) }}" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]" required>
                </div>
                <div>
                    <label for="assessment_type" class="block text-sm font-medium text-gray-700 mb-2">Assessment Type</label>
                    <select id="assessment_type" name="assessment_type" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                        <option value="periodic" {{ old('assessment_type') === 'periodic' ? 'selected' : '' }}>Periodic Review</option>
                        <option value="event_driven" {{ old('assessment_type') === 'event_driven' ? 'selected' : '' }}>Event-Driven</option>
                        <option value="initial" {{ old('assessment_type') === 'initial' ? 'selected' : '' }}>Initial Assessment</option>
                    </select>
                </div>
            </div>
        </div>

        {{-- Multi-Dimensional Scoring --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6"><div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">2</div><h2 class="text-lg font-semibold text-[#1A365D]">Multi-Dimensional Scoring (1-5)</h2></div>

            {{-- Likelihood --}}
            <div class="mb-8">
                <label class="block text-sm font-medium text-gray-700 mb-4">Likelihood <span class="text-red-500">*</span></label>
                <div class="flex flex-wrap gap-2">
                    @foreach ([1 => 'Rare', 2 => 'Unlikely', 3 => 'Possible', 4 => 'Likely', 5 => 'Almost Certain'] as $score => $label)
                        <label class="cursor-pointer">
                            <input type="radio" name="likelihood" value="{{ $score }}" class="sr-only peer" {{ old('likelihood') == $score ? 'checked' : '' }} required>
                            <div class="px-4 py-2 border-2 border-gray-300 rounded-lg text-sm font-medium peer-checked:border-[#1A365D] peer-checked:bg-[#F0F4F8] peer-checked:text-[#1A365D] hover:border-[#1A365D]/50 transition-colors">{{ $score }}: {{ $label }}</div>
                        </label>
                    @endforeach
                </div>
                @error('likelihood')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
            </div>

            {{-- Impact Dimensions --}}
            <h4 class="text-sm font-medium text-gray-700 mb-4">Impact Dimensions <span class="text-red-500">*</span></h4>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                @foreach ([
                    'impact_financial' => ['Financial Impact', 'Direct monetary loss or cost'],
                    'impact_operational' => ['Operational Impact', 'Process disruption or service degradation'],
                    'impact_reputational' => ['Reputational Impact', 'Brand damage, media coverage, customer trust'],
                    'impact_regulatory' => ['Regulatory Impact', 'CBN sanctions, penalties, license risk'],
                    'impact_strategic' => ['Strategic Impact', 'Effect on strategic objectives'],
                    'impact_people' => ['People Impact', 'Staff safety, morale, retention'],
                ] as $field => [$label, $hint])
                    <div>
                        <p class="text-sm font-semibold text-gray-700 mb-2">{{ $label }}</p>
                        <select name="{{ $field }}" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error($field) border-red-500 @enderror" required>
                            <option value="">Select Score</option>
                            @foreach ([1 => 'Insignificant', 2 => 'Minor', 3 => 'Moderate', 4 => 'Major', 5 => 'Catastrophic'] as $score => $impLabel)
                                <option value="{{ $score }}" {{ old($field) == $score ? 'selected' : '' }}>{{ $score }}: {{ $impLabel }}</option>
                            @endforeach
                        </select>
                        <p class="text-xs text-gray-500 mt-1">{{ $hint }}</p>
                        @error($field)<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                    </div>
                @endforeach
            </div>

            {{-- Calculated Score --}}
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                <p class="text-sm text-gray-600">Calculated Risk Score</p>
                <div class="flex items-baseline gap-2">
                    <span class="text-3xl font-bold text-[#1A365D]" id="calcScore">0</span>
                    <span class="text-sm text-gray-600">/25 (Likelihood x Max Impact)</span>
                </div>
            </div>
        </div>

        {{-- Assessment Notes --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6"><div class="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">3</div><h2 class="text-lg font-semibold text-[#1A365D]">Assessment Notes</h2></div>
            <div class="grid grid-cols-1 gap-6">
                <div>
                    <label for="rationale" class="block text-sm font-medium text-gray-700 mb-2">Assessment Rationale <span class="text-red-500">*</span></label>
                    <textarea id="rationale" name="rationale" rows="4" placeholder="Explain the reasoning behind the scoring..." class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D] @error('rationale') border-red-500 @enderror" required>{{ old('rationale') }}</textarea>
                    @error('rationale')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="recommendations" class="block text-sm font-medium text-gray-700 mb-2">Recommendations</label>
                    <textarea id="recommendations" name="recommendations" rows="3" placeholder="Recommended actions or changes..." class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">{{ old('recommendations') }}</textarea>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-between">
            <a href="{{ route('risk.assessments.index') }}" class="px-6 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</a>
            <div class="flex gap-2">
                <button type="submit" name="action" value="draft" class="px-6 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">Save Draft</button>
                <button type="submit" name="action" value="submit" class="px-6 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-2"><span class="material-symbols-outlined text-lg">send</span> Submit Assessment</button>
            </div>
        </div>
    </form>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    function calcScore() {
        const likelihood = document.querySelector('input[name="likelihood"]:checked');
        const impacts = ['impact_financial','impact_operational','impact_reputational','impact_regulatory','impact_strategic','impact_people'];
        let maxImpact = 0;
        impacts.forEach(f => { const s = document.querySelector('select[name="'+f+'"]'); if (s && parseInt(s.value) > maxImpact) maxImpact = parseInt(s.value); });
        const score = (likelihood ? parseInt(likelihood.value) : 0) * maxImpact;
        document.getElementById('calcScore').textContent = score;
    }
    document.querySelectorAll('input[name="likelihood"]').forEach(r => r.addEventListener('change', calcScore));
    ['impact_financial','impact_operational','impact_reputational','impact_regulatory','impact_strategic','impact_people'].forEach(f => { const s = document.querySelector('select[name="'+f+'"]'); if (s) s.addEventListener('change', calcScore); });
    calcScore();
});
</script>
@endpush
