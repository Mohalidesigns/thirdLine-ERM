@extends('layouts.app')

@section('title', 'Dashboards')
@section('page-section', 'Configuration')
@section('page-title', 'Dashboards')

@section('content')
<div class="mx-auto max-w-4xl">
    <div class="mb-4 flex items-center justify-between">
        <p class="text-xs text-gray-500">
            A dashboard is a composition of widgets, bound to an object type and published to roles.
            Business HQ renders the published dashboard matching each node's type.
        </p>
        <form method="GET" action="{{ route('risk.dashboards.create') }}">
            <button type="submit"
                    class="inline-flex items-center gap-1 rounded-md bg-[--color-primary] px-3 py-1.5 text-xs font-semibold text-white hover:opacity-90">
                <span class="material-symbols-outlined text-[16px]">add</span> New dashboard
            </button>
        </form>
    </div>

    <ul class="divide-y divide-gray-100 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
        @forelse($dashboards as $dashboard)
            <li>
                <a href="{{ route('risk.dashboards.edit', $dashboard) }}" class="flex items-center gap-3 px-4 py-3 hover:bg-gray-50">
                    <span class="material-symbols-outlined shrink-0 rounded-lg bg-gray-100 p-2 text-[20px] leading-none text-gray-500">dashboard</span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-gray-800">{{ $dashboard->name }}</span>
                        <span class="block text-xs text-gray-400">
                            {{ $dashboard->objectType?->name ?? 'Any object type' }}
                            · {{ count($dashboard->tabList()) }} {{ Str::plural('tab', count($dashboard->tabList())) }}
                            · v{{ $dashboard->version }}
                        </span>
                    </span>
                    @if($dashboard->is_published)
                        <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">Published</span>
                    @else
                        <span class="rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-700">Draft</span>
                    @endif
                    <span class="material-symbols-outlined shrink-0 text-[18px] text-gray-300">chevron_right</span>
                </a>
            </li>
        @empty
            <li class="px-4 py-10 text-center text-sm text-gray-400">No dashboards yet. Create the first one.</li>
        @endforelse
    </ul>
</div>
@endsection
