@extends('layouts.app')

@section('title', 'Dashboards')
@section('page-section', 'Configuration')
@section('page-title', 'Dashboards')

@section('content')
{{-- WP-13 — this list used to be one flat roll of names with a green
     "Published" pill on the right. That pill was the problem: it said a
     dashboard was live without saying live WHERE, so a dashboard bound to
     Obligation — a type with no nodes, which Business HQ can never render —
     looked exactly as healthy as one bound to Enterprise. Splitting live from
     draft and printing each binding's reach is the whole change. --}}
<div class="mx-auto max-w-5xl">
    <div class="mb-4 flex items-start justify-between gap-4">
        <p class="max-w-2xl text-xs text-gray-500">
            A dashboard is a composition of widgets, bound to an object type and published to roles.
            Business HQ renders the published dashboard matching each node's type — so a dashboard is only
            ever seen if nodes of its type exist.
        </p>
        <form method="GET" action="{{ route('risk.dashboards.create') }}">
            <button type="submit"
                    class="inline-flex shrink-0 items-center gap-1 rounded-md bg-[--color-primary] px-3 py-1.5 text-xs font-semibold text-white hover:opacity-90">
                <span class="material-symbols-outlined text-[16px]">add</span> New dashboard
            </button>
        </form>
    </div>

    @foreach([
        ['Live in Business HQ', 'Rendering right now on every node of their type.', $live],
        ['Drafts', 'Not visible to anyone until published.', $drafts],
    ] as [$title, $blurb, $rows])
        @if($rows->isNotEmpty())
            <div class="mb-2 mt-6 flex items-baseline gap-2 first:mt-0">
                <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $title }}</h2>
                <span class="text-[11px] text-gray-400">{{ $blurb }}</span>
            </div>

            <ul class="divide-y divide-gray-100 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                @foreach($rows as $row)
                    @php
                        $dashboard = $row['dashboard'];
                        $option = $row['option'];
                        $warning = $row['warning'];
                        $tabCount = count($dashboard->tabList());
                    @endphp
                    <li>
                        <a href="{{ route('risk.dashboards.edit', $dashboard) }}"
                           class="flex items-start gap-3 px-4 py-3 hover:bg-gray-50">
                            <span class="material-symbols-outlined shrink-0 rounded-lg bg-gray-100 p-2 text-[20px] leading-none text-gray-500">dashboard</span>

                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-gray-800">{{ $dashboard->name }}</span>
                                <span class="block text-xs text-gray-400">
                                    {{ $tabCount }} {{ Str::plural('tab', $tabCount) }},
                                    {{ $dashboard->draftWidgetCount() }} {{ Str::plural('widget', $dashboard->draftWidgetCount()) }}
                                    · {{ empty($dashboard->role_ids) ? 'every role' : count($dashboard->role_ids).' '.Str::plural('role', count($dashboard->role_ids)) }}
                                    @if($dashboard->organization_id === null)
                                        · <span class="text-blue-600">system</span>
                                    @endif
                                </span>

                                {{-- Reach, in nodes. The number is the point:
                                     "Obligation · 0 nodes" is the whole bug
                                     report, printed where it was made. --}}
                                <span class="mt-1 block text-xs text-gray-600">
                                    Shows on
                                    <b>{{ $option['name'] ?? ($dashboard->objectType?->name ?? 'any object type') }}</b>
                                    @if($option)
                                        — {{ $option['node_count'] }} {{ Str::plural('node', $option['node_count']) }}
                                    @endif
                                </span>

                                @if($warning)
                                    <span class="mt-0.5 flex items-start gap-1 text-[11px] {{ $warning['tone'] === 'warn' ? 'text-amber-700' : 'text-gray-400' }}">
                                        @if($warning['tone'] === 'warn')
                                            <span class="material-symbols-outlined text-[13px] leading-[1.3]">warning</span>
                                        @endif
                                        <span>{{ $warning['text'] }}</span>
                                    </span>
                                @endif
                            </span>

                            <span class="shrink-0 pt-0.5 text-right">
                                @if($dashboard->is_published && $dashboard->hasUnpublishedChanges())
                                    <span class="rounded-full bg-blue-50 px-2 py-0.5 text-[10px] font-semibold text-blue-700">Unpublished changes</span>
                                @elseif($dashboard->is_published)
                                    <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">Published · v{{ $dashboard->version }}</span>
                                @else
                                    <span class="rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-700">Draft</span>
                                @endif

                                @if($dashboard->is_published && $option && $option['first_node_id'])
                                    <span class="mt-1 block text-[11px] text-gray-400">
                                        {{ $dashboard->publishedWidgetCount() }} live {{ Str::plural('widget', $dashboard->publishedWidgetCount()) }}
                                    </span>
                                @endif
                            </span>

                            <span class="material-symbols-outlined shrink-0 pt-1 text-[18px] text-gray-300">chevron_right</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    @endforeach

    @if($live->isEmpty() && $drafts->isEmpty())
        <div class="rounded-xl border border-dashed border-gray-300 bg-white px-4 py-12 text-center">
            <span class="material-symbols-outlined text-4xl text-gray-300">dashboard_customize</span>
            <p class="mt-2 text-sm text-gray-500">No dashboards yet. Create the first one.</p>
        </div>
    @elseif($live->isEmpty())
        {{-- Every dashboard is a draft: Business HQ is empty everywhere, and
             that is worth saying once at the bottom of the list rather than
             letting the admin discover it node by node. --}}
        <p class="mt-4 flex items-center gap-1.5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] text-amber-800">
            <span class="material-symbols-outlined text-[15px]">info</span>
            Nothing is published, so every node in Business HQ shows an empty state. Publish one of the drafts above.
        </p>
    @endif
</div>
@endsection
