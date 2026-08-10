<?php

namespace App\Services\Configuration;

/**
 * WP-05 TASK 4 — the structured diff.
 *
 * FOUR CATEGORIES, AND THE FOURTH IS THE ONE THAT MATTERS.
 *
 *   added      in the bundle, not in the target
 *   changed    in both, with different values
 *   removed    in the target, not in the bundle
 *   conflicting  in both, changed on BOTH sides since a common ancestor
 *
 * Most import tooling has the first three and calls the job done. Without the
 * fourth, an import silently discards work: somebody edits a scoring band in
 * production on Tuesday, a bundle exported on Monday is applied on Wednesday,
 * and Tuesday's change is gone with no record that it ever existed. Conflicts
 * are detected by comparing both sides against the snapshot of the last apply
 * — the common ancestor — and are reported separately so an operator decides
 * rather than discovering.
 *
 * REMOVED IS NEVER APPLIED BY DEFAULT. A bundle that lacks a section a target
 * has is far more often an older or narrower export than an instruction to
 * delete. Removals are reported and require --prune to act on.
 */
class ConfigurationDiffer
{
    /**
     * @param  array<string, mixed>  $incoming  the bundle being imported
     * @param  array<string, mixed>  $current  the target's own configuration
     * @param  array<string, mixed>|null  $ancestor  the last applied snapshot
     * @return array{
     *     sections: array<string, array{added:list<array>,changed:list<array>,removed:list<array>,conflicting:list<array>}>,
     *     totals: array{added:int,changed:int,removed:int,conflicting:int},
     *     is_empty: bool
     * }
     */
    public function diff(array $incoming, array $current, ?array $ancestor = null): array
    {
        $sections = [];
        $totals = ['added' => 0, 'changed' => 0, 'removed' => 0, 'conflicting' => 0];

        $sectionNames = array_unique(array_merge(
            array_keys($incoming['sections'] ?? []),
            array_keys($current['sections'] ?? []),
        ));

        foreach ($sectionNames as $section) {
            // A reserved section carries no rows in either direction yet.
            if (in_array($section, ConfigurationSchema::reservedSections(), true)) {
                continue;
            }

            $result = $this->diffSection(
                $section,
                $this->keyed($section, $incoming['sections'][$section] ?? []),
                $this->keyed($section, $current['sections'][$section] ?? []),
                $ancestor === null ? null : $this->keyed($section, $ancestor['sections'][$section] ?? []),
            );

            if ($result['added'] === [] && $result['changed'] === []
                && $result['removed'] === [] && $result['conflicting'] === []) {
                continue;
            }

            $sections[$section] = $result;

            foreach ($totals as $category => $count) {
                $totals[$category] = $count + count($result[$category]);
            }
        }

        return [
            'sections' => $sections,
            'totals' => $totals,
            'is_empty' => array_sum($totals) === 0,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $incoming
     * @param  array<string, array<string, mixed>>  $current
     * @param  array<string, array<string, mixed>>|null  $ancestor
     * @return array{added:list<array>,changed:list<array>,removed:list<array>,conflicting:list<array>}
     */
    private function diffSection(string $section, array $incoming, array $current, ?array $ancestor): array
    {
        $result = ['added' => [], 'changed' => [], 'removed' => [], 'conflicting' => []];

        foreach ($incoming as $key => $row) {
            if (! array_key_exists($key, $current)) {
                $result['added'][] = ['key' => $key, 'row' => $row];

                continue;
            }

            $fieldChanges = $this->fieldChanges($current[$key], $row);

            if ($fieldChanges === []) {
                continue;
            }

            // A conflict needs a common ancestor to be meaningful: without one
            // there is no way to tell "they changed it" from "it was always
            // like that here".
            $ancestorRow = $ancestor[$key] ?? null;

            $conflicts = $ancestorRow === null
                ? []
                : $this->conflictingFields($ancestorRow, $current[$key], $row);

            if ($conflicts !== []) {
                $result['conflicting'][] = [
                    'key' => $key,
                    'fields' => $conflicts,
                    'row' => $row,
                ];

                continue;
            }

            $result['changed'][] = [
                'key' => $key,
                'fields' => $fieldChanges,
                'row' => $row,
            ];
        }

        foreach ($current as $key => $row) {
            if (! array_key_exists($key, $incoming)) {
                $result['removed'][] = ['key' => $key, 'row' => $row];
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{from:mixed,to:mixed}>
     */
    private function fieldChanges(array $before, array $after): array
    {
        $changes = [];

        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $field) {
            $from = $before[$field] ?? null;
            $to = $after[$field] ?? null;

            if (! $this->equivalent($from, $to)) {
                $changes[$field] = ['from' => $from, 'to' => $to];
            }
        }

        return $changes;
    }

    /**
     * Fields both sides moved away from the ancestor, in different directions.
     *
     * @param  array<string, mixed>  $ancestor
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $incoming
     * @return array<string, array{base:mixed,theirs:mixed,ours:mixed}>
     */
    private function conflictingFields(array $ancestor, array $current, array $incoming): array
    {
        $conflicts = [];

        foreach ($this->fieldChanges($current, $incoming) as $field => $change) {
            $base = $ancestor[$field] ?? null;

            $targetMoved = ! $this->equivalent($base, $current[$field] ?? null);
            $bundleMoved = ! $this->equivalent($base, $incoming[$field] ?? null);

            if ($targetMoved && $bundleMoved) {
                $conflicts[$field] = [
                    'base' => $base,
                    'ours' => $current[$field] ?? null,
                    'theirs' => $incoming[$field] ?? null,
                ];
            }
        }

        return $conflicts;
    }

    /**
     * Whether two exported values mean the same thing.
     *
     * Key order inside a JSON column is not meaningful and must not read as a
     * change, or every diff against a re-exported bundle is full of noise and
     * an operator stops reading them — which is the failure mode that lets a
     * real change through. Boolean 1 and true are likewise the same value
     * arriving from two different drivers.
     */
    private function equivalent(mixed $a, mixed $b): bool
    {
        if (is_bool($a) || is_bool($b)) {
            if ($this->isBoolish($a) && $this->isBoolish($b)) {
                return (bool) $a === (bool) $b;
            }
        }

        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }

        if (is_array($a) && is_array($b)) {
            return ConfigurationExporter::canonicalise($a) == ConfigurationExporter::canonicalise($b);
        }

        // null and '' both mean "not set" in a nullable column, and round-trip
        // through JSON and three database drivers as either.
        if (($a === null || $a === '') && ($b === null || $b === '')) {
            return true;
        }

        return $a === $b;
    }

    private function isBoolish(mixed $value): bool
    {
        return is_bool($value) || $value === 0 || $value === 1 || $value === '0' || $value === '1';
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function keyed(string $section, array $rows): array
    {
        $keyed = [];

        foreach ($rows as $row) {
            $keyed[ConfigurationSchema::naturalKey($section, $row)] = $row;
        }

        return $keyed;
    }
}
