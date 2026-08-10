@extends('layouts.app')

@section('title', 'Edit Emerging Risk - GRC Risk Management')
@section('page-section', 'Risk Intelligence')
@section('page-title', 'Edit Emerging Risk')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.emerging.index') }}" class="hover:text-[#1A365D]">Emerging Risk Register</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">{{ $entry->reference }}</span>
@endsection

@section('content')
    <div class="flex items-start justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">{{ $entry->reference }}</h1>
            <p class="text-sm text-gray-500 mt-1">
                Added {{ $entry->created_at?->format('d M Y') }}
                @if ($entry->creator) by {{ $entry->creator->name }} @endif
                · Radar position {{ $entry->radar_score }}/25
            </p>
        </div>
        <form method="POST" action="{{ route('risk.emerging.review', $entry) }}">
            @csrf
            <button type="submit"
                    class="px-4 py-2 border border-gray-300 rounded-lg text-xs font-semibold text-gray-700 hover:bg-gray-50 flex items-center gap-2">
                <span class="material-symbols-outlined text-sm">event_available</span> Mark reviewed today
            </button>
        </form>
    </div>

    <form method="POST" action="{{ route('risk.emerging.update', $entry) }}">
        @method('PUT')
        @include('risk.emerging._form')

        <div class="flex items-center justify-between">
            <a href="{{ route('risk.emerging.index') }}"
               class="px-6 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</a>
            <button type="submit"
                    class="px-6 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A]">
                Save changes
            </button>
        </div>
    </form>
@endsection
