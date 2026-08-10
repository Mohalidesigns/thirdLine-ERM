{{--
    WP-05 TASK 2 — <x-dynamic-form>

        <form method="POST" action="{{ route('risk.controls.store') }}">
            @csrf
            <x-dynamic-form type="Control" />
            <button type="submit">Save</button>
        </form>

    Renders the fields configured on an object type. The enclosing form, its
    action, its CSRF token and its buttons belong to the caller — this
    component renders fields, not a form, which is what lets a caller keep a
    bespoke section alongside the generated ones.

    Conditional visibility is Alpine's, and Alpine's only: what a user SEES.
    What a user may SET is decided server-side in DynamicForm::visibleToUser()
    and again in PersistsConfiguredAttributes, because a field hidden only in
    the browser is hidden only from people who do not open devtools.
--}}
@php
    $sectioned = $sectioned();
@endphp

@if ($sectioned->isEmpty())
    <div class="rounded-lg border border-dashed border-gray-300 bg-gray-50 p-6 text-center">
        <span class="material-symbols-outlined text-3xl text-gray-400">tune</span>
        <p class="mt-2 text-sm text-gray-500">
            No fields are configured on {{ $objectType?->name ?? 'this type' }} yet.
        </p>
        @can('admin.metadata')
            <a href="{{ $objectType ? route('admin.builder.attributes', $objectType) : route('admin.builder') }}"
               class="mt-2 inline-block text-xs text-[#1A365D] hover:underline">Configure its fields</a>
        @endcan
    </div>
@else
    <div class="space-y-8" x-data="{ values: @js($values) }">
        @foreach ($sectioned as $section => $sectionFields)
            <section>
                @if ($sectioned->count() > 1)
                    <h3 class="mb-4 border-b border-gray-100 pb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">
                        {{ $section }}
                    </h3>
                @endif

                <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                    @foreach ($sectionFields as $field)
                        @php
                            $name = $nameFor($field);
                            $errorKey = $errorKeyFor($field);
                            $inputId = 'field-'.$field->code;
                            $value = $values[$field->code] ?? null;
                            $condition = $field->visibilityExpression('values');
                            $hasError = $errors->has($errorKey);
                            $inputClass = 'mt-1 w-full rounded-lg border px-3 py-2 text-sm focus:border-[#1A365D] focus:ring-1 focus:ring-[#1A365D] '
                                .($hasError ? 'border-red-400' : 'border-gray-200');
                        @endphp

                        <div class="{{ $field->width === 'full' ? 'md:col-span-2' : '' }}"
                            @if ($condition)
                                x-show="{{ $condition }}"
                                x-transition
                            @endif
                        >
                            <label for="{{ $inputId }}" class="block text-xs font-medium text-gray-700">
                                {{ $field->label }}
                                @if ($field->is_required) <span class="text-red-500">*</span> @endif
                                @if ($field->is_pii)
                                    <span class="ml-1 rounded bg-amber-50 px-1 py-0.5 text-[9px] font-medium text-amber-700"
                                          title="Personal data — never written to a log line">PII</span>
                                @endif
                            </label>

                            @if ($isChoice($field))
                                @php $options = $optionsFor($field); @endphp
                                @if ($field->data_type === 'multi_enum')
                                    <select id="{{ $inputId }}" name="{{ $name }}[]" multiple size="4"
                                            x-model="values['{{ $field->code }}']"
                                            @required($field->is_required)
                                            class="{{ $inputClass }} bg-white">
                                        @foreach ($options as $optionValue => $optionLabel)
                                            <option value="{{ $optionValue }}"
                                                @selected(in_array($optionValue, (array) $value))>{{ $optionLabel }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <select id="{{ $inputId }}" name="{{ $name }}"
                                            x-model="values['{{ $field->code }}']"
                                            @required($field->is_required)
                                            class="{{ $inputClass }} bg-white">
                                        <option value="">{{ $field->is_required ? 'Choose…' : '— none —' }}</option>
                                        @foreach ($options as $optionValue => $optionLabel)
                                            <option value="{{ $optionValue }}" @selected((string) $value === (string) $optionValue)>
                                                {{ $optionLabel }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @if ($options === [])
                                        <p class="mt-1 text-[11px] text-amber-600">
                                            Nothing to choose from yet — create one first.
                                        </p>
                                    @endif
                                @endif

                            @elseif ($field->data_type === 'text')
                                <textarea id="{{ $inputId }}" name="{{ $name }}" rows="3"
                                          x-model="values['{{ $field->code }}']"
                                          @required($field->is_required)
                                          class="{{ $inputClass }}">{{ $value }}</textarea>

                            @elseif ($field->data_type === 'bool')
                                <div class="mt-2">
                                    {{-- The hidden input carries the false case; without it an
                                         unchecked box posts nothing and the column keeps its
                                         old value, which reads as the toggle not working. --}}
                                    <input type="hidden" name="{{ $name }}" value="0">
                                    <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                        <input type="checkbox" id="{{ $inputId }}" name="{{ $name }}" value="1"
                                               x-model="values['{{ $field->code }}']"
                                               @checked(filter_var($value, FILTER_VALIDATE_BOOLEAN))>
                                        Yes
                                    </label>
                                </div>

                            @elseif ($field->data_type === 'json')
                                <textarea id="{{ $inputId }}" name="{{ $name }}" rows="4"
                                          class="{{ $inputClass }} font-mono">{{ is_array($value) ? json_encode($value, JSON_PRETTY_PRINT) : $value }}</textarea>

                            @else
                                @php
                                    $inputType = match ($field->data_type) {
                                        'int' => 'number',
                                        'decimal', 'money' => 'number',
                                        'date' => 'date',
                                        'datetime' => 'datetime-local',
                                        default => 'text',
                                    };
                                @endphp
                                <div class="relative">
                                    @if ($field->data_type === 'money')
                                        <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-gray-400">
                                            {{ (auth()->user()?->organization?->settings['reporting_currency'] ?? 'NGN') }}
                                        </span>
                                    @endif
                                    <input type="{{ $inputType }}" id="{{ $inputId }}" name="{{ $name }}"
                                           value="{{ $value }}"
                                           x-model="values['{{ $field->code }}']"
                                           @required($field->is_required)
                                           @if (in_array($field->data_type, ['decimal', 'money'])) step="0.01" @endif
                                           class="{{ $inputClass }} {{ $field->data_type === 'money' ? 'pl-12' : '' }}">
                                </div>
                            @endif

                            @if ($field->help_text)
                                <p class="mt-1 text-[11px] text-gray-400">{{ $field->help_text }}</p>
                            @endif

                            @error($errorKey)
                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
@endif
