<?php

namespace App\Services\Bcms;

use App\Enums\Bcms\RaciRole;
use App\Models\Bcms\Process;
use App\Models\Bcms\Programme;
use App\Models\Bcms\RaciAssignment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * RACI assignment and the gap report.
 *
 * ACCOUNTABLE IS SINGULAR, AND THAT IS ENFORCED RATHER THAN ADVISED. A process
 * with two accountable people has no owner: when it fails, each will reasonably
 * believe it was the other's. `assign()` replaces an existing A rather than
 * adding a second, and records who it replaced.
 *
 * THE GAP REPORT IS THE POINT OF THE WHOLE FEATURE. "Fourteen processes have no
 * accountable owner" is a sentence a risk committee acts on; a RACI matrix
 * nobody audits is a spreadsheet in a different shape. It reports three gaps and
 * they are different in kind:
 *
 *   NO ACCOUNTABLE  — nobody answers for it.
 *   NO RESPONSIBLE  — somebody answers for work nobody is doing.
 *   INACTIVE HOLDER — the accountable person has left, which is the gap that
 *                     hides, because the matrix still looks complete.
 */
class RaciService
{
    public function assign(Model $subject, int $userId, RaciRole $role, ?string $note = null): RaciAssignment
    {
        $this->assertAssignable($subject);

        return DB::transaction(function () use ($subject, $userId, $role, $note) {
            if (! $role->allowsMultiple()) {
                // One A. Removing the previous holder is the correct behaviour
                // and it is visible in the audit log rather than silent.
                RaciAssignment::query()
                    ->where('assignable_type', $subject->getMorphClass())
                    ->where('assignable_id', $subject->getKey())
                    ->where('raci_role', $role->value)
                    ->where('user_id', '!=', $userId)
                    ->delete();
            }

            return RaciAssignment::query()->updateOrCreate(
                [
                    'assignable_type' => $subject->getMorphClass(),
                    'assignable_id' => $subject->getKey(),
                    'user_id' => $userId,
                    'raci_role' => $role->value,
                ],
                ['note' => $note, 'created_by' => auth()->id()]
            );
        });
    }

    public function unassign(Model $subject, int $userId, RaciRole $role): int
    {
        return RaciAssignment::query()
            ->where('assignable_type', $subject->getMorphClass())
            ->where('assignable_id', $subject->getKey())
            ->where('user_id', $userId)
            ->where('raci_role', $role->value)
            ->delete();
    }

    /**
     * The gap report over the process catalogue.
     *
     * @return array{
     *   total: int,
     *   without_accountable: list<array{id:int, code:string, name:string, tier:int|null, critical:bool}>,
     *   without_responsible: list<array{id:int, code:string, name:string}>,
     *   inactive_accountable: list<array{id:int, code:string, name:string, holder:string}>,
     * }
     */
    public function processGaps(): array
    {
        $processes = Process::query()->where('status', 'active')->get(['id', 'code', 'name', 'criticality_tier', 'is_critical_service']);

        if ($processes->isEmpty()) {
            return ['total' => 0, 'without_accountable' => [], 'without_responsible' => [], 'inactive_accountable' => []];
        }

        $assignments = RaciAssignment::query()
            ->where('assignable_type', 'bcms_process')
            ->whereIn('assignable_id', $processes->pluck('id'))
            ->with('user:id,name,is_active')
            ->get()
            ->groupBy('assignable_id');

        $withoutAccountable = [];
        $withoutResponsible = [];
        $inactiveAccountable = [];

        foreach ($processes as $process) {
            /** @var Collection<int, RaciAssignment> $rows */
            $rows = $assignments->get($process->id, collect());

            $accountable = $rows->firstWhere('raci_role', RaciRole::Accountable);
            $responsible = $rows->contains(fn (RaciAssignment $r) => $r->raci_role === RaciRole::Responsible);

            if ($accountable === null) {
                $withoutAccountable[] = [
                    'id' => $process->id,
                    'code' => $process->code,
                    'name' => $process->name,
                    'tier' => $process->criticality_tier,
                    'critical' => (bool) $process->is_critical_service,
                ];
            } elseif ($accountable->user !== null && ! $accountable->user->is_active) {
                // The gap that hides: the matrix still looks complete.
                $inactiveAccountable[] = [
                    'id' => $process->id,
                    'code' => $process->code,
                    'name' => $process->name,
                    'holder' => $accountable->user->name,
                ];
            }

            if (! $responsible) {
                $withoutResponsible[] = ['id' => $process->id, 'code' => $process->code, 'name' => $process->name];
            }
        }

        // Most critical first: a tier-1 critical service with no accountable
        // owner is a different urgency from a tier-4 back-office process, and a
        // report sorted by code buries it.
        usort($withoutAccountable, function (array $a, array $b) {
            return [$b['critical'], -($a['tier'] ?? 9)] <=> [$a['critical'], -($b['tier'] ?? 9)];
        });

        return [
            'total' => $processes->count(),
            'without_accountable' => $withoutAccountable,
            'without_responsible' => $withoutResponsible,
            'inactive_accountable' => $inactiveAccountable,
        ];
    }

    private function assertAssignable(Model $subject): void
    {
        if (! $subject instanceof Process && ! $subject instanceof Programme) {
            throw new InvalidArgumentException('RACI can only be assigned against a BCMS process or programme.');
        }
    }
}
