<?php

namespace App\Support\Bcms;

use App\Enums\Bcms\PlanSectionSource;
use InvalidArgumentException;

/**
 * What a plan section is bound to, as a validated value object.
 *
 * Stored in `bcms_plan_sections.source_binding`:
 *
 *   {"source": "bia.rto"}
 *   {"source": "bia.rto",        "process_ids": [4, 9]}
 *   {"source": "call_tree",      "call_tree_id": 2}
 *   {"source": "sites.assembly", "site_ids": [1, 3, 7]}
 *
 * SCOPE OMITTED MEANS "INHERIT FROM THE PLAN", NOT "EVERYTHING". A plan carries
 * a business unit and a site; a binding with no explicit ids resolves through
 * those. That is what makes the twelve templates reusable — a departmental BCP
 * template cannot name process ids it has never seen — and it is also why an
 * inherited scope that resolves to nothing renders an empty section rather than
 * the whole organisation's data. The failure mode being avoided is a branch
 * plan that quietly prints head office's recovery objectives.
 *
 * A RAW ARRAY REACHING THE ASSEMBLER IS A BUG, for the same reason as
 * `AudienceRule`: the grammar lives in customer JSON, and an unvalidated
 * binding is a section that renders nothing in six months with no explanation.
 */
final readonly class PlanBinding
{
    /** @param array<string, mixed> $binding */
    private function __construct(public PlanSectionSource $source, public array $binding) {}

    /** @param array<string, mixed> $binding */
    public static function fromArray(array $binding): self
    {
        $source = $binding['source'] ?? null;

        if (! is_string($source)) {
            throw new InvalidArgumentException('A plan section binding needs a string "source".');
        }

        $case = PlanSectionSource::tryFrom($source);

        if ($case === null) {
            throw new InvalidArgumentException("Unknown plan section source '{$source}'.");
        }

        foreach (['process_ids', 'site_ids'] as $key) {
            if (! array_key_exists($key, $binding)) {
                continue;
            }

            $ids = $binding[$key];

            if (! is_array($ids) || array_filter($ids, fn ($i) => ! is_numeric($i)) !== []) {
                throw new InvalidArgumentException("A plan section binding's \"{$key}\" must be an array of numbers.");
            }
        }

        if (array_key_exists('call_tree_id', $binding) && ! is_numeric($binding['call_tree_id'])) {
            throw new InvalidArgumentException('A plan section binding\'s "call_tree_id" must be numeric.');
        }

        return new self($case, $binding);
    }

    /**
     * Build from JSON as it comes off the model cast, tolerating null.
     *
     * Null is the honest answer for a free-text section. A section that is not
     * bound is not broken, and the caller has to be able to tell the two apart.
     */
    public static function fromJson(mixed $value): ?self
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException('A plan section binding must be a JSON object.');
        }

        return self::fromArray($value);
    }

    /** @param array<string, mixed> $extra */
    public static function make(PlanSectionSource $source, array $extra = []): self
    {
        return self::fromArray(['source' => $source->value] + $extra);
    }

    /** @return list<int>|null Null means "inherit from the plan". */
    public function processIds(): ?array
    {
        if (! array_key_exists('process_ids', $this->binding)) {
            return null;
        }

        return array_values(array_map('intval', $this->binding['process_ids']));
    }

    /** @return list<int>|null Null means "inherit from the plan". */
    public function siteIds(): ?array
    {
        if (! array_key_exists('site_ids', $this->binding)) {
            return null;
        }

        return array_values(array_map('intval', $this->binding['site_ids']));
    }

    public function callTreeId(): ?int
    {
        return isset($this->binding['call_tree_id']) ? (int) $this->binding['call_tree_id'] : null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->binding;
    }
}
