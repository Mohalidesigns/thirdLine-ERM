{{--
    WP-05 TASK 1 — typed edges.

    The delete dialog is the point of this screen. A relationship type with
    live instances cannot simply be dropped: the edge table restricts on
    delete, and cascading would destroy the record of what once mitigated
    what. Deleting archives instead, and says so with a count.
--}}
<div class="space-y-4">
    @if (session('builder-status'))
        <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
            {{ session('builder-status') }}
        </div>
    @endif

    @error('relationship_type')
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</div>
    @enderror

    <div class="flex items-center justify-between">
        <p class="text-xs text-gray-500 max-w-2xl">
            A relationship type is a named edge between two kinds of thing. Constraining which types it may run
            between is what stops "mitigates" from being drawn between two business units; the weight is what
            makes roll-up across the graph arithmetic rather than a guess.
        </p>
        <button wire:click="create" type="button"
                class="inline-flex shrink-0 items-center gap-2 rounded-lg bg-[#1A365D] px-4 py-2 text-sm font-medium text-white hover:bg-[#12263f]">
            <span class="material-symbols-outlined text-[18px]">add</span> New relationship type
        </button>
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-[11px] uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-4 py-3">Relationship</th>
                    <th class="px-4 py-3">Cardinality</th>
                    <th class="px-4 py-3">Constrained</th>
                    <th class="px-4 py-3 text-right">In use</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($relationshipTypes as $type)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <div class="font-medium text-gray-900">
                                {{ $type->name }}
                                @if ($type->is_system)
                                    <span class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium text-gray-600">system</span>
                                @endif
                                @if ($type->has_weight)
                                    <span class="ml-1 rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] font-medium text-emerald-700">weighted</span>
                                @endif
                            </div>
                            <div class="text-[11px] font-mono text-gray-400">
                                {{ $type->code }}{{ $type->inverse_code ? " ⇄ {$type->inverse_code}" : '' }}
                            </div>
                        </td>
                        <td class="px-4 py-3 text-gray-600">{{ str_replace('_', ' ', $type->cardinality) }}</td>
                        <td class="px-4 py-3 text-xs text-gray-500">
                            {{ $type->from_type_ids ? count($type->from_type_ids).' from' : 'any from' }}
                            &rarr;
                            {{ $type->to_type_ids ? count($type->to_type_ids).' to' : 'any to' }}
                        </td>
                        <td class="px-4 py-3 text-right text-gray-600">{{ $type->instance_count }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-1">
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
                    <tr><td colspan="5" class="px-4 py-10 text-center text-sm text-gray-400">No relationship types.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($deletingId)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
            <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                <h3 class="text-base font-semibold text-gray-900">Delete this relationship type?</h3>
                @if ($deletingInstanceCount > 0)
                    <div class="mt-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs text-amber-900">
                        <p class="font-semibold">{{ $deletingInstanceCount }} live relationship(s) use this type.</p>
                        <p class="mt-1">
                            They will be <span class="font-medium">archived</span>, not deleted: they stop appearing in
                            traversals, roll-ups and the assurance map, but they are retained so that an auditor asking
                            what mitigated a risk two years ago still gets an answer.
                        </p>
                    </div>
                @else
                    <p class="mt-2 text-sm text-gray-600">Nothing currently uses it.</p>
                @endif
                @error('relationship_type')
                    <p class="mt-3 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{{ $message }}</p>
                @enderror
                <div class="mt-5 flex justify-end gap-2">
                    <button wire:click="cancelDelete" type="button" class="rounded-lg px-4 py-2 text-sm text-gray-600 hover:bg-gray-100">Cancel</button>
                    <button wire:click="delete" type="button" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">
                        {{ $deletingInstanceCount > 0 ? 'Archive and delete' : 'Delete' }}
                    </button>
                </div>
            </div>
        </div>
    @endif

    @if ($showForm)
        <div class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-black/40 p-4">
            <form wire:submit="save" class="my-8 w-full max-w-2xl rounded-xl bg-white p-6 shadow-xl">
                <h3 class="text-base font-semibold text-gray-900">{{ $editingId ? 'Edit relationship type' : 'New relationship type' }}</h3>

                <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Name</label>
                        <input type="text" wire:model="name" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                        @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700">Code</label>
                        <input type="text" wire:model="code" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 font-mono text-sm" placeholder="mitigates">
                        @error('code') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700">Inverse code</label>
                        <input type="text" wire:model="inverse_code" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 font-mono text-sm" placeholder="mitigated_by">
                        <p class="mt-1 text-[11px] text-gray-400">The edge read backwards, so traversal in either direction reads as a phrase.</p>
                        @error('inverse_code') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700">Cardinality</label>
                        <select wire:model="cardinality" class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                            <option value="one_to_one">One to one</option>
                            <option value="one_to_many">One to many</option>
                            <option value="many_to_many">Many to many</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700">From these types</label>
                        <select wire:model="from_type_ids" multiple size="6" class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                            @foreach ($typeOptions as $option)
                                <option value="{{ $option->id }}">{{ $option->name }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-[11px] text-gray-400">Empty means any type.</p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700">To these types</label>
                        <select wire:model="to_type_ids" multiple size="6" class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                            @foreach ($typeOptions as $option)
                                <option value="{{ $option->id }}">{{ $option->name }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-[11px] text-gray-400">Empty means any type.</p>
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-xs font-medium text-gray-700">Attributes carried by the edge</label>
                        <textarea wire:model="attribute_schema" rows="3"
                                  class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 font-mono text-sm"
                                  placeholder="coverage_note|Coverage note|string"></textarea>
                        <p class="mt-1 text-[11px] text-gray-400">One per line: <span class="font-mono">code|Label|type</span>. Types: string, int, decimal, bool, date.</p>
                    </div>

                    <label class="md:col-span-2 flex items-start gap-2 rounded-lg bg-gray-50 p-3">
                        <input type="checkbox" wire:model="has_weight" class="mt-0.5">
                        <span class="text-xs text-gray-700">
                            <span class="font-medium">Carries a weight.</span>
                            Records how much of the target this edge accounts for — a control covering 40% of a risk,
                            a subsidiary contributing 30% of group exposure. Roll-up arithmetic reads this.
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
