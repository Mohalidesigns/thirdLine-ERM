@extends('layouts.app')

@section('title', 'Control Library - GRC Risk Management')
@section('page-section', 'Controls')
@section('page-title', 'Control Library')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" wire:navigate class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Control Library</span>
@endsection

@section('content')
    @if (session('success'))
        <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-xl flex items-center gap-3">
            <span class="material-symbols-outlined text-green-600">check_circle</span>
            <span class="text-sm text-green-700">{{ session('success') }}</span>
            <button onclick="this.parentElement.remove()" class="ml-auto text-green-400 hover:text-green-600"><span class="material-symbols-outlined text-lg">close</span></button>
        </div>
    @endif

    @if (session('error'))
        <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-xl flex items-center gap-3">
            <span class="material-symbols-outlined text-red-600">error</span>
            <span class="text-sm text-red-700">{{ session('error') }}</span>
            <button onclick="this.parentElement.remove()" class="ml-auto text-red-400 hover:text-red-600"><span class="material-symbols-outlined text-lg">close</span></button>
        </div>
    @endif

    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-[#1A365D]">Control Library</h1>
            <p class="text-sm text-gray-500 mt-1">{{ $total }} controls registered &middot; Manage organizational risk controls</p>
        </div>
        <div class="flex gap-2">
            @can('control.create')
                <a href="{{ route('risk.controls.create') }}" wire:navigate class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-2"><span class="material-symbols-outlined text-lg">add_circle</span> New Control</a>
            @endcan
        </div>
    </div>

    {{-- WP-09: search, filters, sorting, column chooser, saved views,
         selection, bulk actions, inline edit and export all live inside the
         shared grid — see App\Grids\Definitions\ControlsGrid. --}}
    <x-data-grid grid="controls" />
@endsection
