<?php

namespace App\Support\Bcms;

use InvalidArgumentException;

/**
 * The audience grammar, as a value object. FROZEN AT G0 (ADR 0003).
 *
 * Three features need to answer "who does this go to?" — the T-10 reminder
 * ladder (Phase 5, Track B), an EMNS dispatch (Phase 7, Track C) and an
 * incident notification (Phase 10, Track D) — built by three tracks in three
 * sessions over ten weeks. Left alone they produce three targeting languages,
 * two of which will not support the case the third invented. This is the one
 * grammar, and `AudienceResolver` is the one resolver.
 *
 * A RAW ARRAY REACHING THE RESOLVER IS A BUG. Everything constructs a rule
 * through `make()` or `fromArray()`, both of which validate. The grammar is
 * stored as JSON in customer data — on every reminder schedule and every alert
 * — so an unvalidated rule is a row that resolves to nobody in six months and
 * nobody can say why.
 *
 *   {"type": "org_node",               "id": 12, "include_descendants": true}
 *   {"type": "site",                   "ids": [3, 7]}
 *   {"type": "role",                   "names": ["branch-manager"], "org_node_id": 12}
 *   {"type": "call_tree",              "id": 4, "tiers": [1, 2]}
 *   {"type": "occurrence_participants","id": 88, "roles": ["participant"]}
 *   {"type": "saved_group",            "id": 2}
 *   {"type": "geo",                    "lat": 12.0, "lng": 8.59, "radius_km": 25}
 *   {"type": "all_of" | "any_of" | "none_of", "rules": [ … ]}
 *
 * `none_of` IS APPLIED LAST AND CAN ONLY REMOVE. A rule that resolves to
 * nobody resolves to nobody; it never falls back to everybody. Fail closed.
 */
final readonly class AudienceRule
{
    public const LEAF_TYPES = [
        'org_node', 'site', 'role', 'call_tree', 'occurrence_participants', 'saved_group', 'geo',
    ];

    public const COMBINATORS = ['all_of', 'any_of', 'none_of'];

    /** Nesting deeper than this is a rule nobody wrote by hand, and a cheap DoS. */
    public const MAX_DEPTH = 6;

    /** @param array<string, mixed> $rule */
    private function __construct(public array $rule) {}

    /** @param array<string, mixed> $rule */
    public static function fromArray(array $rule): self
    {
        self::assertValid($rule, 0);

        return new self($rule);
    }

    /**
     * Build from JSON as it comes off a model cast, tolerating null.
     *
     * A NULL RULE IS NOT AN EMPTY RULE. Null means nobody chose an audience,
     * which is a draft; an empty `any_of` means somebody chose an audience that
     * matches nobody, which is a mistake worth showing them. Both resolve to
     * nobody, and only one of them should be dispatchable.
     */
    public static function fromJson(mixed $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException('An audience rule must be a JSON object.');
        }

        return self::fromArray($value);
    }

    /** @param array<string, mixed> $extra */
    public static function make(string $type, array $extra = []): self
    {
        return self::fromArray(['type' => $type] + $extra);
    }

    /** @param list<self|array<string, mixed>> $rules */
    public static function anyOf(array $rules): self
    {
        return self::combinator('any_of', $rules);
    }

    /** @param list<self|array<string, mixed>> $rules */
    public static function allOf(array $rules): self
    {
        return self::combinator('all_of', $rules);
    }

    /** @param list<self|array<string, mixed>> $rules */
    public static function noneOf(array $rules): self
    {
        return self::combinator('none_of', $rules);
    }

    /** @param list<self|array<string, mixed>> $rules */
    private static function combinator(string $type, array $rules): self
    {
        return self::fromArray([
            'type' => $type,
            'rules' => array_map(fn ($r) => $r instanceof self ? $r->rule : $r, $rules),
        ]);
    }

    public function type(): string
    {
        return (string) $this->rule['type'];
    }

    public function isCombinator(): bool
    {
        return in_array($this->type(), self::COMBINATORS, true);
    }

    /** @return list<self> */
    public function children(): array
    {
        if (! $this->isCombinator()) {
            return [];
        }

        return array_map(
            fn (array $r) => new self($r),
            array_values($this->rule['rules'] ?? [])
        );
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->rule[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->rule;
    }

    /**
     * A stable hash of the rule, for the reminder idempotency key (ADR 0005).
     *
     * Key order must not change the hash, or regenerating an identical ladder
     * would produce different keys and send everything twice — the exact defect
     * Gate G1 tests for.
     */
    public function fingerprint(): string
    {
        return sha1(json_encode(self::normalise($this->rule)));
    }

    /** @param array<string, mixed> $rule */
    private static function normalise(array $rule): array
    {
        ksort($rule);

        foreach ($rule as $key => $value) {
            if ($key === 'rules' && is_array($value)) {
                $rule[$key] = array_map(
                    fn ($r) => is_array($r) ? self::normalise($r) : $r,
                    array_values($value)
                );
            } elseif (is_array($value)) {
                $sorted = $value;
                sort($sorted);
                $rule[$key] = $sorted;
            }
        }

        return $rule;
    }

    /* ------------------------------------------------------------------ */
    /*  Validation */
    /* ------------------------------------------------------------------ */

    /** @param array<string, mixed> $rule */
    private static function assertValid(array $rule, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException('Audience rule nested deeper than '.self::MAX_DEPTH.' levels.');
        }

        $type = $rule['type'] ?? null;

        if (! is_string($type)) {
            throw new InvalidArgumentException('An audience rule needs a string "type".');
        }

        if (in_array($type, self::COMBINATORS, true)) {
            $children = $rule['rules'] ?? null;

            if (! is_array($children)) {
                throw new InvalidArgumentException("A '{$type}' rule needs a \"rules\" array.");
            }

            foreach ($children as $child) {
                if (! is_array($child)) {
                    throw new InvalidArgumentException("Every entry in a '{$type}' rule must itself be a rule.");
                }

                self::assertValid($child, $depth + 1);
            }

            return;
        }

        if (! in_array($type, self::LEAF_TYPES, true)) {
            throw new InvalidArgumentException("Unknown audience rule type '{$type}'.");
        }

        match ($type) {
            'org_node', 'call_tree', 'saved_group', 'occurrence_participants' => self::requireId($rule, $type),
            'site' => self::requireIds($rule, $type),
            'role' => self::requireStringList($rule, 'names', $type),
            'geo' => self::requireGeo($rule),
        };
    }

    /** @param array<string, mixed> $rule */
    private static function requireId(array $rule, string $type): void
    {
        if (! isset($rule['id']) || ! is_numeric($rule['id'])) {
            throw new InvalidArgumentException("A '{$type}' rule needs a numeric \"id\".");
        }
    }

    /** @param array<string, mixed> $rule */
    private static function requireIds(array $rule, string $type): void
    {
        $ids = $rule['ids'] ?? null;

        if (! is_array($ids) || $ids === [] || array_filter($ids, fn ($i) => ! is_numeric($i)) !== []) {
            throw new InvalidArgumentException("A '{$type}' rule needs a non-empty \"ids\" array of numbers.");
        }
    }

    /** @param array<string, mixed> $rule */
    private static function requireStringList(array $rule, string $key, string $type): void
    {
        $values = $rule[$key] ?? null;

        if (! is_array($values) || $values === [] || array_filter($values, fn ($v) => ! is_string($v)) !== []) {
            throw new InvalidArgumentException("A '{$type}' rule needs a non-empty \"{$key}\" array of strings.");
        }
    }

    /** @param array<string, mixed> $rule */
    private static function requireGeo(array $rule): void
    {
        foreach (['lat', 'lng', 'radius_km'] as $key) {
            if (! isset($rule[$key]) || ! is_numeric($rule[$key])) {
                throw new InvalidArgumentException("A 'geo' rule needs a numeric \"{$key}\".");
            }
        }

        if ((float) $rule['radius_km'] <= 0) {
            throw new InvalidArgumentException("A 'geo' rule needs a positive \"radius_km\".");
        }
    }
}
