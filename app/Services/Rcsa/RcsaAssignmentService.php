<?php

namespace App\Services\Rcsa;

use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaCycle;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\Rcsa\RcsaScope;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Who does each business unit's assessment (RCSA-05).
 *
 * `rcsa_assessments.assigned_to` has always existed and the reminders, the
 * dashboard and the returned-for-rework notice all read it — nothing ever wrote
 * it, so every unit read "Unassigned" and the reminder fell back to guessing.
 * This is the writer.
 *
 * THE ASSIGNEE HAS TO BE SOMEBODY WHO COULD DO THE WORK. Holding
 * `rcsa_assessment.complete` is the permission; reaching the unit is the §11
 * scope (RcsaScope — the same class every other RCSA screen asks). An assignment
 * to a person who would get a 403 on opening it is an assignment that looks
 * like it worked, and the unit then waits on somebody who cannot act.
 *
 * THE ACTOR IS BOUND BY THEIR OWN §11 SCOPE TOO. `rcsa_cycle.manage` says a
 * coordinator may assign; it does not say they may assign EVERY unit. A
 * coordinator assigned only to Retail must not be able to hand out Treasury's
 * assessment by posting its id — so assessments outside the ACTOR's scope are
 * never loaded, exactly as an id from another cycle is never loaded (and with
 * the same "none of the selected…" answer when nothing reachable is left). The
 * policy (`RcsaCyclePolicy::assign`) cannot say this: it sees the cycle, not
 * the ids in the request.
 *
 * ALL OR NOTHING. A request that names a unit the assignee cannot reach, or an
 * assessment that has already been filed, is refused whole and names the
 * offender. Applying the other nineteen and reporting "1 skipped" in a toast
 * would leave a coordinator to work out which one and why.
 *
 * ASSESSMENTS ARE READ UNDER A WRITE LOCK, inside the transaction that changes
 * them, so "does this still accept edits" is judged against the row as it is
 * while we hold it, not as it was when the page rendered.
 *
 * THE ASSIGNEE IS NOTIFIED ONCE PER REQUEST, not once per unit: assigning
 * twelve units to one person is one message listing them. Unassigning notifies
 * nobody, and neither does assigning something to yourself.
 */
class RcsaAssignmentService
{
    /** How many unit names a notification spells out before saying "and N more". */
    private const NAMES_IN_NOTICE = 5;

    public function __construct(private readonly RcsaAuditRecorder $audit) {}

    /**
     * The people an assessment may be assigned to in this tenant: active users
     * holding `rcsa_assessment.complete`. Whether each can reach a particular
     * unit is a per-assessment question and is asked in assign().
     *
     * @return Collection<int, User>
     */
    public function eligibleAssignees(int $organizationId): Collection
    {
        return User::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->orderBy('name')
            ->orderBy('id')
            // can() reads roles and direct permissions; loading them for the
            // whole set at once is two queries rather than two per user.
            ->with(['roles', 'permissions'])
            ->get(['id', 'name', 'organization_id'])
            ->filter(fn (User $user) => $this->canComplete($user))
            ->values();
    }

    /**
     * Set (or, with a null assignee, clear) `assigned_to` on some of a cycle's
     * assessments.
     *
     * Ids that are not this cycle's — another cycle, another tenant — are
     * ignored: they are never loaded, so there is nothing to reach across to.
     * So are ids of units outside the ACTOR's own scope (RcsaScope): never
     * loaded, never locked, never audited, never notified about. That is a
     * different rule from the assignee's reach, which refuses the request whole.
     *
     * @param  list<int>  $assessmentIds
     * @return array{changed: int, matched: int, assignee: User|null, notified: bool}
     *
     * @throws RuntimeException When the cycle is closed or an assessment no longer accepts edits.
     * @throws ValidationException When the assignee cannot do the work or cannot reach a selected unit.
     */
    public function assign(
        RcsaCycle $cycle,
        array $assessmentIds,
        ?User $assignee,
        User $actor,
        ?Request $request = null,
    ): array {
        if (! $cycle->acceptsEdits()) {
            throw new RuntimeException(sprintf(
                'Assessments can only be assigned while the cycle is open. This one is %s.',
                str_replace('_', ' ', (string) $cycle->status),
            ));
        }

        if ($assignee !== null && ! $this->canComplete($assignee)) {
            throw ValidationException::withMessages([
                'assigned_to' => sprintf('%s cannot complete assessments, so they cannot be assigned one.', $assignee->name),
            ]);
        }

        /** @var Collection<int, RcsaAssessment> $changed */
        $changed = collect();
        $matched = 0;

        DB::transaction(function () use ($cycle, $assessmentIds, $assignee, $actor, &$changed, &$matched) {
            $query = RcsaAssessment::query()
                ->where('organization_id', $cycle->organization_id)
                ->where('cycle_id', $cycle->id)
                ->whereIn('id', $assessmentIds);

            // The ACTOR's units, not the assignee's: see the class docblock.
            $assessments = app(RcsaScope::class)
                ->apply($query, $actor)
                ->with('businessUnit:id,name')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $matched = $assessments->count();

            if ($matched === 0) {
                throw new RuntimeException('None of the selected assessments belong to this cycle.');
            }

            $filed = $assessments->reject(fn (RcsaAssessment $a) => in_array($a->status, RcsaAssessment::EDITABLE, true));

            if ($filed->isNotEmpty()) {
                throw new RuntimeException(sprintf(
                    '%s can no longer be reassigned: it has already been submitted.',
                    $this->unitNames($filed),
                ));
            }

            if ($assignee !== null) {
                $this->assertReaches($assignee, $assessments);
            }

            $newId = $assignee?->id;

            foreach ($assessments as $assessment) {
                $oldId = $assessment->assigned_to === null ? null : (int) $assessment->assigned_to;

                if ($oldId === $newId) {
                    continue;
                }

                $assessment->forceFill(['assigned_to' => $newId])->save();

                $this->audit->assignmentChange(
                    $assessment,
                    $oldId,
                    $newId,
                    $actor,
                    $assignee === null ? 'Unassigned' : 'Assigned to '.$assignee->name,
                );

                $changed->push($assessment);
            }
        });

        $notified = false;

        if ($assignee !== null && $changed->isNotEmpty() && $assignee->id !== $actor->id) {
            $this->notify($cycle, $changed, $assignee, $actor);
            $notified = true;
        }

        return [
            'changed' => $changed->count(),
            'matched' => $matched,
            'assignee' => $assignee,
            'notified' => $notified,
        ];
    }

    /**
     * Holds the permission — the same question the workspace's own policy asks,
     * so a super-admin (who passes every gate) is eligible too.
     */
    private function canComplete(User $user): bool
    {
        return $user->can('rcsa_assessment.complete');
    }

    /**
     * Every selected unit must be inside the assignee's §11 scope.
     *
     * @param  Collection<int, RcsaAssessment>  $assessments
     *
     * @throws ValidationException
     */
    private function assertReaches(User $assignee, Collection $assessments): void
    {
        $reachable = app(RcsaScope::class)->unitIdsFor($assignee);

        if ($reachable === null) {
            return;
        }

        $outside = $assessments->reject(
            fn (RcsaAssessment $a) => in_array((int) $a->business_unit_id, $reachable, true)
        );

        if ($outside->isEmpty()) {
            return;
        }

        throw ValidationException::withMessages([
            'assigned_to' => sprintf(
                '%s is not assigned to %s, so they cannot work on %s.',
                $assignee->name,
                $this->unitNames($outside),
                $outside->count() === 1 ? 'it' : 'them',
            ),
        ]);
    }

    /**
     * One message for the whole request.
     *
     * @param  Collection<int, RcsaAssessment>  $assessments
     */
    private function notify(RcsaCycle $cycle, Collection $assessments, User $assignee, User $actor): void
    {
        $count = $assessments->count();

        // One unit: straight to its workspace. Several: the assessor's own list,
        // narrowed to this cycle — a link to the cycle page would need
        // `rcsa_cycle.view`, which a risk champion does not necessarily hold.
        $url = $count === 1
            ? route('rcsa.assessments.show', $assessments->first(), absolute: false)
            : route('rcsa.assessments.index', ['cycle' => $cycle->id], absolute: false);

        NotificationService::sendMany(
            organizationId: (int) $cycle->organization_id,
            userIds: [(int) $assignee->id],
            type: 'rcsa.assessment.assigned',
            subject: $count === 1
                ? sprintf('%s assigned you the %s RCSA', $actor->name, $this->unitNames($assessments))
                : sprintf('%s assigned you %d RCSA assessments', $actor->name, $count),
            body: sprintf(
                '%s assigned you %d %s in %s: %s.%s',
                $actor->name,
                $count,
                Str::plural('assessment', $count),
                $cycle->name,
                $this->unitNames($assessments),
                $cycle->due_date === null ? '' : ' Due '.$cycle->due_date->toDateString().'.',
            ),
            metadata: [
                'cycle_id' => $cycle->id,
                'assessment_ids' => $assessments->pluck('id')->map(fn ($id) => (int) $id)->all(),
            ],
            actionUrl: $url,
            priority: 'medium',
            category: 'workflow',
        );
    }

    /**
     * "Retail, Treasury and 3 more" — names a person can read in a sentence.
     *
     * @param  Collection<int, RcsaAssessment>  $assessments
     */
    private function unitNames(Collection $assessments): string
    {
        $names = $assessments
            ->map(function (RcsaAssessment $assessment): string {
                $unit = $assessment->getRelationValue('businessUnit');

                return $unit === null ? 'a business unit' : (string) $unit->name;
            })
            ->values();

        if ($names->count() <= self::NAMES_IN_NOTICE) {
            return $names->join(', ', ' and ');
        }

        return $names->take(self::NAMES_IN_NOTICE)->join(', ').sprintf(' and %d more', $names->count() - self::NAMES_IN_NOTICE);
    }
}
