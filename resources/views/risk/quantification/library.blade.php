@extends('layouts.app')

@section('title', 'Scenario Library - GRC Risk Management')
@section('page-section', 'Quantification')
@section('page-title', 'Scenario Library')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.quantification.dashboard') }}" class="hover:text-[#1A365D]">Quantification</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Scenario Library</span>
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
            <h1 class="text-2xl font-bold text-[#1A365D]">Nigerian Risk Scenario Library</h1>
            <p class="text-sm text-gray-500 mt-1">Pre-built loss scenarios calibrated for the Nigerian banking sector</p>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        @forelse (($libraryScenarios ?? []) as $scenario)
            <div class="bg-white rounded-xl border border-gray-200 p-5 hover:shadow-lg transition-shadow">
                <div class="flex items-center justify-between mb-3">
                    <span class="badge bg-blue-100 text-blue-700">{{ $scenario->risk_category ?? '-' }}</span>
                    <span class="badge bg-gray-100 text-gray-600">{{ ucfirst($scenario->distribution_type ?? '-') }}</span>
                </div>
                <h3 class="text-sm font-semibold text-[#1A365D] mb-2">{{ $scenario->name }}</h3>
                <p class="text-xs text-gray-500 mb-4 line-clamp-2">{{ $scenario->description ?? '' }}</p>
                <div class="grid grid-cols-2 gap-2 text-xs mb-4">
                    <div class="bg-gray-50 p-2 rounded"><p class="text-gray-500">Mean Loss</p><p class="font-semibold">₦{{ number_format($scenario->mean ?? 0) }}</p></div>
                    <div class="bg-gray-50 p-2 rounded"><p class="text-gray-500">Std Dev</p><p class="font-semibold">₦{{ number_format($scenario->std_dev ?? 0) }}</p></div>
                    <div class="bg-gray-50 p-2 rounded"><p class="text-gray-500">Frequency</p><p class="font-semibold">{{ $scenario->frequency_per_year ?? 0 }}/year</p></div>
                    <div class="bg-gray-50 p-2 rounded"><p class="text-gray-500">Source</p><p class="font-semibold">{{ $scenario->source ?? 'CBN Data' }}</p></div>
                </div>
                <form method="POST" action="{{ route('risk.quantification.library.import', $scenario->id) }}">
                    @csrf
                    <button type="submit" class="w-full px-4 py-2 bg-[#1A365D] text-white rounded-lg text-xs font-semibold hover:bg-[#2D4A7A] flex items-center justify-center gap-2">
                        <span class="material-symbols-outlined text-sm">add_circle</span> Import Scenario
                    </button>
                </form>
            </div>
        @empty
            <div class="col-span-full text-center py-12">
                <span class="material-symbols-outlined text-4xl text-gray-300 mb-3 block">library_books</span>
                <p class="text-sm text-gray-500">No pre-built scenarios available in the library.</p>
            </div>
        @endforelse
    </div>
@endsection
