@extends('layouts.app')
@section('title', 'Regulatory Compliance')
@section('breadcrumbs')
    <a href="/risk/dashboard" class="hover:text-primary">Dashboard</a><span class="material-symbols-outlined text-[14px]">chevron_right</span><span class="text-gray-700 font-medium">Regulatory Compliance</span>
@endsection
@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-gray-900">Regulatory Compliance Dashboard</h1>
            <p class="text-sm text-gray-500 mt-1">CBN &middot; NFIU &middot; SEC &middot; NDPA &middot; NAICOM regulatory tracking</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('risk.regulatory.create-deadline') }}" class="px-4 py-2 bg-primary text-white rounded-lg text-sm font-medium">Add Deadline</a>
            <a href="{{ route('risk.regulatory.create-circular') }}" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm font-medium">Record Circular</a>
        </div>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <x-kpi-card title="Active Circulars" :value="$totalCirculars" icon="description" color="blue" />
        <x-kpi-card title="Pending Compliance" :value="$pendingCompliance" icon="pending" color="yellow" />
        <x-kpi-card title="Overdue Deadlines" :value="$overdueCount" icon="error" color="red" />
        <x-kpi-card title="Compliance Rate" :value="$complianceRate . '%'" icon="verified" color="green" />
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- Upcoming Deadlines --}}
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-gray-900">Upcoming Deadlines</h3>
                <a href="{{ route('risk.regulatory.calendar') }}" class="text-xs text-primary hover:underline">View Calendar</a>
            </div>
            <div class="p-4">
                @forelse($upcomingDeadlines as $dl)
                <div class="flex items-center justify-between py-3 {{ !$loop->last ? 'border-b border-gray-50' : '' }}">
                    <div>
                        <p class="text-sm font-medium text-gray-900">{{ $dl->title }}</p>
                        <p class="text-xs text-gray-500">{{ $dl->regulator }} &middot; {{ $dl->report_type }}</p>
                    </div>
                    <div class="text-right">
                        @php $days = now()->diffInDays($dl->deadline_date, false); @endphp
                        <span class="text-xs font-semibold {{ $days <= 7 ? 'text-red-600' : ($days <= 14 ? 'text-yellow-600' : 'text-gray-600') }}">{{ $dl->deadline_date->format('M d, Y') }}</span>
                        <p class="text-[10px] text-gray-400">{{ $days >= 0 ? $days . ' days left' : abs($days) . ' days overdue' }}</p>
                    </div>
                </div>
                @empty
                <p class="text-sm text-gray-400 py-4 text-center">No upcoming deadlines</p>
                @endforelse
            </div>
        </div>

        {{-- Recent Circulars --}}
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-gray-900">Recent Regulatory Circulars</h3>
                <a href="{{ route('risk.regulatory.circulars') }}" class="text-xs text-primary hover:underline">View All</a>
            </div>
            <div class="p-4">
                @forelse($recentCirculars as $circ)
                <div class="py-3 {{ !$loop->last ? 'border-b border-gray-50' : '' }}">
                    <div class="flex items-center gap-2 mb-1">
                        <span class="badge bg-{{ $circ->impact_level === 'critical' ? 'red' : ($circ->impact_level === 'high' ? 'orange' : ($circ->impact_level === 'medium' ? 'yellow' : 'green')) }}-100 text-{{ $circ->impact_level === 'critical' ? 'red' : ($circ->impact_level === 'high' ? 'orange' : ($circ->impact_level === 'medium' ? 'yellow' : 'green')) }}-700">{{ ucfirst($circ->impact_level) }}</span>
                        <span class="text-xs text-gray-400">{{ $circ->regulator }}</span>
                    </div>
                    <a href="{{ route('risk.regulatory.show-circular', $circ) }}" class="text-sm font-medium text-primary hover:underline">{{ Str::limit($circ->title, 60) }}</a>
                    <p class="text-xs text-gray-500 mt-1">{{ $circ->circular_ref }} &middot; {{ $circ->date_issued->format('M d, Y') }}</p>
                </div>
                @empty
                <p class="text-sm text-gray-400 py-4 text-center">No circulars recorded</p>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Compliance by Regulator --}}
    @if($regulatorStats->isNotEmpty())
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
        <h3 class="text-sm font-semibold text-gray-900 mb-4">Compliance by Regulator</h3>
        <div class="grid grid-cols-2 md:grid-cols-{{ min($regulatorStats->count(), 5) }} gap-4">
            @foreach($regulatorStats as $stat)
            <div class="text-center p-4 bg-gray-50 rounded-lg">
                <p class="text-lg font-bold text-gray-900">{{ $stat->total > 0 ? round($stat->compliant_count / $stat->total * 100) : 0 }}%</p>
                <p class="text-sm font-medium text-gray-700">{{ $stat->regulator }}</p>
                <p class="text-xs text-gray-400">{{ $stat->compliant_count }}/{{ $stat->total }} compliant</p>
            </div>
            @endforeach
        </div>
    </div>
    @endif
</div>
@endsection
