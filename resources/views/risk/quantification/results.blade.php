@extends('layouts.app')

@section('title', 'Simulation Results - GRC Risk Management')
@section('page-section', 'Quantification')
@section('page-title', 'Results')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.quantification.dashboard') }}" class="hover:text-[#1A365D]">Quantification</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Simulation Results</span>
@endsection

@section('content')
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-[#1A365D]">Simulation Results</h1>
            <p class="text-sm text-gray-500 mt-1">All Monte Carlo simulation runs and their results</p>
        </div>
        <a href="{{ route('risk.quantification.simulate') }}" class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-2">
            <span class="material-symbols-outlined text-lg">play_arrow</span> New Simulation
        </a>
    </div>

    <x-data-table>
        <x-slot name="head">
            <th>Date</th>
            <th>Simulation Name</th>
            <th>Scenarios</th>
            <th>Iterations</th>
            <th>VaR (95%)</th>
            <th>VaR (99.5%)</th>
            <th>Expected Loss</th>
            <th>Status</th>
            <th>Actions</th>
        </x-slot>

        @forelse (($results ?? []) as $result)
            <tr class="hover:bg-blue-50/50">
                <td class="text-xs text-gray-500">{{ $result->created_at?->format('d M Y H:i') }}</td>
                <td class="font-medium text-[#1A365D]"><a href="{{ route('risk.quantification.show-results', $result) }}" class="hover:underline">{{ $result->name }}</a></td>
                <td class="text-xs">{{ $result->scenario_count ?? '-' }}</td>
                <td class="text-xs">{{ number_format($result->iterations ?? 0) }}</td>
                <td class="text-xs font-medium">₦{{ number_format($result->var_95 ?? 0) }}</td>
                <td class="text-xs font-medium">₦{{ number_format($result->var_995 ?? 0) }}</td>
                <td class="text-xs font-medium">₦{{ number_format($result->expected_loss ?? 0) }}</td>
                <td><x-status-badge :status="$result->status ?? 'completed'" /></td>
                <td>
                    <div class="flex items-center gap-1">
                        <a href="{{ route('risk.quantification.show-results', $result) }}" class="p-1 hover:bg-gray-100 rounded"><span class="material-symbols-outlined text-gray-400 text-lg">visibility</span></a>
                        <button onclick="window.print()" class="p-1 hover:bg-gray-100 rounded"><span class="material-symbols-outlined text-gray-400 text-lg">download</span></button>
                    </div>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="9" class="text-center py-12">
                    <span class="material-symbols-outlined text-4xl text-gray-300 mb-2 block">calculate</span>
                    <p class="text-sm text-gray-500">No simulation results yet.</p>
                    <a href="{{ route('risk.quantification.simulate') }}" class="mt-2 inline-block px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A]">Run First Simulation</a>
                </td>
            </tr>
        @endforelse
    </x-data-table>
@endsection
