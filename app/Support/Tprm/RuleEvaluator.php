<?php

namespace App\Support\Tprm;

/**
 * The TPRM rules DSL — TRD §9.2.
 *
 * Four different things in this module are the same problem: which questions
 * does this engagement see, which contract clauses does it need, which
 * knockouts fire, and which monitoring signals raise an alert. They share one
 * JSON grammar so an administrator learns it once, and one evaluator so all
 * four behave identically.
 *
 *   { "all": [ node, ... ] }        every child true
 *   { "any": [ node, ... ] }        at least one child true
 *   { "not": node }                 negation
 *   { "fact": "…", "op": "…", "value": … }
 *
 * THE EVALUATOR IS PURE AND TOUCHES NO DATABASE. It takes a flat context array
 * and returns a boolean. That is not a style preference: these rules ARE the
 * regulatory logic — KO-PII-XB deciding that a cross-border transfer without a
 * recorded NDPA §41 basis is Critical, a blocking clause deciding an
 * engagement may not go live — and logic that needs a database to be exercised
 * is logic that gets tested through six layers of fixture, or not at all.
 *
 * TWO DECISIONS ABOUT MISSING FACTS, both deliberate:
 *
 *   1. AN UNKNOWN FACT NEVER THROWS. A rule referencing a fact that is not in
 *      the context evaluates that leaf as false (or, under `not`, true). A
 *      throw here would mean a questionnaire fails to render because a rule
 *      mentions an attribute the engagement has not filled in yet, which is
 *      the normal state of a half-completed intake.
 *
 *   2. BUT AN UNKNOWN FACT IS NOT SILENT. Every unresolved fact is collected
 *      in `unresolvedFacts()` so the rule builder's live preview can say "this
 *      rule refers to `engagement.pci_in_scope`, which is not set" instead of
 *      showing a confident, wrong `false`. A rule that quietly never fires is
 *      the worst outcome available here: nobody reports a control that was
 *      never asked for.
 *
 * `exists` and `empty` are the two operators that DO distinguish a missing
 * fact — that is their whole purpose — so they are handled before the
 * resolution check.
 */
class RuleEvaluator
{
    /** @var list<string> */
    private array $unresolvedFacts = [];

    /** @var list<string> */
    public const OPERATORS = [
        'eq', 'ne', 'in', 'not_in', 'gt', 'gte', 'lt', 'lte', 'contains', 'exists', 'empty',
    ];

    /**
     * Evaluate a rule against a context.
     *
     * A null or empty rule is TRUE — "no visibility rule" means "always
     * visible", which is what an author who left the field blank meant.
     *
     * @param  array<string, mixed>|null  $rule
     * @param  array<string, mixed>  $context  flat, dot-keyed facts
     */
    public function evaluate(?array $rule, array $context): bool
    {
        $this->unresolvedFacts = [];

        if ($rule === null || $rule === []) {
            return true;
        }

        return $this->node($rule, $context);
    }

    /**
     * The facts the last evaluation referenced but could not resolve.
     *
     * @return list<string>
     */
    public function unresolvedFacts(): array
    {
        return array_values(array_unique($this->unresolvedFacts));
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $context
     */
    private function node(array $node, array $context): bool
    {
        if (array_key_exists('all', $node)) {
            $children = $this->children($node['all']);

            // An empty `all` is true — the vacuous truth, and the same answer
            // SQL and every other rule engine gives.
            foreach ($children as $child) {
                if (! $this->node($child, $context)) {
                    return false;
                }
            }

            return true;
        }

        if (array_key_exists('any', $node)) {
            $children = $this->children($node['any']);

            // An empty `any` is false: "at least one of nothing" is not
            // satisfiable, and treating it as true would make a rule with an
            // accidentally emptied branch match everything.
            foreach ($children as $child) {
                if ($this->node($child, $context)) {
                    return true;
                }
            }

            return false;
        }

        if (array_key_exists('not', $node)) {
            $child = $node['not'];

            if (! is_array($child)) {
                return false;
            }

            return ! $this->node($child, $context);
        }

        if (array_key_exists('fact', $node)) {
            return $this->leaf($node, $context);
        }

        // A node that is none of the five shapes is malformed. False rather
        // than a throw, for the same reason a missing fact is false: a broken
        // rule must not take a screen down. The rule builder validates on save
        // and is where a malformed rule should be caught.
        return false;
    }

    /**
     * @param  mixed  $value
     * @return list<array<string, mixed>>
     */
    private function children(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $context
     */
    private function leaf(array $node, array $context): bool
    {
        $fact = $node['fact'];

        if (! is_string($fact)) {
            return false;
        }

        $op = is_string($node['op'] ?? null) ? $node['op'] : 'eq';
        $expected = $node['value'] ?? null;

        $present = array_key_exists($fact, $context);
        $actual = $present ? $context[$fact] : null;

        // These two ask ABOUT presence, so they answer before the miss is
        // recorded — a rule checking whether a fact exists has not failed to
        // resolve it.
        if ($op === 'exists') {
            return $present && $actual !== null;
        }

        if ($op === 'empty') {
            return ! $present || $actual === null || $actual === '' || $actual === [];
        }

        if (! $present) {
            $this->unresolvedFacts[] = $fact;

            return false;
        }

        return $this->compare($op, $actual, $expected);
    }

    private function compare(string $op, mixed $actual, mixed $expected): bool
    {
        return match ($op) {
            'eq' => $this->looselyEqual($actual, $expected),
            'ne' => ! $this->looselyEqual($actual, $expected),
            'in' => $this->inList($actual, $expected),
            'not_in' => ! $this->inList($actual, $expected),
            'gt' => $this->numeric($actual, $expected, fn ($a, $b) => $a > $b),
            'gte' => $this->numeric($actual, $expected, fn ($a, $b) => $a >= $b),
            'lt' => $this->numeric($actual, $expected, fn ($a, $b) => $a < $b),
            'lte' => $this->numeric($actual, $expected, fn ($a, $b) => $a <= $b),
            'contains' => $this->contains($actual, $expected),
            default => false,
        };
    }

    /**
     * Equality with ONE coercion and no more: the boolean one.
     *
     * A rule authored in a JSON editor says `"value": true`, and the fact
     * arriving from a form post or a JSON column is very often `1`, `"1"` or
     * `"true"`. Refusing to match those would make every boolean rule in the
     * module a coin toss depending on where its fact came from.
     *
     * Everything else compares with `===` after a string/number reconciliation.
     * PHP's `==` is not used anywhere here: `"abc" == 0` was true for years and
     * a regulatory rule is not the place to discover which version of that
     * behaviour is live.
     */
    private function looselyEqual(mixed $actual, mixed $expected): bool
    {
        if (is_bool($expected)) {
            return $this->toBool($actual) === $expected;
        }

        if (is_bool($actual)) {
            return $actual === $this->toBool($expected);
        }

        if (is_numeric($actual) && is_numeric($expected)) {
            return (float) $actual === (float) $expected;
        }

        if (is_scalar($actual) && is_scalar($expected)) {
            return (string) $actual === (string) $expected;
        }

        return $actual === $expected;
    }

    private function toBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === 1 || $value === '1' || $value === 'true') {
            return true;
        }

        if ($value === 0 || $value === '0' || $value === 'false' || $value === '') {
            return false;
        }

        return null;
    }

    /**
     * `in` is symmetric about which side is the list.
     *
     * `{fact: "engagement.transfer_basis", op: "in", value: ["bcr","scc"]}` is
     * the ordinary reading. But `answer.Q-DATA-07` on a multi-select question
     * IS a list, and an author writing `{fact: "answer.Q-DATA-07", op: "in",
     * value: "encryption"}` means "is encryption among the answers". Both are
     * reasonable readings of the same word and the DSL is authored by risk
     * officers, not programmers, so both work.
     */
    private function inList(mixed $actual, mixed $expected): bool
    {
        if (is_array($expected)) {
            foreach ($expected as $candidate) {
                if ($this->looselyEqual($actual, $candidate)) {
                    return true;
                }
            }

            return false;
        }

        if (is_array($actual)) {
            foreach ($actual as $candidate) {
                if ($this->looselyEqual($candidate, $expected)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Ordered comparison, defined only over numbers.
     *
     * A non-numeric operand returns false rather than falling back to string
     * ordering. "critical" > "high" is true alphabetically and meaningless as
     * a risk statement; a rule author who wants tier ordering should compare
     * the score, and getting a silent false points them at that.
     */
    private function numeric(mixed $actual, mixed $expected, callable $test): bool
    {
        if (! is_numeric($actual) || ! is_numeric($expected)) {
            return false;
        }

        return (bool) $test((float) $actual, (float) $expected);
    }

    /**
     * `contains` covers both a list holding a member and a string holding a
     * substring. String matching is case-insensitive, because the facts it is
     * used against — a certificate's scope text, a service description — are
     * prose typed by whoever typed it.
     */
    private function contains(mixed $actual, mixed $expected): bool
    {
        if (is_array($actual)) {
            foreach ($actual as $candidate) {
                if ($this->looselyEqual($candidate, $expected)) {
                    return true;
                }
            }

            return false;
        }

        if (is_string($actual) && is_scalar($expected)) {
            $needle = (string) $expected;

            return $needle !== '' && str_contains(mb_strtolower($actual), mb_strtolower($needle));
        }

        return false;
    }
}
