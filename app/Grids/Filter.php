<?php

namespace App\Grids;

use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * One dropdown filter on a grid toolbar. By default a selected value becomes
 * WHERE {column} = {value}; pass ->apply() when the filter needs a join,
 * a range, or anything richer.
 */
class Filter
{
    public string $key;
    public string $label;

    /** @var array<string, string>|Closure value => label, or a closure resolving that lazily */
    public array|Closure $options = [];

    public ?Closure $apply = null;

    /** Column used by the default equality WHERE when it differs from $key. */
    public ?string $sqlColumn = null;

    public static function make(string $key, string $label): self
    {
        $filter = new self();
        $filter->key = $key;
        $filter->label = $label;

        return $filter;
    }

    /** @param array<string, string>|Closure $options */
    public function options(array|Closure $options): self
    {
        $this->options = $options;

        return $this;
    }

    public function column(string $sqlColumn): self
    {
        $this->sqlColumn = $sqlColumn;

        return $this;
    }

    /** @param Closure(Builder, string): void $apply */
    public function apply(Closure $apply): self
    {
        $this->apply = $apply;

        return $this;
    }

    public function resolveOptions(): array
    {
        return $this->options instanceof Closure ? ($this->options)() : $this->options;
    }

    public function applyTo(Builder $query, string $value): void
    {
        if ($this->apply) {
            ($this->apply)($query, $value);

            return;
        }

        $query->where($this->sqlColumn ?? $this->key, $value);
    }
}
