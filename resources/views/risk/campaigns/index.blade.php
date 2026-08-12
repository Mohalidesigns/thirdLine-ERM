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
    @if (session('success'))
        <div class="p-4 bg-green-50 border border-green-200 rounded-xl flex items-center gap-3">
            <span class="material-symbols-outlined text-green-600">check_circle</span>
            <span class="text-sm text-green-700">{{ session('success') }}</span>
        </div>
    @endif

    @if (session('error'))
        <div class="p-4 bg-red-50 border border-red-200 rounded-xl flex items-center gap-3">
            <span class="material-symbols-outlined text-red-600">error</span>
            <span class="text-sm text-red-700">{{ session('error') }}</span>
        </div>
    @endif

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-gray-900">All Assessment Campaigns</h1>
            <p class="text-sm text-gray-500 mt-1">{{ $total }} {{ Str::plural('campaign', $total) }} registered</p>
        </div>
        @can('campaign.create')
            <a href="{{ route('risk.campaigns.create') }}" wire:navigate class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-white rounded-lg text-sm font-medium hover:bg-opacity-90">
                <span class="material-symbols-outlined text-lg">add_circle</span> New Campaign
            </a>
        @endcan
    </div>

    {{-- WP-09: the status/type filters the old controller read but never
         rendered now come from the grid — see
         App\Grids\Definitions\CampaignsGrid. --}}
    <x-data-grid grid="campaigns" />
</div>
@endsection
