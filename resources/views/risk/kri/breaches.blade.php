@extends('layouts.app')

@section('title', 'KRI Breaches - GRC Risk Management')
@section('page-section', 'KRI Monitoring')
@section('page-title', 'Active Breaches')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.kri.dashboard') }}" class="hover:text-[#1A365D]">KRI Monitoring</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Active Breaches</span>
@endsection

@section('content')
    @if (session('success'))
        <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-xl flex items-center gap-3">
            <span class="material-symbols-outlined text-green-600">check_circle</span>
            <span class="text-sm text-green-700">{{ session('success') }}</span>
            <button onclick="this.parentElement.remove()" class="ml-auto text-green-400 hover:text-green-600"><span class="material-symbols-outlined text-lg">close</span></button>
        </div>
    @endif

    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-[#1A365D]">Active KRI Breaches</h1>
            <p class="text-sm text-gray-500 mt-1">{{ count($breaches ?? []) }} active breaches requiring attention</p>
        </div>
        <div class="flex gap-2">
            <button onclick="window.print()" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2">
                <span class="material-symbols-outlined text-lg">download</span> Export
            </button>
        </div>
    </div>

    {{-- Summary Cards --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <x-kpi-card title="Red Breaches" :value="$redBreaches ?? 0" icon="error" color="danger" subtitle="Critical - Immediate action required" />
        <x-kpi-card title="Amber Warnings" :value="$amberBreaches ?? 0" icon="warning" color="warning" subtitle="Approaching threshold" />
        <x-kpi-card title="Avg. Days in Breach" :value="$avgDaysInBreach ?? 0" icon="schedule" color="info" />
    </div>

    {{-- Filter --}}
    <form method="GET" action="{{ route('risk.kri.breaches') }}" id="filterForm">
        <div class="bg-white rounded-xl border border-gray-200 p-4 mb-4">
            <div class="flex flex-wrap gap-3 items-center">
                <div class="flex-1 min-w-[200px]">
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-lg">search</span>
                        <input type="text" name="search" value="{{ request('search') }}" placeholder="Search breaches..."
                               class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                               data-live-search>
                    </div>
                </div>
                <select name="level" class="border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-700" onchange="document.getElementById('filterForm').submit()">
                    <option value="">All Levels</option>
                    <option value="red" {{ request('level') === 'red' ? 'selected' : '' }}>Red Only</option>
                    <option value="amber" {{ request('level') === 'amber' ? 'selected' : '' }}>Amber Only</option>
                </select>
                <select name="category" class="border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-700" onchange="document.getElementById('filterForm').submit()">
                    <option value="">All Categories</option>
                    @foreach (($categories ?? []) as $cat)
                        <option value="{{ $cat }}" {{ request('category') === $cat ? 'selected' : '' }}>{{ $cat }}</option>
                    @endforeach
                </select>
                @if (request()->hasAny(['search', 'level', 'category']))
                    <a href="{{ route('risk.kri.breaches') }}" class="text-xs text-[#1A365D] font-medium hover:underline">Clear</a>
                @endif
            </div>
        </div>
    </form>

    {{-- Breaches Table --}}
    <x-data-table>
        <x-slot name="head">
            <th>KRI</th>
            <th>Category</th>
            <th>Current Value</th>
            <th>Threshold</th>
            <th>Breach Level</th>
            <th>Days in Breach</th>
            <th>Owner</th>
            <th>Breach Date</th>
            <th>Action Taken</th>
            <th>Actions</th>
        </x-slot>

        @forelse (($breaches ?? []) as $breach)
            <tr class="hover:bg-blue-50/50 {{ ($breach->level ?? '') === 'red' ? 'border-l-4 border-l-red-500' : 'border-l-4 border-l-yellow-500' }}">
                <td class="font-medium text-[#1A365D]">
                    <a href="{{ route('risk.kri.show', $breach->kri_id ?? $breach->id) }}" class="hover:underline">{{ $breach->kri_name ?? $breach->name }}</a>
                </td>
                <td class="text-xs">{{ $breach->category ?? '-' }}</td>
                <td class="font-bold {{ ($breach->level ?? '') === 'red' ? 'text-red-600' : 'text-yellow-600' }}">{{ $breach->current_value ?? '-' }}</td>
                <td class="text-xs text-gray-500">{{ $breach->threshold_value ?? '-' }}</td>
                <td>
                    <span class="badge {{ ($breach->level ?? '') === 'red' ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700' }}">
                        {{ ucfirst($breach->level ?? 'breach') }}
                    </span>
                </td>
                <td class="text-xs font-semibold {{ ($breach->days_in_breach ?? 0) > 14 ? 'text-red-600' : 'text-gray-700' }}">{{ $breach->days_in_breach ?? 0 }} days</td>
                <td class="text-xs">{{ $breach->owner ?? '-' }}</td>
                <td class="text-xs text-gray-500">{{ isset($breach->breach_date) ? $breach->breach_date->format('d M Y') : '-' }}</td>
                <td class="text-xs">{{ Str::limit($breach->action_taken ?? 'Pending', 30) }}</td>
                <td>
                    <a href="{{ route('risk.kri.show', $breach->kri_id ?? $breach->id) }}" class="p-1 hover:bg-gray-100 rounded"><span class="material-symbols-outlined text-gray-400 text-lg">visibility</span></a>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="10" class="text-center py-12">
                    <span class="material-symbols-outlined text-4xl text-green-300 mb-2 block">verified</span>
                    <p class="text-sm text-gray-500">No active breaches. All KRIs are within tolerance.</p>
                </td>
            </tr>
        @endforelse
    </x-data-table>
@endsection
