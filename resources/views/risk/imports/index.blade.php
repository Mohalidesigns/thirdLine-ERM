@extends('layouts.app')
@section('title', 'Data Imports')
@section('breadcrumbs')
    <a href="/risk/dashboard" class="hover:text-primary">Dashboard</a><span class="material-symbols-outlined text-[14px]">chevron_right</span><span class="text-gray-700 font-medium">Data Imports</span>
@endsection
@section('content')
<div class="space-y-6">
    @if (session('success'))
        <div class="p-4 bg-green-50 border border-green-200 rounded-xl flex items-center gap-3">
            <span class="material-symbols-outlined text-green-600">check_circle</span>
            <span class="text-sm text-green-700">{{ session('success') }}</span>
        </div>
    @endif

    @if (session('error'))
        <div class="p-4 bg-red-50 border border-red-200 rounded-xl flex items-center gap-3">
            <span class="material-symbols-outlined text-red-600">error</span>
            <span class="text-sm text-red-700">{{ session('error') }}</span>
        </div>
    @endif

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-gray-900">Data Import History</h1>
            <p class="text-sm text-gray-500 mt-1">{{ $total }} {{ Str::plural('import', $total) }} recorded</p>
        </div>
        @can('import.create')
            <a href="{{ route('risk.imports.create') }}" wire:navigate class="px-4 py-2 bg-primary text-white rounded-lg text-sm font-medium">New Import</a>
        @endcan
    </div>

    {{-- WP-09: shared grid — see App\Grids\Definitions\DataImportsGrid. --}}
    <x-data-grid grid="imports" />
</div>
@endsection
