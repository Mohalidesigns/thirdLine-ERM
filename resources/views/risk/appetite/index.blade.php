@extends('layouts.app')

@section('title', 'Risk Appetite Framework - GRC Risk Management')
@section('page-section', 'Risk Appetite')
@section('page-title', 'Appetite Framework')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Risk Appetite Framework</span>
@endsection

@section('content')
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">Risk Appetite Framework</h1>
            <p class="text-sm text-gray-500 mt-1">Board-approved appetite statements, tolerance bands, and current position monitoring</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('risk.export.appetite') }}" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2"><span class="material-symbols-outlined text-lg">download</span> Export</a>
            <button onclick="document.getElementById('versionHistorySection').classList.toggle('hidden')" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2"><span class="material-symbols-outlined text-lg">history</span> Version History</button>
            <button onclick="document.getElementById('addAppetiteSection').classList.toggle('hidden')" class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-2 transition-colors"><span class="material-symbols-outlined text-lg">add_circle</span> Add Appetite Statement</button>
        </div>
    </div>

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

    {{-- Add Appetite Statement (collapsible) --}}
    <div id="addAppetiteSection" class="{{ $errors->any() && old('risk_category_id') !== null ? '' : 'hidden' }} bg-white rounded-xl border border-gray-200 p-6 mb-6">
        <h2 class="text-sm font-bold text-[#1A365D] mb-4 flex items-center gap-2">
            <span class="material-symbols-outlined text-lg">add_circle</span>
            Add Appetite Statement
        </h2>
        <form method="POST" action="{{ route('risk.appetite.store') }}">
            @csrf
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Risk Category <span class="text-red-500">*</span></label>
                    <select name="risk_category_id" required class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 focus:ring-1 focus:ring-[#1A365D] focus:border-[#1A365D]">
                        <option value="">Select category...</option>
                        @foreach (($categoriesWithoutAppetite ?? []) as $cat)
                            <option value="{{ $cat->id }}" {{ old('risk_category_id') == $cat->id ? 'selected' : '' }}>{{ $cat->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Appetite Level <span class="text-red-500">*</span></label>
                    <select name="appetite_level" required class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 focus:ring-1 focus:ring-[#1A365D] focus:border-[#1A365D]">
                        <option value="">Select level...</option>
                        @foreach (['averse' => 'Averse', 'minimal' => 'Minimal', 'low' => 'Low', 'cautious' => 'Cautious', 'moderate' => 'Moderate', 'open' => 'Open', 'high' => 'High', 'hungry' => 'Hungry'] as $value => $label)
                            <option value="{{ $value }}" {{ old('appetite_level') === $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Tolerance Metric <span class="text-red-500">*</span></label>
                    <input type="text" name="tolerance_metric" value="{{ old('tolerance_metric') }}" required maxlength="255"
                        class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 focus:ring-1 focus:ring-[#1A365D] focus:border-[#1A365D]"
                        placeholder="e.g. NPL Ratio">
                </div>
                <div class="md:col-span-3">
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Appetite Statement <span class="text-red-500">*</span></label>
                    <textarea name="appetite_statement" rows="2" required maxlength="2000"
                        class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 focus:ring-1 focus:ring-[#1A365D] focus:border-[#1A365D]"
                        placeholder="The Board's stated appetite for this risk category">{{ old('appetite_statement') }}</textarea>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Target Min <span class="text-red-500">*</span></label>
                    <input type="number" name="target_min" value="{{ old('target_min', 0) }}" required min="0" step="0.01"
                        class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 focus:ring-1 focus:ring-[#1A365D] focus:border-[#1A365D]">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Target Max <span class="text-red-500">*</span></label>
                    <input type="number" name="target_max" value="{{ old('target_max') }}" required min="0" step="0.01"
                        class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 focus:ring-1 focus:ring-[#1A365D] focus:border-[#1A365D]">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Max Tolerance (hard limit) <span class="text-red-500">*</span></label>
                    <input type="number" name="max_tolerance" value="{{ old('max_tolerance') }}" required min="0" step="0.01"
                        class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 focus:ring-1 focus:ring-[#1A365D] focus:border-[#1A365D]">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Current Position</label>
                    <input type="number" name="current_position" value="{{ old('current_position') }}" min="0" step="0.01"
                        class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 focus:ring-1 focus:ring-[#1A365D] focus:border-[#1A365D]">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Unit of Measure</label>
                    <input type="text" name="unit_of_measure" value="{{ old('unit_of_measure', 'percentage') }}" maxlength="100"
                        class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 focus:ring-1 focus:ring-[#1A365D] focus:border-[#1A365D]">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Effective Date <span class="text-red-500">*</span></label>
                    <input type="date" name="effective_date" value="{{ old('effective_date', now()->toDateString()) }}" required
                        class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 focus:ring-1 focus:ring-[#1A365D] focus:border-[#1A365D]">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Expiry Date</label>
                    <input type="date" name="expiry_date" value="{{ old('expiry_date') }}"
                        class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 focus:ring-1 focus:ring-[#1A365D] focus:border-[#1A365D]">
                </div>
            </div>
            <div class="flex justify-end gap-2 mt-4">
                <button type="button" onclick="document.getElementById('addAppetiteSection').classList.add('hidden')"
                    class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-2 transition-colors">
                    <span class="material-symbols-outlined text-lg">save</span> Save Appetite Statement
                </button>
            </div>
        </form>
    </div>

    {{-- Version History (collapsible) --}}
    <div id="versionHistorySection" class="hidden bg-white rounded-xl border border-gray-200 overflow-hidden mb-6">
        <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-[#1A365D] flex items-center gap-2"><span class="material-symbols-outlined text-lg">history</span> Version History</h3>
            <button onclick="document.getElementById('versionHistorySection').classList.add('hidden')" class="text-gray-400 hover:text-gray-600"><span class="material-symbols-outlined text-lg">close</span></button>
        </div>
        <table class="data-table">
            <thead><tr><th>Created</th><th>Last Updated</th><th>Risk Category</th><th>Level</th><th>Metric</th><th>Tolerance Band</th><th>Effective</th><th>Expires</th><th>Approved</th></tr></thead>
            <tbody>
                @forelse (($appetites ?? collect())->sortByDesc('created_at') as $version)
                    <tr>
                        <td class="text-xs text-gray-500">{{ $version->created_at?->format('d M Y H:i') ?? '-' }}</td>
                        <td class="text-xs text-gray-500">{{ $version->updated_at?->format('d M Y H:i') ?? '-' }}</td>
                        <td class="text-xs font-medium text-[#1A365D]">{{ $version->category->name ?? 'Uncategorised' }}</td>
                        <td><span class="badge bg-blue-100 text-blue-700">{{ ucfirst($version->appetite_level ?? '-') }}</span></td>
                        <td class="text-xs">{{ $version->tolerance_metric ?? '-' }}</td>
                        <td class="text-xs">{{ number_format((float) ($version->target_min ?? 0), 1) }} - {{ number_format((float) ($version->max_tolerance ?? 0), 1) }} {{ $version->unit_of_measure === 'percentage' ? '%' : $version->unit_of_measure }}</td>
                        <td class="text-xs text-gray-500">{{ $version->effective_date?->format('d M Y') ?? '-' }}</td>
                        <td class="text-xs text-gray-500">{{ $version->expiry_date?->format('d M Y') ?? '-' }}</td>
                        <td class="text-xs text-gray-500">{{ $version->approved_date?->format('d M Y') ?? '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center py-8 text-gray-400">No appetite statements recorded yet</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Overall Appetite Status --}}
    <div class="bg-gradient-to-r from-[#1A365D] to-[#2D4A7A] rounded-xl p-6 text-white mb-6">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-lg font-bold">Overall Risk Appetite Status</h2>
                <p class="text-sm text-blue-200 mt-1">Board approved: {{ $approvalDate ?? now()->subMonths(3)->format('d M Y') }} &middot; Next review: {{ $nextReviewDate ?? now()->addMonths(3)->format('d M Y') }}</p>
            </div>
            <div class="text-right">
                <div class="text-3xl font-bold">{{ $overallStatus ?? 'Within Appetite' }}</div>
                <div class="flex items-center gap-2 mt-1 justify-end">
                    <span class="w-3 h-3 rounded-full {{ ($overallStatus ?? '') === 'Within Appetite' ? 'bg-green-400' : (($overallStatus ?? '') === 'Near Limit' ? 'bg-yellow-400' : 'bg-red-400') }}"></span>
                    <span class="text-sm">{{ $appetiteBreaches ?? 0 }} breaches active</span>
                </div>
            </div>
        </div>
    </div>

    {{-- KPI Cards --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <x-kpi-card title="Appetite Metrics" :value="$totalMetrics ?? 0" icon="speed" color="primary" />
        <x-kpi-card title="Within Tolerance" :value="$withinTolerance ?? 0" icon="check_circle" color="success" />
        <x-kpi-card title="Near Limit" :value="$nearLimit ?? 0" icon="warning" color="warning" />
        <x-kpi-card title="Breach" :value="$appetiteBreaches ?? 0" icon="error" color="danger" />
    </div>

    {{-- Appetite Visualization --}}
    <div class="bg-white rounded-xl border border-gray-200 p-5 mb-6">
        <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Appetite vs Current Position</h3>
        <canvas id="appetiteChart" height="150"></canvas>
    </div>

    {{-- Appetite Metrics Table --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100">
            <h3 class="text-sm font-semibold text-[#1A365D]">Risk Appetite Metrics</h3>
        </div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Risk Category</th>
                    <th>Appetite Statement</th>
                    <th>Metric</th>
                    <th>Tolerance Band</th>
                    <th>Current Position</th>
                    <th>Status</th>
                    <th>Trend</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse (($appetiteMetrics ?? []) as $metric)
                    <tr class="{{ ($metric->status ?? '') === 'breach' ? 'border-l-4 border-l-red-500 bg-red-50/50' : (($metric->status ?? '') === 'near_limit' ? 'border-l-4 border-l-yellow-500' : '') }}">
                        <td class="font-medium text-[#1A365D] text-xs">{{ $metric->risk_category ?? '-' }}</td>
                        <td class="text-xs text-gray-600 max-w-[200px]">{{ Str::limit($metric->appetite_statement ?? '-', 60) }}</td>
                        <td class="text-xs font-medium">{{ $metric->metric_name ?? '-' }}</td>
                        <td class="text-xs">
                            <div class="flex items-center gap-1">
                                <span class="text-green-600">{{ $metric->lower_limit ?? '-' }}</span>
                                <span class="text-gray-400">-</span>
                                <span class="text-red-600">{{ $metric->upper_limit ?? '-' }}</span>
                            </div>
                        </td>
                        <td class="text-xs font-bold {{ ($metric->status ?? '') === 'breach' ? 'text-red-600' : (($metric->status ?? '') === 'near_limit' ? 'text-yellow-600' : 'text-green-600') }}">
                            {{ $metric->current_value ?? '-' }}
                        </td>
                        <td>
                            <span class="flex items-center gap-1">
                                <span class="w-2.5 h-2.5 rounded-full {{ ($metric->status ?? '') === 'breach' ? 'bg-red-500' : (($metric->status ?? '') === 'near_limit' ? 'bg-yellow-500' : 'bg-green-500') }}"></span>
                                <span class="text-xs font-medium">{{ ucfirst(str_replace('_', ' ', $metric->status ?? 'within')) }}</span>
                            </span>
                        </td>
                        <td>
                            @if (($metric->trend ?? null) === 'up') <span class="material-symbols-outlined text-sm text-red-500">trending_up</span>
                            @elseif (($metric->trend ?? null) === 'down') <span class="material-symbols-outlined text-sm text-green-500">trending_down</span>
                            @else <span class="material-symbols-outlined text-sm text-gray-400">trending_flat</span> @endif
                        </td>
                        <td>
                            <div class="flex items-center gap-2">
                                @if (isset($metric->id))
                                    <button onclick="document.getElementById('edit-appetite-{{ $metric->id }}').classList.toggle('hidden')"
                                        class="p-1 hover:bg-gray-100 rounded" title="Edit appetite statement">
                                        <span class="material-symbols-outlined text-gray-400 text-lg">edit</span>
                                    </button>
                                @endif
                                @if (($metric->status ?? '') === 'breach')
                                    <span class="badge bg-red-100 text-red-700">Action Required</span>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @if (isset($metric->id, $metric->appetite))
                    <tr id="edit-appetite-{{ $metric->id }}" class="hidden">
                        <td colspan="8" class="bg-blue-50/40 p-4">
                            <form method="POST" action="{{ route('risk.appetite.update', $metric->id) }}">
                                @csrf
                                @method('PUT')
                                <p class="text-xs font-bold text-[#1A365D] mb-3">Edit appetite statement — {{ $metric->risk_category }}</p>
                                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">Appetite Level <span class="text-red-500">*</span></label>
                                        <select name="appetite_level" required class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2">
                                            @foreach (['averse' => 'Averse', 'minimal' => 'Minimal', 'low' => 'Low', 'cautious' => 'Cautious', 'moderate' => 'Moderate', 'open' => 'Open', 'high' => 'High', 'hungry' => 'Hungry'] as $value => $label)
                                                <option value="{{ $value }}" {{ ($metric->appetite->appetite_level ?? '') === $value ? 'selected' : '' }}>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">Tolerance Metric <span class="text-red-500">*</span></label>
                                        <input type="text" name="tolerance_metric" value="{{ $metric->appetite->tolerance_metric }}" required maxlength="255"
                                            class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">Unit of Measure</label>
                                        <input type="text" name="unit_of_measure" value="{{ $metric->appetite->unit_of_measure }}" maxlength="100"
                                            class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">Current Position</label>
                                        <input type="number" name="current_position" value="{{ $metric->appetite->current_position }}" min="0" step="0.01"
                                            class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2">
                                    </div>
                                    <div class="col-span-2 md:col-span-4">
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">Appetite Statement <span class="text-red-500">*</span></label>
                                        <textarea name="appetite_statement" rows="2" required maxlength="2000"
                                            class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2">{{ $metric->appetite->appetite_statement }}</textarea>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">Target Min <span class="text-red-500">*</span></label>
                                        <input type="number" name="target_min" value="{{ (float) $metric->appetite->target_min }}" required min="0" step="0.01"
                                            class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">Target Max <span class="text-red-500">*</span></label>
                                        <input type="number" name="target_max" value="{{ (float) $metric->appetite->target_max }}" required min="0" step="0.01"
                                            class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">Max Tolerance <span class="text-red-500">*</span></label>
                                        <input type="number" name="max_tolerance" value="{{ (float) $metric->appetite->max_tolerance }}" required min="0" step="0.01"
                                            class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">Effective Date <span class="text-red-500">*</span></label>
                                        <input type="date" name="effective_date" value="{{ $metric->appetite->effective_date?->toDateString() }}" required
                                            class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">Expiry Date</label>
                                        <input type="date" name="expiry_date" value="{{ $metric->appetite->expiry_date?->toDateString() }}"
                                            class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2">
                                    </div>
                                </div>
                                <div class="flex justify-end gap-2 mt-3">
                                    <button type="button" onclick="document.getElementById('edit-appetite-{{ $metric->id }}').classList.add('hidden')"
                                        class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Cancel</button>
                                    <button type="submit" class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-2 transition-colors">
                                        <span class="material-symbols-outlined text-lg">save</span> Update
                                    </button>
                                </div>
                            </form>
                        </td>
                    </tr>
                    @endif
                @empty
                    <tr><td colspan="8" class="text-center py-12"><span class="material-symbols-outlined text-4xl text-gray-300 mb-2 block">speed</span><p class="text-sm text-gray-500">No appetite metrics configured</p></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection

@php
    $appetiteChartDefaults = $appetiteChartData ?? ['labels' => [], 'appetite' => [], 'current' => [], 'limit' => []];
@endphp

@push('scripts')
<script>
window.onPageReady(function() {
    const appData = @json($appetiteChartDefaults);
    new Chart(document.getElementById('appetiteChart'), {
        type: 'bar',
        data: { labels: appData.labels, datasets: [
            { label: 'Appetite Limit', data: appData.limit, backgroundColor: 'rgba(197,48,48,0.15)', borderColor: '#C53030', borderWidth: 2, borderDash: [5,5], type: 'line', fill: false, pointRadius: 0 },
            { label: 'Current Position', data: appData.current, backgroundColor: appData.current.map((v,i) => v > (appData.limit[i] || 999) ? '#C53030' : (v > (appData.appetite[i] || 0) * 0.8 ? '#D4AF37' : '#2D7D46')), borderRadius: 4 }
        ] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { font: { size: 10 }, usePointStyle: true } } }, scales: { x: { grid: { display: false }, ticks: { font: { size: 10 } } }, y: { beginAtZero: true, grid: { color: '#F0F0F0' }, ticks: { font: { size: 10 } } } } }
    });
});
</script>
@endpush
