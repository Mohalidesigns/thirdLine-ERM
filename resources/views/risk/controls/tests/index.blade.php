@extends('layouts.app')
@section('title', 'Control Tests')
@section('breadcrumbs')
    <a href="/risk/dashboard" class="hover:text-primary">Dashboard</a>
    <span class="material-symbols-outlined text-[14px]">chevron_right</span>
    <a href="{{ route('risk.control-tests.dashboard') }}" class="hover:text-primary">Control Testing</a>
    <span class="material-symbols-outlined text-[14px]">chevron_right</span>
    <span class="text-gray-700 font-medium">All Tests</span>
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
            <h1 class="text-xl font-bold text-gray-900">All Control Tests</h1>
            <p class="text-sm text-gray-500 mt-1">{{ $total }} {{ Str::plural('test', $total) }} scheduled or completed</p>
        </div>
        @can('control_test.create')
            <a href="{{ route('risk.control-tests.create') }}" wire:navigate class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-white rounded-lg text-sm font-medium hover:bg-opacity-90">
                <span class="material-symbols-outlined text-lg">add_circle</span> Schedule Test
            </a>
        @endcan
    </div>

    {{-- WP-09: search, filters, sorting, column chooser, saved views and
         export all live inside the shared grid — see
         App\Grids\Definitions\ControlTestsGrid. --}}
    <x-data-grid grid="control_tests" />
</div>
@endsection
