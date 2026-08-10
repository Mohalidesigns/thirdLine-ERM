{{--
    WP-05 TASK 3 — the screen where an organisation decides what a score means.

    The re-rating preview is what stops this being a form that quietly moves
    the whole register. Changing a band edge is one keystroke; seeing "14 risks
    move, 3 of them from High to Critical" before saving is the difference
    between a governance decision and an accident.
--}}
<div class="space-y-4">
    @if (session('builder-status'))
        <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
            {{ session('builder-status') }}
        </div>
    @endif

    @error('profile')
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</div>
    @enderror

    <div class="flex items-center justify-between">
        <p class="max-w-2xl text-xs text-gray-500">
            A scoring profile defines the matrix, what each point on each axis means, which impact dimensions are
            scored and how they collapse, the rating bands, and how residual follows from inherent. The most
            specific profile that matches a risk governs it.
        </p>
        <button wire:click="create" type="button"
                class="inline-flex shrink-0 items-center gap-2 rounded-lg bg-[#1A365D] px-4 py-2 text-sm font-medium text-white hover:bg-[#12263f]">
            <span class="material-symbols-outlined text-[18px]">add</span> New profile
        </button>
    </div>

    <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
        @foreach ($profiles as $profile)
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <div class="flex items-start justify-between">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900">
                            {{ $profile->name }}
                            @if ($profile->is_default)
                                <span class="ml-1 rounded bg-blue-50 px-1.5 py-0.5 text-[10px] font-medium text-blue-700">default</span>
                            @endif
                            @if ($profile->is_system)
                                <span class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium text-gray-600">system</span>
                            @endif
                        </h3>
                        <p class="text-[11px] text-gray-400">
                            {{ $profile->matrix_rows }}&times;{{ $profile->matrix_cols }} ·
                            {{ str_replace('_', ' ', $profile->impact_aggregation) }} aggregation
                            @if ($profile->effective_from && $profile->effective_from->isFuture())
                                · <span class="text-amber-600">effective {{ $profile->effective_from->format('d M Y') }}</span>
                            @endif
                        </p>
                    </div>
                    <div class="flex gap-1">
                        <button wire:click="edit({{ $profile->id }})" type="button"
                                class="rounded px-2 py-1 text-xs text-gray-600 hover:bg-gray-100">
                            {{ $profile->is_system ? 'Fork' : 'Edit' }}
                        </button>
                        @unless ($profile->is_system)
                            <button wire:click="delete({{ $profile->id }})" type="button"
                                    class="rounded px-2 py-1 text-xs text-red-600 hover:bg-red-50">Delete</button>
                        @endunless
                    </div>
                </div>

                <div class="mt-3 flex flex-wrap gap-1">
                    @foreach ($profile->rating_bands ?? [] as $band)
                        <span class="rounded px-2 py-0.5 text-[10px] font-medium text-white"
                              style="background-color: {{ $band['color'] ?? '#64748b' }};">
                            {{ $band['label'] }} {{ $band['min'] }}–{{ $band['max'] }}
                        </span>
                    @endforeach
                </div>

                @if ($profile->applies_to)
                    <p class="mt-2 text-[11px] text-gray-500">
                        Applies to:
                        @if (!empty($profile->applies_to['risk_types']))
                            risk types {{ implode(', ', $profile->applies_to['risk_types']) }}
                        @endif
                        @if (!empty($profile->applies_to['object_type_ids']))
                            {{ count($profile->applies_to['object_type_ids']) }} object type(s)
                        @endif
                    </p>
                @endif
            </div>
        @endforeach
    </div>

    @if ($showForm)
        <div class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-black/40 p-4">
            <form wire:submit="save" class="my-8 w-full max-w-5xl rounded-xl bg-white p-6 shadow-xl">
                <h3 class="text-base font-semibold text-gray-900">{{ $editingId ? 'Edit scoring profile' : 'New scoring profile' }}</h3>

                {{-- Re-rating preview --}}
                @if ($rerating && $rerating['total'] > 0)
                    <div class="mt-3 rounded-lg border {{ $rerating['moved'] > 0 ? 'border-amber-300 bg-amber-50' : 'border-gray-200 bg-gray-50' }} p-4">
                        <p class="text-xs {{ $rerating['moved'] > 0 ? 'text-amber-900' : 'text-gray-600' }}">
                            <span class="font-semibold">
                                {{ $rerating['moved'] }} of {{ $rerating['total'] }} active risk(s) change rating
                            </span>
                            if this profile is saved as it stands.
                        </p>
                        @if ($rerating['examples'])
                            <ul class="mt-2 space-y-0.5 font-mono text-[11px] text-amber-800">
                                @foreach ($rerating['examples'] as $example)
                                    <li>{{ $example }}</li>
                                @endforeach
                                @if ($rerating['moved'] > count($rerating['examples']))
                                    <li class="text-amber-600">…and {{ $rerating['moved'] - count($rerating['examples']) }} more</li>
                                @endif
                            </ul>
                        @endif
                    </div>
                @endif

                <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-4">
                    <div class="md:col-span-2">
                        <label class="block text-xs font-medium text-gray-700">Name</label>
                        <input type="text" wire:model="name" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                        @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Code</label>
                        <input type="text" wire:model="code" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 font-mono text-sm">
                        @error('code') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Effective from</label>
                        <input type="date" wire:model="effective_from" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                        <p class="mt-1 text-[11px] text-gray-400">Future dates stage without re-rating.</p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700">Likelihood points</label>
                        <input type="number" min="3" max="10" wire:model.live="matrix_rows" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                        @error('matrix_rows') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Impact points</label>
                        <input type="number" min="3" max="10" wire:model.live="matrix_cols" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                        @error('matrix_cols') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Dimensions collapse by</label>
                        <select wire:model="impact_aggregation" class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                            <option value="max">Worst dimension</option>
                            <option value="average">Mean of scored dimensions</option>
                            <option value="weighted">Weighted mean</option>
                            <option value="worst_two">Mean of the worst two</option>
                        </select>
                    </div>
                    <div class="flex items-end">
                        <label class="flex items-center gap-2 text-xs text-gray-700">
                            <input type="checkbox" wire:model="is_default"> Organisation default
                        </label>
                    </div>

                    <div class="md:col-span-4">
                        <label class="block text-xs font-medium text-gray-700">Residual formula</label>
                        <input type="text" wire:model="residual_formula"
                               class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 font-mono text-sm"
                               placeholder="inherent * (1 - effectiveness / 100)">
                        <p class="mt-1 text-[11px] text-gray-400">
                            <span class="font-mono">inherent</span>, <span class="font-mono">effectiveness</span> and
                            <span class="font-mono">max_score</span> are in scope. Parsed, never evaluated as code.
                        </p>
                    </div>
                </div>

                {{-- Rating bands --}}
                <div class="mt-6">
                    <div class="flex items-center justify-between">
                        <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                            Rating bands — every score from 1 to {{ $matrix_rows * $matrix_cols }} must fall into exactly one
                        </h4>
                        <button type="button" wire:click="addBand" class="text-xs text-[#1A365D] hover:underline">+ Add band</button>
                    </div>

                    @error('rating_bands')
                        <p class="mt-2 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{{ $message }}</p>
                    @enderror

                    <div class="mt-2 space-y-2">
                        @foreach ($rating_bands as $index => $band)
                            <div class="grid grid-cols-1 gap-2 rounded-lg border border-gray-200 p-3 md:grid-cols-12 md:items-center">
                                <input type="color" wire:model.live="rating_bands.{{ $index }}.color" class="h-8 w-full rounded md:col-span-1">
                                <input type="text" wire:model.live="rating_bands.{{ $index }}.label" placeholder="Label"
                                       class="rounded-lg border border-gray-200 px-2 py-1.5 text-xs md:col-span-3">
                                <input type="text" wire:model="rating_bands.{{ $index }}.code" placeholder="code"
                                       class="rounded-lg border border-gray-200 px-2 py-1.5 font-mono text-xs md:col-span-3">
                                <input type="number" wire:model.live="rating_bands.{{ $index }}.min" placeholder="from"
                                       class="rounded-lg border border-gray-200 px-2 py-1.5 text-xs md:col-span-2">
                                <input type="number" wire:model.live="rating_bands.{{ $index }}.max" placeholder="to"
                                       class="rounded-lg border border-gray-200 px-2 py-1.5 text-xs md:col-span-2">
                                <button type="button" wire:click="removeBand({{ $index }})"
                                        class="text-red-500 hover:text-red-700 md:col-span-1">
                                    <span class="material-symbols-outlined text-[18px]">delete</span>
                                </button>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Live matrix preview --}}
                <div class="mt-6">
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Matrix preview</h4>
                    @php
                        $bandFor = function ($score) use ($rating_bands) {
                            foreach ($rating_bands as $band) {
                                if ($score >= (int) ($band['min'] ?? 0) && $score <= (int) ($band['max'] ?? 0)) {
                                    return $band;
                                }
                            }
                            return null;
                        };
                    @endphp
                    <div class="mt-2 inline-block overflow-x-auto">
                        @for ($l = $matrix_rows; $l >= 1; $l--)
                            <div class="flex gap-1 mb-1">
                                @for ($i = 1; $i <= $matrix_cols; $i++)
                                    @php $cell = $bandFor($l * $i); @endphp
                                    <div class="flex h-9 w-9 items-center justify-center rounded text-[10px] font-bold text-white"
                                         style="background-color: {{ $cell['color'] ?? '#e5e7eb' }};"
                                         title="{{ $cell['label'] ?? 'NOT BANDED' }} — score {{ $l * $i }}">
                                        {{ $l * $i }}
                                    </div>
                                @endfor
                            </div>
                        @endfor
                    </div>
                </div>

                {{-- Scale definitions --}}
                <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <div>
                        <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Likelihood scale</h4>
                        <div class="mt-2 space-y-2">
                            @foreach ($likelihood_scale as $index => $level)
                                <div class="grid grid-cols-12 gap-2">
                                    <span class="col-span-1 pt-2 text-center text-xs font-bold text-gray-400">{{ $level['value'] }}</span>
                                    <input type="text" wire:model="likelihood_scale.{{ $index }}.label" placeholder="Label"
                                           class="col-span-4 rounded-lg border border-gray-200 px-2 py-1.5 text-xs">
                                    <input type="text" wire:model="likelihood_scale.{{ $index }}.definition" placeholder="What this means"
                                           class="col-span-7 rounded-lg border border-gray-200 px-2 py-1.5 text-xs">
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                            Impact scale <span class="font-normal normal-case text-gray-400">— financial bands in {{ $currency }}</span>
                        </h4>
                        <div class="mt-2 space-y-2">
                            @foreach ($impact_scale as $index => $level)
                                <div class="grid grid-cols-12 gap-2">
                                    <span class="col-span-1 pt-2 text-center text-xs font-bold text-gray-400">{{ $level['value'] }}</span>
                                    <input type="text" wire:model="impact_scale.{{ $index }}.label" placeholder="Label"
                                           class="col-span-4 rounded-lg border border-gray-200 px-2 py-1.5 text-xs">
                                    <input type="text" wire:model="impact_scale.{{ $index }}.definition" placeholder="What this means"
                                           class="col-span-7 rounded-lg border border-gray-200 px-2 py-1.5 text-xs">
                                </div>
                            @endforeach
                        </div>
                        <p class="mt-2 text-[11px] text-gray-400">
                            Financial bands are stored in minor units with their currency. They start empty because the
                            platform has never held a figure for them — an invented one would look exactly like an agreed one.
                        </p>
                    </div>
                </div>

                {{-- Applies to --}}
                <fieldset class="mt-6 rounded-lg border border-gray-200 p-4">
                    <legend class="px-1 text-xs font-medium text-gray-700">Applies to</legend>
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                        <div>
                            <label class="block text-[11px] text-gray-500">Object types</label>
                            <select wire:model="applies_to_object_type_ids" multiple size="4"
                                    class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                                @foreach ($typeOptions as $option)
                                    <option value="{{ $option->id }}">{{ $option->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-[11px] text-gray-500">Risk types</label>
                            <input type="text" wire:model="applies_to_risk_types" placeholder="credit, market, operational"
                                   class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                        </div>
                    </div>
                    <p class="mt-2 text-[11px] text-gray-400">
                        Leave both empty for a general profile. A profile that narrows on something beats one that
                        does not; a profile that narrows on a risk type will never score a risk of another type.
                    </p>
                </fieldset>

                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" wire:click="cancel" class="rounded-lg px-4 py-2 text-sm text-gray-600 hover:bg-gray-100">Cancel</button>
                    <button type="submit" class="rounded-lg bg-[#1A365D] px-4 py-2 text-sm font-medium text-white hover:bg-[#12263f]">Save profile</button>
                </div>
            </form>
        </div>
    @endif
</div>
