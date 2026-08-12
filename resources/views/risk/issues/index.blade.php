@extends('layouts.app')

@section('title', 'Issues Register - GRC Platform')

@section('breadcrumbs')
    <span>Risk Management</span>
    <span class="text-gray-300">/</span>
    <span>Issues & Findings</span>
    <span class="text-gray-300">/</span>
    <span class="text-[#1A365D] font-semibold">Issues Register</span>
@endsection

@section('content')
    {{-- Page Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">Issues Register</h1>
            <p class="text-sm text-gray-500 mt-1">Comprehensive register of all issues, findings, and audit observations</p>
        </div>
        <div class="flex items-center gap-3">
            @can('issue.create')
                <a href="{{ url('/risk/issues/create') }}" class="flex items-center gap-2 px-4 py-2 bg-[#1A365D] text-white rounded-lg text-xs font-semibold hover:bg-[#2D4A7A] transition">
                    <span class="material-symbols-outlined text-sm">add</span>
                    Log New Issue
                </a>
            @endcan
        </div>
    </div>

    {{-- Flash Messages --}}
    @if (session('success'))
        <div class="mb-4 px-4 py-3 bg-green-50 border border-green-200 text-green-700 rounded-lg text-sm flex items-center gap-2">
            <span class="material-symbols-outlined text-lg">check_circle</span>
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="mb-4 px-4 py-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm flex items-center gap-2">
            <span class="material-symbols-outlined text-lg">error</span>
            {{ session('error') }}
        </div>
    @endif

    {{-- WP-09: search, filters (status/priority/source/overdue/escalation/
         business unit), sorting, column chooser, saved views and export all
         live inside the shared grid — see App\Grids\Definitions\IssuesGrid. --}}
    <x-data-grid grid="issues" />
@endsection
