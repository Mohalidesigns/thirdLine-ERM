<?php

namespace App\Grids;

use Closure;

/**
 * One column of a data grid: where the value comes from, how it renders,
 * and what the engine may do with it (sort, search, edit, hide).
 *
 * Rendering is declarative — a type plus options — so the Blade side owns
 * every pixel and the definition never emits HTML. The one escape hatch is
 * ->using(), whose closure returns a plain string (it is escaped on output).
 */
class Column
{
    public string $key;

    public string $label;

    public bool $sortable = false;

    public bool $searchable = false;

    public bool $visibleByDefault = true;

    /** Column used for ORDER BY / WHERE when it differs from $key (e.g. a relation aggregate). */
    public ?string $sqlColumn = null;

    /** text | badge | date | datetime | money | rag | progress | count */
    public string $type = 'text';

    public array $typeOptions = [];

    /** Renders the cell as a link to this URL. */
    public ?Closure $linkTo = null;

    /** Overrides the raw value before formatting. */
    public ?Closure $value = null;

    /** null = read-only; 'text' or ['options' => [...]] = inline editable. */
    public string|array|null $editable = null;

    public static function make(string $key, string $label): self
    {
        $column = new self;
        $column->key = $key;
        $column->label = $label;

        return $column;
    }

    public function sortable(?string $sqlColumn = null): self
    {
        $this->sortable = true;
        $this->sqlColumn = $sqlColumn ?? $this->sqlColumn;

        return $this;
    }

    public function searchable(?string $sqlColumn = null): self
    {
        $this->searchable = true;
        $this->sqlColumn = $sqlColumn ?? $this->sqlColumn;

        return $this;
    }

    public function hiddenByDefault(): self
    {
        $this->visibleByDefault = false;

        return $this;
    }

    /** @param array<string, string> $map value => tailwind classes; '*' is the fallback */
    public function badge(array $map): self
    {
        $this->type = 'badge';
        $this->typeOptions = ['map' => $map];

        return $this;
    }

    public function date(string $format = 'd M Y'): self
    {
        $this->type = 'date';
        $this->typeOptions = ['format' => $format];

        return $this;
    }

    public function datetime(string $format = 'd M Y H:i'): self
    {
        $this->type = 'datetime';
        $this->typeOptions = ['format' => $format];

        return $this;
    }

    /** Money stored in minor units (kobo) unless $minorUnits is false. */
    public function money(bool $minorUnits = true): self
    {
        $this->type = 'money';
        $this->typeOptions = ['minor' => $minorUnits];

        return $this;
    }

    /** Red/amber/green chip from a value => rag-level map (levels: red, amber, green, neutral). */
    public function rag(array $map): self
    {
        $this->type = 'rag';
        $this->typeOptions = ['map' => $map];

        return $this;
    }

    public function progress(): self
    {
        $this->type = 'progress';

        return $this;
    }

    public function count(): self
    {
        $this->type = 'count';

        return $this;
    }

    public function linkTo(Closure $url): self
    {
        $this->linkTo = $url;

        return $this;
    }

    public function using(Closure $value): self
    {
        $this->value = $value;

        return $this;
    }

    public function editableText(): self
    {
        $this->editable = 'text';

        return $this;
    }

    /** @param array<string, string> $options value => label */
    public function editableSelect(array $options): self
    {
        $this->editable = ['options' => $options];

        return $this;
    }

    public function orderByColumn(): string
    {
        return $this->sqlColumn ?? $this->key;
    }
}
