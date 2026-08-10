{{--
    The Livewire editor for the objects.attributes bag — the fields a tenant
    configured that are NOT backed by a column.

    This was <x-dynamic-form> until WP-05 TASK 2, which gave that tag the job
    of rendering a whole create/edit form from metadata. The two do different
    things and both are needed: this one edits configured attributes in place
    on a detail page, saving through Livewire; <x-dynamic-form> renders fields
    into a classic form that a controller receives.

    object-type: an ObjectType, its id, or its code ('Risk').
    model:      the record being edited — a typed model with HasObjectIdentity,
                a GraphObject, or an object id. Omit for a blank form.
    embedded:   true when the caller supplies its own submit button.
--}}
@props([
    'objectType',
    'model' => null,
    'embedded' => false,
])

@livewire('dynamic-form', [
    'objectType' => $objectType,
    'model' => $model,
    'embedded' => $embedded,
], key('dynamic-attributes-'.(is_object($model) ? get_class($model).'-'.($model->getKey() ?? 'new') : ($model ?? 'new'))))
