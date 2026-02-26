@extends('layouts.app')

@section('title', 'Control Effectiveness - GRC Risk Management')
@section('page-section', 'RCSA')
@section('page-title', 'Control Assessment')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.rcsa.dashboard') }}" class="hover:text-[#1A365D]">RCSA</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Control Effectiveness</span>
@endsection

@section('content')
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-[#1A365D]">Control Effectiveness Assessment</h1>
            <p class="text-sm text-gray-500 mt-1">Evaluate the design and operating effectiveness of controls</p>
        </div>
    </div>

    {{-- Summary --}}
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <x-kpi-card title="Total Controls Assessed" :value="$totalControls ?? 0" icon="verified_user" color="primary" />
        <x-kpi-card title="Effective" :value="$effectiveControls ?? 0" icon="check_circle" color="success" />
        <x-kpi-card title="Partially Effective" :value="$partialControls ?? 0" icon="warning" color="warning" />
        <x-kpi-card title="Ineffective" :value="$ineffectiveControls ?? 0" icon="cancel" color="danger" />
    </div>

    <x-data-table>
        <x-slot name="head">
            <th>Control</th>
            <th>Linked Risk</th>
            <th>Type</th>
            <th>Design Effectiveness</th>
            <th>Operating Effectiveness</th>
            <th>Overall Rating</th>
            <th>Issues Found</th>
            <th>Assessor</th>
            <th>Last Assessed</th>
        </x-slot>

        @forelse (($controls ?? []) as $control)
            <tr class="hover:bg-blue-50/50">
                <td class="font-medium text-[#1A365D]">{{ $control->name ?? '-' }}</td>
                <td class="text-xs">{{ $control->risk->risk_code ?? '-' }}</td>
                <td><span class="badge bg-gray-100 text-gray-700">{{ ucfirst($control->type ?? '-') }}</span></td>
                <td>
                    <span class="badge {{ ($control->design_effectiveness ?? '') === 'effective' ? 'bg-green-100 text-green-700' : (($control->design_effectiveness ?? '') === 'partially' ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700') }}">
                        {{ ucfirst($control->design_effectiveness ?? '-') }}
                    </span>
                </td>
                <td>
                    <span class="badge {{ ($control->operating_effectiveness ?? '') === 'effective' ? 'bg-green-100 text-green-700' : (($control->operating_effectiveness ?? '') === 'partially' ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700') }}">
                        {{ ucfirst($control->operating_effectiveness ?? '-') }}
                    </span>
                </td>
                <td>
                    <span class="badge {{ ($control->overall_rating ?? '') === 'effective' ? 'bg-green-100 text-green-700' : (($control->overall_rating ?? '') === 'partially' ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700') }}">
                        {{ ucfirst($control->overall_rating ?? '-') }}
                    </span>
                </td>
                <td class="text-xs {{ ($control->issues_count ?? 0) > 0 ? 'text-red-600 font-semibold' : 'text-gray-500' }}">{{ $control->issues_count ?? 0 }}</td>
                <td class="text-xs">{{ $control->assessor ?? '-' }}</td>
                <td class="text-xs text-gray-500">{{ $control->assessed_at?->format('d M Y') ?? '-' }}</td>
            </tr>
        @empty
            <tr><td colspan="9" class="text-center py-12"><span class="material-symbols-outlined text-4xl text-gray-300 mb-2 block">verified_user</span><p class="text-sm text-gray-500">No control assessments available</p></td></tr>
        @endforelse
    </x-data-table>
@endsection
