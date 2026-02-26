@extends('layouts.app')

@section('title', 'Loss Event Reports - GRC Platform')

@section('breadcrumbs')
    <span>Risk Management</span>
    <span class="text-gray-300">/</span>
    <span>Loss Events</span>
    <span class="text-gray-300">/</span>
    <span class="text-[#1A365D] font-semibold">Reports</span>
@endsection

@section('content')
    {{-- Page Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">Loss Event Reports</h1>
            <p class="text-sm text-gray-500 mt-1">Generate and download operational loss event reports for regulatory and management use</p>
        </div>
    </div>

    {{-- Flash Messages --}}
    @if (session('success'))
        <div class="mb-4 px-4 py-3 bg-green-50 border border-green-200 text-green-700 rounded-lg text-sm flex items-center gap-2">
            <span class="material-symbols-outlined text-lg">check_circle</span>
            {{ session('success') }}
        </div>
    @endif

    {{-- Report Types Grid --}}
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
        {{-- CBN ORMS Report --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5 hover:shadow-md transition">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-10 h-10 rounded-lg bg-red-50 flex items-center justify-center">
                    <span class="material-symbols-outlined text-red-500">account_balance</span>
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-[#1A365D]">CBN ORMS Report</h3>
                    <p class="text-[10px] text-gray-500">Quarterly loss data submission</p>
                </div>
            </div>
            <p class="text-xs text-gray-500 mb-4">Formatted operational risk loss data for CBN ORMS quarterly submission. Includes Basel classification and threshold reporting.</p>
            <form method="POST" action="{{ url('/risk/loss-events/reports/cbn-orms') }}">
                @csrf
                <div class="grid grid-cols-2 gap-3 mb-3">
                    <div>
                        <label class="block text-[10px] font-medium text-gray-500 mb-1">Quarter</label>
                        <select name="quarter" class="w-full text-xs border border-gray-200 rounded-lg px-2 py-1.5 focus:ring-1 focus:ring-[#1A365D]">
                            <option value="Q1">Q1 (Jan-Mar)</option>
                            <option value="Q2">Q2 (Apr-Jun)</option>
                            <option value="Q3">Q3 (Jul-Sep)</option>
                            <option value="Q4">Q4 (Oct-Dec)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-medium text-gray-500 mb-1">Year</label>
                        <select name="year" class="w-full text-xs border border-gray-200 rounded-lg px-2 py-1.5 focus:ring-1 focus:ring-[#1A365D]">
                            @for ($y = date('Y'); $y >= date('Y') - 3; $y--)
                                <option value="{{ $y }}">{{ $y }}</option>
                            @endfor
                        </select>
                    </div>
                </div>
                <button type="submit" class="w-full flex items-center justify-center gap-2 px-4 py-2 bg-[#1A365D] text-white text-xs font-semibold rounded-lg hover:bg-[#2D4A7A] transition">
                    <span class="material-symbols-outlined text-sm">download</span>
                    Generate Report
                </button>
            </form>
        </div>

        {{-- Basel Loss Data Report --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5 hover:shadow-md transition">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-10 h-10 rounded-lg bg-blue-50 flex items-center justify-center">
                    <span class="material-symbols-outlined text-blue-500">assessment</span>
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-[#1A365D]">Basel Loss Data</h3>
                    <p class="text-[10px] text-gray-500">Basel II/III compliant data</p>
                </div>
            </div>
            <p class="text-xs text-gray-500 mb-4">Comprehensive loss data report formatted per Basel II/III operational risk framework. Includes event types, business lines, and severity mapping.</p>
            <form method="POST" action="{{ url('/risk/loss-events/reports/basel') }}">
                @csrf
                <div class="grid grid-cols-2 gap-3 mb-3">
                    <div>
                        <label class="block text-[10px] font-medium text-gray-500 mb-1">From</label>
                        <input type="date" name="from_date" value="{{ date('Y-01-01') }}" class="w-full text-xs border border-gray-200 rounded-lg px-2 py-1.5 focus:ring-1 focus:ring-[#1A365D]">
                    </div>
                    <div>
                        <label class="block text-[10px] font-medium text-gray-500 mb-1">To</label>
                        <input type="date" name="to_date" value="{{ date('Y-m-d') }}" class="w-full text-xs border border-gray-200 rounded-lg px-2 py-1.5 focus:ring-1 focus:ring-[#1A365D]">
                    </div>
                </div>
                <button type="submit" class="w-full flex items-center justify-center gap-2 px-4 py-2 bg-[#1A365D] text-white text-xs font-semibold rounded-lg hover:bg-[#2D4A7A] transition">
                    <span class="material-symbols-outlined text-sm">download</span>
                    Generate Report
                </button>
            </form>
        </div>

        {{-- Management Summary --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5 hover:shadow-md transition">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-10 h-10 rounded-lg bg-green-50 flex items-center justify-center">
                    <span class="material-symbols-outlined text-green-500">summarize</span>
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-[#1A365D]">Management Summary</h3>
                    <p class="text-[10px] text-gray-500">Executive loss summary</p>
                </div>
            </div>
            <p class="text-xs text-gray-500 mb-4">Executive-level summary of operational loss events with key metrics, trends, and risk indicators for management review.</p>
            <form method="POST" action="{{ url('/risk/loss-events/reports/management') }}">
                @csrf
                <div class="mb-3">
                    <label class="block text-[10px] font-medium text-gray-500 mb-1">Period</label>
                    <select name="period" class="w-full text-xs border border-gray-200 rounded-lg px-2 py-1.5 focus:ring-1 focus:ring-[#1A365D]">
                        <option value="monthly">Monthly</option>
                        <option value="quarterly">Quarterly</option>
                        <option value="annual">Annual</option>
                        <option value="ytd">Year to Date</option>
                    </select>
                </div>
                <button type="submit" class="w-full flex items-center justify-center gap-2 px-4 py-2 bg-[#1A365D] text-white text-xs font-semibold rounded-lg hover:bg-[#2D4A7A] transition">
                    <span class="material-symbols-outlined text-sm">download</span>
                    Generate Report
                </button>
            </form>
        </div>

        {{-- NFIU STR Report --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5 hover:shadow-md transition">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-10 h-10 rounded-lg bg-yellow-50 flex items-center justify-center">
                    <span class="material-symbols-outlined text-yellow-600">policy</span>
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-[#1A365D]">NFIU STR Report</h3>
                    <p class="text-[10px] text-gray-500">Suspicious transaction reports</p>
                </div>
            </div>
            <p class="text-xs text-gray-500 mb-4">Generate NFIU-compliant suspicious transaction reports for loss events flagged for regulatory review.</p>
            <form method="POST" action="{{ url('/risk/loss-events/reports/nfiu') }}">
                @csrf
                <div class="grid grid-cols-2 gap-3 mb-3">
                    <div>
                        <label class="block text-[10px] font-medium text-gray-500 mb-1">From</label>
                        <input type="date" name="from_date" class="w-full text-xs border border-gray-200 rounded-lg px-2 py-1.5 focus:ring-1 focus:ring-[#1A365D]">
                    </div>
                    <div>
                        <label class="block text-[10px] font-medium text-gray-500 mb-1">To</label>
                        <input type="date" name="to_date" class="w-full text-xs border border-gray-200 rounded-lg px-2 py-1.5 focus:ring-1 focus:ring-[#1A365D]">
                    </div>
                </div>
                <button type="submit" class="w-full flex items-center justify-center gap-2 px-4 py-2 bg-[#1A365D] text-white text-xs font-semibold rounded-lg hover:bg-[#2D4A7A] transition">
                    <span class="material-symbols-outlined text-sm">download</span>
                    Generate Report
                </button>
            </form>
        </div>

        {{-- Trend Analysis --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5 hover:shadow-md transition">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-10 h-10 rounded-lg bg-purple-50 flex items-center justify-center">
                    <span class="material-symbols-outlined text-purple-500">trending_up</span>
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-[#1A365D]">Trend Analysis</h3>
                    <p class="text-[10px] text-gray-500">Loss trend patterns</p>
                </div>
            </div>
            <p class="text-xs text-gray-500 mb-4">Trend analysis of operational losses over time, broken down by event type, severity, and business unit.</p>
            <form method="POST" action="{{ url('/risk/loss-events/reports/trends') }}">
                @csrf
                <div class="mb-3">
                    <label class="block text-[10px] font-medium text-gray-500 mb-1">Time Range</label>
                    <select name="range" class="w-full text-xs border border-gray-200 rounded-lg px-2 py-1.5 focus:ring-1 focus:ring-[#1A365D]">
                        <option value="6m">Last 6 Months</option>
                        <option value="12m">Last 12 Months</option>
                        <option value="24m">Last 24 Months</option>
                        <option value="36m">Last 36 Months</option>
                    </select>
                </div>
                <button type="submit" class="w-full flex items-center justify-center gap-2 px-4 py-2 bg-[#1A365D] text-white text-xs font-semibold rounded-lg hover:bg-[#2D4A7A] transition">
                    <span class="material-symbols-outlined text-sm">download</span>
                    Generate Report
                </button>
            </form>
        </div>

        {{-- Full Register Export --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5 hover:shadow-md transition">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-10 h-10 rounded-lg bg-gray-100 flex items-center justify-center">
                    <span class="material-symbols-outlined text-gray-500">table_view</span>
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-[#1A365D]">Full Register Export</h3>
                    <p class="text-[10px] text-gray-500">Complete data extract</p>
                </div>
            </div>
            <p class="text-xs text-gray-500 mb-4">Export the complete loss event register in Excel format including all fields, classifications, and financial data.</p>
            <form method="POST" action="{{ url('/risk/loss-events/reports/export') }}">
                @csrf
                <div class="mb-3">
                    <label class="block text-[10px] font-medium text-gray-500 mb-1">Format</label>
                    <select name="format" class="w-full text-xs border border-gray-200 rounded-lg px-2 py-1.5 focus:ring-1 focus:ring-[#1A365D]">
                        <option value="xlsx">Excel (.xlsx)</option>
                        <option value="csv">CSV (.csv)</option>
                        <option value="pdf">PDF</option>
                    </select>
                </div>
                <button type="submit" class="w-full flex items-center justify-center gap-2 px-4 py-2 bg-[#1A365D] text-white text-xs font-semibold rounded-lg hover:bg-[#2D4A7A] transition">
                    <span class="material-symbols-outlined text-sm">download</span>
                    Export Data
                </button>
            </form>
        </div>
    </div>

    {{-- Recently Generated Reports --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100">
            <h3 class="text-sm font-semibold text-[#1A365D]">Recently Generated Reports</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Report Name</th>
                        <th>Type</th>
                        <th>Period</th>
                        <th>Generated By</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (($recentReports ?? []) as $report)
                        <tr>
                            <td class="font-medium text-gray-800 text-sm">{{ $report->name }}</td>
                            <td class="text-xs">
                                <span class="px-2 py-0.5 rounded bg-blue-50 text-blue-700 text-[11px] font-medium">{{ $report->type }}</span>
                            </td>
                            <td class="text-xs text-gray-500">{{ $report->period ?? '-' }}</td>
                            <td class="text-xs text-gray-600">{{ $report->generatedBy->name ?? '-' }}</td>
                            <td class="text-xs text-gray-500">{{ $report->created_at?->format('d M Y H:i') }}</td>
                            <td>
                                <a href="{{ $report->download_url ?? '#' }}" class="flex items-center gap-1 text-xs text-[#1A365D] font-medium hover:underline">
                                    <span class="material-symbols-outlined text-sm">download</span>
                                    Download
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-8 text-gray-400">
                                <span class="material-symbols-outlined text-3xl mb-2 block">description</span>
                                <p class="text-sm">No reports generated yet</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
