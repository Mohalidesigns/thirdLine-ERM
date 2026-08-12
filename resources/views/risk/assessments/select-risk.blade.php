@extends('layouts.app')

@section('title', 'New Assessment - GRC Risk Management')
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
    {{--
        Step 1 of the chain. The rest of the form is drawn FROM the risk — its
        causes, its controls, its KRIs — so the risk is chosen first rather than
        being one select among twenty on a form that cannot populate itself.
    --}}
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-[#1A365D]">Which risk are you assessing?</h1>
        <p class="text-sm text-gray-500 mt-1">
            The assessment then walks the full chain: root cause, likelihood, impact, inherent risk,
            existing controls, control effectiveness, residual risk, treatment, action plan and KRI.
        </p>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 p-6" x-data="{ search: '' }">
        <div class="relative mb-5">
            <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">search</span>
            <input type="text" x-model="search" placeholder="Search by code, title or category…"
                   class="w-full pl-11 pr-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
        </div>

        <div class="divide-y divide-gray-100 max-h-[32rem] overflow-y-auto">
            @forelse ($risks as $risk)
                <a href="{{ route('risk.assessments.create', ['risk_id' => $risk->id]) }}"
                   x-show="search === '' || $el.dataset.haystack.includes(search.toLowerCase())"
                   data-haystack="{{ Str::lower($risk->risk_code.' '.$risk->title.' '.($risk->category->name ?? '')) }}"
                   class="flex items-center gap-4 py-3 px-2 -mx-2 rounded-lg hover:bg-gray-50 group">
                    <span class="font-mono text-xs text-gray-500 w-20 shrink-0">{{ $risk->risk_code }}</span>
                    <span class="flex-1 min-w-0">
                        <span class="block text-sm font-medium text-gray-900 truncate">{{ $risk->title }}</span>
                        <span class="block text-xs text-gray-500">
                            {{ $risk->category->name ?? 'Uncategorised' }}
                            @if ($risk->last_assessment_date)
                                &middot; last assessed {{ \Illuminate\Support\Carbon::parse($risk->last_assessment_date)->format('d M Y') }}
                            @else
                                &middot; <span class="text-amber-600">never assessed</span>
                            @endif
                        </span>
                    </span>
                    @if ($risk->inherent_rating)
                        <x-risk-badge :rating="$risk->inherent_rating" />
                    @endif
                    <span class="material-symbols-outlined text-gray-300 group-hover:text-[#1A365D]">chevron_right</span>
                </a>
            @empty
                <p class="py-8 text-center text-sm text-gray-500">
                    No active risks yet. <a href="{{ route('risk.register.create') }}" class="text-[#1A365D] underline">Register one first</a>.
                </p>
            @endforelse
        </div>
    </div>
@endsection
