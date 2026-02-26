@extends('layouts.app')

@section('title', 'Treatment Plan Reviews - GRC Risk Management')
@section('page-section', 'Treatment Plans')
@section('page-title', 'Pending Review')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.treatments.index') }}" class="hover:text-[#1A365D]">Treatment Plans</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Pending Review</span>
@endsection

@section('content')
    @if (session('success'))
        <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-xl flex items-center gap-3">
            <span class="material-symbols-outlined text-green-600">check_circle</span>
            <span class="text-sm text-green-700">{{ session('success') }}</span>
            <button onclick="this.parentElement.remove()" class="ml-auto text-green-400 hover:text-green-600"><span class="material-symbols-outlined text-lg">close</span></button>
        </div>
    @endif

    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-[#1A365D]">Plans Pending Review</h1>
            <p class="text-sm text-gray-500 mt-1">{{ count($pendingPlans ?? []) }} plans awaiting your review and approval</p>
        </div>
    </div>

    @forelse (($pendingPlans ?? []) as $plan)
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-4">
            <div class="flex items-start justify-between">
                <div class="flex-1">
                    <div class="flex items-center gap-3 mb-2">
                        <a href="{{ route('risk.treatments.show', $plan) }}" class="text-lg font-semibold text-[#1A365D] hover:underline">{{ $plan->title }}</a>
                        <x-risk-badge :rating="$plan->priority ?? 'medium'" />
                        <span class="badge bg-blue-100 text-blue-700">{{ ucfirst($plan->strategy ?? '-') }}</span>
                    </div>
                    <p class="text-sm text-gray-600 mb-3">{{ Str::limit($plan->description, 200) }}</p>
                    <div class="flex flex-wrap gap-4 text-xs text-gray-500">
                        <span class="flex items-center gap-1"><span class="material-symbols-outlined text-sm">link</span> Risk: {{ $plan->risk->risk_code ?? 'N/A' }}</span>
                        <span class="flex items-center gap-1"><span class="material-symbols-outlined text-sm">person</span> Owner: {{ $plan->owner->name ?? '-' }}</span>
                        <span class="flex items-center gap-1"><span class="material-symbols-outlined text-sm">calendar_today</span> Target: {{ $plan->target_date?->format('d M Y') ?? '-' }}</span>
                        <span class="flex items-center gap-1"><span class="material-symbols-outlined text-sm">payments</span> Budget: ₦{{ number_format($plan->cost_estimate ?? 0) }}</span>
                    </div>

                    {{-- Progress Bar --}}
                    <div class="flex items-center gap-3 mt-3">
                        <div class="w-40 bg-gray-200 rounded-full h-2">
                            <div class="h-2 rounded-full {{ ($plan->progress ?? 0) >= 75 ? 'bg-green-500' : (($plan->progress ?? 0) >= 50 ? 'bg-yellow-500' : 'bg-red-500') }}"
                                 style="width: {{ $plan->progress ?? 0 }}%"></div>
                        </div>
                        <span class="text-xs text-gray-600">{{ $plan->progress ?? 0 }}% complete</span>
                    </div>
                </div>

                {{-- Review Actions --}}
                <div class="flex flex-col gap-2 ml-6">
                    <form method="POST" action="{{ route('risk.treatments.approve', $plan) }}">
                        @csrf
                        <button type="submit" class="w-full px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700 flex items-center gap-2 transition-colors">
                            <span class="material-symbols-outlined text-lg">check_circle</span> Approve
                        </button>
                    </form>
                    <form method="POST" action="{{ route('risk.treatments.reject', $plan) }}">
                        @csrf
                        <button type="submit" class="w-full px-4 py-2 bg-red-600 text-white rounded-lg text-sm font-medium hover:bg-red-700 flex items-center gap-2 transition-colors">
                            <span class="material-symbols-outlined text-lg">cancel</span> Reject
                        </button>
                    </form>
                    <button onclick="document.getElementById('comment-{{ $plan->id }}').classList.toggle('hidden')"
                            class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2 transition-colors">
                        <span class="material-symbols-outlined text-lg">comment</span> Comment
                    </button>
                </div>
            </div>

            {{-- Comment Section (hidden by default) --}}
            <div id="comment-{{ $plan->id }}" class="hidden mt-4 pt-4 border-t border-gray-100">
                <form method="POST" action="{{ route('risk.treatments.comment', $plan) }}">
                    @csrf
                    <textarea name="comment" rows="3" placeholder="Add your review comments..."
                              class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"></textarea>
                    <div class="flex justify-end mt-2">
                        <button type="submit" class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] transition-colors">Submit Comment</button>
                    </div>
                </form>
            </div>
        </div>
    @empty
        <div class="bg-white rounded-xl border border-gray-200 p-12 text-center">
            <span class="material-symbols-outlined text-4xl text-gray-300 mb-3 block">task_alt</span>
            <p class="text-gray-500">No treatment plans pending review.</p>
            <a href="{{ route('risk.treatments.index') }}" class="text-sm text-[#1A365D] font-medium hover:underline mt-2 inline-block">View All Plans</a>
        </div>
    @endforelse
@endsection
