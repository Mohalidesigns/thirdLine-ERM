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
            <button onclick="window.print()" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2"><span class="material-symbols-outlined text-lg">download</span> Export</button>
        </div>
    </div>

    {{-- Compliance Status --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <x-kpi-card title="Overall Compliance" :value="($overallCompliance ?? 0) . '%'" icon="verified" :color="($overallCompliance ?? 0) >= 90 ? 'success' : (($overallCompliance ?? 0) >= 70 ? 'warning' : 'danger')" />
        <x-kpi-card title="Pending Returns" :value="$pendingReturns ?? 0" icon="description" color="warning" subtitle="Regulatory filings" />
        <x-kpi-card title="Overdue Items" :value="$overdueItems ?? 0" icon="error" color="danger" />
        <x-kpi-card title="CBN Directives" :value="$cbnDirectives ?? 0" icon="gavel" color="info" subtitle="Active directives" />
    </div>

    {{-- ORMS Framework Compliance --}}
    <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
        <h3 class="text-sm font-semibold text-[#1A365D] mb-4">CBN ORMS Framework Compliance</h3>
        <div class="space-y-4">
            @foreach ([
                ['Risk Governance & Culture', $ormsGovernance ?? 90],
                ['Risk Appetite & Strategy', $ormsAppetite ?? 85],
                ['Risk Identification & Assessment', $ormsIdentification ?? 78],
                ['Risk Monitoring & Reporting', $ormsMonitoring ?? 82],
                ['Risk Mitigation & Control', $ormsMitigation ?? 75],
                ['Capital Adequacy (ICAAP)', $ormsCapital ?? 88],
                ['Business Continuity Management', $ormsBCM ?? 70],
                ['Stress Testing', $ormsStress ?? 65],
            ] as [$area, $score])
                <div class="flex items-center gap-4">
                    <div class="w-60 text-xs font-medium text-gray-700">{{ $area }}</div>
                    <div class="flex-1">
                        <div class="w-full bg-gray-200 rounded-full h-3">
                            <div class="h-3 rounded-full transition-all {{ $score >= 90 ? 'bg-green-500' : ($score >= 70 ? 'bg-yellow-500' : 'bg-red-500') }}" style="width: {{ $score }}%"></div>
                        </div>
                    </div>
                    <span class="text-xs font-bold w-12 text-right {{ $score >= 90 ? 'text-green-600' : ($score >= 70 ? 'text-yellow-600' : 'text-red-600') }}">{{ $score }}%</span>
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
                        <td><x-risk-badge :rating="$dir->impact ?? 'medium'" /></td>
                    </tr>
                @empty <tr><td colspan="6" class="text-center py-8 text-gray-400">No active directives</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
