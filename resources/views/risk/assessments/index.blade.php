@extends('layouts.app')

@section('title', 'Risk Assessments - GRC Risk Management')
@section('page-section', 'Assessments')
@section('page-title', 'Assessment List')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Risk Assessments</span>
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
            <h1 class="text-2xl font-bold text-[#1A365D]">Risk Assessments</h1>
            <p class="text-sm text-gray-500 mt-1">{{ $total }} assessments &middot; Multi-dimensional risk scoring and tracking</p>
        </div>
        <div class="flex gap-2">
            @can('assessment.create')
                <a href="{{ route('risk.assessments.create') }}" class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">add_circle</span> New Assessment
                </a>
            @endcan
        </div>
    </div>

    {{-- WP-09: search, filters, sorting, column chooser, saved views and
         export all live inside the shared grid — see
         App\Grids\Definitions\RiskAssessmentsGrid. --}}
    <x-data-grid grid="assessments" />
@endsection
