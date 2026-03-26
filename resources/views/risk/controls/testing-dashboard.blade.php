@extends('layouts.app')
@section('title', 'Control Testing Dashboard')
@section('breadcrumbs')
    <a href="/risk/dashboard" class="hover:text-primary">Dashboard</a>
    <span class="material-symbols-outlined text-[14px]">chevron_right</span>
    <span class="text-gray-700 font-medium">Control Testing</span>
@endsection

@section('content')
<div class="space-y-6">
    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-gray-900">Control Testing Dashboard</h1>
            <p class="text-sm text-gray-500 mt-1">Monitor control effectiveness through systematic testing</p>
        </div>
        <a href="{{ route('risk.control-tests.create') }}" class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-white rounded-lg text-sm font-medium hover:bg-opacity-90">
            <span class="material-symbols-outlined text-lg">add_circle</span> Schedule Test
        </a>
    </div>

    {{-- KPI Cards --}}
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
        <x-kpi-card title="Total Tests" :value="$totalTests" icon="assignment" color="blue" />
        <x-kpi-card title="Scheduled" :value="$scheduledTests" icon="schedule" color="gray" />
        <x-kpi-card title="In Progress" :value="$inProgress" icon="pending" color="yellow" />
        <x-kpi-card title="Completed" :value="$completedTests" icon="check_circle" color="green" />
        <x-kpi-card title="Overdue" :value="$overdueTests" icon="warning" color="red" />
        <x-kpi-card title="Pass Rate" :value="$passRate . '%'" icon="trending_up" color="emerald" />
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- Upcoming Tests --}}
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
            <div class="px-5 py-4 border-b border-gray-100">
                <h3 class="text-sm font-semibold text-gray-900">Upcoming Tests</h3>
            </div>
            <div class="p-4">
                @forelse($upcomingTests as $test)
                <div class="flex items-center justify-between py-3 {{ !$loop->last ? 'border-b border-gray-50' : '' }}">
                    <div>
                        <p class="text-sm font-medium text-gray-900">{{ $test->title }}</p>
                        <p class="text-xs text-gray-500">{{ $test->control?->name }} &middot; {{ $test->tester?->name }}</p>
                    </div>
                    <div class="text-right">
                        <span class="text-xs {{ $test->scheduled_date->isPast() ? 'text-red-600 font-semibold' : 'text-gray-500' }}">
                            {{ $test->scheduled_date->format('M d, Y') }}
                        </span>
                    </div>
                </div>
                @empty
                <p class="text-sm text-gray-400 py-4 text-center">No upcoming tests scheduled</p>
                @endforelse
            </div>
        </div>

        {{-- Recent Tests --}}
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
            <div class="px-5 py-4 border-b border-gray-100">
                <h3 class="text-sm font-semibold text-gray-900">Recent Test Results</h3>
            </div>
            <div class="p-4">
                @forelse($recentTests as $test)
                <div class="flex items-center justify-between py-3 {{ !$loop->last ? 'border-b border-gray-50' : '' }}">
                    <div>
                        <a href="{{ route('risk.control-tests.show', $test) }}" class="text-sm font-medium text-primary hover:underline">{{ $test->title }}</a>
                        <p class="text-xs text-gray-500">{{ $test->control?->name }}</p>
                    </div>
                    <div>
                        @if($test->result === 'effective')
                            <span class="badge bg-green-100 text-green-700">Effective</span>
                        @elseif($test->result === 'partially_effective')
                            <span class="badge bg-yellow-100 text-yellow-700">Partial</span>
                        @elseif($test->result === 'ineffective')
                            <span class="badge bg-red-100 text-red-700">Ineffective</span>
                        @else
                            <span class="badge bg-gray-100 text-gray-600">{{ ucfirst(str_replace('_', ' ', $test->status)) }}</span>
                        @endif
                    </div>
                </div>
                @empty
                <p class="text-sm text-gray-400 py-4 text-center">No tests completed yet</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection
