@extends('layouts.app')

@section('title', 'KRI Library - GRC Risk Management')
@section('page-section', 'KRI Monitoring')
@section('page-title', 'KRI Library')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.kri.dashboard') }}" class="hover:text-[#1A365D]">KRI Monitoring</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">KRI Library</span>
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
            <h1 class="text-2xl font-bold text-[#1A365D]">Key Risk Indicators Library</h1>
            <p class="text-sm text-gray-500 mt-1">{{ $kris->total() ?? 0 }} indicators configured &middot; {{ $activeBreachCount ?? 0 }} active breaches</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('risk.kri.create') }}" class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-2 transition-colors">
                <span class="material-symbols-outlined text-lg">add_circle</span> New KRI
            </a>
            <a href="{{ route('risk.kri.thresholds') }}" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2 transition-colors">
                <span class="material-symbols-outlined text-lg">tune</span> Manage Thresholds
            </a>
        </div>
    </div>

    {{-- Filter Bar --}}
    <form method="GET" action="{{ route('risk.kri.index') }}" id="filterForm">
        <div class="bg-white rounded-xl border border-gray-200 p-4 mb-4">
            <div class="flex flex-wrap gap-3 items-center">
                <div class="flex-1 min-w-[200px]">
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-lg">search</span>
                        <input type="text" name="search" value="{{ request('search') }}" placeholder="Search KRIs..."
                               class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
                    </div>
                </div>
                <select name="status" class="border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-700" onchange="document.getElementById('filterForm').submit()">
                    <option value="">All Statuses</option>
                    <option value="green" {{ request('status') === 'green' ? 'selected' : '' }}>Green (Normal)</option>
                    <option value="amber" {{ request('status') === 'amber' ? 'selected' : '' }}>Amber (Warning)</option>
                    <option value="red" {{ request('status') === 'red' ? 'selected' : '' }}>Red (Breach)</option>
                </select>
                <select name="category" class="border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-700" onchange="document.getElementById('filterForm').submit()">
                    <option value="">All Categories</option>
                    @foreach (($categories ?? []) as $cat)
                        <option value="{{ $cat }}" {{ request('category') === $cat ? 'selected' : '' }}>{{ $cat }}</option>
                    @endforeach
                </select>
                <select name="frequency" class="border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-700" onchange="document.getElementById('filterForm').submit()">
                    <option value="">All Frequencies</option>
                    @foreach (['Daily', 'Weekly', 'Monthly', 'Quarterly'] as $freq)
                        <option value="{{ strtolower($freq) }}" {{ request('frequency') === strtolower($freq) ? 'selected' : '' }}>{{ $freq }}</option>
                    @endforeach
                </select>
                @if (request()->hasAny(['search', 'status', 'category', 'frequency']))
                    <a href="{{ route('risk.kri.index') }}" class="text-xs text-[#1A365D] font-medium hover:underline">Clear</a>
                @endif
            </div>
        </div>
    </form>

    {{-- KRI Table --}}
    <x-data-table id="kriTable">
        <x-slot name="head">
            <th>KRI Name</th>
            <th>Category</th>
            <th>Current Value</th>
            <th>Green Threshold</th>
            <th>Amber Threshold</th>
            <th>Red Threshold</th>
            <th>Status</th>
            <th>Trend</th>
            <th>Frequency</th>
            <th>Owner</th>
            <th>Actions</th>
        </x-slot>

        @forelse (($kris ?? []) as $kri)
            <tr class="hover:bg-blue-50/50">
                <td class="font-medium text-[#1A365D]">
                    <a href="{{ route('risk.kri.show', $kri) }}" class="hover:underline">{{ $kri->name }}</a>
                </td>
                <td class="text-xs">{{ $kri->category ?? '-' }}</td>
                <td class="font-semibold {{ ($kri->current_status ?? 'green') === 'red' ? 'text-red-600' : (($kri->current_status ?? 'green') === 'amber' ? 'text-yellow-600' : 'text-green-600') }}">
                    {{ $kri->current_value ?? '-' }}{{ $kri->unit ?? '' }}
                </td>
                <td class="text-xs text-green-600">{{ $kri->green_threshold ?? '-' }}</td>
                <td class="text-xs text-yellow-600">{{ $kri->amber_threshold ?? '-' }}</td>
                <td class="text-xs text-red-600">{{ $kri->red_threshold ?? '-' }}</td>
                <td>
                    <span class="flex items-center gap-1">
                        <span class="w-2.5 h-2.5 rounded-full {{ ($kri->current_status ?? 'green') === 'red' ? 'bg-red-500' : (($kri->current_status ?? 'green') === 'amber' ? 'bg-yellow-500' : 'bg-green-500') }}"></span>
                        <span class="text-xs font-medium">{{ ucfirst($kri->current_status ?? 'green') }}</span>
                    </span>
                </td>
                <td>
                    @if (($kri->trend ?? null) === 'up')
                        <span class="material-symbols-outlined text-sm text-red-500">trending_up</span>
                    @elseif (($kri->trend ?? null) === 'down')
                        <span class="material-symbols-outlined text-sm text-green-500">trending_down</span>
                    @else
                        <span class="material-symbols-outlined text-sm text-gray-400">trending_flat</span>
                    @endif
                </td>
                <td class="text-xs">{{ ucfirst($kri->frequency ?? '-') }}</td>
                <td class="text-xs">{{ $kri->owner->name ?? '-' }}</td>
                <td>
                    <div class="flex items-center gap-1">
                        <a href="{{ route('risk.kri.show', $kri) }}" class="p-1 hover:bg-gray-100 rounded"><span class="material-symbols-outlined text-gray-400 text-lg">visibility</span></a>
                        <a href="{{ route('risk.kri.edit', $kri) }}" class="p-1 hover:bg-gray-100 rounded"><span class="material-symbols-outlined text-gray-400 text-lg">edit</span></a>
                    </div>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="11" class="text-center py-12">
                    <div class="flex flex-col items-center gap-3">
                        <span class="material-symbols-outlined text-4xl text-gray-300">speed</span>
                        <p class="text-sm text-gray-500">No KRIs found.</p>
                        <a href="{{ route('risk.kri.create') }}" class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A]">Create First KRI</a>
                    </div>
                </td>
            </tr>
        @endforelse
    </x-data-table>

    @if (isset($kris) && $kris->hasPages())
        <div class="mt-4 flex items-center justify-between">
            <span class="text-xs text-gray-500">Showing {{ $kris->firstItem() }}-{{ $kris->lastItem() }} of {{ $kris->total() }}</span>
            <div>{{ $kris->appends(request()->query())->links('vendor.pagination.tailwind') }}</div>
        </div>
    @endif
@endsection
