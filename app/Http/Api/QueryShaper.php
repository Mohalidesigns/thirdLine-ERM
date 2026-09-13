<?php

namespace App\Http\Api;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\CursorPaginator;

/**
 * WP-07 TASK 2 — turns JSON:API query parameters into a bounded query.
 *
 * BOUNDED IS THE POINT. Every parameter is checked against the resource's
 * allowlist before it reaches the builder. Without that, `?sort=remember_token`
 * orders by a secret, `?filter[password]=` becomes an oracle, and
 * `?include=owner.tokens` publishes a relationship nobody meant to. An API over
 * a risk register is not the place to trust a query string.
 *
 * CURSOR PAGINATION BY DEFAULT. Offset pagination on a register that is being
 * written to while it is read silently skips and repeats rows — a client
 * exporting page by page ends up with a file that is missing risks and has
 * others twice, and nothing in the output says so. Offset is still available
 * (`?page[number]=`) because some clients need a total count, and the cost is
 * documented rather than hidden.
 */
class QueryShaper
{
    private const DEFAULT_PAGE_SIZE = 25;

    private const MAX_PAGE_SIZE = 200;

    /** @var list<string> */
    private array $ignored = [];

    /** @param array<string, mixed> $definition */
    public function __construct(private array $definition) {}

    /**
     * Parameters that were asked for and refused, reported back in `meta` so a
     * client is told its filter did nothing instead of quietly receiving
     * everything.
     *
     * @return list<string>
     */
    public function ignored(): array
    {
        return $this->ignored;
    }

    public function apply(Builder $query, Request $request): Builder
    {
        $this->applyFilters($query, $request);
        $this->applyIncludes($query, $request);
        $this->applySorts($query, $request);

        return $query;
    }

    /**
     * @return CursorPaginator|\Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function paginate(Builder $query, Request $request)
    {
        $size = (int) ($request->input('page.size') ?? self::DEFAULT_PAGE_SIZE);
        $size = max(1, min(self::MAX_PAGE_SIZE, $size));

        if ($request->has('page.number')) {
            return $query->paginate($size, ['*'], 'page[number]')->withQueryString();
        }

        return $query->cursorPaginate($size, ['*'], 'page[cursor]')->withQueryString();
    }

    /* ------------------------------------------------------------------ */

    private function applyFilters(Builder $query, Request $request): void
    {
        $allowed = (array) ($this->definition['filters'] ?? []);
        $filters = (array) $request->input('filter', []);

        foreach ($filters as $field => $value) {
            if (! in_array($field, $allowed, true)) {
                $this->ignored[] = "filter[{$field}]";

                continue;
            }

            // A comma means "any of these". It is the JSON:API convention and
            // saves clients from N round trips for "open or in progress".
            $values = is_string($value) ? array_filter(explode(',', $value), fn ($v) => $v !== '') : (array) $value;

            if ($values === []) {
                continue;
            }

            // Range syntax: filter[created_at]=gte:2026-01-01
            if (count($values) === 1 && is_string($values[0] ?? null) && str_contains($values[0], ':')) {
                [$operator, $operand] = explode(':', $values[0], 2);

                $sql = match ($operator) {
                    'gte' => '>=', 'gt' => '>', 'lte' => '<=', 'lt' => '<', 'ne' => '!=',
                    default => null,
                };

                if ($sql !== null) {
                    $query->where($field, $sql, $operand);

                    continue;
                }

                if ($operator === 'like') {
                    // Escaped: a client sending % should not get a table scan
                    // over every row that happens to contain anything.
                    $query->where($field, 'like', '%'.addcslashes($operand, '%_\\').'%');

                    continue;
                }

                if ($operator === 'null') {
                    $operand === 'true' ? $query->whereNull($field) : $query->whereNotNull($field);

                    continue;
                }
            }

            $query->whereIn($field, array_map([$this, 'castBooleans'], $values));
        }
    }

    private function applyIncludes(Builder $query, Request $request): void
    {
        $allowed = (array) ($this->definition['includes'] ?? []);
        $asked = array_filter(explode(',', (string) $request->input('include', '')));

        $load = [];

        foreach ($asked as $relation) {
            $relation = trim($relation);

            if (in_array($relation, $allowed, true)) {
                $load[] = $relation;
            } else {
                $this->ignored[] = "include={$relation}";
            }
        }

        if ($load !== []) {
            $query->with($load);
        }
    }

    private function applySorts(Builder $query, Request $request): void
    {
        $allowed = (array) ($this->definition['sorts'] ?? []);
        $asked = array_filter(explode(',', (string) $request->input('sort', '')));
        $applied = 0;

        foreach ($asked as $field) {
            $field = trim($field);
            $direction = 'asc';

            if (str_starts_with($field, '-')) {
                $direction = 'desc';
                $field = substr($field, 1);
            }

            if (! in_array($field, $allowed, true)) {
                $this->ignored[] = "sort={$field}";

                continue;
            }

            $query->orderBy($field, $direction);
            $applied++;
        }

        // Cursor pagination needs a deterministic total order; without a
        // tie-break on the primary key, rows sharing a sort value can be
        // skipped or repeated across pages.
        $query->orderBy($query->getModel()->getQualifiedKeyName(), $applied > 0 ? 'asc' : 'desc');
    }

    private function castBooleans(mixed $value): mixed
    {
        return match ($value) {
            'true' => true,
            'false' => false,
            default => $value,
        };
    }

    /**
     * The fields a client asked for, intersected with what the resource
     * publishes. `?fields[risks]=risk_code,title`
     *
     * @return list<string>
     */
    public function fieldsFor(string $resource, Request $request): array
    {
        $published = (array) ($this->definition['fields'] ?? []);
        $asked = array_filter(explode(',', (string) $request->input("fields.{$resource}", '')));

        if ($asked === []) {
            return $published;
        }

        return array_values(array_intersect($published, array_map('trim', $asked)));
    }
}
