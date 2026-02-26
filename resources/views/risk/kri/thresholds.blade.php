@extends('layouts.app')

@section('title', 'KRI Threshold Management - GRC Risk Management')
@section('page-section', 'KRI Monitoring')
@section('page-title', 'Threshold Management')

@section('breadcrumbs')
    <a href="{{ url('/risk/dashboard') }}" class="hover:text-[#1A365D]">Home</a>
    <span class="text-gray-400">/</span>
    <a href="{{ route('risk.kri.index') }}" class="hover:text-[#1A365D]">KRI Monitoring</a>
    <span class="text-gray-400">/</span>
    <span class="text-gray-700 font-medium">Threshold Management</span>
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
            <h1 class="text-2xl font-bold text-[#1A365D]">KRI Threshold Management</h1>
            <p class="text-sm text-gray-500 mt-1">Manage traffic light thresholds for all key risk indicators</p>
        </div>
        <button type="submit" form="thresholdForm" class="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-2">
            <span class="material-symbols-outlined text-lg">save</span> Save All Changes
        </button>
    </div>

    <form method="POST" action="{{ route('risk.kri.thresholds.update') }}" id="thresholdForm">
        @csrf
        @method('PUT')

        <x-data-table>
            <x-slot name="head">
                <th>KRI Name</th>
                <th>Category</th>
                <th>Current Value</th>
                <th class="bg-green-50">
                    <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full bg-green-500"></span> Green (Max)</span>
                </th>
                <th class="bg-yellow-50">
                    <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full bg-yellow-500"></span> Amber (Max)</span>
                </th>
                <th class="bg-red-50">
                    <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full bg-red-500"></span> Red (Trigger)</span>
                </th>
                <th>Inverse</th>
                <th>Status</th>
            </x-slot>

            @forelse (($kris ?? []) as $kri)
                <tr class="hover:bg-blue-50/50">
                    <input type="hidden" name="kris[{{ $kri->id }}][id]" value="{{ $kri->id }}">
                    <td class="font-medium text-[#1A365D]">{{ $kri->name }}</td>
                    <td class="text-xs">{{ $kri->category ?? '-' }}</td>
                    <td class="font-semibold {{ ($kri->current_status ?? 'green') === 'red' ? 'text-red-600' : (($kri->current_status ?? 'green') === 'amber' ? 'text-yellow-600' : 'text-green-600') }}">
                        {{ $kri->current_value ?? '-' }}{{ $kri->unit ?? '' }}
                    </td>
                    <td class="bg-green-50/50">
                        <input type="number" step="0.01" name="kris[{{ $kri->id }}][green_threshold]" value="{{ $kri->green_threshold }}"
                               class="w-full px-2 py-1 border border-green-300 rounded text-sm text-center focus:ring-1 focus:ring-green-400">
                    </td>
                    <td class="bg-yellow-50/50">
                        <input type="number" step="0.01" name="kris[{{ $kri->id }}][amber_threshold]" value="{{ $kri->amber_threshold }}"
                               class="w-full px-2 py-1 border border-yellow-300 rounded text-sm text-center focus:ring-1 focus:ring-yellow-400">
                    </td>
                    <td class="bg-red-50/50">
                        <input type="number" step="0.01" name="kris[{{ $kri->id }}][red_threshold]" value="{{ $kri->red_threshold }}"
                               class="w-full px-2 py-1 border border-red-300 rounded text-sm text-center focus:ring-1 focus:ring-red-400">
                    </td>
                    <td class="text-center">
                        <input type="checkbox" name="kris[{{ $kri->id }}][inverse]" value="1" class="rounded border-gray-300 text-[#1A365D]" {{ $kri->inverse_threshold ? 'checked' : '' }}>
                    </td>
                    <td>
                        <span class="flex items-center gap-1">
                            <span class="w-2.5 h-2.5 rounded-full {{ ($kri->current_status ?? 'green') === 'red' ? 'bg-red-500' : (($kri->current_status ?? 'green') === 'amber' ? 'bg-yellow-500' : 'bg-green-500') }}"></span>
                            <span class="text-xs">{{ ucfirst($kri->current_status ?? 'green') }}</span>
                        </span>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="text-center py-12">
                        <span class="material-symbols-outlined text-4xl text-gray-300 mb-2 block">tune</span>
                        <p class="text-sm text-gray-500">No KRIs configured. <a href="{{ route('risk.kri.create') }}" class="text-[#1A365D] hover:underline">Create your first KRI</a></p>
                    </td>
                </tr>
            @endforelse
        </x-data-table>
    </form>
@endsection
