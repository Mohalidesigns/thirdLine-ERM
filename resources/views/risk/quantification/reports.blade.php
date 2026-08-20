@extends('layouts.app')

@section('title', 'Quantification Reports - GRC Risk Management')
@section('page-section', 'Quantification')
@section('page-title', 'Reports')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.quantification.dashboard') }}" class="hover:text-[#1A365D]">Quantification</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Reports</span>
@endsection

@section('content')
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-[#1A365D]">Quantification Reports</h1>
            <p class="text-sm text-gray-500 mt-1">Generate and download risk quantification reports for regulatory submission</p>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        @foreach ([
            ['ICAAP Report', 'Full Internal Capital Adequacy Assessment Process report including stress testing and capital planning', 'assessment', route('risk.quantification.icaap')],
            ['Capital Adequacy Summary', 'Summary of capital position with Pillar 1 and Pillar 2 breakdown for CBN submission', 'account_balance', route('risk.quantification.reports.capital-adequacy')],
            ['Simulation Results Report', 'Detailed Monte Carlo simulation output with VaR, Expected Shortfall, and risk contributions', 'calculate', route('risk.quantification.results')],
            ['Stress Testing Report', 'Capital impact of the stress simulation bound to the latest ICAAP assessment, by confidence level', 'crisis_alert', route('risk.quantification.reports.stress-testing')],
            ['Risk Contribution Analysis', 'Expected annual loss by scenario, and residual risk score by business unit', 'pie_chart', route('risk.quantification.reports.risk-contribution')],
            ['Regulatory Compliance Pack', 'Combined regulatory reporting package for CBN ORMS compliance', 'gavel', route('risk.quantification.reports.regulatory-pack')],
        ] as [$title, $desc, $icon, $link])
            <div class="bg-white rounded-xl border border-gray-200 p-5 hover:shadow-lg transition-shadow">
                <div class="flex items-center gap-3 mb-3">
                    <div class="w-10 h-10 rounded-lg bg-blue-50 flex items-center justify-center">
                        <span class="material-symbols-outlined text-[#1A365D]">{{ $icon }}</span>
                    </div>
                    <h3 class="text-sm font-semibold text-[#1A365D]">{{ $title }}</h3>
                </div>
                <p class="text-xs text-gray-500 mb-4">{{ $desc }}</p>
                <div class="flex gap-2">
                    <a href="{{ $link }}" class="flex-1 px-3 py-2 bg-[#1A365D] text-white rounded-lg text-xs font-medium hover:bg-[#2D4A7A] text-center flex items-center justify-center gap-1">
                        <span class="material-symbols-outlined text-sm">visibility</span> View
                    </a>
                    <a href="{{ $link }}" onclick="if(this.href.endsWith('#')){event.preventDefault();return;} var w=window.open(this.href,'_blank'); setTimeout(function(){w.print();},1000); event.preventDefault();" class="px-3 py-2 border border-gray-300 rounded-lg text-xs text-gray-700 hover:bg-gray-50 flex items-center gap-1 cursor-pointer">
                        <span class="material-symbols-outlined text-sm">download</span> PDF
                    </a>
                </div>
            </div>
        @endforeach
    </div>
@endsection
