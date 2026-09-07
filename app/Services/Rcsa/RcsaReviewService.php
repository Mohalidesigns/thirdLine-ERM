<?php

namespace App\Services\Rcsa;

use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaAssessmentLine;
use App\Models\Rcsa\RcsaCycle;
use App\Models\Rcsa\RcsaLineComment;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\Rcsa\RcsaScope;
use Illuminate\Support\Collection;

/**
 * The ORM review queue (§9.2) and the per-line challenge inside it.
 *
 * ABOVE-APPETITE IS COUNTED IN SQL, NOT IN PHP. The queue shows every unit in
 * the bank at once and the count is one of its columns; loading each
 * assessment's lines to ask `isAboveAppetite()` per row is a query per
 * assessment and a few thousand models on a screen that renders a table. The
 * methodology answers WHICH BANDS are above appetite once per cycle — the
 * ceiling is a property of the methodology, not of a line — and the count is
 * then a grouped `whereIn` over `residual_level`. Same answer, one query, and
 * it works identically on MySQL and SQLite because it is ordinary SQL.
 *
 * PRIORITY IS SORTED IN PHP, and deliberately. The formula weighs three things
 * that live in different places (a count from a join, an age from a timestamp,
 * an escalation flag), and expressing it as an ORDER BY would hard-code the
 * weights into a raw SQL string in the one place nobody would think to look
 * when the bank asks for them to change. The queue is tens of rows, not
 * thousands.
 */
class RcsaReviewService
{
    /**
     * Weight per above-appetite risk in the priority score.
     *
     * Ten, against one point per day waiting: a unit with four risks above
     * appetite outranks one with none that has been sitting for a working
     * month, and that is the intended reading — the queue is sorted by what is
     * at stake, with age as the tie-breaker that stops anything starving.
     */
    public const ABOVE_APPETITE_WEIGHT = 10;

    /**
     * Added when a reviewer has escalated. Large enough that an escalated
     * assessment is always at the top, because that is what escalating means.
     */
    public const ESCALATION_WEIGHT = 1000;

    public function __construct(
        private readonly RcsaAssessmentService $assessments,
        private readonly RcsaTreatmentOverrideService $overrides,
    ) {}

    /**
     * The work queue: everything awaiting ORM review, most pressing first.
     *
     * IT EXCLUDES WHAT THE VIEWER FILED THEMSELVES, and that is not a nicety.
     * `RcsaAssessmentPolicy::review()` refuses the submitter — §9 is a
     * two-person control — so a queue that listed those rows would draw a
     * Review link straight onto a 403. This is the same defect shape as the
     * super-admin sidebar in `docs/rcsa-v2/super-admin-403.md`: the screen
     * offering what the route refuses. It was found by opening the page as the
     * assessor, not by a test, because every test here reviews as somebody
     * else.
     *
     * @param  array<string, mixed>  $filters
     * @param  int|null  $viewerId  Whoever is looking, for the self-review exclusion above.
     * @param  User|null  $viewer  The same person, for §11's business-unit scoping.
     * @return list<array<string, mixed>>
     */
    public function queue(array $filters = [], ?int $viewerId = null, ?User $viewer = null): array
    {
        $assessments = app(RcsaScope::class)->apply(RcsaAssessment::query(), $viewer)
            ->whereIn('status', RcsaAssessment::REVIEWABLE)
            ->when($viewerId !== null, fn ($q) => $q->where(
                fn ($w) => $w->whereNull('submitted_by')->orWhere('submitted_by', '!=', $viewerId),
            ))
            ->whereHas('cycle', fn ($q) => $q->whereIn('status', [RcsaCycle::OPEN, RcsaCycle::IN_REVIEW]))
            ->with(['cycle:id,name,status,due_date,methodology_id', 'businessUnit:id,name', 'reviewer:id,name', 'submitter:id,name'])
            ->withCount('lines')
            ->when(filled($filters['cycle'] ?? null), fn ($q) => $q->where('cycle_id', $filters['cycle']))
            ->when(filled($filters['status'] ?? null), fn ($q) => $q->where('status', $filters['status']))
            ->when(filled($filters['business_unit'] ?? null), fn ($q) => $q->where('business_unit_id', $filters['business_unit']))
            ->get();

        if ($assessments->isEmpty()) {
            return [];
        }

        $aboveAppetite = $this->aboveAppetiteCounts($assessments);
        $flagged = $this->flaggedCounts($assessments);

        return $assessments
            ->map(function (RcsaAssessment $assessment) use ($aboveAppetite, $flagged) {
                $above = $aboveAppetite[$assessment->id] ?? 0;

                // Days since it was FILED, not since it was created. The queue
                // is a measure of how long the second line has been sitting on
                // somebody's work, and an assessment drafted in January and
                // submitted yesterday has not been waiting since January.
                $age = $assessment->submitted_at === null
                    ? 0
                    : (int) $assessment->submitted_at->startOfDay()->diffInDays(now()->startOfDay());

                return [
                    'id' => $assessment->id,
                    'business_unit' => $assessment->getRelationValue('businessUnit')?->name,
                    'cycle' => $assessment->getRelationValue('cycle')?->name,
                    'cycle_id' => $assessment->cycle_id,
                    'status' => $assessment->status,
                    'submitted_by' => $assessment->getRelationValue('submitter')?->name,
                    'submitted_at' => $assessment->submitted_at?->toDateTimeString(),
                    'age_days' => $age,
                    'lines_count' => $assessment->lines_count,
                    'above_appetite_count' => $above,
                    'flagged_count' => $flagged[$assessment->id] ?? 0,
                    'reviewer' => $assessment->getRelationValue('reviewer')?->name,
                    'reviewer_id' => $assessment->reviewer_id,
                    'escalated' => $assessment->isEscalated(),
                    'escalation_reason' => $assessment->escalation_reason,
                    'priority' => $this->priority($above, $age, $assessment->isEscalated()),
                ];
            })
            ->sortByDesc('priority')
            ->values()
            ->all();
    }

    /**
     * The risk-weighted priority of §9.2.
     */
    public function priority(int $aboveAppetite, int $ageDays, bool $escalated = false): int
    {
        return $aboveAppetite * self::ABOVE_APPETITE_WEIGHT
            + $ageDays
            + ($escalated ? self::ESCALATION_WEIGHT : 0);
    }

    /* ------------------------------------------------------------------ */
    /*  The challenge (§9.2) */
    /* ------------------------------------------------------------------ */

    /**
     * Challenge a line: a comment, and optionally the rating the reviewer
     * thinks it should carry.
     *
     * THE SUGGESTION IS NOT APPLIED. Nothing reads `suggested_values` back onto
     * the line; the assessor either changes their answer or defends it. A
     * reviewer who could overwrite a rating directly would turn a
     * self-assessment into an ORM assessment, and the trail would show the
     * business saying something it never said.
     *
     * @param  array<string, mixed>  $suggested
     */
    public function challenge(
        RcsaAssessmentLine $line,
        User $actor,
        string $body,
        array $suggested = [],
        string $verdict = RcsaAssessmentLine::ORM_CHALLENGED,
    ): RcsaLineComment {
        $comment = $line->comments()->create([
            'organization_id' => $line->organization_id,
            'user_id' => $actor->id,
            'type' => RcsaLineComment::CHALLENGE,
            'body' => $body,
            'suggested_values' => $suggested === [] ? null : $suggested,
        ]);

        $this->markLine($line, $verdict, $actor);

        return $comment;
    }

    /**
     * The accept half of §9.2's accept/flag toggle.
     *
     * Accepting CLEARS nothing that was said. Any challenge already on the line
     * stays in the thread — the reviewer changing their mind is part of the
     * record, and deleting the challenge would hide the argument that produced
     * the answer.
     */
    public function accept(RcsaAssessmentLine $line, User $actor): RcsaAssessmentLine
    {
        return $this->markLine($line, RcsaAssessmentLine::ORM_ACCEPTED, $actor);
    }

    public function flag(RcsaAssessmentLine $line, User $actor): RcsaAssessmentLine
    {
        return $this->markLine($line, RcsaAssessmentLine::ORM_FLAGGED, $actor);
    }

    /**
     * The assessor answers a challenge on a returned line.
     */
    public function respond(RcsaAssessmentLine $line, User $actor, string $body, ?int $parentId = null): RcsaLineComment
    {
        $comment = $line->comments()->create([
            'organization_id' => $line->organization_id,
            'user_id' => $actor->id,
            'parent_id' => $parentId,
            'type' => RcsaLineComment::RESPONSE,
            'body' => $body,
        ]);

        $this->notifyChallenger($line, $actor, $body);

        return $comment;
    }

    /**
     * What the reviewer has and has not decided — the review summary panel.
     *
     * @return array{total: int, accepted: int, flagged: int, pending: int, above_appetite: int, undecided: list<array{line_id: int, risk_no: string}>, overrides_awaiting: list<array{line_id: int, risk_no: string, from: string|null, to: string|null, reason: string|null}>}
     */
    public function summary(RcsaAssessment $assessment): array
    {
        $lines = $assessment->lines()->get();

        $undecided = $lines
            ->filter(fn (RcsaAssessmentLine $line) => (string) $line->orm_status === RcsaAssessmentLine::ORM_PENDING)
            ->map(fn (RcsaAssessmentLine $line) => ['line_id' => (int) $line->id, 'risk_no' => (string) $line->risk_no])
            ->values()
            ->all();

        return [
            'total' => $lines->count(),
            'accepted' => $lines->where('orm_status', RcsaAssessmentLine::ORM_ACCEPTED)->count(),
            'flagged' => $lines->filter(fn (RcsaAssessmentLine $l) => $l->isFlaggedByOrm())->count(),
            'pending' => count($undecided),
            'above_appetite' => $lines->filter(fn (RcsaAssessmentLine $l) => $this->assessments->isAboveAppetite($l))->count(),
            'undecided' => $undecided,

            // §14 Q5. Separate from `undecided`, which is about the reviewer's
            // own accept/flag verdict on each line. An override is a different
            // decision with a different consequence — it is what blocks
            // validation — and folding the two together would let a reviewer
            // clear the count without having looked at a single override.
            'overrides_awaiting' => $this->overrides->awaitingDecision($assessment),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Internals */
    /* ------------------------------------------------------------------ */

    private function markLine(RcsaAssessmentLine $line, string $status, User $actor): RcsaAssessmentLine
    {
        $line->forceFill([
            'orm_status' => $status,
            'orm_reviewer_id' => $actor->id,
            'orm_reviewed_at' => now(),
        ])->save();

        return $line;
    }

    /**
     * How many lines of each assessment sit above the methodology's appetite
     * ceiling — counted in SQL, from the band names the methodology says are
     * above it.
     *
     * @param  Collection<int, RcsaAssessment>  $assessments
     * @return array<int, int>
     */
    private function aboveAppetiteCounts(Collection $assessments): array
    {
        // ONE QUERY, no grouping by methodology. This used to fan out across
        // methodologies to fetch each one's list of above-appetite band names,
        // because appetite was a property of the band. §14 Q4 made it a
        // property of the band AND the risk's category, so the engine now
        // stores the verdict per line and the queue simply counts it.
        $rows = RcsaAssessmentLine::query()
            ->whereIn('assessment_id', $assessments->pluck('id'))
            ->where('above_appetite', true)
            ->selectRaw('assessment_id, count(*) as aggregate')
            ->groupBy('assessment_id')
            ->pluck('aggregate', 'assessment_id');

        $counts = [];

        foreach ($rows as $assessmentId => $count) {
            $counts[(int) $assessmentId] = (int) $count;
        }

        return $counts;
    }

    /**
     * @param  Collection<int, RcsaAssessment>  $assessments
     * @return array<int, int>
     */
    private function flaggedCounts(Collection $assessments): array
    {
        return RcsaAssessmentLine::query()
            ->whereIn('assessment_id', $assessments->pluck('id'))
            ->whereIn('orm_status', RcsaAssessmentLine::ORM_REOPENS)
            ->selectRaw('assessment_id, count(*) as aggregate')
            ->groupBy('assessment_id')
            ->pluck('aggregate', 'assessment_id')
            ->mapWithKeys(fn ($count, $id) => [(int) $id => (int) $count])
            ->all();
    }

    /**
     * Tell the reviewer their challenge was answered.
     */
    private function notifyChallenger(RcsaAssessmentLine $line, User $actor, string $body): void
    {
        $reviewer = $line->orm_reviewer_id;

        if ($reviewer === null || (int) $reviewer === $actor->id) {
            return;
        }

        NotificationService::send(
            organizationId: (int) $line->organization_id,
            userId: (int) $reviewer,
            type: 'rcsa.line.responded',
            subject: sprintf('%s answered your challenge on %s', $actor->name, $line->risk_no),
            body: \Illuminate\Support\Str::limit($body, 300),
            metadata: ['assessment_id' => $line->assessment_id, 'line_id' => $line->id],
            actionUrl: route('rcsa.review.show', $line->assessment_id, absolute: false),
            priority: 'medium',
            category: 'workflow',
        );
    }
}
