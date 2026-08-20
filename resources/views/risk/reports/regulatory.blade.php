@extends('layouts.app')

@section('title', 'Regulatory Report - GRC Risk Management')
@section('page-section', 'Reports')
@section('page-title', 'Regulatory')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-500">Reports</span>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Regulatory Compliance</span>
@endsection

@section('content')
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold text-[#1A365D]">CBN Regulatory Compliance Report</h1>
            <p class="text-sm text-gray-500 mt-1">ORMS compliance status, regulatory returns, and CBN directive tracking</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('risk.reports.regulatory', ['download' => 1, 'format' => 'pdf']) }}"
               class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm hover:bg-[#2D4A7A] flex items-center gap-2">
                <span class="material-symbols-outlined text-lg">picture_as_pdf</span> Download PDF
            </a>
            <a href="{{ route('risk.reports.regulatory', ['download' => 1, 'format' => 'xlsx']) }}"
               class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2">
                <span class="material-symbols-outlined text-lg">table_view</span> Excel
            </a>
        </div>
    </div>

    {{-- Compliance Status --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        {{-- Null, not 0%, when none of the three underlying measures has any
             data behind it — see ReportController::regulatory(). --}}
        <x-kpi-card title="Overall Compliance"
                    :value="($overallCompliance ?? 0) . '%'"
                    :unavailable="($overallCompliance ?? null) === null"
                    unavailableLabel="Nothing measured"
                    icon="verified"
                    :color="($overallCompliance ?? 0) >= 90 ? 'success' : (($overallCompliance ?? 0) >= 70 ? 'warning' : 'danger')" />
        <x-kpi-card title="Pending Returns" :value="$pendingReturns ?? 0" icon="description" color="warning" subtitle="Regulatory filings" />
        <x-kpi-card title="Overdue Items" :value="$overdueItems ?? 0" icon="error" color="danger" />
        <x-kpi-card title="CBN Directives" :value="$cbnDirectives ?? 0" icon="gavel" color="info" subtitle="Active directives" />
    </div>

    {{-- ORMS Framework Compliance --}}
    <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
        <h3 class="text-sm font-semibold text-[#1A365D] mb-4">CBN ORMS Framework Compliance</h3>
        {{-- The pillar list and every score in it now come from the controller,
             which scores only the three pillars this product measures and marks
             the other five not assessed, with the reason. This block used to
             hold its own eight-entry list with a numeric literal on each line
             (90, 85, 78, 82, 75, 88, 70, 65) as the fallback, and the
             controller's "derived" values were barely better: Business
             Continuity was the constant 70 for every tenant on the platform and
             the product stores no business continuity data at all. Eight full
             progress bars on a report headed "CBN Regulatory Compliance" is
             precisely the screenshot a bank should not be able to produce from
             an empty system. --}}
        <div class="space-y-4">
            @foreach (($ormsPillars ?? []) as [$area, $score, $basis])
                <div class="flex items-center gap-4">
                    <div class="w-60 text-xs font-medium text-gray-700">
                        {{ $area }}
                        <span class="block text-[11px] font-normal text-gray-400 leading-tight mt-0.5">{{ $basis }}</span>
                    </div>
                    <div class="flex-1">
                        @if ($score === null)
                            <div class="w-full border border-dashed border-gray-300 rounded-full h-3"></div>
                        @else
                            <div class="w-full bg-gray-200 rounded-full h-3">
                                <div class="h-3 rounded-full transition-all {{ $score >= 90 ? 'bg-green-500' : ($score >= 70 ? 'bg-yellow-500' : 'bg-red-500') }}" style="width: {{ $score }}%"></div>
                            </div>
                        @endif
                    </div>
                    @if ($score === null)
                        <span class="text-[11px] italic w-28 text-right text-gray-400">Not assessed</span>
                    @else
                        <span class="text-xs font-bold w-28 text-right {{ $score >= 90 ? 'text-green-600' : ($score >= 70 ? 'text-yellow-600' : 'text-red-600') }}">{{ $score }}%</span>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    {{-- Regulatory Returns Schedule --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden mb-6">
        <div class="px-5 py-4 border-b border-gray-100"><h3 class="text-sm font-semibold text-[#1A365D]">Regulatory Returns Schedule</h3></div>
        <table class="data-table">
            <thead><tr><th>Return</th><th>Regulator</th><th>Frequency</th><th>Due Date</th><th>Status</th><th>Filed By</th></tr></thead>
            <tbody>
                @forelse (($regulatoryReturns ?? []) as $ret)
                    <tr class="{{ ($ret->status ?? '') === 'overdue' ? 'border-l-4 border-l-red-500' : '' }}">
                        <td class="text-xs font-medium text-[#1A365D]">{{ $ret->name ?? '-' }}</td>
                        <td class="text-xs">{{ $ret->regulator ?? 'CBN' }}</td>
                        <td class="text-xs">{{ $ret->frequency ?? '-' }}</td>
                        <td class="text-xs {{ ($ret->status ?? '') === 'overdue' ? 'text-red-600 font-semibold' : 'text-gray-500' }}">{{ $ret->due_date ?? '-' }}</td>
                        <td><x-status-badge :status="$ret->status ?? 'pending'" /></td>
                        <td class="text-xs">{{ $ret->filed_by ?? '-' }}</td>
                    </tr>
                @empty <tr><td colspan="6" class="text-center py-8 text-gray-400">No regulatory returns scheduled</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- CBN Directives --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100"><h3 class="text-sm font-semibold text-[#1A365D]">Active CBN Directives & Circulars</h3></div>
        <table class="data-table">
            <thead><tr><th>Reference</th><th>Title</th><th>Date Issued</th><th>Compliance Deadline</th><th>Status</th><th>Impact</th></tr></thead>
            <tbody>
                @forelse (($directives ?? []) as $dir)
                    <tr>
                        <td class="text-xs font-medium text-[#1A365D]">{{ $dir->reference ?? '-' }}</td>
                        <td class="text-xs">{{ Str::limit($dir->title ?? '-', 50) }}</td>
                        <td class="text-xs text-gray-500">{{ $dir->issued_date ?? '-' }}</td>
                        <td class="text-xs">{{ $dir->deadline ?? '-' }}</td>
                        <td><x-status-badge :status="$dir->status ?? 'pending'" /></td>
                        {{-- A circular with no impact_level recorded is
                             Unrated, not Medium. --}}
                        <td><x-risk-badge :rating="$dir->impact ?? 'Unrated'" /></td>
                    </tr>
                @empty <tr><td colspan="6" class="text-center py-8 text-gray-400">No active directives</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
