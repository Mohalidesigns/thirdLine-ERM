<?php

namespace App\Services\Metadata;

use App\Models\ObjectAttribute;
use App\Models\ObjectType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * WP-05 TASK 2 — how a configured attribute is DISPLAYED on a detail page.
 *
 * The read side of the same metadata ObjectFormSchema writes. One definition,
 * so a field added in the builder appears on both without anybody remembering
 * to add it twice — which is how a detail page comes to be missing the field
 * somebody added last quarter.
 *
 * Was `<x-dynamic-detail>` until migration Phase 6.8 deleted the Blade
 * renderer; see ObjectFormSchema for why the class outlived the tag.
 *
 * VALUES ARE DISPLAYED THROUGH THEIR DEFINITION, not raw. An enum shows its
 * human label rather than `semi_automated`; a lookup shows the name of what it
 * points at rather than `42`; money is rendered in major units with its
 * currency rather than as a bare count of kobo. Every one of those is a place
 * where showing the stored value would be technically accurate and useless.
 *
 * PII IS MARKED, NOT HIDDEN. The is_pii flag governs logging and export
 * redaction; a user entitled to open the record is entitled to read it. What
 * the flag does here is tell them what they are looking at.
 */
class ObjectDetailSchema
{
    public ?ObjectType $objectType;

    /** @var Collection<int, ObjectAttribute> */
    public Collection $fields;

    public function __construct(
        public ?Model $record = null,
        public ObjectType|string|int|null $type = null,
        public ?array $sections = null,
        public array $omit = [],
        /** Hide fields with no value, for a record that is mostly blank. */
        public bool $hideEmpty = false,
        public bool $mobile = false,
    ) {
        $this->objectType = $this->resolveType($type, $record);
        $this->fields = $this->resolveFields();
    }

    /**
     * @return Collection<string, Collection<int, ObjectAttribute>>
     */
    public function sectioned(): Collection
    {
        $grouped = $this->fields
            ->reject(fn (ObjectAttribute $field) => $this->hideEmpty && $this->isBlank($field))
            ->groupBy(fn (ObjectAttribute $field) => $field->section ?: 'Details');

        if ($this->sections === null) {
            return $grouped;
        }

        return collect($this->sections)
            ->mapWithKeys(fn (string $section) => [$section => $grouped->get($section, collect())])
            ->filter(fn (Collection $fields) => $fields->isNotEmpty());
    }

    /**
     * The value as a human should read it.
     */
    public function display(ObjectAttribute $field): ?string
    {
        $value = $this->rawValue($field);

        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        $resolver = app(FormOptionResolver::class);

        if ($resolver->isChoice($field)) {
            $options = $resolver->optionsFor($field);

            if ($field->data_type === 'multi_enum') {
                return implode(', ', array_map(
                    fn ($item) => $options[$item] ?? (string) $item,
                    (array) $value
                ));
            }

            // An option that has since been removed from the list still has to
            // render: the record holds it whatever the configuration now says.
            return $options[$value] ?? (string) $value;
        }

        return match ($field->data_type) {
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'Yes' : 'No',
            'money' => $this->currency().' '.number_format(((float) $value) / 100, 2),
            'decimal' => rtrim(rtrim(number_format((float) $value, 2), '0'), '.'),
            'date' => $this->asDate($value)?->format('d M Y') ?? (string) $value,
            'datetime' => $this->asDate($value)?->format('d M Y H:i') ?? (string) $value,
            'json' => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            default => is_array($value) ? implode(', ', $value) : (string) $value,
        };
    }

    /** Long text renders as a block rather than in a definition row. */
    public function isBlock(ObjectAttribute $field): bool
    {
        return in_array($field->data_type, ['text', 'json'], true) || $field->width === 'full';
    }

    /* ------------------------------------------------------------------ */

    private function isBlank(ObjectAttribute $field): bool
    {
        $value = $this->rawValue($field);

        return $value === null || $value === '' || $value === [];
    }

    private function rawValue(ObjectAttribute $field): mixed
    {
        if ($this->record === null) {
            return null;
        }

        if ($field->isMapped()) {
            return $this->record->getAttribute($field->maps_to_column);
        }

        if (! method_exists($this->record, 'graphObject')) {
            return null;
        }

        return $this->record->graphObject()?->customAttributes()[$field->code] ?? null;
    }

    private function asDate(mixed $value): ?\DateTimeInterface
    {
        if ($value instanceof \DateTimeInterface) {
            return $value;
        }

        try {
            return new \DateTimeImmutable((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function currency(): string
    {
        return (string) (auth()->user()?->organization?->settings['reporting_currency'] ?? 'NGN');
    }

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
            ->reject(fn (ObjectAttribute $field) => ! $field->show_in_detail)
            ->reject(fn (ObjectAttribute $field) => $this->mobile && ! $field->show_on_mobile)
            ->filter(fn (ObjectAttribute $field) => $field->visibleToCurrentUser())
            ->sortBy([['section', 'asc'], ['sort_order', 'asc']])
            ->values();
    }
}
