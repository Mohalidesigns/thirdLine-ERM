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

{{-- WP-13 — previewing a draft on a real node. The builder links here so an
     administrator can see what a composition WILL look like, with this node's
     data, before anyone else sees it. Loud on purpose: everything below is
     unpublished, and mistaking it for the live page is the one failure this
     mode can cause. --}}
@if($preview)
    <div class="mb-3 flex flex-wrap items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-3 py-2.5 text-xs text-blue-800">
        <span class="material-symbols-outlined text-[18px]">visibility</span>
        <span class="font-semibold">Preview</span>
        <span>
            “{{ $preview->name }}” as it would appear on
            {{ $preview->objectType?->name ?? 'every' }} nodes.
            @if($preview->is_published && $preview->hasUnpublishedChanges())
                Showing your unpublished edits — Business HQ still serves v{{ $preview->version }}.
            @elseif(!$preview->is_published)
                This dashboard is a draft and is not live anywhere.
            @else
                This matches the published v{{ $preview->version }}.
            @endif
        </span>
        <span class="flex-1"></span>
        <a href="{{ route('hq.show', $object) }}"
           class="rounded-md border border-blue-200 bg-white px-2.5 py-1 font-medium text-blue-700 hover:bg-blue-50">
            Exit preview
        </a>
        <a href="{{ route('risk.dashboards.edit', $preview) }}"
           class="rounded-md bg-blue-600 px-2.5 py-1 font-medium text-white hover:bg-blue-700">
            Back to editor
        </a>
    </div>
@elseif($previewRefused)
    <div class="mb-3 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs text-amber-800">
        <span class="material-symbols-outlined text-[18px]">info</span>
        <span>{{ $previewRefused }}</span>
    </div>
@endif

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
            {{-- WP-13 — this box used to say "No dashboard published for
                 Enterprise" and offer "Build one", which was true, useless and
                 usually wrong about what to do next. Nine times in ten a
                 dashboard for this type already exists and is sitting in draft.
                 So: name the type, say how many nodes share the problem, offer
                 the existing drafts first, and only then offer to create. --}}
            <div class="rounded-lg border border-dashed border-gray-300 bg-white p-10 text-center">
                <span class="material-symbols-outlined text-4xl text-gray-300">dashboard_customize</span>

                @php
                    $typeName = $objectType?->name ?? 'this object type';
                    $article = Str::startsWith(Str::lower($typeName), ['a','e','i','o','u']) ? 'an' : 'a';
                @endphp

                {{-- Three different causes wear the same empty box, and telling
                     them apart is the whole job. Role exclusion goes first
                     because it is the only one where every OTHER screen says
                     things are working. --}}
                @if($roleBlocked->isNotEmpty())
                    <h2 class="mt-2 text-sm font-semibold text-gray-700">
                        Published for {{ $typeName }} — but not for your roles
                    </h2>

                    <p class="mx-auto mt-1 max-w-lg text-xs text-gray-500">
                        {{ $roleBlocked->count() === 1 ? 'A dashboard is' : $roleBlocked->count().' dashboards are' }}
                        published for {{ $typeName }} nodes, composed for
                        {{ $roleBlocked->count() === 1 ? 'roles' : 'roles' }} you do not hold, so
                        Business HQ has nothing to show <em>you</em> here. Other users will see
                        {{ $roleBlocked->count() === 1 ? 'it' : 'them' }} on this node.
                    </p>

                    <ul class="mx-auto mt-3 max-w-lg space-y-1 text-left">
                        @foreach($roleBlocked as $blocked)
                            <li class="flex items-center gap-2 rounded-md border border-gray-100 bg-gray-50 px-3 py-1.5 text-xs">
                                <span class="material-symbols-outlined text-[15px] text-gray-400">lock_person</span>
                                <span class="min-w-0 flex-1 truncate font-medium text-gray-700">{{ $blocked->name }}</span>
                                <span class="shrink-0 text-[11px] text-gray-500">{{ $blocked->role_names }}</span>
                                @if($canManage)
                                    <a href="{{ route('risk.dashboards.edit', $blocked) }}"
                                       class="shrink-0 font-medium text-[--color-primary] hover:underline">Edit</a>
                                @endif
                            </li>
                        @endforeach
                    </ul>

                    @if($canManage)
                        <p class="mt-3 text-[11px] text-gray-400">
                            Add one of your roles to a dashboard above, or clear its role list so it shows to everyone.
                        </p>
                    @endif
                @else
                    <h2 class="mt-2 text-sm font-semibold text-gray-700">
                        Nothing published for {{ $typeName }} nodes
                    </h2>

                    @php
                        // Built here, not with an inline @if, so the sentence
                        // reads as one thing and Blade is not asked to splice
                        // punctuation around a directive.
                        $reach = $nodeCountForType > 1
                            ? sprintf(', and on the other %d %s %s too.', $nodeCountForType - 1, $typeName, Str::plural('node', $nodeCountForType - 1))
                            : ', and on every other node of that type.';
                    @endphp
                    <p class="mx-auto mt-1 max-w-md text-xs text-gray-500">
                        <span class="font-medium text-gray-700">{{ $object->name }}</span>
                        is {{ $article }} {{ $typeName }}.
                        Publish a dashboard bound to {{ $typeName }} and it appears here{{ $reach }}
                    </p>
                @endif

                @if($canManage && $roleBlocked->isEmpty())
                    <div class="mt-5 flex flex-wrap items-center justify-center gap-2">
                        @foreach($draftsForType as $draft)
                            <a href="{{ route('risk.dashboards.edit', $draft) }}"
                               class="inline-flex items-center gap-1 rounded-md border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                                <span class="material-symbols-outlined text-[16px] text-amber-500">edit_note</span>
                                Open draft “{{ Str::limit($draft->name, 28) }}”
                            </a>
                        @endforeach

                        <a href="{{ route('risk.dashboards.create', ['object_type_id' => $object->object_type_id]) }}"
                           class="inline-flex items-center gap-1 rounded-md bg-[--color-primary] px-3 py-1.5 text-xs font-medium text-white hover:opacity-90">
                            <span class="material-symbols-outlined text-[16px]">add</span>
                            Create {{ Str::startsWith(Str::lower($objectType?->name ?? ''), ['a','e','i','o','u']) ? 'an' : 'a' }}
                            {{ $objectType?->name ?? 'node' }} dashboard
                        </a>
                    </div>

                    @if($draftsForType->isNotEmpty())
                        <p class="mt-3 text-[11px] text-gray-400">
                            {{ $draftsForType->count() }}
                            {{ Str::plural('draft', $draftsForType->count()) }}
                            already {{ $draftsForType->count() === 1 ? 'exists' : 'exist' }} for this type —
                            publishing one is probably what you want.
                        </p>
                    @endif
                @endif
            </div>
        @else
            {{-- Which composition is on screen.
                 A node can render the system default, a dashboard composed for
                 its object type, or the tenant's own override of either — and
                 until WP-12 nothing on the page said which. That is a support
                 call waiting to happen ("why does Retail show different tabs
                 to Corporate?"), and it is the one thing an administrator
                 needs before clicking Edit layout. --}}
            <div class="mb-2 flex flex-wrap items-center gap-2 text-xs text-gray-500">
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
                {{-- Which VERSION is on screen. Without this the draft/live
                     split is invisible from the page it exists to protect. --}}
                @if($preview)
                    <span class="rounded bg-blue-50 px-1.5 py-0.5 text-[10px] font-medium text-blue-700">draft layout</span>
                @else
                    <span class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-500">v{{ $dashboard->version }}</span>
                @endif
            </div>

            {{-- Tab set from the dashboard definition. --}}
            <div class="mb-3 flex items-center justify-between gap-3">
                <nav class="flex max-w-full items-center gap-1 overflow-x-auto rounded-lg border border-gray-200 bg-white p-1 shadow-sm" aria-label="Dashboard tabs">
                    @foreach($tabs as $tab)
                        <a href="{{ route('hq.show', array_filter([$object, 'tab' => $tab['code'], 'preview' => $preview?->id])) }}"
                           class="whitespace-nowrap rounded-md px-3 py-1.5 text-xs font-medium
                                  {{ ($activeTab['code'] ?? null) === $tab['code'] ? 'bg-[--color-primary] text-white' : 'text-gray-600 hover:bg-gray-100' }}">
                            {{ $tab['label'] }}
                        </a>
                    @endforeach
                </nav>

                @can('dashboard.manage')
                    <a href="{{ route('risk.dashboards.edit', $dashboard) }}"
                       class="inline-flex shrink-0 items-center gap-1 rounded-md border border-gray-200 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-600 shadow-sm hover:bg-gray-50">
                        <span class="material-symbols-outlined text-[16px]">edit</span>
                        {{ $preview ? 'Back to editor' : 'Edit layout' }}
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
