{{-- WP-08 TASK 3 — the builder surface. GridStack owns the drag/resize over
     a wire:ignore container; every change reports back to updateLayout().
     The grid is rebuilt (builder-grid-reload) whenever Livewire changes the
     set of items, because GridStack cannot morph.

     WP-13 — three things this screen would not tell you, and now does:

       WHERE IT SHOWS UP. The object-type selector was a bare list of names.
       Binding a dashboard to Obligation — not a node type, zero objects —
       was one keystroke, published cleanly, and appeared nowhere. Every
       option now carries its node count, and the header says in words what
       the current binding means.

       WHAT IS LIVE. `tabs` used to be the only layout, so a half-finished
       drag was already on the board's HQ page. The draft and the published
       layout are separate columns now, and the header shows which of the two
       Business HQ is serving.

       WHAT A WIDGET IS. The library listed a name and a renderer code
       ("grouped_bar_3"). It now searches, groups by category, shows the
       description, and marks what is already placed. --}}
<div class="flex gap-4" x-data @builder-saved.window="$dispatch('notify', { message: $event.detail.message })">

    {{-- ───────────────────────────── Widget library ───────────────────────────── --}}
    <aside class="w-72 shrink-0">
        <div class="sticky top-20 flex max-h-[calc(100vh-6rem)] flex-col rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-100 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500">
                Widget library
            </div>

            <div class="border-b border-gray-100 p-2">
                <div class="relative">
                    <span class="material-symbols-outlined pointer-events-none absolute left-2 top-1.5 text-[18px] text-gray-400">search</span>
                    <input type="search" wire:model.live.debounce.250ms="search"
                           placeholder="Search widgets"
                           aria-label="Search widgets"
                           class="w-full rounded-md border-gray-200 py-1.5 pl-8 pr-2 text-xs focus:border-[--color-primary] focus:ring-[--color-primary]" />
                </div>

                <div class="mt-2 flex flex-wrap gap-1">
                    <button type="button" wire:click="$set('category', '')"
                            class="rounded-full border px-2 py-0.5 text-[11px] {{ $category === '' ? 'border-gray-800 bg-gray-800 text-white' : 'border-gray-200 text-gray-600 hover:border-gray-400' }}">
                        All
                    </button>
                    @foreach($categories as $name)
                        <button type="button" wire:click="$set('category', @js($name))"
                                class="rounded-full border px-2 py-0.5 text-[11px] {{ $category === $name ? 'border-gray-800 bg-gray-800 text-white' : 'border-gray-200 text-gray-600 hover:border-gray-400' }}">
                            {{ $name }}
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="min-h-0 flex-1 overflow-auto">
                @forelse($palette as $group => $widgets)
                    <div class="px-3 pb-1 pt-3 text-[11px] font-semibold uppercase tracking-wide text-gray-400">{{ $group }}</div>
                    <ul class="divide-y divide-gray-50">
                        @foreach($widgets as $widget)
                            @php $placed = $placedWidgetIds->has((int) $widget->id); @endphp
                            <li>
                                <button type="button" wire:click="addWidget({{ $widget->id }})"
                                        class="flex w-full items-start gap-2 px-3 py-2 text-left hover:bg-gray-50"
                                        title="{{ $placed ? 'Already on this dashboard — click to add another' : 'Add to the active tab' }}">
                                    <span class="material-symbols-outlined mt-px shrink-0 text-[16px] {{ $placed ? 'text-emerald-500' : 'text-gray-400' }}">
                                        {{ $placed ? 'check_circle' : 'add_box' }}
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-xs font-medium text-gray-700">{{ $widget->name }}</span>
                                        <span class="block truncate text-[10px] text-gray-400">
                                            {{ $widget->description ?: str_replace('_', ' ', $widget->widget_type) }}
                                        </span>
                                    </span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @empty
                    <p class="px-3 py-8 text-center text-xs text-gray-400">
                        @if($search !== '')
                            No widgets match “{{ $search }}”.
                        @else
                            No widgets in this category.
                        @endif
                    </p>
                @endforelse
            </div>
        </div>
    </aside>

    <div class="min-w-0 flex-1">

        {{-- ───────────────────────────── Meta bar ───────────────────────────── --}}
        <div class="mb-3 rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-center gap-3 px-4 py-3">
                <input type="text" wire:model.blur="name"
                       class="w-64 rounded-md border-gray-200 text-sm font-semibold focus:border-[--color-primary] focus:ring-[--color-primary]" />

                <div class="flex items-center gap-1.5 text-xs text-gray-500">
                    <span>Shows on</span>
                    <select wire:model.change="objectTypeId" class="rounded-md border-gray-200 text-xs">
                        @foreach($objectTypes as $type)
                            <option value="{{ $type['id'] }}">
                                {{ $type['name'] }}@if(!$type['is_node_type']) — not a node type @elseif($type['node_count'] === 0) — no nodes yet @else ({{ $type['node_count'] }}) @endif
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                    <button type="button" @click="open = !open"
                            class="inline-flex items-center gap-1 rounded-md border border-gray-200 px-2.5 py-1.5 text-xs text-gray-600 hover:bg-gray-50">
                        <span class="material-symbols-outlined text-[15px]">group</span>
                        {{ count($roleIds) === 0 ? 'All roles' : count($roleIds).' roles' }}
                    </button>
                    <div x-show="open" x-cloak
                         class="absolute z-20 mt-1 w-56 rounded-md border border-gray-200 bg-white p-2 shadow-lg">
                        @foreach($roles as $role)
                            <label class="flex items-center gap-2 rounded px-2 py-1 text-xs text-gray-700 hover:bg-gray-50">
                                <input type="checkbox" @checked(in_array($role->id, $roleIds, true))
                                       wire:click="toggleRole({{ $role->id }})"
                                       class="rounded border-gray-300 text-[--color-primary] focus:ring-[--color-primary]" />
                                {{ $role->name }}
                            </label>
                        @endforeach
                    </div>
                </div>

                <span class="flex-1"></span>

                {{-- State, in the order it is asked about: is it live, and is
                     what I am looking at what is live? --}}
                @if($isPublished && $hasUnpublishedChanges)
                    <span class="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2 py-1 text-[10px] font-semibold text-blue-700">
                        <span class="material-symbols-outlined text-[13px]">edit</span> Unpublished changes
                    </span>
                    <span class="text-[11px] text-gray-400">v{{ $version }} is live</span>
                @elseif($isPublished)
                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-1 text-[10px] font-semibold text-emerald-700">
                        <span class="material-symbols-outlined text-[13px]">check_circle</span> Published
                    </span>
                    <span class="text-[11px] text-gray-400">v{{ $version }}</span>
                @else
                    <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-1 text-[10px] font-semibold text-amber-700">Draft</span>
                    <span class="text-[11px] text-gray-400">not live</span>
                @endif

                @if($binding && $binding['first_node_id'])
                    <a href="{{ route('hq.show', ['object' => $binding['first_node_id'], 'preview' => $dashboardId]) }}"
                       class="inline-flex items-center gap-1 rounded-md border border-gray-200 px-2.5 py-1.5 text-xs text-gray-600 hover:bg-gray-50"
                       title="See this draft on a real {{ $binding['name'] }} node, with real data">
                        <span class="material-symbols-outlined text-[15px]">visibility</span> Preview
                    </a>
                @endif

                <button type="button" wire:click="duplicate"
                        class="inline-flex items-center gap-1 rounded-md border border-gray-200 px-2.5 py-1.5 text-xs text-gray-600 hover:bg-gray-50">
                    <span class="material-symbols-outlined text-[15px]">content_copy</span> Save as template
                </button>

                @if($isPublished && $hasUnpublishedChanges)
                    <button type="button" wire:click="discardChanges"
                            wire:confirm="Discard your unpublished changes and go back to the live v{{ $version }}?"
                            class="rounded-md border border-gray-200 px-2.5 py-1.5 text-xs text-gray-600 hover:bg-gray-50">
                        Discard changes
                    </button>
                @elseif($isPublished)
                    <button type="button" wire:click="unpublish"
                            wire:confirm="Unpublish? Business HQ will stop showing this on every {{ $binding['name'] ?? 'matching' }} node."
                            class="rounded-md border border-gray-200 px-2.5 py-1.5 text-xs text-gray-600 hover:bg-gray-50">
                        Unpublish
                    </button>
                @endif

                @php
                    // Publishing an unchanged, already-live dashboard does
                    // nothing but burn a version number, so the button stops
                    // being the primary action once there is nothing to say.
                    $canPublish = ! $isPublished || $hasUnpublishedChanges;
                    $blocked = $binding !== null && ! $binding['renderable'];
                @endphp
                <button type="button" wire:click="publish" @disabled(!$canPublish || $blocked)
                        title="{{ $blocked ? 'This binding reaches no nodes — nobody would see it.' : ($canPublish ? 'Copy the draft to Business HQ' : 'Nothing to publish') }}"
                        class="inline-flex items-center gap-1 rounded-md px-3 py-1.5 text-xs font-semibold
                               {{ $canPublish && !$blocked
                                    ? 'bg-[--color-primary] text-white hover:opacity-90'
                                    : 'cursor-not-allowed border border-gray-200 bg-gray-50 text-gray-400' }}">
                    <span class="material-symbols-outlined text-[15px]">publish</span>
                    {{ $isPublished ? 'Publish changes' : 'Publish' }}
                </button>
            </div>

            {{-- The sentence the old builder never said. This is where the
                 Obligation mistake becomes visible before it is published,
                 rather than as an empty Business HQ page afterwards. --}}
            @if($binding)
                <div class="border-t border-gray-100 px-4 py-2 text-[11px]
                            {{ $binding['renderable'] ? 'text-gray-500' : 'bg-amber-50 text-amber-800' }}">
                    @if(! $binding['is_node_type'])
                        <span class="material-symbols-outlined align-[-3px] text-[14px]">warning</span>
                        <b>{{ $binding['name'] }}</b> is not a node type, so Business HQ has no page to render this on.
                        Bind it to a type that appears in the organisation tree.
                    @elseif($binding['node_count'] === 0)
                        <span class="material-symbols-outlined align-[-3px] text-[14px]">warning</span>
                        No <b>{{ $binding['name'] }}</b> nodes exist yet — publishing this would put it nowhere.
                    @else
                        Renders on
                        <b>{{ $binding['node_count'] }} {{ Str::plural('node', $binding['node_count']) }}</b>
                        @if($binding['id'] !== null) of type <b>{{ $binding['name'] }}</b> @else (every node with no dashboard of its own) @endif
                        · visible to {{ count($roleIds) === 0 ? 'every role' : count($roleIds).' '.Str::plural('role', count($roleIds)) }}
                        @if($isPublished)
                            · Business HQ is serving v{{ $version }} with {{ $publishedWidgetCount }} {{ Str::plural('widget', $publishedWidgetCount) }}
                        @endif

                        @if($binding['published'] && $binding['published']->id !== $dashboardId)
                            <span class="ml-1 rounded bg-amber-50 px-1.5 py-0.5 text-amber-800">
                                “{{ $binding['published']->name }}” is already published for this type — whichever matches the viewer's role wins.
                            </span>
                        @endif
                    @endif
                </div>
            @endif
        </div>

        {{-- ───────────────────────────── Tab strip ───────────────────────────── --}}
        <div class="mb-3 flex items-center gap-1 overflow-x-auto rounded-lg border border-gray-200 bg-white p-1 shadow-sm">
            @foreach($tabs as $tab)
                <div class="group flex items-center {{ $activeTab === $tab['code'] ? 'rounded-md bg-[--color-primary] text-white' : 'text-gray-600' }}">
                    <button type="button" wire:click="selectTab('{{ $tab['code'] }}')"
                            x-data="{ editing: false }"
                            @dblclick="editing = true; $nextTick(() => $refs.label.focus())"
                            class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium {{ $activeTab === $tab['code'] ? '' : 'hover:bg-gray-100 rounded-md' }}">
                        <span x-show="!editing">{{ $tab['label'] }}</span>
                        {{-- Widget count per tab: an empty tab on a published
                             dashboard is a blank page for whoever opens it. --}}
                        <span x-show="!editing"
                              class="rounded-full px-1.5 text-[10px] {{ $activeTab === $tab['code'] ? 'bg-white/25' : 'bg-gray-100 text-gray-500' }}">
                            {{ count($tab['layout']) }}
                        </span>
                        <input x-show="editing" x-cloak x-ref="label" type="text" value="{{ $tab['label'] }}"
                               @keydown.enter="$wire.renameTab('{{ $tab['code'] }}', $event.target.value); editing = false"
                               @blur="$wire.renameTab('{{ $tab['code'] }}', $event.target.value); editing = false"
                               class="w-24 rounded border-0 bg-white/20 px-1 py-0 text-xs text-inherit" />
                    </button>
                    @if(count($tabs) > 1)
                        <button type="button" wire:click="removeTab('{{ $tab['code'] }}')"
                                wire:confirm="Delete “{{ $tab['label'] }}” and its {{ count($tab['layout']) }} widget(s)?"
                                class="pr-2 opacity-0 group-hover:opacity-70 hover:!opacity-100" title="Remove tab">
                            <span class="material-symbols-outlined text-[13px] leading-none">close</span>
                        </button>
                    @endif
                </div>
            @endforeach
            <button type="button" wire:click="addTab" class="ml-1 rounded-md px-2 py-1.5 text-gray-400 hover:bg-gray-100" title="Add tab">
                <span class="material-symbols-outlined text-[16px] leading-none">add</span>
            </button>
            <span class="ml-auto pr-2 text-[10px] text-gray-400">Double-click a tab to rename · drag &amp; resize tiles below</span>
        </div>

        {{-- ───────────────────────────── The grid ───────────────────────────── --}}
        @php $current = collect($tabs)->firstWhere('code', $activeTab); @endphp
        <div wire:ignore.self data-dashboard-builder wire:key="grid-{{ $activeTab }}-{{ count($current['layout'] ?? []) }}">
            <div class="grid-stack rounded-lg border border-dashed border-gray-300 bg-gray-50/60 p-1" data-builder-grid>
                @foreach(($current['layout'] ?? []) as $position => $placement)
                    @php $widget = $widgetsById[$placement['widget_id']] ?? null; @endphp
                    <div class="grid-stack-item" data-position="{{ $position }}"
                         gs-x="{{ (int) ($placement['x'] ?? 0) }}" gs-y="{{ (int) ($placement['y'] ?? 0) }}"
                         gs-w="{{ (int) ($placement['w'] ?? 4) }}" gs-h="{{ (int) ($placement['h'] ?? 3) }}"
                         gs-min-w="{{ (int) ($widget->min_w ?? 2) }}" gs-min-h="{{ (int) ($widget->min_h ?? 2) }}">
                        <div class="grid-stack-item-content !overflow-visible">
                            <div class="flex h-full flex-col rounded-lg border border-gray-200 bg-white shadow-sm">
                                <div class="flex items-center justify-between gap-1 border-b border-gray-100 px-3 py-2">
                                    <input type="text"
                                           value="{{ $placement['overrides']['title'] ?? '' }}"
                                           placeholder="{{ $widget->name ?? 'Missing widget' }}"
                                           @change="$wire.overrideTitle('{{ $activeTab }}', {{ $position }}, $event.target.value)"
                                           class="w-full truncate rounded border-0 p-0 text-xs font-semibold text-gray-800 placeholder-gray-400 focus:ring-0"
                                           title="Title override — blank uses the widget's own name" />
                                    <button type="button" wire:click="removeWidget('{{ $activeTab }}', {{ $position }})"
                                            class="shrink-0 text-gray-300 hover:text-red-500" title="Remove">
                                        <span class="material-symbols-outlined text-[16px] leading-none">delete</span>
                                    </button>
                                </div>
                                <div class="flex flex-1 flex-col items-center justify-center gap-1 p-3 text-gray-300">
                                    <span class="material-symbols-outlined text-2xl">insert_chart</span>
                                    <span class="text-[10px] uppercase tracking-wide">{{ str_replace('_', ' ', $widget->widget_type ?? '?') }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
            @if(($current['layout'] ?? []) === [])
                <p class="p-6 text-center text-xs text-gray-400">
                    “{{ $current['label'] ?? 'This tab' }}” is empty. Add widgets from the library on the left.
                </p>
            @endif
        </div>
    </div>
</div>
