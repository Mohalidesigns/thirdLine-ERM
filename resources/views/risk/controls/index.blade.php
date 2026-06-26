@extends('layouts.app')

@section('title', 'Control Library - GRC Risk Management')
@section('page-section', 'Controls')
@section('page-title', 'Control Library')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Control Library</span>
@endsection

@section('content')
    @if (session('success'))
        <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-xl flex items-center gap-3">
            <span class="material-symbols-outlined text-green-600">check_circle</span>
            <span class="text-sm text-green-700">{{ session('success') }}</span>
            <button onclick="this.parentElement.remove()" class="ml-auto text-green-400 hover:text-green-600"><span class="material-symbols-outlined text-lg">close</span></button>
        </div>
    @endif

    @if (session('error'))
        <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-xl flex items-center gap-3">
            <span class="material-symbols-outlined text-red-600">error</span>
            <span class="text-sm text-red-700">{{ session('error') }}</span>
            <button onclick="this.parentElement.remove()" class="ml-auto text-red-400 hover:text-red-600"><span class="material-symbols-outlined text-lg">close</span></button>
        </div>
    @endif

    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-[#1A365D]">Control Library</h1>
            <p class="text-sm text-gray-500 mt-1">{{ $controls->total() ?? 0 }} controls registered &middot; Manage organizational risk controls</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('risk.controls.create') }}" class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-2"><span class="material-symbols-outlined text-lg">add_circle</span> New Control</a>
            <a href="{{ route('risk.export.controls') }}" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2"><span class="material-symbols-outlined text-lg">download</span> Export</a>
        </div>
    </div>

    {{-- Filter --}}
    <form method="GET" action="{{ route('risk.controls.index') }}" id="filterForm">
        <div class="bg-white rounded-xl border border-gray-200 p-4 mb-4">
            <div class="flex flex-wrap gap-3 items-center">
                <div class="flex-1 min-w-[200px]">
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-lg">search</span>
                        <input type="text" name="search" value="{{ request('search') }}" placeholder="Search controls..." class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]" data-live-search>
                    </div>
                </div>
                <select name="control_type" class="border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-700" onchange="document.getElementById('filterForm').submit()">
                    <option value="">All Types</option>
                    @foreach (['preventive' => 'Preventive', 'detective' => 'Detective', 'corrective' => 'Corrective', 'directive' => 'Directive'] as $val => $label)
                        <option value="{{ $val }}" {{ request('control_type') === $val ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
                <select name="effectiveness" class="border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-700" onchange="document.getElementById('filterForm').submit()">
                    <option value="">All Effectiveness</option>
                    @foreach (['effective' => 'Effective', 'partially_effective' => 'Partially Effective', 'ineffective' => 'Ineffective'] as $val => $label)
                        <option value="{{ $val }}" {{ request('effectiveness') === $val ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
                @if (request()->hasAny(['search', 'control_type', 'effectiveness']))
                    <a href="{{ route('risk.controls.index') }}" class="text-xs text-[#1A365D] font-medium hover:underline">Clear</a>
                @endif
            </div>
        </div>
    </form>

    <x-data-table id="controlsTable">
        <x-slot name="head">
            <th>Control ID</th>
            <th>Control Name</th>
            <th>Type</th>
            <th>Nature</th>
            <th>Owner</th>
            <th>Effectiveness</th>
            <th>Linked Risks</th>
            <th>Last Tested</th>
            <th>Actions</th>
        </x-slot>

        @forelse (($controls ?? []) as $control)
            <tr class="hover:bg-blue-50/50">
                <td class="font-medium text-[#1A365D]"><a href="{{ route('risk.controls.show', $control) }}" class="hover:underline">{{ $control->control_code ?? 'CTL-' . $control->id }}</a></td>
                <td class="text-xs">{{ Str::limit($control->name, 35) }}</td>
                <td><span class="badge {{ ($control->control_type ?? '') === 'preventive' ? 'bg-blue-100 text-blue-700' : (($control->control_type ?? '') === 'detective' ? 'bg-purple-100 text-purple-700' : (($control->control_type ?? '') === 'corrective' ? 'bg-orange-100 text-orange-700' : 'bg-gray-100 text-gray-700')) }}">{{ ucfirst($control->control_type ?? '-') }}</span></td>
                <td class="text-xs">{{ ucfirst(str_replace('_', ' ', $control->control_nature ?? '-')) }}</td>
                <td class="text-xs">{{ $control->owner->name ?? '-' }}</td>
                <td>
                    <span class="badge {{ ($control->effectiveness_rating ?? '') === 'effective' ? 'bg-green-100 text-green-700' : (($control->effectiveness_rating ?? '') === 'partially_effective' ? 'bg-yellow-100 text-yellow-700' : (($control->effectiveness_rating ?? '') === 'ineffective' ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-600')) }}">
                        {{ ucfirst(str_replace('_', ' ', $control->effectiveness_rating ?? 'not tested')) }}
                    </span>
                </td>
                <td class="text-xs font-medium text-[#1A365D]">{{ $control->risks_count ?? 0 }}</td>
                <td class="text-xs text-gray-500">{{ $control->last_test_date?->format('d M Y') ?? 'Never' }}</td>
                <td>
                    <div class="flex items-center gap-1">
                        <a href="{{ route('risk.controls.show', $control) }}" class="p-1 hover:bg-gray-100 rounded"><span class="material-symbols-outlined text-gray-400 text-lg">visibility</span></a>
                        <a href="{{ route('risk.controls.edit', $control) }}" class="p-1 hover:bg-gray-100 rounded"><span class="material-symbols-outlined text-gray-400 text-lg">edit</span></a>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="9" class="text-center py-12"><span class="material-symbols-outlined text-4xl text-gray-300 mb-2 block">verified_user</span><p class="text-sm text-gray-500">No controls found.</p><a href="{{ route('risk.controls.create') }}" class="mt-2 inline-block px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A]">Create First Control</a></td></tr>
        @endforelse
    </x-data-table>

    @if (isset($controls) && $controls->hasPages())
        <div class="mt-4 flex items-center justify-between">
            <span class="text-xs text-gray-500">Showing {{ $controls->firstItem() }}-{{ $controls->lastItem() }} of {{ $controls->total() }}</span>
            <div>{{ $controls->appends(request()->query())->links('vendor.pagination.tailwind') }}</div>
        </div>
    @endif
@endsection
