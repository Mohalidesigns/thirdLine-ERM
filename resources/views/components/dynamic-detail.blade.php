{{--
    WP-05 TASK 2 — <x-dynamic-detail>

        <x-dynamic-detail :record="$control" />

    The read view of the same metadata <x-dynamic-form> writes, so a field
    added through the builder appears on both without anybody remembering to
    add it in two places.
--}}
@php
    $sectioned = $sectioned();
@endphp

@if ($sectioned->isEmpty())
    <p class="rounded-lg border border-dashed border-gray-300 bg-gray-50 p-6 text-center text-sm text-gray-500">
        Nothing recorded against the configured fields for this record.
    </p>
@else
    <div class="space-y-6">
        @foreach ($sectioned as $section => $sectionFields)
            <section>
                @if ($sectioned->count() > 1)
                    <h3 class="mb-3 border-b border-gray-100 pb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">
                        {{ $section }}
                    </h3>
                @endif

                <dl class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
                    @foreach ($sectionFields as $field)
                        @php $value = $display($field); @endphp
                        <div class="{{ $isBlock($field) ? 'md:col-span-2' : '' }}">
                            <dt class="text-[11px] font-medium uppercase tracking-wide text-gray-500">
                                {{ $field->label }}
                                @if ($field->is_pii)
                                    <span class="ml-1 rounded bg-amber-50 px-1 py-0.5 text-[9px] font-medium normal-case tracking-normal text-amber-700"
                                          title="Personal data — redacted in exports and never logged">PII</span>
                                @endif
                            </dt>
                            <dd class="mt-1 text-sm {{ $value === null ? 'text-gray-300' : 'text-gray-900' }} {{ $isBlock($field) ? 'whitespace-pre-line' : '' }}">
                                {{ $value ?? '—' }}
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        @endforeach
    </div>
@endif
