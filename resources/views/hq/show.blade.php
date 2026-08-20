@extends('layouts.app')

@section('title', $object->name.' · Business HQ')
@section('page-section', 'Business HQ')
@section('page-title', $object->name)

@section('breadcrumbs')
    <nav class="flex items-center gap-1 text-xs text-gray-500" aria-label="Breadcrumb">
        <a href="{{ route('hq.index') }}" class="hover:text-gray-800">Business HQ</a>
        @foreach($ancestors as $ancestor)
            <span class="text-gray-300">›</span>
            <a href="{{ route('hq.show', $ancestor) }}" class="hover:text-gray-800">{{ $ancestor->name }}</a>
        @endforeach
        <span class="text-gray-300">›</span>
        <span class="font-medium text-gray-800">{{ $object->name }}</span>
    </nav>
@endsection

@section('content')
<div class="flex gap-4" x-data="{ treeOpen: true }">
    {{-- Tree navigator: the org graph. Clicking a node re-renders every
         widget in that node's context — same dashboard, new answers. --}}
    <aside class="shrink-0 transition-all" :class="treeOpen ? 'w-64' : 'w-8'">
        <div class="sticky top-20 rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-gray-100 px-3 py-2">
                <span x-show="treeOpen" class="text-xs font-semibold uppercase tracking-wide text-gray-500">Organization</span>
                <button type="button" @click="treeOpen = !treeOpen" class="text-gray-400 hover:text-gray-600"
                        :title="treeOpen ? 'Collapse' : 'Expand'">
                    <span class="material-symbols-outlined text-[18px]" x-text="treeOpen ? 'left_panel_close' : 'left_panel_open'"></span>
                </button>
            </div>
            <div x-show="treeOpen" class="max-h-[70vh] overflow-auto p-2">
                @include('hq.partials.tree', ['nodes' => $tree, 'currentId' => $object->id])
            </div>
        </div>
    </aside>

    <div class="min-w-0 flex-1">
        @if($dashboard === null)
            <div class="rounded-lg border border-dashed border-gray-300 bg-white p-10 text-center">
                <span class="material-symbols-outlined text-4xl text-gray-300">dashboard_customize</span>
                <h2 class="mt-2 text-sm font-semibold text-gray-700">No dashboard published for {{ $objectType?->name ?? 'this type' }}</h2>
                <p class="mt-1 text-xs text-gray-500">
                    A dashboard bound to this object type will render here for every node of that type.
                </p>
                @can('dashboard.manage')
                    <a href="{{ route('risk.dashboards.index') }}"
                       class="mt-4 inline-flex items-center gap-1 rounded-md bg-[--color-primary] px-3 py-1.5 text-xs font-medium text-white hover:opacity-90">
                        <span class="material-symbols-outlined text-[16px]">add</span> Build one
                    </a>
                @endcan
            </div>
        @else
            {{-- Which composition is on screen.
                 A node can render the system default, a dashboard composed for
                 its object type, or the tenant's own override of either — and
                 until WP-12 nothing on the page said which. That is a support
                 call waiting to happen ("why does Retail show different tabs
                 to Corporate?"), and it is the one thing an administrator
                 needs before clicking Edit layout. --}}
            <div class="mb-2 flex items-center gap-2 text-xs text-gray-500">
                <span class="material-symbols-outlined text-[15px] text-gray-400">dashboard</span>
                <span class="font-medium text-gray-700">{{ $dashboard->name }}</span>
                @if($dashboard->object_type_id === null)
                    <span class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-500">default for every type</span>
                @else
                    <span class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-500">composed for {{ $dashboard->objectType?->name ?? 'this type' }}</span>
                @endif
                @if($dashboard->organization_id === null)
                    <span class="rounded bg-blue-50 px-1.5 py-0.5 text-[10px] text-blue-700">system</span>
                @endif
            </div>

            {{-- Tab set from the dashboard definition. --}}
            <div class="mb-3 flex items-center justify-between gap-3">
                <nav class="flex max-w-full items-center gap-1 overflow-x-auto rounded-lg border border-gray-200 bg-white p-1 shadow-sm" aria-label="Dashboard tabs">
                    @foreach($tabs as $tab)
                        <a href="{{ route('hq.show', [$object, 'tab' => $tab['code']]) }}"
                           class="whitespace-nowrap rounded-md px-3 py-1.5 text-xs font-medium
                                  {{ ($activeTab['code'] ?? null) === $tab['code'] ? 'bg-[--color-primary] text-white' : 'text-gray-600 hover:bg-gray-100' }}">
                            {{ $tab['label'] }}
                        </a>
                    @endforeach
                </nav>

                @can('dashboard.manage')
                    <a href="{{ route('risk.dashboards.edit', $dashboard) }}"
                       class="inline-flex shrink-0 items-center gap-1 rounded-md border border-gray-200 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-600 shadow-sm hover:bg-gray-50">
                        <span class="material-symbols-outlined text-[16px]">edit</span> Edit layout
                    </a>
                @endcan
            </div>

            @if($activeTab === null || ($activeTab['layout'] ?? []) === [])
                <div class="rounded-lg border border-dashed border-gray-300 bg-white p-10 text-center text-xs text-gray-500">
                    This tab has no widgets yet.
                </div>
            @else
                {{-- 12-column dashboard grid. Placement is inline style because
                     x/y/w/h are data, not a finite class list Tailwind could
                     see; the .hq-grid media rule collapses it to one column on
                     small screens. --}}
                <div class="hq-grid grid gap-4" style="grid-template-columns: repeat(12, minmax(0, 1fr)); grid-auto-rows: 92px;">
                    @foreach($activeTab['layout'] as $placement)
                        @php
                            $x = max(0, min(11, (int) ($placement['x'] ?? 0)));
                            $w = max(2, min(12 - $x, (int) ($placement['w'] ?? 4)));
                            $y = max(0, (int) ($placement['y'] ?? 0));
                            $h = max(2, (int) ($placement['h'] ?? 3));
                        @endphp
                        <div class="hq-cell min-w-0"
                             style="grid-column: {{ $x + 1 }} / span {{ $w }}; grid-row: {{ $y + 1 }} / span {{ $h }};">
                            <livewire:widgets.widget-panel
                                :widget-id="(int) $placement['widget_id']"
                                :node-id="(int) $object->id"
                                :overrides="$placement['overrides'] ?? []"
                                :wire:key="'w-'.$dashboard->id.'-'.$activeTab['code'].'-'.$placement['widget_id'].'-'.$object->id" />
                        </div>
                    @endforeach
                </div>
            @endif
        @endif
    </div>
</div>
@endsection
