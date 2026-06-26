@extends('layouts.app')

@section('title', 'Risk Contribution Analysis - GRC Risk Management')
@section('page-section', 'Quantification')
@section('page-title', 'Risk Contribution')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.quantification.reports') }}" class="hover:text-[#1A365D]">Reports</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Risk Contribution</span>
@endsection

@php
    $pct = fn ($v) => number_format((float) $v, 2) . '%';
    $num = fn ($v) => number_format((float) $v, 2);
@endphp

@section('content')
    <div class="flex items-center justify-between mb-6 print:hidden">
        <div>
            <h1 class="text-2xl font-bold text-[#1A365D]">Risk Contribution Analysis</h1>
            <p class="text-sm text-gray-500 mt-1">Breakdown of economic capital by risk type and business unit</p>
        </div>
        <div class="flex gap-2">
            <button onclick="window.print()" class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-2">
                <span class="material-symbols-outlined text-lg">download</span> Download PDF
            </button>
            <a href="{{ route('risk.quantification.reports') }}" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Back</a>
        </div>
    </div>

    @if (! $hasData)
        <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-4 mb-6 text-sm text-yellow-800">
            No active risks or simulations available yet.
        </div>
    @endif

    @if ($latestSim)
        <div class="mb-4 text-xs text-gray-500">
            Source: simulation
            <a href="{{ route('risk.quantification.show-results', $latestSim) }}" class="text-[#1A365D] font-semibold hover:underline">
                {{ $latestSim->simulation_code }}
            </a>
            &middot; {{ $latestSim->completed_at?->format('d M Y') }}
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- By Risk Type --}}
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100">
                <h3 class="text-sm font-semibold text-[#1A365D]">By Risk Type</h3>
            </div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Risk Type</th>
                        <th class="text-right">Capital / Score</th>
                        <th class="text-right">Share</th>
                        <th>Distribution</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($byType as $row)
                        <tr>
                            <td class="font-medium text-[#1A365D]">{{ $row->label }}</td>
                            <td class="text-right">{{ $num($row->capital) }}</td>
                            <td class="text-right">{{ $pct($row->share_pct) }}</td>
                            <td class="min-w-[160px]">
                                <div class="w-full bg-gray-100 rounded-full h-2 overflow-hidden">
                                    <div class="h-2 bg-[#1A365D]" style="width: {{ min(100, $row->share_pct) }}%"></div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center py-6 text-gray-400">No data available</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- By Business Unit --}}
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100">
                <h3 class="text-sm font-semibold text-[#1A365D]">By Business Unit</h3>
            </div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Business Unit</th>
                        <th class="text-right">Risks</th>
                        <th class="text-right">Capital / Score</th>
                        <th class="text-right">Share</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($byUnit as $row)
                        <tr>
                            <td class="font-medium text-[#1A365D]">{{ $row->label }}</td>
                            <td class="text-right">{{ (int) $row->risks }}</td>
                            <td class="text-right">{{ $num($row->capital) }}</td>
                            <td class="text-right">{{ $pct($row->share_pct) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center py-6 text-gray-400">No data available</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-6 bg-white rounded-xl border border-gray-200 p-5">
        <h3 class="text-sm font-semibold text-[#1A365D] mb-3">Notes</h3>
        <p class="text-sm text-gray-600 leading-relaxed">
            When a completed Monte Carlo simulation exists, contribution values are taken from its scenario contributions (expected loss &times; contribution percentage).
            Otherwise the fallback uses aggregated residual-risk scores on active risks in the register, weighted by category and business unit.
        </p>
    </div>
@endsection
