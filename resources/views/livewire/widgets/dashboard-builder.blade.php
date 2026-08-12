{{-- WP-08 TASK 3 — the builder surface. GridStack owns the drag/resize over
     a wire:ignore container; every change reports back to updateLayout().
     The grid is rebuilt (builder-grid-reload) whenever Livewire changes the
     set of items, because GridStack cannot morph. --}}
<div class="flex gap-4" x-data @builder-saved.window="$dispatch('notify', { message: $event.detail.message })">

    {{-- Palette --}}
    <aside class="w-60 shrink-0">
        <div class="sticky top-20 rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-100 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500">
                Widget library
            </div>
            <ul class="max-h-[65vh] divide-y divide-gray-50 overflow-auto">
                @foreach($palette as $widget)
                    <li>
                        <button type="button" wire:click="addWidget({{ $widget->id }})"
                                class="flex w-full items-center gap-2 px-3 py-2 text-left text-xs text-gray-700 hover:bg-gray-50"
                                title="Add to the active tab">
                            <span class="material-symbols-outlined shrink-0 text-[16px] text-gray-400">add_box</span>
                            <span class="min-w-0">
                                <span class="block truncate font-medium">{{ $widget->name }}</span>
                                <span class="block text-[10px] text-gray-400">{{ $widget->widget_type }}</span>
                            </span>
                        </button>
                    </li>
                @endforeach
            </ul>
        </div>
    </aside>

    <div class="min-w-0 flex-1">
        {{-- Meta bar --}}
        <div class="mb-3 flex flex-wrap items-center gap-3 rounded-lg border border-gray-200 bg-white px-4 py-3 shadow-sm">
            <input type="text" wire:model.blur="name"
                   class="w-64 rounded-md border-gray-200 text-sm font-semibold focus:border-[--color-primary] focus:ring-[--color-primary]" />

            <select wire:model.change="objectTypeId" class="rounded-md border-gray-200 text-xs">
                <option value="">Any object type (default dashboard)</option>
                @foreach(\App\Models\ObjectType::query()->orderBy('name')->get(['id','name']) as $type)
                    <option value="{{ $type->id }}">{{ $type->name }}</option>
                @endforeach
            </select>

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

            <button type="button" wire:click="duplicate"
                    class="inline-flex items-center gap-1 rounded-md border border-gray-200 px-2.5 py-1.5 text-xs text-gray-600 hover:bg-gray-50">
                <span class="material-symbols-outlined text-[15px]">content_copy</span> Save as template
            </button>

            @if($isPublished)
                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-1 text-[10px] font-semibold text-emerald-700">
                    <span class="material-symbols-outlined text-[13px]">check_circle</span> Published
                </span>
                <button type="button" wire:click="unpublish"
                        class="rounded-md border border-gray-200 px-2.5 py-1.5 text-xs text-gray-600 hover:bg-gray-50">
                    Unpublish
                </button>
            @else
                <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-1 text-[10px] font-semibold text-amber-700">Draft</span>
            @endif
            <button type="button" wire:click="publish"
                    class="inline-flex items-center gap-1 rounded-md bg-[--color-primary] px-3 py-1.5 text-xs font-semibold text-white hover:opacity-90">
                <span class="material-symbols-outlined text-[15px]">publish</span> Publish
            </button>
        </div>

        {{-- Tab strip --}}
        <div class="mb-3 flex items-center gap-1 overflow-x-auto rounded-lg border border-gray-200 bg-white p-1 shadow-sm">
            @foreach($tabs as $tab)
                <div class="group flex items-center {{ $activeTab === $tab['code'] ? 'rounded-md bg-[--color-primary] text-white' : 'text-gray-600' }}">
                    <button type="button" wire:click="selectTab('{{ $tab['code'] }}')"
                            x-data="{ editing: false }"
                            @dblclick="editing = true; $nextTick(() => $refs.label.focus())"
                            class="px-3 py-1.5 text-xs font-medium {{ $activeTab === $tab['code'] ? '' : 'hover:bg-gray-100 rounded-md' }}">
                        <span x-show="!editing">{{ $tab['label'] }}</span>
                        <input x-show="editing" x-cloak x-ref="label" type="text" value="{{ $tab['label'] }}"
                               @keydown.enter="$wire.renameTab('{{ $tab['code'] }}', $event.target.value); editing = false"
                               @blur="$wire.renameTab('{{ $tab['code'] }}', $event.target.value); editing = false"
                               class="w-24 rounded border-0 bg-white/20 px-1 py-0 text-xs text-inherit" />
                    </button>
                    @if(count($tabs) > 1)
                        <button type="button" wire:click="removeTab('{{ $tab['code'] }}')"
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

        {{-- The grid. Rebuilt by builder.js on every builder-grid-reload. --}}
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
                                <div class="flex flex-1 items-center justify-center gap-2 p-3 text-gray-300">
                                    <span class="material-symbols-outlined text-2xl">insert_chart</span>
                                    <span class="text-[10px] uppercase tracking-wide">{{ $widget->widget_type ?? '?' }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
            @if(($current['layout'] ?? []) === [])
                <p class="p-6 text-center text-xs text-gray-400">Add widgets from the library on the left.</p>
            @endif
        </div>
    </div>
</div>
