@extends('layouts.app')

@section('title', 'Scenarios - GRC Risk Management')
@section('page-section', 'Quantification')
@section('page-title', 'Scenarios')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.quantification.dashboard') }}" class="hover:text-[#1A365D]">Quantification</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Scenarios</span>
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
            <h1 class="text-2xl font-bold text-[#1A365D]">Risk Scenarios</h1>
            <p class="text-sm text-gray-500 mt-1">Define loss distribution scenarios for Monte Carlo simulation</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('risk.quantification.create-scenario') }}" class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-2">
                <span class="material-symbols-outlined text-lg">add_circle</span> New Scenario
            </a>
            <a href="{{ route('risk.quantification.library') }}" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2">
                <span class="material-symbols-outlined text-lg">library_books</span> Scenario Library
            </a>
        </div>
    </div>

    <x-data-table id="scenariosTable">
        <x-slot name="head">
            <th>Scenario Name</th>
            <th>Risk Category</th>
            <th>Distribution</th>
            <th>Mean (₦)</th>
            <th>Std Dev (₦)</th>
            <th>Frequency</th>
            <th>Status</th>
            <th>Last Run</th>
            <th>Actions</th>
        </x-slot>

        @forelse (($scenarios ?? []) as $scenario)
            <tr class="hover:bg-blue-50/50">
                <td class="font-medium text-[#1A365D]">
                    <a href="{{ route('risk.quantification.show-scenario', $scenario) }}" class="hover:underline">{{ $scenario->name }}</a>
                </td>
                <td class="text-xs">{{ $scenario->risk_category ?? '-' }}</td>
                <td><span class="badge bg-blue-100 text-blue-700">{{ ucfirst($scenario->distribution_type ?? '-') }}</span></td>
                <td class="text-xs">₦{{ number_format($scenario->mean ?? 0) }}</td>
                <td class="text-xs">₦{{ number_format($scenario->std_dev ?? 0) }}</td>
                <td class="text-xs">{{ $scenario->frequency_per_year ?? '-' }}/year</td>
                <td><x-status-badge :status="$scenario->status ?? 'active'" /></td>
                <td class="text-xs text-gray-500">{{ $scenario->last_run_at?->format('d M Y') ?? 'Never' }}</td>
                <td>
                    <div class="flex items-center gap-1">
                        <a href="{{ route('risk.quantification.show-scenario', $scenario) }}" class="p-1 hover:bg-gray-100 rounded"><span class="material-symbols-outlined text-gray-400 text-lg">visibility</span></a>
                        <a href="{{ route('risk.quantification.edit-scenario', $scenario) }}" class="p-1 hover:bg-gray-100 rounded"><span class="material-symbols-outlined text-gray-400 text-lg">edit</span></a>
                    </div>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="9" class="text-center py-12">
                    <span class="material-symbols-outlined text-4xl text-gray-300 mb-2 block">category</span>
                    <p class="text-sm text-gray-500">No scenarios defined. <a href="{{ route('risk.quantification.create-scenario') }}" class="text-[#1A365D] hover:underline">Create your first scenario</a></p>
                </td>
            </tr>
        @endforelse
    </x-data-table>
@endsection
