@extends('layouts.app')

@section('title', 'Command Centre - GRC Risk Management')
@section('page-section', 'Enterprise Risk Management')
@section('page-title', 'Command Centre')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Command Centre</span>
@endsection

@section('content')

    {{-- Flash Messages --}}
    @if (session('success'))
        <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-xl flex items-center gap-3">
            <span class="material-symbols-outlined text-green-600">check_circle</span>
            <span class="text-sm text-green-700">{{ session('success') }}</span>
            <button onclick="this.parentElement.remove()" class="ml-auto text-green-400 hover:text-green-600">
                <span class="material-symbols-outlined text-lg">close</span>
            </button>
        </div>
    @endif

    @if (session('error'))
        <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-xl flex items-center gap-3">
            <span class="material-symbols-outlined text-red-600">error</span>
            <span class="text-sm text-red-700">{{ session('error') }}</span>
            <button onclick="this.parentElement.remove()" class="ml-auto text-red-400 hover:text-red-600">
                <span class="material-symbols-outlined text-lg">close</span>
            </button>
        </div>
    @endif

    {{-- Page Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-[#1A365D]">Enterprise Risk Command Centre</h1>
            <p class="text-sm text-gray-500 mt-1">Executive overview of organisational risk posture &mdash; {{ now()->format('d M Y, H:i') }}</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('risk.register.create') }}"
               class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-2 transition-colors">
                <span class="material-symbols-outlined text-lg">add_circle</span> Register Risk
            </a>
            <a href="{{ route('risk.export.dashboard') }}" class="px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 flex items-center gap-2 transition-colors">
                <span class="material-symbols-outlined text-lg">download</span> Export
            </a>
        </div>
    </div>

    {{-- ════════════════════════════════════════════════════════════════
         Section 1 — Executive KPI Strip (8 cards)
         ════════════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-8 gap-4 mb-6">
        <x-kpi-card title="Active Risks"   :value="$totalActiveRisks"   icon="security"         color="primary" subtitle="In register" />
        <x-kpi-card title="Critical Risks"  :value="$criticalRisks"     icon="error"             color="danger"  subtitle="Residual rating" />
        <x-kpi-card title="High Risks"      :value="$highRisks"         icon="warning"           color="warning" subtitle="Residual rating" />
        <x-kpi-card title="KRI Breaches"    :value="$kriBreaches"       icon="speed"             color="danger"  subtitle="Red threshold" />
        <x-kpi-card title="Open Issues"     :value="$openIssues"        icon="bug_report"        color="warning" subtitle="Active issues" />
        <x-kpi-card title="YTD Net Loss"    :value="'₦' . number_format($ytdNetLoss, 0)" icon="payments" color="danger" subtitle="{{ now()->year }} year to date" />
        <x-kpi-card title="Overdue Plans"   :value="$overdueTreatments" icon="schedule"          color="warning" subtitle="Treatment plans" />
        <x-kpi-card title="CAR (%)"         :value="($carPercentage !== null ? $carPercentage . '%' : 'N/A')" icon="account_balance" color="{{ $carPercentage !== null && (float)$carPercentage >= 15 ? 'success' : 'danger' }}" subtitle="Capital adequacy" />
    </div>

    {{-- ════════════════════════════════════════════════════════════════
         Section 2 — Residual Risk Heatmap + Rating Distribution
         ════════════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">

        {{-- 5×5 Residual Risk Heatmap --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold text-[#1A365D]">Residual Risk Heatmap</h3>
                <a href="{{ route('risk.analysis.heatmap') }}" class="text-xs text-[#1A365D] font-medium hover:underline flex items-center gap-1">
                    Full View <span class="material-symbols-outlined text-sm">arrow_forward</span>
                </a>
            </div>
            @php
                $likelihoodLabels = ['Almost Certain', 'Likely', 'Possible', 'Unlikely', 'Rare'];
                $impactLabels = ['Insignificant', 'Minor', 'Moderate', 'Major', 'Catastrophic'];
                // Cell colours: row=5-likelihood, col=impact-1  →  score = likelihood × impact
                $cellColors = [];
                for ($r = 0; $r < 5; $r++) {
                    $likelihood = 5 - $r;
                    for ($c = 0; $c < 5; $c++) {
                        $impact = $c + 1;
                        $score = $likelihood * $impact;
                        if ($score >= 20)      $cellColors[$r][$c] = 'bg-red-600';
                        elseif ($score >= 12)   $cellColors[$r][$c] = 'bg-orange-500';
                        elseif ($score >= 5)    $cellColors[$r][$c] = 'bg-yellow-400';
                        else                    $cellColors[$r][$c] = 'bg-green-500';
                    }
                }
            @endphp
            <div class="overflow-x-auto">
                <table class="w-full border-collapse">
                    <thead>
                        <tr>
                            <th class="p-1 text-[10px] text-gray-400 font-medium w-20"></th>
                            @foreach ($impactLabels as $label)
                                <th class="p-1 text-[10px] text-gray-500 font-medium text-center">{{ $label }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($likelihoodLabels as $rIdx => $lLabel)
                            <tr>
                                <td class="p-1 text-[10px] text-gray-500 font-medium text-right pr-2 whitespace-nowrap">{{ $lLabel }}</td>
                                @for ($cIdx = 0; $cIdx < 5; $cIdx++)
                                    @php
                                        $count = $heatmapData[$rIdx][$cIdx] ?? 0;
                                        $bg = $cellColors[$rIdx][$cIdx];
                                    @endphp
                                    <td class="p-1">
                                        <div class="{{ $bg }} rounded-lg text-center py-3 min-w-[50px] {{ $count > 0 ? 'text-white font-bold' : 'text-white/50' }} text-sm cursor-default hover:opacity-80 transition-opacity">
                                            {{ $count > 0 ? $count : '-' }}
                                        </div>
                                    </td>
                                @endfor
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="flex items-center justify-between mt-2 px-1">
                    <span class="text-[9px] text-gray-400 uppercase tracking-wider">Likelihood &darr;</span>
                    <span class="text-[9px] text-gray-400 uppercase tracking-wider">Impact &rarr;</span>
                </div>
            </div>
        </div>

        {{-- Risk Rating Distribution Ring --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Risk Rating Distribution</h3>
            <div class="flex items-center justify-center" style="position: relative; height: 260px; width: 100%;">
                <canvas id="ratingDistChart" height="260"></canvas>
            </div>
            <div class="grid grid-cols-4 gap-2 mt-4">
                @foreach (['Critical' => '#C53030', 'High' => '#DD6B20', 'Medium' => '#D4AF37', 'Low' => '#2F855A'] as $label => $color)
                    <div class="text-center">
                        <div class="text-lg font-bold" style="color: {{ $color }}">{{ $ratingDistribution[$label] ?? 0 }}</div>
                        <div class="text-[10px] text-gray-500">{{ $label }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ════════════════════════════════════════════════════════════════
         Section 3 — Risk Rating Trend + Loss Event Trend
         ════════════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">

        {{-- Risk Rating Trend (12 months) --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Risk Rating Trend (12 Months)</h3>
            <div style="position: relative; height: 260px; width: 100%;">
                <canvas id="riskTrendChart" height="260"></canvas>
            </div>
        </div>

        {{-- Loss Event Trend (12 months) --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Monthly Loss Events (Gross vs Net)</h3>
            <div style="position: relative; height: 260px; width: 100%;">
                <canvas id="lossTrendChart" height="260"></canvas>
            </div>
        </div>
    </div>

    {{-- ════════════════════════════════════════════════════════════════
         Section 4 — KRI Status + Control Effectiveness
         ════════════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">

        {{-- KRI Status Overview --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold text-[#1A365D]">KRI Status Overview</h3>
                <a href="{{ url('/risk/kri/dashboard') }}" class="text-xs text-[#1A365D] font-medium hover:underline flex items-center gap-1">
                    KRI Dashboard <span class="material-symbols-outlined text-sm">arrow_forward</span>
                </a>
            </div>

            {{-- Summary badges --}}
            <div class="grid grid-cols-3 gap-3 mb-4">
                <div class="text-center p-3 rounded-lg bg-green-50 border border-green-100">
                    <div class="text-xl font-bold text-green-700">{{ $kriStatusCounts['green'] }}</div>
                    <div class="text-[10px] text-gray-500 font-medium mt-0.5">Green</div>
                </div>
                <div class="text-center p-3 rounded-lg bg-yellow-50 border border-yellow-100">
                    <div class="text-xl font-bold text-yellow-700">{{ $kriStatusCounts['amber'] }}</div>
                    <div class="text-[10px] text-gray-500 font-medium mt-0.5">Amber</div>
                </div>
                <div class="text-center p-3 rounded-lg bg-red-50 border border-red-100">
                    <div class="text-xl font-bold text-red-700">{{ $kriStatusCounts['red'] }}</div>
                    <div class="text-[10px] text-gray-500 font-medium mt-0.5">Red (Breached)</div>
                </div>
            </div>

            {{-- KRI stacked bar --}}
            <div style="position: relative; height: 40px; width: 100%;" class="mb-4">
                <canvas id="kriStackedBar" height="40"></canvas>
            </div>

            {{-- Breached KRIs list --}}
            @if($breachedKris->count() > 0)
                <h4 class="text-xs font-semibold text-red-600 mb-2 flex items-center gap-1">
                    <span class="material-symbols-outlined text-sm">warning</span> Active Breaches
                </h4>
                <div class="space-y-2">
                    @foreach($breachedKris as $kri)
                        <div class="flex items-center justify-between text-xs bg-red-50/50 rounded-lg px-3 py-2">
                            <div class="flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full bg-red-500 flex-shrink-0"></span>
                                <span class="text-gray-700 font-medium">{{ \Illuminate\Support\Str::limit($kri->name, 40) }}</span>
                            </div>
                            <span class="font-semibold text-red-600">{{ number_format($kri->current_value, 1) }} {{ $kri->unit_of_measure ?? '' }}</span>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="text-center py-4">
                    <span class="material-symbols-outlined text-2xl text-green-300">verified</span>
                    <p class="text-xs text-gray-400 mt-1">No KRI breaches detected</p>
                </div>
            @endif
        </div>

        {{-- Control Effectiveness Distribution --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Control Effectiveness Distribution</h3>
            <div style="position: relative; height: 260px; width: 100%;">
                <canvas id="controlEffChart" height="260"></canvas>
            </div>
            <div class="grid grid-cols-3 gap-2 mt-4">
                @foreach (['Effective' => '#2F855A', 'Partially Effective' => '#D4AF37', 'Ineffective' => '#C53030'] as $label => $color)
                    <div class="text-center">
                        <div class="text-lg font-bold" style="color: {{ $color }}">{{ $controlEffectiveness[$label] ?? 0 }}</div>
                        <div class="text-[10px] text-gray-500">{{ $label }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ════════════════════════════════════════════════════════════════
         Section 5 — Risk Appetite + Treatment Progress
         ════════════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">

        {{-- Risk Appetite Utilisation --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold text-[#1A365D]">Risk Appetite Utilisation</h3>
                <a href="{{ route('risk.appetite.index') }}" class="text-xs text-[#1A365D] font-medium hover:underline flex items-center gap-1">
                    Details <span class="material-symbols-outlined text-sm">arrow_forward</span>
                </a>
            </div>
            @if(count($appetiteData['categories'] ?? []) > 0)
                <div style="position: relative; height: 260px; width: 100%;">
                    <canvas id="appetiteChart" height="260"></canvas>
                </div>
            @else
                <div class="text-center py-12">
                    <span class="material-symbols-outlined text-3xl text-gray-300">tune</span>
                    <p class="text-xs text-gray-400 mt-2">No risk appetite statements configured</p>
                </div>
            @endif
        </div>

        {{-- Treatment Plan Status --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold text-[#1A365D]">Treatment Plan Status</h3>
                <a href="{{ route('risk.treatments.dashboard') }}" class="text-xs text-[#1A365D] font-medium hover:underline flex items-center gap-1">
                    View All <span class="material-symbols-outlined text-sm">arrow_forward</span>
                </a>
            </div>
            @if(array_sum($treatmentStatusDist) > 0)
                <div class="text-center mb-3">
                    <span class="text-3xl font-bold text-[#1A365D]">{{ round($avgTreatmentProgress) }}%</span>
                    <p class="text-xs text-gray-500">Average Completion</p>
                </div>
                <div style="position: relative; height: 200px; width: 100%;">
                    <canvas id="treatmentChart" height="200"></canvas>
                </div>
            @else
                <div class="text-center py-12">
                    <span class="material-symbols-outlined text-3xl text-gray-300">healing</span>
                    <p class="text-xs text-gray-400 mt-2">No treatment plans found</p>
                </div>
            @endif
        </div>
    </div>

    {{-- ════════════════════════════════════════════════════════════════
         Section 6 — Top 10 Risks by Residual Score
         ════════════════════════════════════════════════════════════════ --}}
    <div class="bg-white rounded-xl border border-gray-200 mb-6">
        <div class="p-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-[#1A365D]">Top 10 Risks by Residual Score</h3>
            <a href="{{ route('risk.register.index') }}" class="text-xs text-[#1A365D] font-medium hover:underline flex items-center gap-1">
                View Full Register <span class="material-symbols-outlined text-sm">arrow_forward</span>
            </a>
        </div>
        <div class="overflow-x-auto">
            <table class="data-table w-full">
                <thead>
                    <tr>
                        <th>Risk Code</th>
                        <th>Title</th>
                        <th>Category</th>
                        <th>Inherent</th>
                        <th>Residual</th>
                        <th>Score</th>
                        <th>Treatment</th>
                        <th>Owner</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($topRisks as $risk)
                        <tr class="hover:bg-blue-50/30">
                            <td>
                                <a href="{{ route('risk.register.show', $risk) }}" class="font-semibold text-[#1A365D] hover:underline text-xs">
                                    {{ $risk->risk_code }}
                                </a>
                            </td>
                            <td class="max-w-[220px]">
                                <div class="truncate text-xs text-gray-700">{{ $risk->title }}</div>
                            </td>
                            <td><span class="text-xs text-gray-600">{{ $risk->category->name ?? '-' }}</span></td>
                            <td><x-risk-badge :rating="$risk->inherent_rating ?? 'N/A'" /></td>
                            <td><x-risk-badge :rating="$risk->residual_rating ?? 'N/A'" /></td>
                            <td>
                                <span class="text-xs font-bold {{ ($risk->residual_score ?? 0) >= 20 ? 'text-red-600' : (($risk->residual_score ?? 0) >= 12 ? 'text-orange-600' : 'text-gray-600') }}">
                                    {{ $risk->residual_score ?? '-' }}
                                </span>
                            </td>
                            <td>
                                @php $latestPlan = $risk->treatmentPlans->first(); @endphp
                                @if($latestPlan)
                                    <x-status-badge :status="$latestPlan->status" type="treatment" />
                                @else
                                    <span class="text-xs text-gray-400 italic">None</span>
                                @endif
                            </td>
                            <td>
                                @if ($risk->riskOwner)
                                    <div class="flex items-center gap-2">
                                        <div class="w-6 h-6 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center text-[10px] font-bold flex-shrink-0">
                                            {{ strtoupper(substr($risk->riskOwner->name ?? '', 0, 1)) }}{{ strtoupper(substr(explode(' ', $risk->riskOwner->name ?? '')[1] ?? '', 0, 1)) }}
                                        </div>
                                        <span class="text-xs text-gray-600">{{ \Illuminate\Support\Str::limit($risk->riskOwner->name ?? '-', 16) }}</span>
                                    </div>
                                @else
                                    <span class="text-xs text-gray-400 italic">Unassigned</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center py-10">
                                <span class="material-symbols-outlined text-3xl text-gray-300 block mb-2">shield</span>
                                <p class="text-sm text-gray-500">No active risks found in the register.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ════════════════════════════════════════════════════════════════
         Section 7 — Activity Feed + Quick Actions
         ════════════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">

        {{-- Recent Activity Feed (spans 2 cols) --}}
        <div class="lg:col-span-2 bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Recent Activity</h3>
            <div class="space-y-1">
                @forelse ($activityFeed as $item)
                    <a href="{{ $item->url }}" class="flex gap-3 items-start hover:bg-gray-50 p-2.5 rounded-lg transition group">
                        <div class="w-8 h-8 rounded-full {{ $item->icon_bg }} flex items-center justify-center flex-shrink-0">
                            <span class="material-symbols-outlined {{ $item->icon_color }} text-sm">{{ $item->icon }}</span>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-xs text-gray-700 group-hover:text-[#1A365D]">
                                <strong class="text-[#1A365D]">{{ $item->code }}</strong>
                                &mdash; {{ \Illuminate\Support\Str::limit($item->title, 70) }}
                            </p>
                            <div class="flex items-center gap-2 mt-0.5">
                                <span class="text-[10px] text-gray-400">{{ $item->date?->diffForHumans() ?? '' }}</span>
                                @if ($item->amount && $item->amount > 0)
                                    <span class="text-[10px] font-semibold text-red-500">₦{{ number_format($item->amount, 0) }}</span>
                                @endif
                            </div>
                        </div>
                        <span class="material-symbols-outlined text-gray-300 text-sm opacity-0 group-hover:opacity-100 transition">chevron_right</span>
                    </a>
                @empty
                    <div class="text-center py-8">
                        <span class="material-symbols-outlined text-2xl text-gray-300">history</span>
                        <p class="text-xs text-gray-400 mt-1">No recent activity to display</p>
                    </div>
                @endforelse
            </div>
        </div>

        {{-- Quick Actions --}}
        <div class="space-y-3">
            <h3 class="text-sm font-semibold text-[#1A365D] mb-1">Quick Actions</h3>

            <a href="{{ route('risk.register.create') }}" class="bg-white rounded-xl border border-gray-200 p-4 hover:border-[#1A365D] hover:shadow-md transition-all group block">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-blue-50 flex items-center justify-center group-hover:bg-[#1A365D] transition-colors">
                        <span class="material-symbols-outlined text-[#1A365D] group-hover:text-white text-xl transition-colors">add_circle</span>
                    </div>
                    <div>
                        <div class="text-sm font-semibold text-gray-800">Register New Risk</div>
                        <div class="text-[10px] text-gray-400">Add risk to the register</div>
                    </div>
                </div>
            </a>

            <a href="{{ url('/risk/rcsa/worksheet') }}" class="bg-white rounded-xl border border-gray-200 p-4 hover:border-[#2D7D46] hover:shadow-md transition-all group block">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-green-50 flex items-center justify-center group-hover:bg-[#2D7D46] transition-colors">
                        <span class="material-symbols-outlined text-[#2D7D46] group-hover:text-white text-xl transition-colors">fact_check</span>
                    </div>
                    <div>
                        <div class="text-sm font-semibold text-gray-800">New Assessment (RCSA)</div>
                        <div class="text-[10px] text-gray-400">Risk & control self-assessment</div>
                    </div>
                </div>
            </a>

            <a href="{{ url('/risk/loss-events/create') }}" class="bg-white rounded-xl border border-gray-200 p-4 hover:border-red-400 hover:shadow-md transition-all group block">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-red-50 flex items-center justify-center group-hover:bg-red-500 transition-colors">
                        <span class="material-symbols-outlined text-red-500 group-hover:text-white text-xl transition-colors">report_problem</span>
                    </div>
                    <div>
                        <div class="text-sm font-semibold text-gray-800">Log Loss Event</div>
                        <div class="text-[10px] text-gray-400">Record operational loss event</div>
                    </div>
                </div>
            </a>

            <a href="{{ url('/risk/issues/create') }}" class="bg-white rounded-xl border border-gray-200 p-4 hover:border-orange-400 hover:shadow-md transition-all group block">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-orange-50 flex items-center justify-center group-hover:bg-orange-500 transition-colors">
                        <span class="material-symbols-outlined text-orange-500 group-hover:text-white text-xl transition-colors">bug_report</span>
                    </div>
                    <div>
                        <div class="text-sm font-semibold text-gray-800">Create Issue</div>
                        <div class="text-[10px] text-gray-400">Log new issue or finding</div>
                    </div>
                </div>
            </a>
        </div>
    </div>

    {{-- ════════════════════════════════════════════════════════════════
         Section 8 — Regulatory & Compliance Summary
         ════════════════════════════════════════════════════════════════ --}}
    <div class="bg-white rounded-xl border border-gray-200 p-5 mb-6">
        <h3 class="text-sm font-semibold text-[#1A365D] mb-4">Regulatory & Compliance Summary</h3>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="text-center p-4 rounded-xl bg-red-50 border border-red-100">
                <div class="text-2xl font-bold text-red-600">{{ $cbnReportableCount }}</div>
                <p class="text-xs text-gray-600 mt-1 font-medium">Pending CBN Notifications</p>
                <p class="text-[10px] text-gray-400 mt-0.5">Reportable loss events</p>
            </div>
            <div class="text-center p-4 rounded-xl bg-orange-50 border border-orange-100">
                <div class="text-2xl font-bold text-orange-600">{{ $regulatoryIssues }}</div>
                <p class="text-xs text-gray-600 mt-1 font-medium">Open Regulatory Issues</p>
                <p class="text-[10px] text-gray-400 mt-0.5">Audit & compliance findings</p>
            </div>
            <div class="text-center p-4 rounded-xl bg-yellow-50 border border-yellow-100">
                <div class="text-2xl font-bold text-yellow-700">{{ $upcomingReviews }}</div>
                <p class="text-xs text-gray-600 mt-1 font-medium">Reviews Due (30 Days)</p>
                <p class="text-[10px] text-gray-400 mt-0.5">Upcoming risk reviews</p>
            </div>
            <div class="text-center p-4 rounded-xl bg-blue-50 border border-blue-100">
                <div class="text-2xl font-bold text-blue-600">{{ $overdueReviews }}</div>
                <p class="text-xs text-gray-600 mt-1 font-medium">Overdue Risk Reviews</p>
                <p class="text-[10px] text-gray-400 mt-0.5">Past next review date</p>
            </div>
        </div>
    </div>

@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const chartDefaults = {
        responsive: true,
        maintainAspectRatio: false,
    };

    const riskColors = {
        'Critical': '#C53030',
        'High':     '#DD6B20',
        'Medium':   '#D4AF37',
        'Low':      '#2F855A',
    };

    // ────────────────────────────────────────────────────────
    // Chart 1: Rating Distribution Doughnut
    // ────────────────────────────────────────────────────────
    const ratingData = @json($ratingDistribution);
    const ratingLabels = Object.keys(ratingData);
    const ratingValues = Object.values(ratingData);

    if (document.getElementById('ratingDistChart') && ratingValues.some(v => v > 0)) {
        new Chart(document.getElementById('ratingDistChart'), {
            type: 'doughnut',
            data: {
                labels: ratingLabels,
                datasets: [{
                    data: ratingValues,
                    backgroundColor: ratingLabels.map(l => riskColors[l] || '#A0AEC0'),
                    borderWidth: 0,
                    spacing: 2,
                }]
            },
            options: {
                ...chartDefaults,
                cutout: '65%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                let total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                let pct = total > 0 ? ((ctx.parsed / total) * 100).toFixed(1) : 0;
                                return ctx.label + ': ' + ctx.parsed + ' (' + pct + '%)';
                            }
                        }
                    }
                }
            }
        });
    }

    // ────────────────────────────────────────────────────────
    // Chart 2: Risk Rating Trend (Line)
    // ────────────────────────────────────────────────────────
    const riskTrend = @json($riskTrendData);

    if (document.getElementById('riskTrendChart')) {
        new Chart(document.getElementById('riskTrendChart'), {
            type: 'line',
            data: {
                labels: riskTrend.map(d => d.label),
                datasets: [
                    {
                        label: 'Critical',
                        data: riskTrend.map(d => d.critical),
                        borderColor: '#C53030',
                        backgroundColor: 'rgba(197,48,48,0.1)',
                        tension: 0.3,
                        fill: true,
                        borderWidth: 2,
                        pointRadius: 3,
                        pointBackgroundColor: '#C53030',
                    },
                    {
                        label: 'High',
                        data: riskTrend.map(d => d.high),
                        borderColor: '#DD6B20',
                        backgroundColor: 'rgba(221,107,32,0.1)',
                        tension: 0.3,
                        fill: true,
                        borderWidth: 2,
                        pointRadius: 3,
                        pointBackgroundColor: '#DD6B20',
                    },
                    {
                        label: 'Medium',
                        data: riskTrend.map(d => d.medium),
                        borderColor: '#D4AF37',
                        backgroundColor: 'rgba(212,175,55,0.1)',
                        tension: 0.3,
                        fill: true,
                        borderWidth: 2,
                        pointRadius: 3,
                        pointBackgroundColor: '#D4AF37',
                    },
                    {
                        label: 'Low',
                        data: riskTrend.map(d => d.low),
                        borderColor: '#2F855A',
                        backgroundColor: 'rgba(47,133,90,0.1)',
                        tension: 0.3,
                        fill: true,
                        borderWidth: 2,
                        pointRadius: 3,
                        pointBackgroundColor: '#2F855A',
                    },
                ]
            },
            options: {
                ...chartDefaults,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 1, font: { size: 10 } }, grid: { color: '#F0F0F0' } },
                    x: { grid: { display: false }, ticks: { font: { size: 10 } } }
                },
                plugins: {
                    legend: { position: 'bottom', labels: { font: { size: 10 }, boxWidth: 12, usePointStyle: true } }
                }
            }
        });
    }

    // ────────────────────────────────────────────────────────
    // Chart 3: Loss Event Trend (Bar)
    // ────────────────────────────────────────────────────────
    const lossTrend = @json($lossTrendData);

    if (document.getElementById('lossTrendChart')) {
        new Chart(document.getElementById('lossTrendChart'), {
            type: 'bar',
            data: {
                labels: lossTrend.map(d => d.label),
                datasets: [
                    {
                        label: 'Gross Loss',
                        data: lossTrend.map(d => d.gross),
                        backgroundColor: 'rgba(197,48,48,0.8)',
                        borderRadius: 4,
                        barPercentage: 0.7,
                    },
                    {
                        label: 'Net Loss',
                        data: lossTrend.map(d => d.net),
                        backgroundColor: 'rgba(221,107,32,0.8)',
                        borderRadius: 4,
                        barPercentage: 0.7,
                    },
                ]
            },
            options: {
                ...chartDefaults,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: '#F0F0F0' },
                        ticks: {
                            font: { size: 10 },
                            callback: function(v) {
                                if (v >= 1000000000) return '₦' + (v / 1000000000).toFixed(1) + 'B';
                                if (v >= 1000000) return '₦' + (v / 1000000).toFixed(1) + 'M';
                                if (v >= 1000) return '₦' + (v / 1000).toFixed(0) + 'K';
                                return '₦' + v;
                            }
                        }
                    },
                    x: { grid: { display: false }, ticks: { font: { size: 10 } } }
                },
                plugins: {
                    legend: { position: 'bottom', labels: { font: { size: 10 }, boxWidth: 12, usePointStyle: true } },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                return ctx.dataset.label + ': ₦' + ctx.parsed.y.toLocaleString();
                            }
                        }
                    }
                }
            }
        });
    }

    // ────────────────────────────────────────────────────────
    // Chart 4: KRI Status Stacked Bar
    // ────────────────────────────────────────────────────────
    const kriCounts = @json($kriStatusCounts);
    const kriTotal = kriCounts.green + kriCounts.amber + kriCounts.red;

    if (document.getElementById('kriStackedBar') && kriTotal > 0) {
        new Chart(document.getElementById('kriStackedBar'), {
            type: 'bar',
            data: {
                labels: [''],
                datasets: [
                    { label: 'Green (' + kriCounts.green + ')', data: [kriCounts.green], backgroundColor: '#2F855A', borderRadius: 4 },
                    { label: 'Amber (' + kriCounts.amber + ')', data: [kriCounts.amber], backgroundColor: '#D4AF37', borderRadius: 4 },
                    { label: 'Red (' + kriCounts.red + ')',     data: [kriCounts.red],   backgroundColor: '#C53030', borderRadius: 4 },
                ]
            },
            options: {
                ...chartDefaults,
                indexAxis: 'y',
                scales: {
                    x: { stacked: true, display: false },
                    y: { stacked: true, display: false },
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                let pct = kriTotal > 0 ? ((ctx.parsed.x / kriTotal) * 100).toFixed(0) : 0;
                                return ctx.dataset.label + ' — ' + pct + '%';
                            }
                        }
                    }
                }
            }
        });
    }

    // ────────────────────────────────────────────────────────
    // Chart 5: Control Effectiveness Doughnut
    // ────────────────────────────────────────────────────────
    const ctrlEff = @json($controlEffectiveness);
    const ctrlColors = { 'Effective': '#2F855A', 'Partially Effective': '#D4AF37', 'Ineffective': '#C53030' };
    const ctrlLabels = Object.keys(ctrlEff);
    const ctrlValues = Object.values(ctrlEff);

    if (document.getElementById('controlEffChart') && ctrlValues.some(v => v > 0)) {
        new Chart(document.getElementById('controlEffChart'), {
            type: 'doughnut',
            data: {
                labels: ctrlLabels,
                datasets: [{
                    data: ctrlValues,
                    backgroundColor: ctrlLabels.map(l => ctrlColors[l] || '#A0AEC0'),
                    borderWidth: 0,
                    spacing: 2,
                }]
            },
            options: {
                ...chartDefaults,
                cutout: '65%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                let total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                let pct = total > 0 ? ((ctx.parsed / total) * 100).toFixed(0) : 0;
                                return ctx.label + ': ' + ctx.parsed + ' (' + pct + '%)';
                            }
                        }
                    }
                }
            }
        });
    }

    // ────────────────────────────────────────────────────────
    // Chart 6: Appetite Utilisation Horizontal Bar
    // ────────────────────────────────────────────────────────
    const appetiteCategories = @json($appetiteData['categories'] ?? []);

    if (document.getElementById('appetiteChart') && appetiteCategories.length > 0) {
        new Chart(document.getElementById('appetiteChart'), {
            type: 'bar',
            data: {
                labels: appetiteCategories.map(c => c.category_name),
                datasets: [{
                    label: 'Utilisation %',
                    data: appetiteCategories.map(c => c.utilization_pct),
                    backgroundColor: appetiteCategories.map(c => {
                        if (c.utilization_pct > 100) return '#C53030';
                        if (c.utilization_pct > 75) return '#DD6B20';
                        return '#2F855A';
                    }),
                    borderRadius: 4,
                    barPercentage: 0.6,
                }]
            },
            options: {
                ...chartDefaults,
                indexAxis: 'y',
                scales: {
                    x: {
                        beginAtZero: true,
                        max: Math.max(150, ...appetiteCategories.map(c => c.utilization_pct + 10)),
                        grid: { color: '#F0F0F0' },
                        ticks: { callback: v => v + '%', font: { size: 10 } }
                    },
                    y: { grid: { display: false }, ticks: { font: { size: 10 } } }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                return ctx.label + ': ' + ctx.parsed.x.toFixed(1) + '% utilisation';
                            }
                        }
                    }
                }
            }
        });
    }

    // ────────────────────────────────────────────────────────
    // Chart 7: Treatment Status Doughnut
    // ────────────────────────────────────────────────────────
    const treatmentData = @json($treatmentStatusDist);
    const treatmentColors = {
        'not_started':  '#A0AEC0',
        'in_progress':  '#3182CE',
        'completed':    '#2F855A',
        'overdue':      '#C53030',
    };
    const treatmentLabels = Object.keys(treatmentData);
    const treatmentValues = Object.values(treatmentData);

    if (document.getElementById('treatmentChart') && treatmentValues.some(v => v > 0)) {
        new Chart(document.getElementById('treatmentChart'), {
            type: 'doughnut',
            data: {
                labels: treatmentLabels.map(s => s.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())),
                datasets: [{
                    data: treatmentValues,
                    backgroundColor: treatmentLabels.map(s => treatmentColors[s] || '#A0AEC0'),
                    borderWidth: 0,
                    spacing: 2,
                }]
            },
            options: {
                ...chartDefaults,
                cutout: '65%',
                plugins: {
                    legend: { position: 'bottom', labels: { font: { size: 10 }, boxWidth: 12, usePointStyle: true } },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                let total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                let pct = total > 0 ? ((ctx.parsed / total) * 100).toFixed(0) : 0;
                                return ctx.label + ': ' + ctx.parsed + ' (' + pct + '%)';
                            }
                        }
                    }
                }
            }
        });
    }
});
</script>
@endpush
