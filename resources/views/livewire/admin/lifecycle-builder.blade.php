{{--
    WP-05 TASK 1 — the visual state machine editor.

    The transition matrix is the whole design. Rows are the state you are in,
    columns the state you may move to, and a checked box is a legal move. Drawn
    this way, an empty row is instantly visible — and an empty row is a state
    nothing can leave, which is the mistake people actually make and the one a
    list of dropdowns hides completely.
--}}
<div class="space-y-4">
    @if (session('builder-status'))
        <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
            {{ session('builder-status') }}
        </div>
    @endif

    @error('lifecycle')
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</div>
    @enderror

    <div class="flex items-center justify-between">
        <p class="max-w-2xl text-xs text-gray-500">
            A lifecycle declares the states a record can be in and the moves between them, so the same rule is
            enforced by the UI, the API and the importer rather than by a conditional in three controllers.
        </p>
        <button wire:click="create" type="button"
                class="inline-flex shrink-0 items-center gap-2 rounded-lg bg-[#1A365D] px-4 py-2 text-sm font-medium text-white hover:bg-[#12263f]">
            <span class="material-symbols-outlined text-[18px]">add</span> New lifecycle
        </button>
    </div>

    {{-- List --}}
    <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
        @forelse ($lifecycles as $lifecycle)
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <div class="flex items-start justify-between">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900">
                            {{ $lifecycle->name }}
                            @if ($lifecycle->is_system)
                                <span class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium text-gray-600">system</span>
                            @endif
                        </h3>
                        <p class="text-[11px] text-gray-400">{{ $lifecycle->objectType?->name }} · {{ $lifecycle->code }}</p>
                    </div>
                    <div class="flex gap-1">
                        <button wire:click="edit({{ $lifecycle->id }})" type="button"
                                class="rounded px-2 py-1 text-xs text-gray-600 hover:bg-gray-100">
                            {{ $lifecycle->is_system ? 'Clone' : 'Edit' }}
                        </button>
                        @unless ($lifecycle->is_system)
                            <button wire:click="delete({{ $lifecycle->id }})" type="button"
                                    class="rounded px-2 py-1 text-xs text-red-600 hover:bg-red-50">Delete</button>
                        @endunless
                    </div>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-1">
                    @foreach ($lifecycle->states ?? [] as $state)
                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-medium text-white"
                              style="background-color: {{ $state['color'] ?? '#94a3b8' }};">
                            @if ($state['is_initial'] ?? false)
                                <span class="material-symbols-outlined text-[12px]">play_arrow</span>
                            @endif
                            {{ $state['name'] ?? $state['code'] }}
                            @if ($state['is_terminal'] ?? false)
                                <span class="material-symbols-outlined text-[12px]">stop</span>
                            @endif
                        </span>
                    @endforeach
                </div>
            </div>
        @empty
            <div class="md:col-span-2 rounded-xl border border-dashed border-gray-300 bg-gray-50 p-8 text-center">
                <span class="material-symbols-outlined text-3xl text-gray-400">linear_scale</span>
                <p class="mt-2 text-sm text-gray-500">No lifecycles yet.</p>
            </div>
        @endforelse
    </div>

    {{-- Editor --}}
    @if ($showForm)
        <div class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-black/40 p-4">
            <form wire:submit="save" class="my-8 w-full max-w-5xl rounded-xl bg-white p-6 shadow-xl">
                <h3 class="text-base font-semibold text-gray-900">{{ $editingId ? 'Edit lifecycle' : 'New lifecycle' }}</h3>

                @error('states')
                    <div class="mt-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</div>
                @enderror

                <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Applies to</label>
                        <select wire:model="objectTypeId" class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                            <option value="">— choose a type —</option>
                            @foreach ($typeOptions as $option)
                                <option value="{{ $option->id }}">{{ $option->name }}</option>
                            @endforeach
                        </select>
                        @error('objectTypeId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Name</label>
                        <input type="text" wire:model="name" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                        @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Code</label>
                        <input type="text" wire:model="code" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 font-mono text-sm">
                        @error('code') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                {{-- States --}}
                <div class="mt-6">
                    <div class="flex items-center justify-between">
                        <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500">States</h4>
                        <button type="button" wire:click="addState" class="text-xs text-[#1A365D] hover:underline">+ Add state</button>
                    </div>

                    <div class="mt-2 space-y-2">
                        @foreach ($states as $index => $state)
                            <div class="grid grid-cols-1 gap-2 rounded-lg border border-gray-200 p-3 md:grid-cols-12 md:items-center">
                                <input type="color" wire:model.live="states.{{ $index }}.color" class="h-8 w-full rounded md:col-span-1">
                                <input type="text" wire:model.live="states.{{ $index }}.code" placeholder="code"
                                       class="rounded-lg border border-gray-200 px-2 py-1.5 font-mono text-xs md:col-span-2">
                                <input type="text" wire:model.live="states.{{ $index }}.name" placeholder="Display name"
                                       class="rounded-lg border border-gray-200 px-2 py-1.5 text-xs md:col-span-2">

                                <select wire:model="states.{{ $index }}.required_permission"
                                        class="rounded-lg border border-gray-200 bg-white px-2 py-1.5 text-xs md:col-span-3">
                                    <option value="">no permission needed to enter</option>
                                    @foreach ($permissionOptions as $permission)
                                        <option value="{{ $permission }}">{{ $permission }}</option>
                                    @endforeach
                                </select>

                                <select wire:model="states.{{ $index }}.required_workflow_id"
                                        class="rounded-lg border border-gray-200 bg-white px-2 py-1.5 text-xs md:col-span-2">
                                    <option value="">no workflow</option>
                                    @foreach ($workflowOptions as $workflow)
                                        <option value="{{ $workflow->id }}">{{ $workflow->name }}</option>
                                    @endforeach
                                </select>

                                <div class="flex items-center gap-3 md:col-span-2">
                                    <label class="flex items-center gap-1 text-[11px] text-gray-600" title="Records start here">
                                        <input type="radio" name="initial" @checked($state['is_initial'] ?? false)
                                               wire:click="setInitial({{ $index }})"> start
                                    </label>
                                    <label class="flex items-center gap-1 text-[11px] text-gray-600" title="Records can end here">
                                        <input type="checkbox" wire:model.live="states.{{ $index }}.is_terminal"> end
                                    </label>
                                    <button type="button" wire:click="removeState({{ $index }})"
                                            class="ml-auto text-red-500 hover:text-red-700" title="Remove state">
                                        <span class="material-symbols-outlined text-[18px]">delete</span>
                                    </button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Transition matrix --}}
                <div class="mt-6">
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Allowed transitions</h4>
                    <p class="mt-1 text-[11px] text-gray-400">
                        Each row is a state a record is in; each tick is a state it may move to. A row with no ticks
                        and no “end” flag is a dead end — nothing will ever leave it.
                    </p>

                    <div class="mt-2 overflow-x-auto">
                        <table class="min-w-full border border-gray-200 text-xs">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="border-b border-r border-gray-200 px-3 py-2 text-left text-gray-500">from &rarr; to</th>
                                    @foreach ($states as $target)
                                        <th class="border-b border-gray-200 px-3 py-2 text-center font-medium text-gray-700">
                                            {{ $target['name'] ?: $target['code'] ?: '—' }}
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($states as $fromIndex => $from)
                                    @php
                                        $isDeadEnd = empty($from['allowed_transitions']) && ! ($from['is_terminal'] ?? false);
                                    @endphp
                                    <tr class="{{ $isDeadEnd ? 'bg-amber-50' : '' }}">
                                        <th class="border-r border-gray-200 px-3 py-2 text-left font-medium text-gray-700">
                                            {{ $from['name'] ?: $from['code'] ?: '—' }}
                                            @if ($isDeadEnd)
                                                <span class="ml-1 text-[10px] font-normal text-amber-700">dead end</span>
                                            @endif
                                        </th>
                                        @foreach ($states as $toIndex => $to)
                                            <td class="border-t border-gray-100 px-3 py-2 text-center">
                                                @if ($fromIndex === $toIndex)
                                                    <span class="text-gray-300">—</span>
                                                @else
                                                    <input type="checkbox"
                                                           @checked(in_array($to['code'], $from['allowed_transitions'] ?? [], true))
                                                           wire:click="toggleTransition({{ $fromIndex }}, '{{ $to['code'] }}')">
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" wire:click="cancel" class="rounded-lg px-4 py-2 text-sm text-gray-600 hover:bg-gray-100">Cancel</button>
                    <button type="submit" class="rounded-lg bg-[#1A365D] px-4 py-2 text-sm font-medium text-white hover:bg-[#12263f]">Save lifecycle</button>
                </div>
            </form>
        </div>
    @endif
</div>
