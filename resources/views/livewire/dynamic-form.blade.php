{{--
    Configured attributes for one object type.

    Conditional visibility is Alpine's job and Alpine's job only — what a user
    SEES. What they may submit is decided in DynamicForm::fields() on the
    server, so a hidden field is genuinely absent from the request, not merely
    display:none.

    visible_when is read from an attribute's validation JSON:
        {"visible_when": {"attribute": "has_third_party", "equals": true}}
--}}
<div class="space-y-6"
     x-data="{ values: @entangle('values') }">

    @if($fields->isEmpty())
        <div class="rounded-lg border border-dashed border-gray-300 bg-gray-50 p-6 text-center">
            <span class="material-symbols-outlined text-3xl text-gray-400">tune</span>
            <p class="mt-2 text-sm text-gray-500">
                No configured attributes on this object type yet.
            </p>
            <p class="mt-1 text-xs text-gray-400">
                Attributes added to this type appear here automatically — no deployment needed.
            </p>
        </div>
    @else
        @foreach($fields->groupBy(fn($field) => $field->section ?: 'Details') as $section => $sectionFields)
            <section>
                <h3 class="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $section }}</h3>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    @foreach($sectionFields as $field)
                        @php
                            $condition = data_get($field->validation, 'visible_when');
                            $inputId   = 'attr-'.$field->code;
                        @endphp

                        <div class="{{ in_array($field->data_type, ['text', 'json', 'multi_enum'], true) ? 'md:col-span-2' : '' }}"
                            @if($condition)
                                x-show="JSON.stringify(values['{{ $condition['attribute'] }}']) === JSON.stringify(@js($condition['equals'] ?? true))"
                                x-transition
                            @endif
                        >
                            <label for="{{ $inputId }}" class="mb-1 block text-sm font-medium text-gray-700">
                                {{ $field->label }}
                                @if($field->is_required)
                                    <span class="text-red-500" aria-hidden="true">*</span>
                                @endif
                                @if($field->is_pii)
                                    {{-- Flagged so the person typing knows it lands in the NDPA data map. --}}
                                    <span class="ml-1 rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-amber-700">PII</span>
                                @endif
                            </label>

                            @switch($field->data_type)
                                @case('text')
                                    <textarea id="{{ $inputId }}" rows="3"
                                              wire:model="values.{{ $field->code }}"
                                              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-[#1A365D] focus:ring-1 focus:ring-[#1A365D]"></textarea>
                                    @break

                                @case('bool')
                                    <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                        <input type="checkbox" id="{{ $inputId }}"
                                               wire:model="values.{{ $field->code }}"
                                               class="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]">
                                        <span>Yes</span>
                                    </label>
                                    @break

                                @case('enum')
                                    <select id="{{ $inputId }}" wire:model="values.{{ $field->code }}"
                                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-[#1A365D] focus:ring-1 focus:ring-[#1A365D]">
                                        <option value="">— select —</option>
                                        @foreach($field->enum_options ?? [] as $option)
                                            <option value="{{ $option }}">{{ $option }}</option>
                                        @endforeach
                                    </select>
                                    @break

                                @case('multi_enum')
                                    <div class="flex flex-wrap gap-3 rounded-lg border border-gray-300 p-3">
                                        @foreach($field->enum_options ?? [] as $option)
                                            <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                                <input type="checkbox" value="{{ $option }}"
                                                       wire:model="values.{{ $field->code }}"
                                                       class="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]">
                                                <span>{{ $option }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                    @break

                                @case('date')
                                @case('datetime')
                                    <input type="{{ $field->data_type === 'date' ? 'date' : 'datetime-local' }}"
                                           id="{{ $inputId }}" wire:model="values.{{ $field->code }}"
                                           class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-[#1A365D] focus:ring-1 focus:ring-[#1A365D]">
                                    @break

                                @case('money')
                                    <div class="flex rounded-lg border border-gray-300 focus-within:border-[#1A365D] focus-within:ring-1 focus-within:ring-[#1A365D]">
                                        {{-- Typed in naira, stored in kobo. --}}
                                        <span class="flex items-center bg-gray-50 px-3 text-sm text-gray-500">&#8358;</span>
                                        <input type="number" step="0.01" id="{{ $inputId }}"
                                               wire:model="values.{{ $field->code }}"
                                               class="w-full rounded-r-lg border-0 px-3 py-2 text-sm focus:ring-0">
                                    </div>
                                    @break

                                @case('int')
                                @case('decimal')
                                    <input type="number" @if($field->data_type === 'decimal') step="any" @endif
                                           id="{{ $inputId }}" wire:model="values.{{ $field->code }}"
                                           class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-[#1A365D] focus:ring-1 focus:ring-[#1A365D]">
                                    @break

                                @case('formula')
                                    {{-- Derived, never posted: DynamicForm::save() strips it. --}}
                                    <div class="rounded-lg bg-gray-50 px-3 py-2 font-mono text-xs text-gray-500">
                                        {{ $field->formula }}
                                    </div>
                                    @break

                                @default
                                    <input type="text" id="{{ $inputId }}"
                                           wire:model="values.{{ $field->code }}"
                                           class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-[#1A365D] focus:ring-1 focus:ring-[#1A365D]">
                            @endswitch

                            @if($field->help_text)
                                <p class="mt-1 text-xs text-gray-400">{{ $field->help_text }}</p>
                            @endif

                            @error('values.'.$field->code)
                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach

        @unless($embedded)
            <div class="flex items-center gap-3">
                <button type="button" wire:click="save" wire:loading.attr="disabled"
                        class="rounded-lg bg-[#1A365D] px-4 py-2 text-sm font-medium text-white hover:bg-[#132a48] disabled:opacity-50">
                    <span wire:loading.remove wire:target="save">Save attributes</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </button>

                @if($saved)
                    <span class="flex items-center gap-1 text-sm text-green-600">
                        <span class="material-symbols-outlined text-base">check_circle</span> Saved
                    </span>
                @endif
            </div>
        @endunless
    @endif
</div>
