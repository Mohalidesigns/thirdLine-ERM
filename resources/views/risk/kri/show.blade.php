@extends('layouts.app')

@section('title', ($kri->name ?? 'KRI Detail') . ' - GRC Risk Management')
@section('page-section', 'KRI Monitoring')
@section('page-title', $kri->name ?? 'KRI Detail')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.kri.index') }}" class="hover:text-[#1A365D]">KRI Monitoring</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">{{ Str::limit($kri->name ?? 'Detail', 40) }}</span>
@endsection

@section('content')
    @if (session('success'))
        <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-xl flex items-center gap-3">
            <span class="material-symbols-outlined text-green-600">check_circle</span>
            <span class="text-sm text-green-700">{{ session('success') }}</span>
            <button onclick="this.parentElement.remove()" class="ml-auto text-green-400 hover:text-green-600"><span class="material-symbols-outlined text-lg">close</span></button>
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-xl">
            <div class="flex items-center gap-2 text-red-700 text-sm font-semibold mb-2">
                <span class="material-symbols-outlined text-lg">error</span>
                Please correct the following errors:
            </div>
            <ul class="list-disc list-inside text-xs text-red-600 space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @php
        // Recompute status from the current value against the saved thresholds so
        // edits to thresholds (or the value itself) are immediately reflected,
        // without waiting for the next measurement to refresh `current_status`.
        $isHigherWorse = in_array($kri->threshold_direction ?? null, ['higher_worse', 'higher_is_worse'])
                      || ($kri->direction ?? '') === 'higher_is_worse';
        $cv = $kri->current_value;
        $kriStatus = $kri->current_status ?? 'green';
        if ($cv !== null) {
            $val = (float) $cv;
            if ($isHigherWorse) {
                if ($kri->red_threshold_min !== null && $val >= (float) $kri->red_threshold_min) {
                    $kriStatus = 'red';
                } elseif ($kri->amber_threshold_min !== null && $val >= (float) $kri->amber_threshold_min) {
                    $kriStatus = 'amber';
                } elseif ($kri->green_threshold_max !== null && $val <= (float) $kri->green_threshold_max) {
                    $kriStatus = 'green';
                }
            } else {
                if ($kri->red_threshold_max !== null && $val <= (float) $kri->red_threshold_max) {
                    $kriStatus = 'red';
                } elseif ($kri->amber_threshold_max !== null && $val <= (float) $kri->amber_threshold_max) {
                    $kriStatus = 'amber';
                } elseif ($kri->green_threshold_min !== null && $val >= (float) $kri->green_threshold_min) {
                    $kriStatus = 'green';
                }
            }
        }
    @endphp

    <div class="mb-6">
        <div class="flex items-center justify-between">
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-2xl font-bold text-[#1A365D]">{{ $kri->name }}</h1>
                    <span class="flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold
                        {{ $kriStatus === 'red' ? 'bg-red-100 text-red-700' : ($kriStatus === 'amber' ? 'bg-yellow-100 text-yellow-700' : 'bg-green-100 text-green-700') }}">
                        <span class="w-2 h-2 rounded-full {{ $kriStatus === 'red' ? 'bg-red-500' : ($kriStatus === 'amber' ? 'bg-yellow-500' : 'bg-green-500') }}"></span>
                        {{ ucfirst($kriStatus) }}
                    </span>
                </div>
                <p class="text-sm text-gray-500 mt-1">{{ $kri->description }}</p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('risk.kri.edit', $kri) }}" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2"><span class="material-symbols-outlined text-lg">edit</span> Edit</a>
                <a href="{{ route('risk.kri.index') }}" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50"><span class="material-symbols-outlined text-lg">arrow_back</span> Back</a>
            </div>
        </div>
    </div>

    {{-- KPI Cards --}}
    @php
        $currentVal = number_format((float) ($kri->current_value ?? 0), 2) . ' ' . ($kri->unit_of_measure ?? '');

        if ($isHigherWorse) {
            $greenDisplay = $kri->green_threshold_max !== null ? "\u{2264} " . number_format((float) $kri->green_threshold_max, 2) : '-';
            $amberDisplay = $kri->amber_threshold_max !== null ? "\u{2264} " . number_format((float) $kri->amber_threshold_max, 2) : '-';
            $redDisplay   = $kri->red_threshold_min !== null   ? "\u{2265} " . number_format((float) $kri->red_threshold_min, 2) : '-';
        } else {
            $greenDisplay = $kri->green_threshold_min !== null ? "\u{2265} " . number_format((float) $kri->green_threshold_min, 2) : '-';
            $amberDisplay = $kri->amber_threshold_max !== null ? "\u{2264} " . number_format((float) $kri->amber_threshold_max, 2) : '-';
            $redDisplay   = $kri->red_threshold_max !== null   ? "\u{2264} " . number_format((float) $kri->red_threshold_max, 2) : '-';
        }
    @endphp
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <x-kpi-card title="Current Value" :value="$currentVal" icon="speed"
            :color="$kriStatus === 'red' ? 'danger' : ($kriStatus === 'amber' ? 'warning' : 'success')" />
        <x-kpi-card title="Green Threshold" :value="$greenDisplay" icon="check_circle" color="success" />
        <x-kpi-card title="Amber Threshold" :value="$amberDisplay" icon="warning" color="warning" />
        <x-kpi-card title="Red Threshold" :value="$redDisplay" icon="error" color="danger" />
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        {{-- Measurement History Chart --}}
        <div class="lg:col-span-2 bg-white rounded-xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold text-[#1A365D]">Measurement History</h3>
                <select id="chartPeriod" class="text-xs border border-gray-200 rounded-lg px-2 py-1 bg-white">
                    <option value="6">Last 6 Months</option>
                    <option value="12" selected>Last 12 Months</option>
                    <option value="24">Last 24 Months</option>
                </select>
            </div>
            <canvas id="historyChart" height="250"></canvas>
        </div>

        {{-- KRI Info --}}
        <div class="space-y-6">
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <h3 class="text-sm font-semibold text-[#1A365D] mb-4">KRI Information</h3>
                <dl class="space-y-3">
                    <div class="flex justify-between"><dt class="text-xs text-gray-500">KRI Code</dt><dd class="text-xs font-medium">{{ $kri->kri_code ?? '-' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-xs text-gray-500">Frequency</dt><dd class="text-xs font-medium">{{ ucfirst($kri->measurement_frequency ?? '-') }}</dd></div>
                    <div class="flex justify-between"><dt class="text-xs text-gray-500">Unit</dt><dd class="text-xs font-medium">{{ $kri->unit_of_measure ?? '-' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-xs text-gray-500">Owner</dt><dd class="text-xs font-medium">{{ $kri->owner->name ?? '-' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-xs text-gray-500">Data Source</dt><dd class="text-xs font-medium">{{ $kri->data_source ?? '-' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-xs text-gray-500">Direction</dt><dd class="text-xs font-medium">{{ $kri->threshold_direction === 'higher_worse' ? 'Higher is Worse' : 'Lower is Worse' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-xs text-gray-500">Trend</dt><dd class="text-xs font-medium">{{ ucfirst($kri->trend_direction ?? '-') }}</dd></div>
                    <div class="flex justify-between"><dt class="text-xs text-gray-500">Last Updated</dt><dd class="text-xs">{{ $kri->last_measurement_at ? \Carbon\Carbon::parse($kri->last_measurement_at)->format('d M Y') : ($kri->updated_at?->format('d M Y') ?? '-') }}</dd></div>
                </dl>
            </div>

            {{-- Record Measurement --}}
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <h3 class="text-sm font-semibold text-[#1A365D] mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">add_chart</span>
                    Record Measurement
                </h3>
                <form method="POST" action="{{ route('risk.kri.record-measurement', $kri) }}" class="space-y-3">
                    @csrf
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Measurement Date <span class="text-red-500">*</span></label>
                        <input type="date" name="measurement_date" value="{{ old('measurement_date', now()->toDateString()) }}" required max="{{ now()->toDateString() }}"
                            class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 focus:ring-1 focus:ring-[#1A365D] focus:border-[#1A365D]">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Value ({{ $kri->unit_of_measure ?? 'value' }}) <span class="text-red-500">*</span></label>
                        <input type="number" name="value" value="{{ old('value') }}" required step="any"
                            class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 focus:ring-1 focus:ring-[#1A365D] focus:border-[#1A365D]"
                            placeholder="e.g. {{ number_format((float) ($kri->current_value ?? 0), 2) }}">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Notes</label>
                        <textarea name="notes" rows="2" maxlength="1000"
                            class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 focus:ring-1 focus:ring-[#1A365D] focus:border-[#1A365D]"
                            placeholder="Context for this reading (optional)">{{ old('notes') }}</textarea>
                    </div>
                    <button type="submit" class="w-full px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center justify-center gap-2 transition-colors">
                        <span class="material-symbols-outlined text-lg">save</span> Save Measurement
                    </button>
                </form>
            </div>

            @if ($kri->metric_formula)
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <h3 class="text-sm font-semibold text-[#1A365D] mb-3">Formula</h3>
                <div class="p-3 bg-gray-50 rounded-lg font-mono text-xs text-gray-700">{{ $kri->metric_formula }}</div>
            </div>
            @endif

            {{-- Linked Risk --}}
            <div class="bg-white rounded-xl border border-gray-200 p-6">
                <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Linked Risk</h3>
                @if ($kri->risk)
                    <a href="{{ route('risk.register.show', $kri->risk) }}" class="block p-3 bg-blue-50 rounded-lg hover:bg-blue-100 transition-colors mb-2">
                        <p class="text-xs font-semibold text-[#1A365D]">{{ $kri->risk->risk_code }}</p>
                        <p class="text-xs text-gray-600">{{ Str::limit($kri->risk->title, 40) }}</p>
                    </a>
                @else
                    <p class="text-xs text-gray-400">No linked risk</p>
                @endif
            </div>
        </div>
    </div>

    {{-- Measurement History Table --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-[#1A365D]">Measurement Log</h3>
        </div>
        <table class="data-table">
            <thead>
                <tr><th>Date</th><th>Value</th><th>Status</th><th>Entered By</th><th>Notes</th></tr>
            </thead>
            <tbody>
                @forelse (($measurements ?? []) as $m)
                    <tr>
                        <td class="text-xs">{{ $m->measurement_date?->format('d M Y') ?? '-' }}</td>
                        <td class="font-semibold">{{ $m->value }} {{ $kri->unit_of_measure ?? '' }}</td>
                        <td>
                            <span class="flex items-center gap-1">
                                <span class="w-2 h-2 rounded-full {{ $m->status === 'red' ? 'bg-red-500' : ($m->status === 'amber' ? 'bg-yellow-500' : 'bg-green-500') }}"></span>
                                <span class="text-xs">{{ ucfirst($m->status) }}</span>
                            </span>
                        </td>
                        <td class="text-xs">{{ $m->enteredBy->name ?? '-' }}</td>
                        <td class="text-xs text-gray-500">{{ Str::limit($m->notes ?? '', 50) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center py-8 text-gray-400"><span class="material-symbols-outlined text-3xl mb-2 block">analytics</span>No measurements recorded yet</td></tr>
                @endforelse
            </tbody>
        </table>
        @if (method_exists($measurements, 'links'))
            <div class="px-5 py-3 border-t border-gray-100">
                {{ $measurements->links() }}
            </div>
        @endif
    </div>
@endsection

@push('scripts')
@php
    $chartData = $historyData ?? ['labels' => [], 'values' => [], 'green' => 0, 'amber' => 0, 'red' => 0];
@endphp
<script>
document.addEventListener('DOMContentLoaded', function() {
    const histData = @json($chartData);
    if (!document.getElementById('historyChart')) return;
    new Chart(document.getElementById('historyChart'), {
        type: 'line',
        data: {
            labels: histData.labels,
            datasets: [
                {
                    label: 'KRI Value',
                    data: histData.values,
                    borderColor: '#1A365D',
                    backgroundColor: 'rgba(26,54,93,0.05)',
                    tension: 0.3,
                    fill: true,
                    pointRadius: 4,
                    pointBackgroundColor: histData.values.map(v => v >= histData.red ? '#C53030' : (v >= histData.amber ? '#D4AF37' : '#2D7D46')),
                },
                {
                    label: 'Green Threshold',
                    data: histData.labels.map(() => histData.green),
                    borderColor: '#2D7D46',
                    borderDash: [5, 5],
                    pointRadius: 0,
                    fill: false,
                },
                {
                    label: 'Amber Threshold',
                    data: histData.labels.map(() => histData.amber),
                    borderColor: '#D4AF37',
                    borderDash: [5, 5],
                    pointRadius: 0,
                    fill: false,
                },
                {
                    label: 'Red Threshold',
                    data: histData.labels.map(() => histData.red),
                    borderColor: '#C53030',
                    borderDash: [5, 5],
                    pointRadius: 0,
                    fill: false,
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom', labels: { font: { size: 10 }, usePointStyle: true } } },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 10 } } },
                y: { grid: { color: '#F0F0F0' }, ticks: { font: { size: 10 } } }
            }
        }
    });
});
</script>
@endpush
