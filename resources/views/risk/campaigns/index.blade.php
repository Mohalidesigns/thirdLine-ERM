@extends('layouts.app')
@section('title', 'All Campaigns')
@section('breadcrumbs')
    <a href="/risk/dashboard" class="hover:text-primary">Dashboard</a>
    <span class="material-symbols-outlined text-[14px]">chevron_right</span>
    <a href="{{ route('risk.campaigns.dashboard') }}" class="hover:text-primary">Campaigns</a>
    <span class="material-symbols-outlined text-[14px]">chevron_right</span>
    <span class="text-gray-700 font-medium">All Campaigns</span>
@endsection

@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-bold text-gray-900">All Assessment Campaigns</h1>
        <a href="{{ route('risk.campaigns.create') }}" class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-white rounded-lg text-sm font-medium hover:bg-opacity-90">
            <span class="material-symbols-outlined text-lg">add_circle</span> New Campaign
        </a>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <table class="data-table">
            <thead>
                <tr><th>Code</th><th>Title</th><th>Type</th><th>Status</th><th>Assignments</th><th>Completion</th><th>Period</th></tr>
            </thead>
            <tbody>
                @forelse($campaigns as $campaign)
                <tr class="cursor-pointer" onclick="window.location='{{ route('risk.campaigns.show', $campaign) }}'">
                    <td class="font-mono text-xs">{{ $campaign->campaign_code }}</td>
                    <td class="font-medium">{{ Str::limit($campaign->title, 50) }}</td>
                    <td><span class="badge bg-blue-50 text-blue-700">{{ ucfirst(str_replace('_', ' ', $campaign->campaign_type)) }}</span></td>
                    <td>
                        @php $sc = ['draft'=>'gray','active'=>'blue','in_progress'=>'yellow','under_review'=>'purple','closed'=>'green','cancelled'=>'red']; @endphp
                        <span class="badge bg-{{ $sc[$campaign->status] ?? 'gray' }}-100 text-{{ $sc[$campaign->status] ?? 'gray' }}-700">{{ ucfirst(str_replace('_', ' ', $campaign->status)) }}</span>
                    </td>
                    <td>{{ $campaign->assignments_count }}</td>
                    <td>{{ number_format($campaign->completion_pct, 0) }}%</td>
                    <td class="text-xs">{{ $campaign->start_date->format('M d') }} – {{ $campaign->end_date->format('M d, Y') }}</td>
                </tr>
                @empty
                <tr><td colspan="7" class="text-center py-8 text-gray-400">No campaigns found</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div>{{ $campaigns->links() }}</div>
</div>
@endsection
