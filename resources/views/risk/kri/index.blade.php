@extends('layouts.app')

@section('title', 'KRI Library - GRC Risk Management')
@section('page-section', 'KRI Monitoring')
@section('page-title', 'KRI Library')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.kri.dashboard') }}" class="hover:text-[#1A365D]">KRI Monitoring</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">KRI Library</span>
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
            <h1 class="text-2xl font-bold text-[#1A365D]">Key Risk Indicators Library</h1>
            <p class="text-sm text-gray-500 mt-1">{{ $total }} indicators configured &middot; {{ $activeBreachCount }} active breaches</p>
        </div>
        <div class="flex gap-2">
            @can('kri.create')
                <a href="{{ route('risk.kri.create') }}" class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-2 transition-colors">
                    <span class="material-symbols-outlined text-lg">add_circle</span> New KRI
                </a>
            @endcan
            <a href="{{ route('risk.kri.thresholds') }}" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2 transition-colors">
                <span class="material-symbols-outlined text-lg">tune</span> Manage Thresholds
            </a>
        </div>
    </div>

    {{-- WP-09: search, filters, sorting, column chooser, saved views and
         export all live inside the shared grid — see
         App\Grids\Definitions\KrisGrid. --}}
    <x-data-grid grid="kris" />
@endsection
