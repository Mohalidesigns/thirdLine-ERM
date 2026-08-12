@extends('layouts.app')

@section('title', 'Bow-Tie Analysis - GRC Risk Management')
@section('page-section', 'Analysis')
@section('page-title', 'Bow-Tie')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-500">Analysis</span>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Bow-Tie Analysis</span>
@endsection

@section('content')
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">Bow-Tie Risk Analysis</h1>
            <p class="text-sm text-gray-500 mt-1">Visualize causes, risk events, and consequences with preventive and mitigating controls</p>
        </div>
        <div class="flex items-center gap-3">
            <select id="riskSelector" class="border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-700" onchange="window.location.href='?risk_id='+this.value">
                <option value="">Select a Risk</option>
                @foreach (($risks ?? []) as $risk)
                    <option value="{{ $risk->id }}" {{ request('risk_id') == $risk->id ? 'selected' : '' }}>{{ $risk->risk_code }} - {{ Str::limit($risk->title, 50) }}</option>
                @endforeach
            </select>
        </div>
    </div>

    @if ($selectedRisk ?? null)
        {{-- Risk Header --}}
        <div class="bg-white rounded-xl border border-gray-200 p-4 mb-6">
            <div class="flex items-center gap-4">
                <div class="flex-1">
                    <div class="flex items-center gap-3">
                        <h2 class="text-lg font-semibold text-[#1A365D]">{{ $selectedRisk->risk_code }}: {{ $selectedRisk->title }}</h2>
                        <x-risk-badge :rating="$selectedRisk->residual_rating ?? 'medium'" />
                    </div>
                    <p class="text-xs text-gray-500 mt-1">{{ $selectedRisk->category->name ?? '' }} &middot; {{ $selectedRisk->businessUnit->name ?? '' }}</p>
                </div>
            </div>
        </div>

        {{-- Bow-Tie Visualization --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6 overflow-x-auto">
            <div class="min-w-[1000px] flex items-stretch gap-0">
                {{-- Causes (Left) --}}
                <div class="w-1/4 space-y-3">
                    <h4 class="text-xs font-bold text-red-700 uppercase tracking-wide text-center mb-4">Causes / Threats</h4>
                    @forelse (($causes ?? []) as $cause)
                        <div class="p-3 bg-red-50 border border-red-200 rounded-lg text-xs relative">
                            <p class="font-medium text-red-800">{{ $cause->description ?? '' }}</p>
                            @if (($cause->category ?? null) || ($cause->is_primary ?? false))
                                <p class="text-[11px] text-red-500 mt-1">
                                    {{ $cause->category ?? 'Unclassified' }}
                                    @if ($cause->is_primary ?? false) &middot; primary @endif
                                </p>
                            @endif
                            <div class="absolute right-0 top-1/2 -translate-y-1/2 w-4 h-0.5 bg-red-300"></div>
                        </div>
                    @empty
                        {{--
                            An honest empty state. This panel used to show three
                            invented causes for every risk in the register,
                            because the controller read two columns that did not
                            exist and fell back to hardcoded text.
                        --}}
                        <div class="p-4 bg-gray-50 border border-dashed border-gray-300 rounded-lg text-xs text-gray-500 text-center">
                            <p>No root causes recorded for this risk.</p>
                            @if ($selectedRisk ?? null)
                                <a href="{{ route('risk.assessments.create', ['risk_id' => $selectedRisk->id]) }}" class="text-[#1A365D] underline mt-1 inline-block">Capture them in an assessment</a>
                            @endif
                        </div>
                    @endforelse
                </div>

                {{-- Preventive Controls --}}
                <div class="w-[12%] flex flex-col items-center justify-center px-2">
                    <h4 class="text-[10px] font-bold text-blue-700 uppercase tracking-wide mb-3 text-center">Preventive Controls</h4>
                    @forelse (($preventiveControls ?? []) as $ctrl)
                        <div class="p-2 bg-blue-50 border border-blue-200 rounded text-[10px] text-blue-700 font-medium mb-2 w-full text-center">{{ $ctrl->name ?? '' }}</div>
                    @empty
                        <div class="text-[10px] text-gray-400">None</div>
                    @endforelse
                </div>

                {{-- Risk Event (Center) --}}
                <div class="w-[18%] flex items-center justify-center px-2">
                    <div class="w-full aspect-square max-w-[180px] rounded-full bg-gradient-to-br from-red-500 to-orange-500 flex items-center justify-center p-6 shadow-lg">
                        <div class="text-center">
                            <span class="material-symbols-outlined text-white text-3xl mb-1">warning</span>
                            <p class="text-white text-xs font-bold leading-tight">{{ Str::limit($selectedRisk->title, 40) }}</p>
                        </div>
                    </div>
                </div>

                {{-- Mitigating Controls --}}
                <div class="w-[12%] flex flex-col items-center justify-center px-2">
                    <h4 class="text-[10px] font-bold text-green-700 uppercase tracking-wide mb-3 text-center">Mitigating Controls</h4>
                    @forelse (($mitigatingControls ?? []) as $ctrl)
                        <div class="p-2 bg-green-50 border border-green-200 rounded text-[10px] text-green-700 font-medium mb-2 w-full text-center">{{ $ctrl->name ?? '' }}</div>
                    @empty
                        <div class="text-[10px] text-gray-400">None</div>
                    @endforelse
                </div>

                {{-- Consequences (Right) --}}
                <div class="w-1/4 space-y-3">
                    <h4 class="text-xs font-bold text-orange-700 uppercase tracking-wide text-center mb-4">Consequences</h4>
                    @forelse (($consequences ?? []) as $cons)
                        <div class="p-3 bg-orange-50 border border-orange-200 rounded-lg text-xs relative">
                            <div class="absolute left-0 top-1/2 -translate-y-1/2 w-4 h-0.5 bg-orange-300"></div>
                            <p class="font-medium text-orange-800 pl-2">{{ $cons->description ?? '' }}</p>
                            @if ($cons->financial_impact ?? null)
                                <p class="text-orange-600 mt-1">₦{{ number_format($cons->financial_impact) }}</p>
                            @endif
                        </div>
                    @empty
                        <div class="p-3 bg-gray-50 border border-dashed border-gray-300 rounded-lg text-xs text-gray-400 text-center">No consequences defined</div>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- Control Effectiveness Summary --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="bg-white rounded-xl border border-gray-200 p-5">
                <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Control Effectiveness Summary</h3>
                <canvas id="controlEffChart" height="200"></canvas>
            </div>
            <div class="bg-white rounded-xl border border-gray-200 p-5">
                <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Barrier Analysis</h3>
                <table class="data-table">
                    <thead><tr><th>Control</th><th>Type</th><th>Effectiveness</th><th>Gaps</th></tr></thead>
                    <tbody>
                        @foreach (array_merge($preventiveControls ?? [], $mitigatingControls ?? []) as $ctrl)
                            <tr>
                                <td class="text-xs font-medium">{{ $ctrl->name ?? '-' }}</td>
                                <td><span class="badge {{ ($ctrl->type ?? '') === 'preventive' ? 'bg-blue-100 text-blue-700' : 'bg-green-100 text-green-700' }}">{{ ucfirst($ctrl->type ?? '-') }}</span></td>
                                <td><span class="badge {{ ($ctrl->effectiveness ?? '') === 'effective' ? 'bg-green-100 text-green-700' : (($ctrl->effectiveness ?? '') === 'partially' ? 'bg-yellow-100 text-yellow-700' : (($ctrl->effectiveness ?? '') === 'unrated' ? 'bg-gray-100 text-gray-600' : 'bg-red-100 text-red-700')) }}">{{ ucfirst($ctrl->effectiveness ?? '-') }}</span></td>
                                <td class="text-xs">{{ $ctrl->gaps ?? 'None' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <div class="bg-white rounded-xl border border-gray-200 p-12 text-center">
            <span class="material-symbols-outlined text-4xl text-gray-300 mb-3 block">account_tree</span>
            <p class="text-sm text-gray-500">Select a risk above to view its bow-tie analysis.</p>
        </div>
    @endif
@endsection

@php
    $chartControlEffData = $controlEffData ?? ['labels' => ['Effective','Partially','Ineffective','Unrated'], 'values' => [0,0,0,0]];
@endphp

@push('scripts')
<script>
window.onPageReady(function() {
    if (document.getElementById('controlEffChart')) {
        const effData = @json($chartControlEffData);
        new Chart(document.getElementById('controlEffChart'), {
            type: 'bar', data: { labels: effData.labels, datasets: [{ data: effData.values, backgroundColor: ['#2D7D46','#D4AF37','#C53030','#9CA3AF'], borderRadius: 4 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: { display: false } }, y: { beginAtZero: true, grid: { color: '#F0F0F0' }, ticks: { stepSize: 1 } } } }
        });
    }
});
</script>
@endpush
