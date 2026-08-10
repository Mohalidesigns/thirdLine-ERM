{{--
    WP-05 TASK 2 — the emerging-risk fields, rendered from object_attributes.

    This partial was 173 lines of hand-written inputs shared by create and edit.
    The sharing was already right; what it could not do was change without a
    deploy. The definitions now live on the EmergingRisk object type, so a
    tenant who needs a "regulatory driver" field adds it in the builder and it
    appears here, on the edit form and on the detail view, validated.

    $entry is the record being edited, or absent when creating.
--}}
@csrf

@if ($errors->any())
    <div class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4">
        <div class="mb-2 flex items-center gap-2">
            <span class="material-symbols-outlined text-red-600">error</span>
            <span class="text-sm font-semibold text-red-700">Please correct the following:</span>
        </div>
        <ul class="list-inside list-disc space-y-1 text-sm text-red-600">
            @foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach
        </ul>
    </div>
@endif

<div class="mb-6 rounded-xl border border-gray-200 bg-white p-6">
    <x-dynamic-form type="EmergingRisk" :record="$entry ?? null" />
</div>
