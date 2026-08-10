@extends('layouts.app')

@section('title', 'Custom Report Builder - GRC Risk Management')
@section('page-section', 'Reports')
@section('page-title', 'Custom Report')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-500">Reports</span>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Custom Report Builder</span>
@endsection

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-[#1A365D]">Custom Report Builder</h1>
        <p class="text-sm text-gray-500 mt-1">Build custom risk reports with selected filters, metrics, and visualizations</p>
    </div>

    <form method="POST" action="{{ route('risk.reports.custom.generate') }}" id="reportForm">
        @csrf

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
            {{-- Report Configuration --}}
            <div class="lg:col-span-2 space-y-6">
                {{-- Basic Info --}}
                <div class="bg-white rounded-xl border border-gray-200 p-6">
                    <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Report Configuration</h3>
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        <div>
                            <label for="report_name" class="block text-sm font-medium text-gray-700 mb-2">Report Name <span class="text-red-500">*</span></label>
                            <input type="text" id="report_name" name="report_name" value="{{ old('report_name') }}" placeholder="e.g. Q4 Risk Summary" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]" required>
                        </div>
                        <div>
                            <label for="report_type" class="block text-sm font-medium text-gray-700 mb-2">Report Type</label>
                            <select id="report_type" name="report_type" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                                <option value="summary">Summary Report</option>
                                <option value="detailed">Detailed Report</option>
                                <option value="trend">Trend Analysis</option>
                                <option value="comparison">Period Comparison</option>
                            </select>
                        </div>
                        <div><label class="block text-sm font-medium text-gray-700 mb-2">Date From</label><input type="date" name="date_from" value="{{ old('date_from', now()->subMonths(3)->format('Y-m-d')) }}" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"></div>
                        <div><label class="block text-sm font-medium text-gray-700 mb-2">Date To</label><input type="date" name="date_to" value="{{ old('date_to', now()->format('Y-m-d')) }}" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"></div>
                    </div>
                </div>

                {{-- Filters --}}
                <div class="bg-white rounded-xl border border-gray-200 p-6">
                    <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Filters</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Risk Categories</label>
                            @foreach (($categories ?? collect()) as $cat)
                                <label class="flex items-center gap-2 py-1"><input type="checkbox" name="categories[]" value="{{ $cat->id }}" class="rounded border-gray-300 text-[#1A365D]" checked><span class="text-xs text-gray-700">{{ $cat->name }}</span></label>
                            @endforeach
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Risk Ratings</label>
                            @foreach (['Critical', 'High', 'Medium', 'Low'] as $rating)
                                <label class="flex items-center gap-2 py-1"><input type="checkbox" name="ratings[]" value="{{ strtolower($rating) }}" class="rounded border-gray-300 text-[#1A365D]" checked><span class="text-xs text-gray-700">{{ $rating }}</span></label>
                            @endforeach
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Business Units</label>
                            @foreach (($businessUnits ?? []) as $unit)
                                <label class="flex items-center gap-2 py-1"><input type="checkbox" name="business_units[]" value="{{ $unit->id ?? $unit }}" class="rounded border-gray-300 text-[#1A365D]" checked><span class="text-xs text-gray-700">{{ $unit->name ?? $unit }}</span></label>
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- Sections to Include --}}
                <div class="bg-white rounded-xl border border-gray-200 p-6">
                    <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Sections to Include</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                        @foreach ([
                            'risk_summary' => 'Risk Summary & KPIs',
                            'heatmap' => 'Risk Heatmap',
                            'top_risks' => 'Top Risks Table',
                            'kri_status' => 'KRI Status',
                            'treatment_progress' => 'Treatment Progress',
                            'loss_events' => 'Loss Events Summary',
                            'trend_charts' => 'Trend Charts',
                            'appetite_status' => 'Appetite Status',
                            'regulatory_compliance' => 'Regulatory Compliance',
                            'capital_adequacy' => 'Capital Adequacy',
                        ] as $key => $label)
                            <label class="flex items-center gap-2 p-3 bg-gray-50 rounded-lg hover:bg-blue-50 cursor-pointer">
                                <input type="checkbox" name="sections[]" value="{{ $key }}" class="rounded border-gray-300 text-[#1A365D]" {{ in_array($key, ['risk_summary', 'top_risks', 'heatmap', 'kri_status']) ? 'checked' : '' }}>
                                <span class="text-xs text-gray-700">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Sidebar --}}
            <div class="space-y-6">
                <div class="bg-white rounded-xl border border-gray-200 p-6">
                    <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Output Format</h3>
                    <div class="space-y-3">
                        {{-- Only the formats the renderer actually produces are
                             offered. This list previously included Web View and
                             PowerPoint, and every selection returned a CSV. --}}
                        @foreach (['pdf' => 'PDF document', 'xlsx' => 'Excel workbook (.xlsx)', 'csv' => 'CSV'] as $fmt => $label)
                            <label class="flex items-center gap-3 p-3 border border-gray-200 rounded-lg cursor-pointer hover:bg-blue-50">
                                <input type="radio" name="format" value="{{ $fmt }}" class="text-[#1A365D] focus:ring-[#1A365D]" {{ $fmt === 'pdf' ? 'checked' : '' }}>
                                <span class="text-sm text-gray-700">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="bg-white rounded-xl border border-gray-200 p-6">
                    <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Recent Reports</h3>
                    @forelse (($savedTemplates ?? []) as $template)
                        <a href="{{ $template->download_url ?? '#' }}"
                           class="block p-3 bg-gray-50 rounded-lg mb-2 hover:bg-blue-50 text-xs">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0 flex-1">
                                    <p class="font-medium text-gray-700 truncate">{{ $template->name }}</p>
                                    <p class="text-gray-500 truncate">{{ $template->description }}</p>
                                    <p class="text-[10px] text-gray-400 mt-0.5">{{ $template->created_at?->diffForHumans() }}</p>
                                </div>
                                <span class="material-symbols-outlined text-sm text-[#1A365D]">download</span>
                            </div>
                        </a>
                    @empty
                        <p class="text-xs text-gray-400 text-center py-4">No reports generated yet</p>
                    @endforelse
                </div>

                <button type="submit" class="w-full px-6 py-3 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center justify-center gap-2">
                    <span class="material-symbols-outlined text-lg">description</span> Generate Report
                </button>
            </div>
        </div>
    </form>
@endsection
