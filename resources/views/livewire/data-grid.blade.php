{{--
    WP-09 TASK 2: the shared data grid (see app/Livewire/DataGrid.php).

    Layout: toolbar (search · filters · saved views · column chooser · export)
    → bulk bar → table (md+) / card list (mobile) → pagination. Keyboard:
    rows are focusable; ↑/↓ move, Enter opens the row's primary link.
--}}
@php
    $ragClasses = [
        'red' => 'bg-red-100 text-red-700',
        'amber' => 'bg-yellow-100 text-yellow-700',
        'green' => 'bg-green-100 text-green-700',
        'neutral' => 'bg-gray-100 text-gray-600',
    ];
    $keyName = $rows->isNotEmpty() ? $rows->first()->getKeyName() : 'id';
    $pageIds = $rows->pluck($keyName)->map(fn ($id) => (string) $id)->all();
    $hasBulk = $bulkActions->isNotEmpty();
    $colCount = $visibleColumns->count() + ($hasBulk ? 1 : 0) + ($rowActions->isNotEmpty() ? 1 : 0);
@endphp

<div class="space-y-3"
     x-data="{
         focusRow(delta) {
             const rows = [...$el.querySelectorAll('[data-grid-row]')];
             const current = rows.indexOf(document.activeElement);
             const next = rows[current + delta] ?? rows[current === -1 ? (delta > 0 ? 0 : rows.length - 1) : current];
             next?.focus();
         },
         openRow(el) {
             const url = el?.dataset.gridHref;
             if (url) window.Livewire.navigate(url);
         },
     }"
     @keydown.down.prevent="focusRow(1)"
     @keydown.up.prevent="focusRow(-1)">

    {{-- ─────────────────────────────────────────────────────── toolbar --}}
    <div class="bg-white rounded-xl border border-gray-200 p-3 flex flex-wrap items-center gap-2">
        <div class="relative flex-1 min-w-[200px]">
            <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-lg" aria-hidden="true">search</span>
            <label class="sr-only" for="grid-search-{{ $this->grid }}">Search</label>
            <input id="grid-search-{{ $this->grid }}" type="search" wire:model.live.debounce.350ms="search"
                   placeholder="Search…"
                   class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]">
        </div>

        @foreach ($gridFilters as $filter)
            <div>
                <label class="sr-only" for="grid-filter-{{ $filter->key }}">{{ $filter->label }}</label>
                <select id="grid-filter-{{ $filter->key }}" wire:model.live="filters.{{ $filter->key }}"
                        class="border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-700">
                    <option value="">{{ $filter->label }}</option>
                    @foreach ($filter->resolveOptions() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        @endforeach

        @if ($search !== '' || array_filter($filters) !== [])
            <button type="button" wire:click="clearFilters" class="text-xs text-[#1A365D] font-medium hover:underline">Clear</button>
        @endif

        <div class="ml-auto flex items-center gap-2">
            {{-- saved views --}}
            <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                <button type="button" @click="open = !open" :aria-expanded="open"
                        class="px-3 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-lg" aria-hidden="true">bookmark</span>
                    <span class="hidden lg:inline">{{ $views->firstWhere('id', $currentViewId)?->name ?? 'Views' }}</span>
                </button>
                <div x-show="open" x-cloak x-transition.opacity
                     class="absolute right-0 top-full mt-1 w-64 bg-white rounded-xl border border-gray-200 shadow-xl z-20 p-2 space-y-1">
                    @forelse ($views as $view)
                        <div class="flex items-center gap-1 rounded-lg px-2 py-1.5 hover:bg-gray-50 {{ $currentViewId === $view->id ? 'bg-blue-50/60' : '' }}">
                            <button type="button" wire:click="applyView({{ $view->id }})" @click="open = false"
                                    class="flex-1 text-left text-sm text-gray-700">
                                {{ $view->name }}
                                @if ($view->is_default)
                                    <span class="text-[10px] text-gray-400 uppercase ml-1">default</span>
                                @endif
                            </button>
                            <button type="button" wire:click="deleteView({{ $view->id }})" class="p-0.5 rounded hover:bg-gray-100" aria-label="Delete view {{ $view->name }}">
                                <span class="material-symbols-outlined text-gray-400 text-base" aria-hidden="true">delete</span>
                            </button>
                        </div>
                    @empty
                        <p class="px-2 py-1.5 text-xs text-gray-400">No saved views yet.</p>
                    @endforelse
                    <div class="border-t border-gray-100 pt-2 mt-1 space-y-1.5">
                        <label class="sr-only" for="grid-view-name-{{ $this->grid }}">View name</label>
                        <input id="grid-view-name-{{ $this->grid }}" type="text" wire:model="newViewName" placeholder="Save current as…"
                               wire:keydown.enter="saveView"
                               class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-xs">
                        <div class="flex gap-1.5">
                            <button type="button" wire:click="saveView" class="flex-1 px-2 py-1.5 bg-[#1A365D] text-white rounded-lg text-xs font-medium hover:bg-[#2D4A7A]">Save</button>
                            <button type="button" wire:click="saveView(true)" class="flex-1 px-2 py-1.5 border border-gray-300 rounded-lg text-xs text-gray-700 hover:bg-gray-50">Save as default</button>
                        </div>
                    </div>
                </div>
            </div>

            {{-- column chooser --}}
            <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                <button type="button" @click="open = !open" :aria-expanded="open"
                        class="px-3 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-lg" aria-hidden="true">view_column</span>
                    <span class="hidden lg:inline">Columns</span>
                </button>
                <div x-show="open" x-cloak x-transition.opacity
                     class="absolute right-0 top-full mt-1 w-56 bg-white rounded-xl border border-gray-200 shadow-xl z-20 p-2 max-h-80 overflow-y-auto">
                    @foreach ($allColumns as $column)
                        <label class="flex items-center gap-2 px-2 py-1.5 rounded-lg hover:bg-gray-50 cursor-pointer">
                            <input type="checkbox" wire:click="toggleColumn('{{ $column->key }}')"
                                   @checked(in_array($column->key, $columns, true))
                                   class="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]/30">
                            <span class="text-sm text-gray-700">{{ $column->label }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            {{-- export --}}
            <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                <button type="button" @click="open = !open" :aria-expanded="open"
                        class="px-3 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-lg" aria-hidden="true">download</span>
                    <span class="hidden lg:inline">Export</span>
                </button>
                <div x-show="open" x-cloak x-transition.opacity
                     class="absolute right-0 top-full mt-1 w-44 bg-white rounded-xl border border-gray-200 shadow-xl z-20 p-1">
                    <button type="button" wire:click="exportCsv" @click="open = false" class="w-full text-left px-3 py-2 text-sm text-gray-700 rounded-lg hover:bg-gray-50">CSV{{ $selected !== [] ? ' (selection)' : '' }}</button>
                    <button type="button" wire:click="exportXlsx" @click="open = false" class="w-full text-left px-3 py-2 text-sm text-gray-700 rounded-lg hover:bg-gray-50">Excel{{ $selected !== [] ? ' (selection)' : '' }}</button>
                </div>
            </div>

            <label class="sr-only" for="grid-per-page-{{ $this->grid }}">Rows per page</label>
            <select id="grid-per-page-{{ $this->grid }}" wire:model.live="perPage" class="border border-gray-300 rounded-lg px-2 py-2 text-sm text-gray-700">
                @foreach ($definition->perPageOptions() as $n)
                    <option value="{{ $n }}">{{ $n }}/page</option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- ────────────────────────────────────────────────────── bulk bar --}}
    @if ($hasBulk && $selected !== [])
        <div class="bg-[#1A365D] text-white rounded-xl px-4 py-2.5 flex flex-wrap items-center gap-3">
            <span class="text-sm font-medium">{{ count($selected) }} selected</span>
            @if (! $selectingAll && $rows->total() > count($selected))
                <button type="button" wire:click="selectAllMatching" class="text-xs underline text-white/80 hover:text-white">
                    Select all {{ $rows->total() }} matching
                </button>
            @endif
            <div class="ml-auto flex items-center gap-2">
                @foreach ($bulkActions as $action)
                    <button type="button"
                            @if ($action->confirm) wire:confirm="{{ $action->confirm }}" @endif
                            wire:click="runBulk('{{ $action->key }}')"
                            class="px-3 py-1.5 bg-white/10 hover:bg-white/20 rounded-lg text-xs font-medium flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base" aria-hidden="true">{{ $action->icon }}</span>
                        {{ $action->label }}
                    </button>
                @endforeach
                <button type="button" wire:click="resetSelection" class="p-1 rounded hover:bg-white/10" aria-label="Clear selection">
                    <span class="material-symbols-outlined text-lg" aria-hidden="true">close</span>
                </button>
            </div>
        </div>
    @endif

    {{-- ───────────────────────────────────────────────────────── table --}}
    <div class="relative bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div wire:loading.flex wire:target="search, filters, sortBy, perPage, gotoPage, nextPage, previousPage, applyView, clearFilters"
             class="absolute inset-0 bg-white/60 z-10 items-center justify-center">
            <span class="material-symbols-outlined animate-spin text-[#1A365D] text-2xl" aria-hidden="true">progress_activity</span>
            <span class="sr-only">Loading</span>
        </div>

        @if ($rows->isEmpty())
            <div class="text-center py-14 px-6">
                <span class="material-symbols-outlined text-4xl text-gray-300 mb-2 block" aria-hidden="true">{{ $definition->emptyIcon() }}</span>
                <p class="text-sm text-gray-500">{{ $definition->emptyMessage() }}</p>
                @if ($search !== '' || array_filter($filters) !== [])
                    <button type="button" wire:click="clearFilters" class="mt-3 text-xs text-[#1A365D] font-medium hover:underline">Clear search & filters</button>
                @endif
            </div>
        @else
            {{-- md+: the table --}}
            <div class="hidden md:block overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50 border-b border-gray-200 text-left">
                            @if ($hasBulk)
                                <th scope="col" class="w-10 px-4 py-3">
                                    <input type="checkbox" aria-label="Select page"
                                           wire:click="togglePage({{ json_encode($pageIds) }})"
                                           @checked($pageIds !== [] && array_diff($pageIds, $selected) === [])
                                           class="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]/30">
                                </th>
                            @endif
                            @foreach ($visibleColumns as $column)
                                <th scope="col" class="px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap"
                                    @if ($sort === $column->key) aria-sort="{{ $dir === 'asc' ? 'ascending' : 'descending' }}" @endif>
                                    @if ($column->sortable)
                                        <button type="button" wire:click="sortBy('{{ $column->key }}')" class="flex items-center gap-1 hover:text-[#1A365D]">
                                            {{ $column->label }}
                                            <span class="material-symbols-outlined text-sm {{ $sort === $column->key ? 'text-[#1A365D]' : 'text-gray-300' }}" aria-hidden="true">
                                                {{ $sort === $column->key && $dir === 'desc' ? 'arrow_downward' : 'arrow_upward' }}
                                            </span>
                                        </button>
                                    @else
                                        {{ $column->label }}
                                    @endif
                                </th>
                            @endforeach
                            @if ($rowActions->isNotEmpty())
                                <th scope="col" class="w-12 px-4 py-3"><span class="sr-only">Actions</span></th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($rows as $row)
                            @php
                                $rowId = (string) $row->getKey();
                                $primary = $visibleColumns->first(fn ($c) => $c->linkTo);
                                $primaryUrl = $primary ? ($primary->linkTo)($row) : null;
                            @endphp
                            <tr data-grid-row tabindex="0"
                                @if ($primaryUrl) data-grid-href="{{ $primaryUrl }}" @endif
                                @keydown.enter="openRow($el)"
                                class="hover:bg-blue-50/50 focus:outline-none focus:bg-blue-50 focus:ring-2 focus:ring-inset focus:ring-[#1A365D]/40 {{ in_array($rowId, $selected, true) ? 'bg-blue-50/60' : '' }}">
                                @if ($hasBulk)
                                    <td class="px-4 py-2.5">
                                        <input type="checkbox" aria-label="Select row"
                                               wire:click="toggleRow('{{ $rowId }}')"
                                               @checked(in_array($rowId, $selected, true))
                                               class="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]/30">
                                    </td>
                                @endif
                                @foreach ($visibleColumns as $column)
                                    <td class="px-4 py-2.5 {{ $column->type === 'count' ? 'font-medium text-[#1A365D]' : '' }}">
                                        @if ($editing === $rowId.':'.$column->key)
                                            @if (is_array($column->editable))
                                                <select wire:model="editValue" wire:change="saveEdit" wire:keydown.escape="cancelEdit"
                                                        x-init="$el.focus()" class="border border-[#1A365D] rounded-lg px-2 py-1 text-xs">
                                                    @foreach ($column->editable['options'] as $value => $label)
                                                        <option value="{{ $value }}">{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                            @else
                                                <input type="text" wire:model="editValue" wire:keydown.enter="saveEdit" wire:keydown.escape="cancelEdit"
                                                       wire:blur="saveEdit" x-init="$el.focus()"
                                                       class="border border-[#1A365D] rounded-lg px-2 py-1 text-xs w-full">
                                            @endif
                                        @else
                                            <x-data-grid-cell :column="$column" :row="$row" :definition="$definition" :rag-classes="$ragClasses" />
                                            @if ($column->editable)
                                                <button type="button" wire:click="startEdit('{{ $rowId }}', '{{ $column->key }}')"
                                                        class="ml-1 p-0.5 rounded hover:bg-gray-100 align-middle" aria-label="Edit {{ $column->label }}">
                                                    <span class="material-symbols-outlined text-gray-300 text-sm hover:text-gray-500" aria-hidden="true">edit</span>
                                                </button>
                                            @endif
                                        @endif
                                    </td>
                                @endforeach
                                @if ($rowActions->isNotEmpty())
                                    <td class="px-4 py-2.5 text-right">
                                        <div class="relative inline-block" x-data="{ open: false }" @click.outside="open = false">
                                            <button type="button" @click="open = !open" :aria-expanded="open" aria-label="Row actions"
                                                    class="p-1 rounded hover:bg-gray-100">
                                                <span class="material-symbols-outlined text-gray-400 text-lg" aria-hidden="true">more_vert</span>
                                            </button>
                                            <div x-show="open" x-cloak x-transition.opacity
                                                 class="absolute right-0 top-full mt-1 w-40 bg-white rounded-xl border border-gray-200 shadow-xl z-20 p-1">
                                                @foreach ($rowActions as $action)
                                                    <a href="{{ ($action->url)($row) }}" wire:navigate
                                                       class="flex items-center gap-2 px-3 py-2 text-sm text-gray-700 rounded-lg hover:bg-gray-50">
                                                        <span class="material-symbols-outlined text-base text-gray-400" aria-hidden="true">{{ $action->icon }}</span>
                                                        {{ $action->label }}
                                                    </a>
                                                @endforeach
                                            </div>
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- mobile: card list --}}
            <div class="md:hidden divide-y divide-gray-100">
                @foreach ($rows as $row)
                    @php
                        $rowId = (string) $row->getKey();
                        $cardColumns = $visibleColumns->take(4);
                        $primary = $visibleColumns->first(fn ($c) => $c->linkTo);
                        $primaryUrl = $primary ? ($primary->linkTo)($row) : null;
                    @endphp
                    <div class="p-4 space-y-1.5 {{ in_array($rowId, $selected, true) ? 'bg-blue-50/60' : '' }}">
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex items-center gap-2 min-w-0">
                                @if ($hasBulk)
                                    <input type="checkbox" aria-label="Select row"
                                           wire:click="toggleRow('{{ $rowId }}')"
                                           @checked(in_array($rowId, $selected, true))
                                           class="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]/30">
                                @endif
                                <div class="font-medium text-sm text-[#1A365D] truncate">
                                    <x-data-grid-cell :column="$cardColumns->first()" :row="$row" :definition="$definition" :rag-classes="$ragClasses" />
                                </div>
                            </div>
                            @if ($primaryUrl)
                                <a href="{{ $primaryUrl }}" wire:navigate class="p-1 rounded hover:bg-gray-100" aria-label="Open">
                                    <span class="material-symbols-outlined text-gray-400 text-lg" aria-hidden="true">chevron_right</span>
                                </a>
                            @endif
                        </div>
                        <dl class="grid grid-cols-2 gap-x-3 gap-y-1">
                            @foreach ($cardColumns->skip(1) as $column)
                                <div>
                                    <dt class="text-[10px] text-gray-400 uppercase tracking-wide">{{ $column->label }}</dt>
                                    <dd class="text-xs text-gray-700">
                                        <x-data-grid-cell :column="$column" :row="$row" :definition="$definition" :rag-classes="$ragClasses" />
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- ──────────────────────────────────────────────────── pagination --}}
    @if ($rows->hasPages())
        <div class="flex items-center justify-between">
            <span class="text-xs text-gray-500">Showing {{ $rows->firstItem() }}–{{ $rows->lastItem() }} of {{ $rows->total() }}</span>
            <div>{{ $rows->links('vendor.pagination.tailwind') }}</div>
        </div>
    @elseif ($rows->isNotEmpty())
        <span class="text-xs text-gray-500">{{ $rows->total() }} {{ Str::plural('record', $rows->total()) }}</span>
    @endif
</div>
