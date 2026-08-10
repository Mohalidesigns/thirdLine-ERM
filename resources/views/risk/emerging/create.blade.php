@extends('layouts.app')

@section('title', 'Add Emerging Risk - GRC Risk Management')
@section('page-section', 'Risk Intelligence')
@section('page-title', 'Add Emerging Risk')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.emerging.index') }}" class="hover:text-[#1A365D]">Emerging Risk Register</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Add</span>
@endsection

@section('content')
    <div class="mb-6">
        <h1 class="text-xl font-bold text-[#1A365D]">Add an emerging risk</h1>
        <p class="text-sm text-gray-500 mt-1">A reference is assigned automatically once you save.</p>
    </div>

    <form method="POST" action="{{ route('risk.emerging.store') }}">
        @include('risk.emerging._form')

        <div class="flex items-center justify-between">
            <a href="{{ route('risk.emerging.index') }}"
               class="px-6 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</a>
            <button type="submit"
                    class="px-6 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A]">
                Add to register
            </button>
        </div>
    </form>
@endsection
