@extends('layouts.app')
@section('title', 'Assessment Campaigns')
@section('breadcrumbs')
    <a href="/risk/dashboard" class="hover:text-primary">Dashboard</a>
    <span class="material-symbols-outlined text-[14px]">chevron_right</span>
    <span class="text-gray-700 font-medium">Assessment Campaigns</span>
@endsection

@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-gray-900">Assessment Campaign Dashboard</h1>
            <p class="text-sm text-gray-500 mt-1">Manage RCSA and targeted risk assessment campaigns</p>
        </div>
        <a href="{{ route('risk.campaigns.create') }}" class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-white rounded-lg text-sm font-medium hover:bg-opacity-90">
            <span class="material-symbols-outlined text-lg">add_circle</span> New Campaign
        </a>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <x-kpi-card title="Active Campaigns" :value="$activeCampaigns" icon="campaign" color="blue" />
        <x-kpi-card title="Total Campaigns" :value="$totalCampaigns" icon="folder" color="gray" />
        <x-kpi-card title="Pending Review" :value="$pendingReview" icon="rate_review" color="yellow" />
        <x-kpi-card title="Avg Completion" :value="number_format($avgCompletion, 1) . '%'" icon="donut_large" color="green" />
    </div>

    <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
        <div class="px-5 py-4 border-b border-gray-100">
            <h3 class="text-sm font-semibold text-gray-900">Recent Campaigns</h3>
        </div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Title</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Assignments</th>
                    <th>Completion</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                @forelse($campaigns as $campaign)
                <tr class="cursor-pointer hover:bg-gray-50" onclick="window.location='{{ route('risk.campaigns.show', $campaign) }}'">
                    <td class="font-mono text-xs">{{ $campaign->campaign_code }}</td>
                    <td class="font-medium">{{ Str::limit($campaign->title, 40) }}</td>
                    <td><span class="badge bg-blue-50 text-blue-700">{{ ucfirst(str_replace('_', ' ', $campaign->campaign_type)) }}</span></td>
                    <td>
                        @php $sc = ['draft'=>'gray','active'=>'blue','in_progress'=>'yellow','under_review'=>'purple','closed'=>'green','cancelled'=>'red']; @endphp
                        <span class="badge bg-{{ $sc[$campaign->status] ?? 'gray' }}-100 text-{{ $sc[$campaign->status] ?? 'gray' }}-700">{{ ucfirst(str_replace('_', ' ', $campaign->status)) }}</span>
                    </td>
                    <td>{{ $campaign->assignments_count }}</td>
                    <td>
                        {{-- Green is approved, blue is handed in and waiting on a
                             reviewer. The number stays the approved share. --}}
                        @php $progress = $campaign->progressBreakdown(); @endphp
                        <div class="flex items-center gap-2"
                             title="{{ $progress['completed'] }} approved · {{ $progress['awaiting_review'] }} awaiting review · {{ $progress['total'] }} total">
                            <div class="w-16 h-1.5 bg-gray-100 rounded-full overflow-hidden flex">
                                <div class="h-full bg-green-500" style="width:{{ $progress['completed_pct'] }}%"></div>
                                <div class="h-full bg-blue-400" style="width:{{ $progress['awaiting_review_pct'] }}%"></div>
                            </div>
                            <span class="text-xs text-gray-500">{{ number_format($progress['completed_pct'], 0) }}%</span>
                            @if ($progress['awaiting_review'] > 0)
                                <span class="text-[11px] text-blue-600">+{{ $progress['awaiting_review'] }} to review</span>
                            @endif
                        </div>
                    </td>
                    <td class="text-xs text-gray-500">{{ $campaign->created_at->format('M d, Y') }}</td>
                </tr>
                @empty
                <tr><td colspan="7" class="text-center py-8 text-gray-400">No campaigns yet</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
