@extends('layouts.app')

@section('title', 'Risk-Control Matrix - GRC Risk Management')
@section('page-section', 'RCSA')
@section('page-title', 'Matrix')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.rcsa.dashboard') }}" class="hover:text-[#1A365D]">RCSA</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Risk-Control Matrix</span>
@endsection

@section('content')
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-[#1A365D]">Risk-Control Matrix</h1>
            <p class="text-sm text-gray-500 mt-1">Map risks to controls showing coverage and effectiveness</p>
        </div>
        <div class="flex gap-2">
            <select id="unitFilter" class="border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-700">
                <option value="">All Business Units</option>
                @foreach (($businessUnits ?? []) as $unit)
                    <option value="{{ $unit->id ?? $unit }}">{{ $unit->name ?? $unit }}</option>
                @endforeach
            </select>
            <a href="{{ route('risk.export.rcsa-matrix') }}" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2"><span class="material-symbols-outlined text-lg">download</span> Export</a>
        </div>
    </div>

    {{-- Matrix Legend --}}
    <div class="bg-white rounded-xl border border-gray-200 p-4 mb-4">
        <div class="flex items-center gap-6 text-xs text-gray-600">
            <span class="font-semibold">Legend:</span>
            <span class="flex items-center gap-1"><span class="w-4 h-4 rounded bg-green-500 inline-block"></span> Effective Control</span>
            <span class="flex items-center gap-1"><span class="w-4 h-4 rounded bg-yellow-500 inline-block"></span> Partially Effective</span>
            <span class="flex items-center gap-1"><span class="w-4 h-4 rounded bg-red-500 inline-block"></span> Ineffective / Gap</span>
            <span class="flex items-center gap-1"><span class="w-4 h-4 rounded bg-gray-200 inline-block"></span> Not Applicable</span>
        </div>
    </div>

    {{-- Matrix Table --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th class="sticky left-0 bg-white z-10 min-w-[200px]">Risk / Control</th>
                        @foreach (($matrixControls ?? []) as $control)
                            <th class="text-center min-w-[100px]">
                                <div class="text-[10px] leading-tight">{{ Str::limit($control->name ?? 'C' . $loop->iteration, 15) }}</div>
                            </th>
                        @endforeach
                        <th class="text-center">Coverage</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (($matrixRisks ?? []) as $risk)
                        <tr>
                            <td class="sticky left-0 bg-white z-10 font-medium text-[#1A365D]">
                                <div class="flex items-center gap-2">
                                    <x-risk-badge :rating="$risk->residual_rating ?? 'unrated'" />
                                    <span class="text-xs">{{ Str::limit($risk->title ?? '-', 30) }}</span>
                                </div>
                            </td>
                            @foreach (($matrixControls ?? []) as $control)
                                @php
                                    $mapping = collect($risk->control_mappings ?? [])->firstWhere('control_id', $control->id);
                                    $effectiveness = $mapping->effectiveness ?? 'na';
                                @endphp
                                <td class="text-center">
                                    <span class="inline-block w-6 h-6 rounded {{ $effectiveness === 'effective' ? 'bg-green-500' : ($effectiveness === 'partially' ? 'bg-yellow-500' : ($effectiveness === 'ineffective' ? 'bg-red-500' : 'bg-gray-200')) }}"
                                          title="{{ ucfirst($effectiveness) }}"></span>
                                </td>
                            @endforeach
                            <td class="text-center text-xs font-semibold {{ ($risk->control_coverage ?? 0) >= 80 ? 'text-green-600' : (($risk->control_coverage ?? 0) >= 50 ? 'text-yellow-600' : 'text-red-600') }}">
                                {{ $risk->control_coverage ?? 0 }}%
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($matrixControls ?? []) + 2 }}" class="text-center py-12"><span class="material-symbols-outlined text-4xl text-gray-300 mb-2 block">grid_on</span><p class="text-sm text-gray-500">No risk-control mappings available</p></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
