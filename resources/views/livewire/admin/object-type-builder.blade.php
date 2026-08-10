{{--
    WP-05 TASK 1 — object type CRUD.

    System types render with their identity fields locked. That is not a UI
    nicety: the platform resolves the seeded registry by CODE from a dozen
    places, so a renamed code breaks them all at runtime and only at runtime.
    Presentation fields stay open, which is what a tenant actually wants to
    change about a system type.
--}}
<div class="space-y-4">
    @if (session('builder-status'))
        <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
            {{ session('builder-status') }}
        </div>
    @endif

    @error('type')
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</div>
    @enderror

    {{-- Toolbar --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-2">
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search types…"
                   class="text-sm border border-gray-200 rounded-lg px-3 py-2 w-56">
            <select wire:model.live="categoryFilter" class="text-sm border border-gray-200 rounded-lg px-3 py-2 bg-white">
                <option value="">All categories</option>
                <option value="org_node">Organisation node</option>
                <option value="governance">Governance</option>
                <option value="assessment">Assessment</option>
                <option value="reference">Reference</option>
            </select>
        </div>
        <button wire:click="create" type="button"
                class="inline-flex items-center gap-2 rounded-lg bg-[#1A365D] px-4 py-2 text-sm font-medium text-white hover:bg-[#12263f]">
            <span class="material-symbols-outlined text-[18px]">add</span> New type
        </button>
    </div>

    {{-- List --}}
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-[11px] uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-4 py-3">Type</th>
                    <th class="px-4 py-3">Category</th>
                    <th class="px-4 py-3">Inherits</th>
                    <th class="px-4 py-3 text-right">Fields</th>
                    <th class="px-4 py-3 text-right">Records</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($types as $type)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <span class="material-symbols-outlined text-[20px]" style="color: {{ $type->color ?? '#1A365D' }}">{{ $type->icon ?? 'category' }}</span>
                                <div>
                                    <div class="font-medium text-gray-900">{{ $type->name }}</div>
                                    <div class="text-[11px] text-gray-400 font-mono">{{ $type->code }}{{ $type->code_prefix ? " · {$type->code_prefix}-" : '' }}</div>
                                </div>
                                @if ($type->is_system)
                                    <span class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium text-gray-600">system</span>
                                @endif
                                @if ($type->is_node_type)
                                    <span class="rounded bg-blue-50 px-1.5 py-0.5 text-[10px] font-medium text-blue-700">node</span>
                                @endif
                            </div>
                        </td>
                        <td class="px-4 py-3 text-gray-600">{{ str_replace('_', ' ', $type->category) }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $type->parentType?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-right text-gray-600">{{ $type->attribute_definitions_count }}</td>
                        <td class="px-4 py-3 text-right text-gray-600">{{ $type->record_count }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-1">
                                <a href="{{ route('admin.builder.attributes', $type) }}"
                                   class="rounded px-2 py-1 text-xs text-[#1A365D] hover:bg-gray-100">Fields</a>
                                <button wire:click="edit({{ $type->id }})" type="button"
                                        class="rounded px-2 py-1 text-xs text-gray-600 hover:bg-gray-100">Edit</button>
                                @unless ($type->is_system)
                                    <button wire:click="confirmDelete({{ $type->id }})" type="button"
                                            class="rounded px-2 py-1 text-xs text-red-600 hover:bg-red-50">Delete</button>
                                @endunless
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-sm text-gray-400">No object types match.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Delete confirmation --}}
    @if ($confirmingDelete)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
            <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                <h3 class="text-base font-semibold text-gray-900">Delete this type?</h3>
                <p class="mt-2 text-sm text-gray-600">
                    The type and its field definitions are removed. This is refused if any record still uses it,
                    or if another type inherits from it.
                </p>
                @error('type')
                    <p class="mt-3 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{{ $message }}</p>
                @enderror
                <div class="mt-5 flex justify-end gap-2">
                    <button wire:click="cancelDelete" type="button" class="rounded-lg px-4 py-2 text-sm text-gray-600 hover:bg-gray-100">Cancel</button>
                    <button wire:click="delete" type="button" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">Delete</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Form --}}
    @if ($showForm)
        <div class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-black/40 p-4">
            <form wire:submit="save" class="my-8 w-full max-w-2xl rounded-xl bg-white p-6 shadow-xl">
                <h3 class="text-base font-semibold text-gray-900">
                    {{ $editingId ? 'Edit object type' : 'New object type' }}
                </h3>

                @if ($editing?->is_system)
                    <p class="mt-2 rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600">
                        This is a system type. Its code, category and node flag are locked because the platform's
                        own modules resolve it by code. Everything else is yours to change — or create your own
                        type that inherits from this one.
                    </p>
                @endif

                <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Name</label>
                        <input type="text" wire:model.live.debounce.400ms="name" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                        @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700">Code</label>
                        <input type="text" wire:model="code" @disabled($editing?->is_system)
                               class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 font-mono text-sm disabled:bg-gray-100 disabled:text-gray-500">
                        <p class="mt-1 text-[11px] text-gray-400">How code refers to this type. Letters, digits and underscores.</p>
                        @error('code') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700">Plural name</label>
                        <input type="text" wire:model="plural_name" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700">Category</label>
                        <select wire:model="category" @disabled($editing?->is_system)
                                class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm disabled:bg-gray-100">
                            <option value="org_node">Organisation node</option>
                            <option value="governance">Governance</option>
                            <option value="assessment">Assessment</option>
                            <option value="reference">Reference</option>
                        </select>
                        @error('category') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-xs font-medium text-gray-700">Description</label>
                        <textarea wire:model="description" rows="2" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm"></textarea>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700">Inherits from</label>
                        <select wire:model="parent_type_id" class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                            <option value="">— none —</option>
                            @foreach ($parentOptions as $option)
                                <option value="{{ $option->id }}">{{ $option->name }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-[11px] text-gray-400">Inherits every field of the parent; a field of the same code overrides it.</p>
                        @error('parent_type_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700">Default lifecycle</label>
                        <select wire:model="default_lifecycle_id" class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                            <option value="">— none —</option>
                            @foreach ($lifecycleOptions as $option)
                                <option value="{{ $option->id }}">{{ $option->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700">Icon</label>
                        <div class="mt-1 flex items-center gap-2">
                            <span class="material-symbols-outlined text-[22px]" style="color: {{ $color }}">{{ $icon ?: 'category' }}</span>
                            <input type="text" wire:model.live.debounce.400ms="icon" class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm" placeholder="material symbol name">
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700">Colour</label>
                        <input type="color" wire:model.live="color" class="mt-1 h-9 w-full rounded-lg border border-gray-200">
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700">Reference prefix</label>
                        <input type="text" wire:model="code_prefix" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 font-mono text-sm uppercase" placeholder="TP">
                        <p class="mt-1 text-[11px] text-gray-400">Records get codes like TP-0001.</p>
                        @error('code_prefix') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700">Sort order</label>
                        <input type="number" wire:model="sort_order" min="0" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-xs font-medium text-gray-700">Allowed child types</label>
                        <select wire:model="allowed_child_type_ids" multiple size="5"
                                class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                            @foreach ($parentOptions as $option)
                                <option value="{{ $option->id }}">{{ $option->name }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-[11px] text-gray-400">Which types may sit beneath this one in the organisation graph. Leave empty for any.</p>
                    </div>

                    <label class="md:col-span-2 flex items-start gap-2 rounded-lg bg-gray-50 p-3">
                        <input type="checkbox" wire:model="is_node_type" @disabled($editing?->is_system) class="mt-0.5">
                        <span class="text-xs text-gray-700">
                            <span class="font-medium">Part of the organisation graph.</span>
                            Only node types can be a record's node — the backbone that roll-up traverses.
                        </span>
                    </label>
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" wire:click="cancel" class="rounded-lg px-4 py-2 text-sm text-gray-600 hover:bg-gray-100">Cancel</button>
                    <button type="submit" class="rounded-lg bg-[#1A365D] px-4 py-2 text-sm font-medium text-white hover:bg-[#12263f]">Save</button>
                </div>
            </form>
        </div>
    @endif
</div>
