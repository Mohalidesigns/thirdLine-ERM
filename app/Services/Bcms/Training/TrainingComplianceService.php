<?php

namespace App\Services\Bcms\Training;

use App\Models\Bcms\ExerciseOccurrence;
use App\Models\Bcms\ExerciseParticipant;
use App\Models\Bcms\TrainingCurriculum;
use App\Models\Bcms\TrainingRecord;
use App\Models\User;
use App\Support\Rcsa\RcsaScope;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Training & competency — clauses 7.2 (competence) and 7.3 (awareness).
 *
 * COMPETENCY AND ATTENDANCE ARE TWO COLUMNS, NEVER BLENDED
 * (`docs/bcms/screens/training-compliance.md` §1, verbatim from the
 * phase-11-spec). Every read method below returns both, separately, and never
 * derives one from the other.
 *
 * NO CERTIFICATE UPLOAD ANYWHERE (ADR 0021 §3). `certificate_id` is retired in
 * place: nothing here writes it, ever.
 *
 * TARGET-ROLE RESOLUTION IS THE PLATFORM'S OWN ROLE ASSIGNMENTS, NOT AN AD
 * GROUP. Phase 2C's directory-group resolution is not built; `'*'` in
 * `target_roles` means every active user, and any other value is a Spatie
 * role name.
 */
class TrainingComplianceService
{
    /** @return Collection<int, TrainingCurriculum> */
    public function curricula(): Collection
    {
        return TrainingCurriculum::query()->where('is_active', true)->orderBy('code')->get();
    }

    /**
     * The current holders of a curriculum's target roles — computed live,
     * never a stored enrolment count that could go stale.
     *
     * @return Collection<int, User>
     */
    public function assignedUsers(TrainingCurriculum $curriculum): Collection
    {
        $roles = (array) $curriculum->target_roles;

        if (in_array('*', $roles, true)) {
            return User::query()->where('is_active', true)->get();
        }

        if ($roles === []) {
            return collect();
        }

        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', $roles))
            ->get();
    }

    /**
     * The compliance table: one row per person × assigned curriculum.
     *
     * B11: SCOPED TO THE VIEWER'S ORG HIERARCHY (`docs/compliance/
     * ndpa-register.md` §11.4 rules 1-5). A `null` viewer, or one holding
     * `RcsaScope::ALL_UNITS`, sees the whole estate — every other caller sees
     * only: (1) people whose `business_unit_id` is inside their own unit
     * subtree, (2) themselves, and (3) a row they themselves assessed, even
     * where the subject sits outside their units. A no-unit PERSON is the
     * INVERSE of `ScopedToOrgHierarchy`'s own null arm — visible only to a
     * whole-estate viewer, never to a unit-scoped one, because an unplaced
     * person's score is not organisation-level content the way a group plan
     * is.
     *
     * B10: the assessor is eager-loaded (`with('assessor')`), not lazy-loaded
     * per row.
     *
     * @return list<array<string, mixed>>
     */
    public function complianceRows(?int $curriculumId = null, ?string $department = null, ?User $viewer = null): array
    {
        $curricula = $this->curricula()->when($curriculumId !== null, fn ($c) => $c->where('id', $curriculumId));

        $viewerUnits = app(RcsaScope::class)->unitIdsFor($viewer);
        $viewerId = $viewer?->getKey();

        $rows = [];

        foreach ($curricula as $curriculum) {
            $users = $this->assignedUsers($curriculum);

            if ($department !== null) {
                $users = $users->filter(fn (User $u) => $u->department === $department);
            }

            $records = TrainingRecord::query()
                ->where('curriculum_id', $curriculum->getKey())
                ->whereIn('user_id', $users->pluck('id'))
                ->with(['occurrence:id,definition_id,scheduled_date', 'assessor:id,name'])
                ->get()
                ->groupBy('user_id');

            foreach ($users as $user) {
                $userRecords = $records->get($user->getKey(), collect());

                // R5: `completed_at DESC, id DESC` — a plain `->first()` (or
                // a `sortByDesc` on `completed_at` alone) over an
                // unordered-by-the-database collection returns whichever row
                // the database happened to return first. B4 made a second
                // attempt ("re-sit") after a failure the normal path, so
                // without a real ordering here someone who failed then
                // passed could show as failed, nondeterministically — the
                // same class of defect §13's tiebreaker sweep fixed
                // elsewhere in this codebase, here in the write-adjacent
                // read path instead of a query `->orderBy()`.
                $latest = $userRecords->sortByDesc(fn (TrainingRecord $r) => [$r->completed_at === null ? 0 : $r->completed_at->timestamp, $r->getKey()])->first();

                // B3: an assessment happened whenever a score/assessor was
                // recorded, regardless of pass/fail — "assessed" and
                // "passed" are two different facts, never blended.
                $assessed = $userRecords
                    ->filter(fn (TrainingRecord $r) => $r->assessor_id !== null || $r->score !== null)
                    ->sortByDesc(fn (TrainingRecord $r) => [$r->completed_at === null ? 0 : $r->completed_at->timestamp, $r->getKey()])
                    ->first();

                if (! $this->rowVisibleToViewer($viewerUnits, $viewerId, $user, $assessed)) {
                    continue;
                }

                $rows[] = [
                    'user_id' => $user->getKey(),
                    'user_name' => $user->name,
                    'department' => $user->department,
                    'curriculum_id' => $curriculum->getKey(),
                    'curriculum_code' => $curriculum->code,
                    'curriculum_name' => $curriculum->name,
                    'requires_assessment' => (bool) $curriculum->requires_assessment,
                    'attendance' => [
                        'completed_at' => $latest?->completed_at?->toDateString(),
                        'next_due_date' => $latest?->next_due_date?->toDateString(),
                    ],
                    'competency' => $curriculum->requires_assessment ? [
                        'assessed' => $assessed !== null,
                        'passed' => $assessed === null ? null : (bool) $assessed->competency_assessed,
                        'score' => $assessed?->score === null ? null : (float) $assessed->score,
                        'pass_mark' => $curriculum->pass_mark,
                        'assessor' => $assessed?->assessor?->name,
                        'assessed_at' => $assessed?->completed_at?->toDateString(),
                    ] : null,
                    'record_id' => $latest?->getKey(),
                    'source' => $latest?->occurrence_id !== null
                        ? ['type' => 'occurrence', 'occurrence_id' => $latest->occurrence_id]
                        : ['type' => 'manual'],
                ];
            }
        }

        return $rows;
    }

    /**
     * Whether the compliance row for $user is visible to $viewer — NDPA
     * register §11.4 rules 1-3.
     */
    private function rowVisibleToViewer(?array $viewerUnits, ?int $viewerId, User $user, ?TrainingRecord $assessed): bool
    {
        if ($this->personInScope($viewerUnits, $viewerId, (int) $user->getKey(), $user->business_unit_id)) {
            return true; // rules 1-2-3(self)
        }

        // rule 3, the assessor exception: the recorded assessor sees the
        // rows they assessed, even where the subject sits outside their own
        // units — they wrote that score. R2: this is a READ-side exception
        // for a row that already legitimately exists. It must never be
        // reachable from the WRITE side (`subjectVisibleToActor()` below,
        // deliberately does not carry it) — otherwise a unit-scoped actor
        // could create a row for anyone in the bank and this exception would
        // then keep it visible, which is exactly the leak NDPA register
        // §11.4 rule 3 rules out ("Nobody else is added").
        return $viewerId !== null && $assessed !== null && (int) $assessed->assessor_id === $viewerId;
    }

    /**
     * R2: the shared core of the org-hierarchy scope rule (NDPA register
     * §11.4 rules 1-2 and the named-subject half of rule 3) — the ONE place
     * both the read side (`rowVisibleToViewer()`) and the write side
     * (`subjectVisibleToActor()`) resolve "is this person in scope", so the
     * two can never drift into checking two different things.
     */
    private function personInScope(?array $viewerUnits, ?int $viewerId, int $subjectId, ?int $subjectBusinessUnitId): bool
    {
        if ($viewerUnits === null) {
            return true; // a whole-estate viewer (RcsaScope::ALL_UNITS), or no viewer at all — a console/queue context
        }

        if ($viewerId !== null && $subjectId === $viewerId) {
            return true; // the subject always sees/is reachable by themselves
        }

        // A no-unit PERSON is in scope only for a whole-estate viewer — the
        // inverted null arm (rule 2). Falling through means neither the
        // unit match nor the self exception applied.
        return $subjectBusinessUnitId !== null && in_array($subjectBusinessUnitId, $viewerUnits, true);
    }

    /**
     * R2: may $actor record or assess a training outcome for $subject at
     * all? The write-side twin of `rowVisibleToViewer()`, and deliberately
     * narrower than it — it carries rules 1-2 and the self-exception of
     * rule 3, but never the assessor exception, which is a read-time
     * allowance for a row that already exists, not a licence to create one.
     * Without this, a unit-scoped `bcms.training.manage` holder
     * (`risk-manager` lacks `rcsa_scope.all_units`) could record or assess
     * ANY employee in the bank, and the read side's assessor exception would
     * then silently keep that person in their register forever — exactly
     * what NDPA register §11.4 rule 3 rules out ("Nobody else is added").
     */
    public function subjectVisibleToActor(?User $actor, User $subject): bool
    {
        $units = app(RcsaScope::class)->unitIdsFor($actor);

        return $this->personInScope($units, $actor?->getKey(), (int) $subject->getKey(), $subject->business_unit_id);
    }

    /**
     * B10: whether ONE person is currently assigned to a curriculum, checked
     * directly rather than by loading every role-holder and testing
     * membership — the query `MyResilienceService::training()` used to run
     * per curriculum, per page view, to check a single person.
     */
    public function isAssignedTo(TrainingCurriculum $curriculum, User $user): bool
    {
        if (! $user->is_active) {
            return false;
        }

        $roles = (array) $curriculum->target_roles;

        if (in_array('*', $roles, true)) {
            return true;
        }

        if ($roles === []) {
            return false;
        }

        return $user->relationLoaded('roles')
            ? $user->roles->pluck('name')->intersect($roles)->isNotEmpty()
            : $user->roles()->whereIn('name', $roles)->exists();
    }

    /**
     * @param  list<array<string, mixed>>|null  $rows  pass the already-scoped
     *                                                 set `complianceRows()` produced this request, so the tiles never
     *                                                 disagree with the table beneath them (NDPA register §11.4 rule 5)
     *                                                 and are never recomputed by a second query pass (B10).
     * @return array<string, int>
     */
    public function summaryTiles(?array $rows = null, ?User $viewer = null): array
    {
        $rows ??= $this->complianceRows(viewer: $viewer);

        $yearStart = now()->startOfYear();
        $yearEnd = now()->endOfYear();

        $overdue = 0;

        foreach ($rows as $row) {
            $due = $row['attendance']['next_due_date'] ?? null;

            if ($due !== null && $due < now()->toDateString()) {
                $overdue++;
            } elseif ($due === null) {
                $overdue++; // never attended at all counts as overdue for first completion
            }
        }

        // The same user set the (possibly viewer-scoped) rows cover — an
        // empty set here correctly means the tiles report zero too (rule 5:
        // the tiles must never disagree with the table beneath them),
        // rather than silently falling back to counting the whole tenant.
        $userIds = collect($rows)->pluck('user_id')->unique()->values()->all();

        return [
            'curricula_count' => $this->curricula()->count(),
            'overdue_count' => $overdue,
            'assessed_this_year' => TrainingRecord::query()->where('competency_assessed', true)
                ->whereIn('user_id', $userIds)
                ->whereBetween('completed_at', [$yearStart, $yearEnd])->count(),
            'attended_only_this_year' => TrainingRecord::query()->where('competency_assessed', false)
                ->whereIn('user_id', $userIds)
                ->whereBetween('completed_at', [$yearStart, $yearEnd])->count(),
            'awareness_campaigns_sent_this_year' => \App\Models\Bcms\Alert::query()
                ->whereHas('template', fn ($q) => $q->where('category', 'awareness'))
                ->whereBetween('dispatched_at', [$yearStart, $yearEnd])
                ->count(),
        ];
    }

    /**
     * B10 follow-up: the compliance screen's "more than half of this
     * mandatory curriculum's people are attended but not yet assessed"
     * banner used to be computed client-side, in `Compliance.jsx`, over
     * `rows` — which B10 made ONE PAGE of the register, not the whole
     * curriculum. That made the ratio misfire against a viewer's real
     * register-wide picture as soon as a curriculum's rows spanned more
     * than one page. Computed once, here, over the SAME (viewer-scoped,
     * B11) full row set the summary tiles already use — never a second,
     * page-scoped ratio — so this and the tiles below it can never
     * disagree with each other or with the page currently on screen.
     *
     * THE THRESHOLD RULE IS THE SCREEN'S OWN, MOVED HERE VERBATIM, NOT
     * REINVENTED: a curriculum is flagged when it is mandatory, has at
     * least one row in the (scoped) register, and more than half of its
     * rows are "attended, not yet assessed" — a row with a completed_at but
     * `competency.assessed` false, on a curriculum that requires
     * assessment at all (`competency` is null on an unassessed curriculum
     * like `BC-AWARE-ALL`, which this rule therefore never flags).
     *
     * @param  list<array<string, mixed>>  $rows  the already-computed, viewer-scoped set `complianceRows()` produced this request — never recomputed a second time
     * @return array<int, array{curriculum_id: int, attended_not_assessed_count: int, assessed_count: int, total: int, heavy_amber: bool}> keyed by curriculum_id
     */
    public function attendedNotAssessedByCurriculum(array $rows): array
    {
        $byCurriculum = collect($rows)->groupBy('curriculum_id');

        $out = [];

        foreach ($this->curricula() as $curriculum) {
            $curriculumRows = $byCurriculum->get($curriculum->getKey(), collect());
            $total = $curriculumRows->count();

            $attendedNotAssessed = $curriculumRows->filter(fn (array $r) => ($r['competency'] ?? null) !== null
                && ! $r['competency']['assessed']
                && ($r['attendance']['completed_at'] ?? null) !== null)->count();

            $assessed = $curriculumRows->filter(fn (array $r) => ($r['competency']['assessed'] ?? false) === true)->count();

            $out[$curriculum->getKey()] = [
                'curriculum_id' => $curriculum->getKey(),
                'attended_not_assessed_count' => $attendedNotAssessed,
                'assessed_count' => $assessed,
                'total' => $total,
                'heavy_amber' => (bool) $curriculum->is_mandatory && $total > 0 && ($attendedNotAssessed / $total) > 0.5,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * B4: THE ASSESSOR IS ALWAYS THE ACTING USER, NEVER A FREE REQUEST
     * FIELD. A submitted `assessor_id` is ignored outright — a
     * `bcms.training.manage` holder cannot name a colleague as their own
     * assessor. An assessment happens only when a score is submitted; a
     * bare attendance row (no score) records no assessor.
     */
    public function recordOutcome(array $attributes, ?int $actorId = null): TrainingRecord
    {
        $curriculum = TrainingCurriculum::query()->findOrFail($attributes['curriculum_id']);

        $scoreProvided = array_key_exists('score', $attributes) && $attributes['score'] !== null;
        $isAssessment = $curriculum->requires_assessment && $scoreProvided;

        if ($isAssessment && $actorId !== null && (int) $actorId === (int) $attributes['user_id']) {
            throw new InvalidArgumentException('You cannot assess your own competence.');
        }

        // B3: competency_assessed is true only when a score was recorded AND
        // it meets the curriculum's pass mark (ADR 0021 §3). A fail is still
        // a first-class record — an actor, a date and a result — it is just
        // never reported as competence achieved. It is distinguished from
        // "not yet assessed" by its non-null score/assessor_id, not by this
        // flag.
        $passed = $isAssessment && (float) $attributes['score'] >= (float) $curriculum->pass_mark;

        // B5: the next cycle is computed from the completion date, never
        // from today — a record completed months ago is due sooner than a
        // fresh full cycle from the moment it happens to be filed.
        $completedAt = $attributes['completed_at'] ?? now();

        return TrainingRecord::query()->create([
            'organization_id' => $curriculum->organization_id,
            'curriculum_id' => $curriculum->getKey(),
            'user_id' => $attributes['user_id'],
            'completed_at' => $completedAt,
            'score' => $attributes['score'] ?? null,
            'competency_assessed' => $passed,
            'assessor_id' => $isAssessment ? $actorId : null,
            'next_due_date' => \Illuminate\Support\Carbon::parse($completedAt)->addMonths($curriculum->frequency_months)->toDateString(),
            'iso_clause_ref' => $passed ? $curriculum->iso_clause_ref : null,
            'created_by' => $actorId,
        ]);
    }

    /**
     * B4: the assessor is the acting user — there is no assessor picker.
     * Refuses self-assessment and refuses re-assessing a record that
     * already carries a score/assessor (a new record is the correct path
     * for a second attempt, not overwriting the first one's result).
     */
    public function assess(TrainingRecord $record, float $score, int $actorId): TrainingRecord
    {
        if ($actorId === (int) $record->user_id) {
            throw new InvalidArgumentException('You cannot assess your own competence.');
        }

        if ($record->assessor_id !== null || $record->score !== null) {
            throw new InvalidArgumentException('This record has already been assessed. Record a new outcome instead of overwriting it.');
        }

        $curriculum = $record->curriculum ?? TrainingCurriculum::query()->findOrFail($record->curriculum_id);

        // A10: a curriculum that does not require assessment (BC-AWARE-ALL)
        // has no pass mark to score against — assessing one anyway would
        // produce a "competency" reading for a curriculum that only ever
        // claimed to evidence attendance (clause 7.3), never competence
        // (7.2).
        if (! $curriculum->requires_assessment) {
            throw new InvalidArgumentException(
                "\"{$curriculum->name}\" does not require assessment — attendance is the whole record."
            );
        }

        $passed = (float) $score >= (float) $curriculum->pass_mark;

        $record->update([
            'competency_assessed' => $passed,
            'assessor_id' => $actorId,
            'score' => $score,
            'completed_at' => $record->completed_at ?? now(),
            'iso_clause_ref' => $passed ? $curriculum->iso_clause_ref : $record->iso_clause_ref,
            'updated_by' => $actorId,
        ]);

        return $record->refresh();
    }

    /**
     * Criterion 6: a participant who attended an exercise has the occurrence
     * linked to their training record automatically. THE LINK CREATES THE
     * RECORD; IT DOES NOT SET `competency_assessed`, because a machine
     * cannot assert somebody is competent — only an assessor can, and that is
     * a separate act.
     *
     * @return int the number of records linked
     */
    public function linkOccurrenceParticipants(ExerciseOccurrence $occurrence): int
    {
        $linked = 0;

        $present = ExerciseParticipant::query()
            ->where('occurrence_id', $occurrence->getKey())
            ->where('attendance_status', 'present')
            ->whereNotNull('user_id')
            ->get();

        foreach ($present as $participant) {
            $user = User::query()->find($participant->user_id);

            if ($user === null) {
                continue;
            }

            $curriculum = $this->curriculumFor($user);

            if ($curriculum === null) {
                continue;
            }

            $existing = TrainingRecord::query()
                ->where('curriculum_id', $curriculum->getKey())
                ->where('user_id', $user->getKey())
                ->where('occurrence_id', $occurrence->getKey())
                ->first();

            if ($existing !== null) {
                continue;
            }

            $completedAt = $occurrence->actual_end ?? $occurrence->scheduled_date;

            TrainingRecord::query()->create([
                'organization_id' => $occurrence->organization_id,
                'curriculum_id' => $curriculum->getKey(),
                'user_id' => $user->getKey(),
                'completed_at' => $completedAt,
                'competency_assessed' => false,
                'occurrence_id' => $occurrence->getKey(),
                'next_due_date' => $completedAt === null ? null : \Illuminate\Support\Carbon::parse($completedAt)->addMonths($curriculum->frequency_months)->toDateString(),
                'iso_clause_ref' => $curriculum->iso_clause_ref,
            ]);

            $linked++;
        }

        return $linked;
    }

    /**
     * The first mandatory, assessed curriculum a role of this user's targets
     * — the curriculum an exercise occurrence's attendance is evidence for.
     */
    private function curriculumFor(User $user): ?TrainingCurriculum
    {
        $roleNames = $user->roles->pluck('name')->all();

        if ($roleNames === []) {
            return null;
        }

        return TrainingCurriculum::query()
            ->where('is_active', true)
            ->where('requires_assessment', true)
            ->where(function ($q) use ($roleNames) {
                foreach ($roleNames as $name) {
                    $q->orWhereJsonContains('target_roles', $name);
                }
            })
            ->first();
    }
}
