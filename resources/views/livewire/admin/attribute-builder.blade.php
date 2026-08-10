{{--
    WP-05 TASK 1 — the field editor for one object type.

    The migration-path prompt is the important piece of this screen. Changing a
    field's data type while records hold values for it is one click away from
    destroying them, so the form asks as soon as the type is changed rather
    than at save, and states how many records are at stake.
--}}
<div class="space-y-4">
    @if (session('builder-status'))
        <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
            {{ session('builder-status') }}
        </div>
    @endif

    @error('attribute')
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</div>
    @enderror

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">{{ $objectType->name }}</h2>
            <p class="text-xs text-gray-500">
                {{ $attributes->count() }} field(s) defined here{{ $inherited->isNotEmpty() ? ', '.$inherited->count().' inherited' : '' }}.
                Fields added here appear on every form for this type the next time somebody opens one.
            </p>
        </div>
        <button wire:click="create" type="button"
                class="inline-flex items-center gap-2 rounded-lg bg-[#1A365D] px-4 py-2 text-sm font-medium text-white hover:bg-[#12263f]">
            <span class="material-symbols-outlined text-[18px]">add</span> New field
        </button>
    </div>

    {{-- Own fields, grouped by the section they render in --}}
    @forelse ($attributes->groupBy(fn ($a) => $a->section ?: 'Details') as $section => $sectionFields)
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
            <div class="border-b border-gray-100 bg-gray-50 px-4 py-2 text-[11px] font-semibold uppercase tracking-wide text-gray-500">
                {{ $section }}
            </div>
            <table class="min-w-full divide-y divide-gray-100 text-sm">
                <tbody class="divide-y divide-gray-100">
                    @foreach ($sectionFields as $attribute)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-900">
                                    {{ $attribute->label }}
                                    @if ($attribute->is_required) <span class="text-red-500">*</span> @endif
                                </div>
                                <div class="text-[11px] font-mono text-gray-400">
                                    {{ $attribute->code }}
                                    @if ($attribute->isMapped())
                                        <span class="text-blue-500">→ {{ $attribute->maps_to_column }}</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-3 text-gray-600">{{ $dataTypes[$attribute->data_type] ?? $attribute->data_type }}</td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-1">
                                    @if ($attribute->is_system)
                                        <span class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-600">system</span>
                                    @endif
                                    @if ($attribute->is_unique)
                                        <span class="rounded bg-purple-50 px-1.5 py-0.5 text-[10px] text-purple-700">unique</span>
                                    @endif
                                    @if ($attribute->is_pii)
                                        <span class="rounded bg-amber-50 px-1.5 py-0.5 text-[10px] text-amber-700">PII</span>
                                    @endif
                                    @if ($attribute->visible_when)
                                        <span class="rounded bg-blue-50 px-1.5 py-0.5 text-[10px] text-blue-700">conditional</span>
                                    @endif
                                    @if (data_get($attribute->validation, 'roles') || data_get($attribute->validation, 'permission'))
                                        <span class="rounded bg-red-50 px-1.5 py-0.5 text-[10px] text-red-700">role-gated</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-1">
                                    <button wire:click="edit({{ $attribute->id }})" type="button"
                                            class="rounded px-2 py-1 text-xs text-gray-600 hover:bg-gray-100">Edit</button>
                                    @unless ($attribute->is_system)
                                        <button wire:click="delete({{ $attribute->id }})" type="button"
                                                class="rounded px-2 py-1 text-xs {{ $deletingId === $attribute->id ? 'bg-red-600 text-white' : 'text-red-600 hover:bg-red-50' }}">
                                            {{ $deletingId === $attribute->id ? 'Confirm delete' : 'Delete' }}
                                        </button>
                                    @endunless
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @empty
        <div class="rounded-xl border border-dashed border-gray-300 bg-gray-50 p-8 text-center">
            <span class="material-symbols-outlined text-3xl text-gray-400">tune</span>
            <p class="mt-2 text-sm text-gray-500">No fields defined on this type yet.</p>
        </div>
    @endforelse

    {{-- Inherited fields, read-only: an SME who cannot see them will define a duplicate --}}
    @if ($inherited->isNotEmpty())
        <div class="overflow-hidden rounded-xl border border-dashed border-gray-300 bg-gray-50">
            <div class="border-b border-gray-200 px-4 py-2 text-[11px] font-semibold uppercase tracking-wide text-gray-500">
                Inherited — edit these on the type that defines them
            </div>
            <ul class="divide-y divide-gray-200 text-sm">
                @foreach ($inherited as $attribute)
                    <li class="flex items-center justify-between px-4 py-2">
                        <span class="text-gray-700">{{ $attribute->label }} <span class="font-mono text-[11px] text-gray-400">{{ $attribute->code }}</span></span>
                        <span class="text-xs text-gray-500">{{ $dataTypes[$attribute->data_type] ?? $attribute->data_type }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Form --}}
    @if ($showForm)
        <div class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-black/40 p-4">
            <form wire:submit="save" class="my-8 w-full max-w-3xl rounded-xl bg-white p-6 shadow-xl">
                <h3 class="text-base font-semibold text-gray-900">{{ $editingId ? 'Edit field' : 'New field' }}</h3>

                <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Label</label>
                        <input type="text" wire:model="label" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                        @error('label') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700">Code</label>
                        <input type="text" wire:model="code" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 font-mono text-sm">
                        <p class="mt-1 text-[11px] text-gray-400">Lowercase, underscores. This is how formulas and imports name the field.</p>
                        @error('code') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700">Data type</label>
                        <select wire:model.live="data_type" class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                            @foreach ($dataTypes as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('data_type') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700">Stored in column <span class="font-normal text-gray-400">(optional)</span></label>
                        <input type="text" wire:model="maps_to_column" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 font-mono text-sm">
                        <p class="mt-1 text-[11px] text-gray-400">
                            Leave empty to store the value alongside the record's other configured fields.
                            Set it to write a real column on the backing table.
                        </p>
                        @error('maps_to_column') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    {{-- The dangerous edit --}}
                    @if ($needsMigrationPath)
                        <div class="md:col-span-2 rounded-lg border border-amber-300 bg-amber-50 p-4">
                            <div class="flex gap-2">
                                <span class="material-symbols-outlined text-amber-600">warning</span>
                                <div class="text-xs text-amber-900">
                                    <p class="font-semibold">
                                        {{ $affectedRecordCount }} record(s) already hold a value for this field.
                                    </p>
                                    <p class="mt-1">
                                        The new type cannot represent all of them. Choose what happens to the existing
                                        values — there is no option that silently converts them, because that is how
                                        “approximately ₦4m” becomes 0 with no undo.
                                    </p>
                                    <div class="mt-3 space-y-2">
                                        <label class="flex items-start gap-2">
                                            <input type="radio" wire:model="migrationStrategy" value="preserve_as_text" class="mt-0.5">
                                            <span><span class="font-medium">Keep them as text.</span> Nothing is lost; a human decides later.</span>
                                        </label>
                                        <label class="flex items-start gap-2">
                                            <input type="radio" wire:model="migrationStrategy" value="clear" class="mt-0.5">
                                            <span><span class="font-medium">Clear them.</span> The values are discarded deliberately.</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endif

                    @if (in_array($data_type, ['enum', 'multi_enum']))
                        <div class="md:col-span-2">
                            <label class="block text-xs font-medium text-gray-700">Options — one per line</label>
                            <textarea wire:model="enum_options" rows="4" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 font-mono text-sm"></textarea>
                            @error('enum_options') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    @if ($data_type === 'formula')
                        <div class="md:col-span-2">
                            <label class="block text-xs font-medium text-gray-700">Formula</label>
                            <input type="text" wire:model="formula" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 font-mono text-sm"
                                   placeholder="0.005 * @measure('capital.total_qualifying')">
                            <p class="mt-1 text-[11px] text-gray-400">
                                Calculated fields are read-only on every form — posting one is rejected, not overwritten.
                            </p>
                            @error('formula') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    @if ($data_type === 'object_ref')
                        <div class="md:col-span-2">
                            <label class="block text-xs font-medium text-gray-700">Links to</label>
                            <select wire:model="ref_object_type_id" class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                                <option value="">— choose a type —</option>
                                @foreach ($typeOptions as $option)
                                    <option value="{{ $option->id }}">{{ $option->name }}</option>
                                @endforeach
                            </select>
                            @error('ref_object_type_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    <div>
                        <label class="block text-xs font-medium text-gray-700">Section</label>
                        <input type="text" wire:model="section" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-700">Order</label>
                            <input type="number" wire:model="sort_order" min="0" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700">Width</label>
                            <select wire:model="width" class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                                <option value="half">Half</option>
                                <option value="full">Full</option>
                            </select>
                        </div>
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-xs font-medium text-gray-700">Help text</label>
                        <input type="text" wire:model="help_text" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700">Default value</label>
                        <input type="text" wire:model="default_value" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700">Extra validation rules</label>
                        <input type="text" wire:model="extra_rules" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 font-mono text-sm" placeholder="min:3,max:20">
                        <p class="mt-1 text-[11px] text-gray-400">Comma separated. Applied on top of the rules the data type implies.</p>
                    </div>

                    {{-- Conditional visibility --}}
                    <fieldset class="md:col-span-2 rounded-lg border border-gray-200 p-4">
                        <legend class="px-1 text-xs font-medium text-gray-700">Show this field only when…</legend>
                        <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                            <select wire:model="visible_when_field" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                                <option value="">— always show —</option>
                                @foreach ($siblingCodes as $siblingCode => $siblingLabel)
                                    <option value="{{ $siblingCode }}">{{ $siblingLabel }}</option>
                                @endforeach
                            </select>
                            <select wire:model="visible_when_operator" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                                <option value="equals">is</option>
                                <option value="not_equals">is not</option>
                                <option value="in">is one of</option>
                                <option value="filled">has any value</option>
                            </select>
                            <input type="text" wire:model="visible_when_value" placeholder="value"
                                   class="rounded-lg border border-gray-200 px-3 py-2 text-sm">
                        </div>
                        <p class="mt-2 text-[11px] text-gray-400">
                            This controls what a user sees. To control what a user may <em>set</em>, use the role gate below —
                            hiding a field in the browser is not a permission check.
                        </p>
                    </fieldset>

                    {{-- Role gating --}}
                    <fieldset class="md:col-span-2 rounded-lg border border-gray-200 p-4">
                        <legend class="px-1 text-xs font-medium text-gray-700">Restrict to</legend>
                        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                            <div>
                                <label class="block text-[11px] text-gray-500">Roles</label>
                                <select wire:model="visible_to_roles" multiple size="4" class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                                    @foreach ($roleOptions as $role)
                                        <option value="{{ $role }}">{{ $role }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-[11px] text-gray-500">Permission</label>
                                <input type="text" wire:model="required_permission" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 font-mono text-sm" placeholder="risk.edit">
                            </div>
                        </div>
                        <p class="mt-2 text-[11px] text-gray-400">
                            Enforced on the server: a restricted field is never rendered and never accepted on submit.
                        </p>
                    </fieldset>

                    <div class="md:col-span-2 flex flex-wrap gap-4">
                        <label class="flex items-center gap-2 text-xs text-gray-700">
                            <input type="checkbox" wire:model="is_required"> Required
                        </label>
                        <label class="flex items-center gap-2 text-xs text-gray-700">
                            <input type="checkbox" wire:model="is_unique"> Unique
                        </label>
                        <label class="flex items-center gap-2 text-xs text-gray-700">
                            <input type="checkbox" wire:model="is_pii"> Personal data (never written to a log)
                        </label>
                        <label class="flex items-center gap-2 text-xs text-gray-700">
                            <input type="checkbox" wire:model="show_on_mobile"> Show on mobile
                        </label>
                        <label class="flex items-center gap-2 text-xs text-gray-700">
                            <input type="checkbox" wire:model="show_in_detail"> Show on the detail view
                        </label>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" wire:click="cancel" class="rounded-lg px-4 py-2 text-sm text-gray-600 hover:bg-gray-100">Cancel</button>
                    <button type="submit" class="rounded-lg bg-[#1A365D] px-4 py-2 text-sm font-medium text-white hover:bg-[#12263f]">Save field</button>
                </div>
            </form>
        </div>
    @endif
</div>
