<?php

namespace App\Services\Bcms\Bia;

use App\Enums\Bcms\DependencyCriticality;
use App\Enums\Bcms\DependencyRelation;
use App\Enums\Bcms\DependencyType;
use App\Models\Bcms\BiaAssessment;
use App\Models\Bcms\Dependency;
use App\Models\Bcms\Process;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Dependency mapping, the single-point-of-failure register, and the reverse view.
 *
 * THE REVERSE VIEW IS WHAT THE MODULE IS SOLD ON. "If this application fails,
 * these fourteen processes stop, five of them Tier 1, aggregate RTO exposure
 * four hours" is the sentence an IT director acts on, and no spreadsheet in any
 * bank has it — because a spreadsheet is written per process, and this question
 * is asked per dependency. It is the same rows read the other way round.
 *
 * SHARED DEPENDENCIES ARE THE HIDDEN VULNERABILITY. A dependency that four
 * processes rely on is a concentration nobody chose: each assessor recorded a
 * reasonable dependency and the exposure exists only in the aggregate. This is
 * the same argument TPRM's concentration analysis makes about sub-processors,
 * and it is the reason both modules exist.
 *
 * NOTHING IS MATERIALISED. The graph, the SPOF register and the reverse view are
 * queries over `bcms_dependencies`. A cached graph would be a cache with no
 * invalidation story, and the whole estate is thousands of edges rather than
 * millions (ADR 0009).
 */
class DependencyService
{
    /** How deep a process→process chain is walked before it is called a cycle. */
    private const MAX_CHAIN_DEPTH = 12;

    /**
     * Record a dependency.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function attach(BiaAssessment $assessment, Model $target, array $attributes = []): Dependency
    {
        $type = $this->typeOf($target);

        if ($type === DependencyType::Processes && $target->is($assessment->process)) {
            throw new InvalidArgumentException('A process cannot depend on itself.');
        }

        if ($type === DependencyType::Processes) {
            $this->assertNoCycle($assessment->process, $target);
        }

        $dependency = new Dependency(array_merge([
            'assessment_id' => $assessment->getKey(),
            'dependency_type' => DependencyRelation::Upstream->value,
            'criticality' => DependencyCriticality::Medium->value,
        ], $attributes));

        $dependency->dependable()->associate($target);
        $dependency->save();

        return $dependency;
    }

    /* ------------------------------------------------------------------ */
    /*  The reverse view */
    /* ------------------------------------------------------------------ */

    /**
     * What stops if this thing fails.
     *
     * `aggregate_rto_hours` is the SHORTEST RTO among the dependent processes,
     * not the sum and not the longest. It is the time the organisation has
     * before its first commitment is breached — the number that actually bounds
     * the outage. Summing recovery times would be arithmetic on unrelated
     * clocks, and taking the longest would report the most relaxed process as
     * the constraint.
     *
     * @return array{
     *   target: array<string, mixed>,
     *   processes: list<array<string, mixed>>,
     *   process_count: int, tier1_count: int, critical_service_count: int,
     *   aggregate_rto_hours: float|null, halting_count: int,
     * }
     */
    public function impactOf(Model $target): array
    {
        $type = $this->typeOf($target);

        $dependencies = Dependency::query()
            ->where('dependable_type', $type->value)
            ->where('dependable_id', $target->getKey())
            ->with(['assessment.process'])
            ->get();

        $rows = [];

        foreach ($dependencies as $dependency) {
            $process = $dependency->assessment?->process;

            if ($process === null) {
                continue;
            }

            $relation = DependencyRelation::tryFrom((string) $dependency->dependency_type);
            $criticality = DependencyCriticality::tryFrom((string) $dependency->criticality);

            // Latest APPROVED objectives, so the exposure quotes numbers
            // somebody signed off rather than a draft in flight.
            $approved = BiaAssessment::query()
                ->where('process_id', $process->getKey())
                ->where('status', 'approved')
                ->orderByDesc('approved_at')
                ->first();

            $rows[$process->getKey()] = [
                'process_id' => $process->getKey(),
                'code' => $process->code,
                'name' => $process->name,
                'tier' => $process->criticality_tier,
                'is_critical_service' => (bool) $process->is_critical_service,
                'relation' => $relation?->value,
                'halts' => $relation?->halts() ?? true,
                'criticality' => $criticality?->value,
                'single_point_of_failure' => (bool) $dependency->single_point_of_failure,
                'alternative_available' => (bool) $dependency->alternative_available,
                // Null, not zero. A process with no approved BIA has no stated
                // RTO, and printing 0 would claim instant recovery.
                'rto_hours' => $approved?->rto_hours === null ? null : (float) $approved->rto_hours,
            ];
        }

        $rows = array_values($rows);

        usort($rows, fn (array $a, array $b) => [$a['tier'] ?? 9, $a['rto_hours'] ?? PHP_FLOAT_MAX]
            <=> [$b['tier'] ?? 9, $b['rto_hours'] ?? PHP_FLOAT_MAX]);

        $halting = array_values(array_filter($rows, fn (array $r) => $r['halts']));
        $rtos = array_values(array_filter(array_column($halting, 'rto_hours'), fn ($v) => $v !== null));

        return [
            'target' => [
                'type' => $type->value,
                'type_label' => $type->label(),
                'id' => $target->getKey(),
                'name' => $this->labelOf($target),
            ],
            'processes' => $rows,
            'process_count' => count($rows),
            'tier1_count' => count(array_filter($rows, fn (array $r) => (int) ($r['tier'] ?? 0) === 1)),
            'critical_service_count' => count(array_filter($rows, fn (array $r) => $r['is_critical_service'])),
            'halting_count' => count($halting),
            'aggregate_rto_hours' => $rtos === [] ? null : min($rtos),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Single points of failure */
    /* ------------------------------------------------------------------ */

    /**
     * Every dependency flagged a single point of failure, with how much stops
     * with it.
     *
     * Ordered by consequence — criticality, then how many processes fall over —
     * because a register sorted by code is one nobody works down.
     *
     * @return list<array<string, mixed>>
     */
    public function spofRegister(): array
    {
        $spofs = Dependency::query()
            ->where('single_point_of_failure', true)
            ->with(['assessment.process'])
            ->get();

        $grouped = [];

        foreach ($spofs as $dependency) {
            $key = $dependency->dependable_type.':'.$dependency->dependable_id;

            $grouped[$key] ??= [
                'type' => $dependency->dependable_type,
                'type_label' => $dependency->type()?->label(),
                'id' => (int) $dependency->dependable_id,
                'name' => $dependency->dependableLabel(),
                'processes' => [],
                'criticality' => null,
                'alternative_available' => true,
                'recovery_notes' => [],
            ];

            $process = $dependency->assessment?->process;

            if ($process !== null) {
                $grouped[$key]['processes'][$process->getKey()] = [
                    'id' => $process->getKey(),
                    'code' => $process->code,
                    'name' => $process->name,
                    'tier' => $process->criticality_tier,
                ];
            }

            $criticality = DependencyCriticality::tryFrom((string) $dependency->criticality);
            $existing = $grouped[$key]['criticality'] === null
                ? null
                : DependencyCriticality::tryFrom($grouped[$key]['criticality']);

            // The highest criticality any process assigned it. One assessor
            // calling a shared dependency Medium does not make it Medium for
            // the process that called it Critical.
            if ($criticality !== null && ($existing === null || $criticality->rank() > $existing->rank())) {
                $grouped[$key]['criticality'] = $criticality->value;
            }

            // Available only if EVERY dependent process says so.
            $grouped[$key]['alternative_available'] = $grouped[$key]['alternative_available']
                && (bool) $dependency->alternative_available;

            if (filled($dependency->recovery_notes)) {
                $grouped[$key]['recovery_notes'][] = $dependency->recovery_notes;
            }
        }

        $register = [];

        foreach ($grouped as $entry) {
            $entry['processes'] = array_values($entry['processes']);
            $entry['process_count'] = count($entry['processes']);
            $entry['tier1_count'] = count(array_filter($entry['processes'], fn (array $p) => (int) ($p['tier'] ?? 0) === 1));
            $entry['is_finding'] = (DependencyCriticality::tryFrom((string) $entry['criticality'])?->spofIsFinding() ?? false)
                && ! $entry['alternative_available'];
            $register[] = $entry;
        }

        usort($register, function (array $a, array $b) {
            $rank = fn (?string $c) => DependencyCriticality::tryFrom((string) $c)?->rank() ?? 0;

            return [$rank($b['criticality']), $b['process_count']] <=> [$rank($a['criticality']), $a['process_count']];
        });

        return $register;
    }

    /**
     * Dependencies more than one process relies on — the concentration nobody
     * chose.
     *
     * @return list<array<string, mixed>>
     */
    public function sharedDependencies(int $minimumProcesses = 2): array
    {
        $rows = Dependency::query()->with(['assessment.process'])->get();

        $grouped = [];

        foreach ($rows as $dependency) {
            $process = $dependency->assessment?->process;

            if ($process === null) {
                continue;
            }

            $key = $dependency->dependable_type.':'.$dependency->dependable_id;

            $grouped[$key] ??= [
                'type' => $dependency->dependable_type,
                'type_label' => $dependency->type()?->label(),
                'id' => (int) $dependency->dependable_id,
                'name' => $dependency->dependableLabel(),
                'processes' => [],
            ];

            $grouped[$key]['processes'][$process->getKey()] = [
                'id' => $process->getKey(),
                'code' => $process->code,
                'name' => $process->name,
                'tier' => $process->criticality_tier,
            ];
        }

        $shared = [];

        foreach ($grouped as $entry) {
            $entry['processes'] = array_values($entry['processes']);

            if (count($entry['processes']) < $minimumProcesses) {
                continue;
            }

            $entry['process_count'] = count($entry['processes']);
            $entry['tier1_count'] = count(array_filter($entry['processes'], fn (array $p) => (int) ($p['tier'] ?? 0) === 1));
            $shared[] = $entry;
        }

        usort($shared, fn (array $a, array $b) => [$b['tier1_count'], $b['process_count']] <=> [$a['tier1_count'], $a['process_count']]);

        return $shared;
    }

    /* ------------------------------------------------------------------ */
    /*  Cycles */
    /* ------------------------------------------------------------------ */

    /**
     * Would depending on `$target` close a loop?
     *
     * A→B→A means neither can be recovered first, which is not a plan. It is
     * also a real thing assessors record without noticing, because each edge is
     * reasonable on its own and only the pair is impossible.
     */
    public function wouldCycle(Process $process, Process $target): bool
    {
        return $this->reaches($target, $process, 0, []);
    }

    private function assertNoCycle(?Process $process, Model $target): void
    {
        if ($process === null || ! $target instanceof Process) {
            return;
        }

        if ($this->wouldCycle($process, $target)) {
            throw new InvalidArgumentException(sprintf(
                '%s already depends on %s, directly or through a chain. Adding this would close a loop, and a loop '
                .'means neither process can be recovered first.',
                $target->name, $process->name
            ));
        }
    }

    /** @param list<int> $seen */
    private function reaches(Process $from, Process $to, int $depth, array $seen): bool
    {
        if ($depth > self::MAX_CHAIN_DEPTH || in_array($from->getKey(), $seen, true)) {
            return false;
        }

        $seen[] = $from->getKey();

        $nextIds = Dependency::query()
            ->where('dependable_type', DependencyType::Processes->value)
            ->whereIn('assessment_id', BiaAssessment::query()->where('process_id', $from->getKey())->select('id'))
            ->pluck('dependable_id');

        if ($nextIds->contains($to->getKey())) {
            return true;
        }

        foreach (Process::query()->whereIn('id', $nextIds)->get() as $next) {
            if ($this->reaches($next, $to, $depth + 1, $seen)) {
                return true;
            }
        }

        return false;
    }

    /* ------------------------------------------------------------------ */

    private function typeOf(Model $target): DependencyType
    {
        $type = DependencyType::tryFrom($target->getMorphClass());

        if ($type === null) {
            throw new InvalidArgumentException(
                $target::class.' is not one of the seven dependency types (ADR 0002).'
            );
        }

        return $type;
    }

    private function labelOf(Model $target): string
    {
        foreach (['name', 'full_name', 'legal_name', 'title'] as $attribute) {
            $value = $target->getAttribute($attribute);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return class_basename($target).' #'.$target->getKey();
    }
}
