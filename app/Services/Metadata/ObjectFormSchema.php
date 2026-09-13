<?php

namespace App\Services\Metadata;

use App\Models\ObjectAttribute;
use App\Models\ObjectType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * WP-05 TASK 2 — which fields a create/edit form has, and what is in them.
 *
 * Resolves an object type's configured attributes into the field list a form
 * is built from: which fields exist, which of them the signed-in user may see,
 * what each posts under, and what its current value is.
 *
 * WHY IT IS NOT A VIEW COMPONENT ANY MORE. It was `<x-dynamic-form>` until
 * migration Phase 6.8 deleted the Blade renderer. Only its render() method was
 * ever about Blade; everything else answers questions React asks through
 * FormSchemaPresenter, which has been its main caller since Phase 2. Deleting
 * the Blade layer without moving this would have taken the schema engine with
 * it — the presenter instantiated the component and read the answers out
 * rather than re-deriving them, precisely so the two could not disagree.
 *
 * TWO KINDS OF FIELD COME OUT OF THIS.
 *
 *   A MAPPED field posts under its column name — `name`, `owner_id` — and is
 *   validated by the controller's own rule for that column.
 *
 *   An UNMAPPED field, which is what a tenant adds through the builder, posts
 *   under configured_attributes[code] and is persisted by
 *   PersistsConfiguredAttributes. Without that second half, adding a field in
 *   the builder would show it on the form and silently discard what was typed
 *   into it, which is worse than not showing it.
 *
 * ROLE GATING IS SERVER-SIDE. A field the user may not see is not in the
 * schema at all, so the browser never learns it exists, and neither the Form
 * Request nor PersistsConfiguredAttributes will accept it on submit — all
 * three ask ObjectAttribute::visibleToCurrentUser(). Conditional visibility
 * (`visible_when`) is a display rule the client evaluates, and nothing more.
 */
class ObjectFormSchema
{
    public ?ObjectType $objectType;

    /** @var Collection<int, ObjectAttribute> */
    public Collection $fields;

    /** @var array<string, mixed> */
    public array $values;

    public function __construct(
        public ObjectType|string|int|null $type = null,
        public ?Model $record = null,
        /** Render only these sections, in this order. Null renders all. */
        public ?array $sections = null,
        /**
         * Skip these field codes — for a form that supplies one itself,
         * or for a field the receiving controller does not accept.
         *
         * Named `omit` rather than `except` because Illuminate\View\Component
         * owned that property while this was a view component, and callers
         * still pass it by name.
         */
        public array $omit = [],
        /** Prefill for a field the caller knows about, e.g. a preselected risk. */
        public array $defaults = [],
        public bool $mobile = false,
    ) {
        $this->objectType = $this->resolveType($type, $record);
        $this->fields = $this->resolveFields();
        $this->values = $this->resolveValues();
    }

    /**
     * @return Collection<string, Collection<int, ObjectAttribute>>
     */
    public function sectioned(): Collection
    {
        $grouped = $this->fields
            ->groupBy(fn (ObjectAttribute $field) => $field->section ?: 'Details');

        if ($this->sections === null) {
            return $grouped;
        }

        // Caller-supplied order wins, and a section it does not name is not
        // rendered — that is how a wizard puts one section per step.
        return collect($this->sections)
            ->mapWithKeys(fn (string $section) => [$section => $grouped->get($section, collect())])
            ->filter(fn (Collection $fields) => $fields->isNotEmpty());
    }

    /** The HTML name a field posts under. */
    public function nameFor(ObjectAttribute $field): string
    {
        return $field->isMapped()
            ? $field->maps_to_column
            : "configured_attributes[{$field->code}]";
    }

    /** The key old() and the error bag use, which is not the same string. */
    public function errorKeyFor(ObjectAttribute $field): string
    {
        return $field->isMapped()
            ? $field->maps_to_column
            : "configured_attributes.{$field->code}";
    }

    /**
     * @return array<int|string, string>
     */
    public function optionsFor(ObjectAttribute $field): array
    {
        return app(FormOptionResolver::class)->optionsFor($field);
    }

    public function isChoice(ObjectAttribute $field): bool
    {
        return app(FormOptionResolver::class)->isChoice($field);
    }

    /* ------------------------------------------------------------------ */
    /*  Resolution */
    /* ------------------------------------------------------------------ */

    private function resolveType(ObjectType|string|int|null $type, ?Model $record): ?ObjectType
    {
        if ($type instanceof ObjectType) {
            return $type;
        }

        if (is_int($type)) {
            return ObjectType::find($type);
        }

        if (is_string($type)) {
            return ObjectType::resolve($type);
        }

        // Fall back to the record's own type, so a caller that has a model in
        // hand does not have to name its type as well.
        if ($record !== null) {
            $code = \App\Support\Graph\ObjectTypeRegistry::modelTypeMap()[$record::class] ?? null;

            return $code === null ? null : ObjectType::resolve($code);
        }

        return null;
    }

    /**
     * @return Collection<int, ObjectAttribute>
     */
    private function resolveFields(): Collection
    {
        if ($this->objectType === null) {
            return collect();
        }

        return $this->objectType->resolvedAttributes()
            ->reject(fn (ObjectAttribute $field) => in_array($field->code, $this->omit, true))
            // A formula field is computed; offering an input for it would
            // invite a value the server rejects outright.
            ->reject(fn (ObjectAttribute $field) => $field->data_type === 'formula')
            ->reject(fn (ObjectAttribute $field) => $this->mobile && ! $field->show_on_mobile)
            ->filter(fn (ObjectAttribute $field) => $field->visibleToCurrentUser())
            ->sortBy([['section', 'asc'], ['sort_order', 'asc']])
            ->values();
    }

    /**
     * Current value per field: what was posted and bounced, else what is
     * stored, else the caller's default, else the configured default.
     *
     * @return array<string, mixed>
     */
    private function resolveValues(): array
    {
        $stored = $this->record === null ? [] : $this->storedAttributes();
        $values = [];

        foreach ($this->fields as $field) {
            $key = $field->isMapped() ? $field->maps_to_column : $field->code;

            $current = $field->isMapped()
                ? ($this->record?->getAttribute($field->maps_to_column))
                : ($stored[$field->code] ?? null);

            // Money is stored in minor units and edited in major ones.
            if ($field->data_type === 'money' && is_numeric($current)) {
                $current = $current / 100;
            }

            if ($current instanceof \DateTimeInterface) {
                $current = $current->format($field->data_type === 'datetime' ? 'Y-m-d\TH:i' : 'Y-m-d');
            }

            $default = $this->defaults[$field->code] ?? $this->defaults[$key] ?? $field->default_value;

            $values[$field->code] = old(
                $field->isMapped() ? $key : "configured_attributes.{$field->code}",
                $current ?? $default
            );
        }

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    private function storedAttributes(): array
    {
        if ($this->record === null || ! method_exists($this->record, 'graphObject')) {
            return [];
        }

        return $this->record->graphObject()?->customAttributes() ?? [];
    }
}
