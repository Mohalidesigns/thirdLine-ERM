<?php

namespace App\Services\Rcsa;

use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaAssessmentLine;
use App\Models\Rcsa\RcsaAssessmentTransition;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use ThirdLine\Reporting\DocumentRenderer;

/**
 * Step 8 of the process flow: submission, and the gate in front of it.
 *
 * RULE 6 IS THE WHOLE POINT. An assessment cannot be submitted while any line
 * above appetite lacks a complete action plan — and the refusal has to name the
 * lines, because "submission failed" on a 200-line assessment is not something
 * anybody can act on. `blockers()` returns structured issues with a line id
 * each, which the screen turns into jump links.
 *
 * SUBMISSION IS A ONE-WAY TRANSITION WITH FOUR EFFECTS, and doing three of them
 * is worse than doing none: the lines lock, a PDF snapshot is written, the ORM
 * is notified, and the status moves. They happen in one transaction so a
 * failure leaves an assessment somebody can still work on rather than one that
 * is half-submitted and editable by nobody.
 *
 * THE SNAPSHOT IS RENDERED AT SUBMISSION, NOT ON DEMAND. A PDF generated later
 * from live rows is not evidence of what was filed — it is a re-render under
 * whatever the data has become. This one is written to storage once and never
 * regenerated.
 */
class RcsaSubmissionService
{
    /** Who is told when an assessment reaches the ORM with no reviewer of its own. */
    private const REVIEW_PERMISSION = 'rcsa_assessment.review';

    /** The role `Gate::before` (AppServiceProvider) lets through every ability. */
    private const SUPER_ADMIN_ROLE = 'super-admin';

    public function __construct(
        private readonly RcsaAssessmentService $assessments,
        private readonly RcsaWorkflowService $workflow,
        private readonly DocumentRenderer $renderer,
    ) {}

    /**
     * Everything standing between this assessment and submission.
     *
     * `outstanding()` already finds unscored lines, above-appetite lines with
     * no complete plan, unjustified overrides and unexplained movement. This
     * adds the one condition that only matters at submission: a line somebody
     * else still has open. Submitting while a colleague is mid-edit would
     * freeze their work in whatever state it happened to be in.
     *
     * @return array{scored: int, total: int, issues: list<array{type: string, line_id: int, risk_no: string, message: string}>}
     */
    public function blockers(RcsaAssessment $assessment, ?int $actorId = null): array
    {
        $outstanding = $this->assessments->outstanding($assessment);

        $held = $assessment->lines()
            ->whereNotNull('locked_by')
            ->when($actorId !== null, fn ($q) => $q->where('locked_by', '!=', $actorId))
            ->where('lock_expires_at', '>', now())
            ->get(['id', 'risk_no']);

        foreach ($held as $line) {
            $outstanding['issues'][] = [
                'type' => 'locked',
                'line_id' => (int) $line->id,
                'risk_no' => (string) $line->risk_no,
                'message' => 'Somebody else has this risk open. Submitting now would freeze their work part-finished.',
            ];
        }

        return $outstanding;
    }

    public function canSubmit(RcsaAssessment $assessment, ?int $actorId = null): bool
    {
        return $this->blockers($assessment, $actorId)['issues'] === [];
    }

    /**
     * Submit an assessment for ORM review.
     *
     * WHERE IT LANDS IS THE TENANT'S CHOICE. With the BU-head step enabled it
     * goes to `bu_approval` and the head is told; without it, straight to
     * `submitted` and the ORM is told. Either way the lines lock and the
     * snapshot is written here, at the act of FILING — the head reviews the
     * same frozen document the ORM will, and the PDF records what the unit
     * signed off rather than what it looked like after an approval step.
     *
     * @return array{lines: int, snapshot: string|null, notified: int, status: string}
     */
    public function submit(RcsaAssessment $assessment, User $actor, ?Request $request = null): array
    {
        if (! in_array($assessment->status, RcsaAssessment::EDITABLE, true)) {
            throw new RuntimeException('This assessment has already been submitted.');
        }

        if (! $assessment->acceptsEdits()) {
            throw new RuntimeException('This assessment is closed and cannot be submitted.');
        }

        // Heal, then gate. A line whose answers were stored but whose scores
        // were lost (see RcsaAssessmentService's invariant) is repaired here so
        // the gate judges its real band; one that cannot be repaired stays
        // unscored and blocks.
        //
        // The repair is the SYSTEM's, not the submitter's: its revisions and
        // audit rows carry no user and say why, so the trail never claims the
        // person pressing Submit changed a score. Locked lines are not
        // touched (the default) — a returned assessment's unflagged lines were
        // filed as they stand.
        $this->assessments->recomputeUnscored(
            $assessment,
            $request,
            reason: 'Recomputed at submission — scores were missing',
        );

        $blockers = $this->blockers($assessment, $actor->id);

        if ($blockers['issues'] !== []) {
            throw new RuntimeException(sprintf(
                '%d thing%s still to do before this can be submitted.',
                count($blockers['issues']),
                count($blockers['issues']) === 1 ? '' : 's',
            ));
        }

        // Rendered BEFORE the transaction: dompdf on a 500-line assessment is
        // seconds of CPU, and holding a write transaction open across it would
        // block every other save in the unit for its duration.
        $snapshot = $this->writeSnapshot($assessment);

        // Through the state machine, not a forceFill of its own: §9.1 wants
        // user, timestamp, from, to and reason on EVERY transition, and a
        // submit that wrote its own status would be the one movement in the
        // module with no history behind it.
        $assessment = $this->workflow->transition(
            assessment: $assessment,
            to: $this->workflow->submissionTarget($assessment),
            actor: $actor,
            event: RcsaAssessmentTransition::SUBMIT,
            reason: null,
            request: $request,
            attributes: [
                'submitted_by' => $actor->id,
                'submitted_at' => now(),
                'snapshot_path' => $snapshot,
                // A resubmission after a return must not still show the reason
                // it was returned for.
                'returned_reason' => null,
            ],
            inside: function (RcsaAssessment $assessment) {
                // Every line, including ones the ORM never flagged: submission
                // freezes the whole assessment, and a return is what reopens
                // the part of it that has to change.
                $assessment->lines()->update([
                    'locked_at' => now(),
                    'locked_by' => null,
                    'lock_expires_at' => null,
                ]);
            },
        );

        $assessment->loadMissing(['cycle', 'businessUnit']);

        $notified = $assessment->status === RcsaAssessment::BU_APPROVAL
            ? $this->notifyBusinessUnitHead($assessment, $actor)
            : $this->notifyReviewers($assessment, $actor);

        return [
            'lines' => $assessment->lines()->count(),
            'snapshot' => $snapshot,
            'notified' => $notified,
            'status' => (string) $assessment->status,
        ];
    }

    /**
     * Who submitting this assessment would notify, right now, for this actor.
     *
     * THE SEND AND THE COUNT SHARE THIS. The workspace tells the assessor
     * "N people will be notified" (or that nobody will) before they press
     * Submit, and a count computed by a second copy of the rule is a count that
     * drifts the first time either changes — so notifyReviewers() and
     * notifyBusinessUnitHead() read their recipients from the same two
     * methods this does.
     *
     * @return list<int>
     */
    public function submissionRecipients(RcsaAssessment $assessment, User $actor): array
    {
        return $this->workflow->submissionTarget($assessment) === RcsaAssessment::BU_APPROVAL
            ? $this->businessUnitHeadRecipients($assessment, $actor)
            : $this->reviewerRecipients($assessment, $actor);
    }

    /**
     * `business_units.head_id`, and nobody else — see notifyBusinessUnitHead().
     *
     * @return list<int>
     */
    private function businessUnitHeadRecipients(RcsaAssessment $assessment, User $actor): array
    {
        // The relation may have been loaded with a column list that leaves out
        // `head_id` (the workspace selects id, name and code only), in which
        // case reading it would answer "no head" for a unit that has one.
        $unit = $assessment->getRelationValue('businessUnit');

        $headId = $unit !== null && array_key_exists('head_id', $unit->getAttributes())
            ? $unit->head_id
            : DB::table('business_units')->where('id', $assessment->business_unit_id)->value('head_id');

        if ($headId === null || (int) $headId === $actor->id) {
            return [];
        }

        return [(int) $headId];
    }

    /**
     * The assessment's own reviewer when one is set, otherwise everyone who
     * may review — see notifyReviewers().
     *
     * THE FILTER IS IN SQL, and it returns ids only. This sits behind the
     * workspace's "N people will be notified" prop, so it runs on EVERY GET of
     * an open assessment; loading every active user of the tenant with their
     * roles and permissions and asking `can()` of each made that page's cost
     * grow with the size of the bank. Now it is one query, whatever the tenant
     * holds.
     *
     * IT MUST ANSWER WHAT `can('rcsa_assessment.review')` WOULD, so that the
     * count and the send cannot disagree with the Gate:
     *
     *   - role-granted and direct permissions — Spatie's `permission()` scope
     *     matches both (a `model_has_permissions` row, or a role carrying the
     *     permission). Teams are off in this app (`config/permission.php`), so
     *     there is no team/tenant dimension on roles to reproduce; the tenant
     *     bound is the users' own `organization_id`.
     *   - the super-admin bypass — `Gate::before` in AppServiceProvider grants
     *     `super-admin` every ability, so `can()` is BROADER than the scope: a
     *     super-admin holding no review permission at all passes `can()` and
     *     would be missed by the scope alone. The `super-admin` role is
     *     therefore OR-ed in explicitly, with the same role-name test
     *     `hasRole('super-admin')` makes.
     *   - a permission row that does not exist — the scope throws where
     *     `can()` quietly answers no. Treated as "nobody holds it", which
     *     leaves only the super-admins.
     *
     * @return list<int>
     */
    private function reviewerRecipients(RcsaAssessment $assessment, User $actor): array
    {
        if ($assessment->reviewer_id !== null) {
            return [(int) $assessment->reviewer_id];
        }

        $query = User::query()
            ->where('organization_id', $assessment->organization_id)
            ->where('is_active', true)
            ->whereKeyNot($actor->id);

        $query->where(function (Builder $who) {
            try {
                $who->permission(self::REVIEW_PERMISSION);
            } catch (PermissionDoesNotExist) {
                // Nobody can hold a permission that has never been created; the
                // super-admin branch below is all that is left. `where(0 = 1)`
                // keeps the OR well-formed.
                $who->whereRaw('1 = 0');
            }

            $who->orWhereHas('roles', fn (Builder $roles) => $roles->where('name', self::SUPER_ADMIN_ROLE));
        });

        // toBase(): ids straight off the result set. Eloquent's own pluck()
        // builds a model per row when the model casts or mutates the column
        // (users.id is cast), and a hydrated User per recipient is exactly the
        // cost this method exists to avoid. Global scopes (tenant, soft
        // deletes) are applied by toBase().
        return $query->orderBy('id')
            ->toBase()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Tell the head of the business unit their unit has filed.
     *
     * `business_units.head_id`, and nothing else — an approval step with no
     * named approver is not an approval step, so a unit with no head set
     * notifies nobody and the assessment waits visibly in `bu_approval` rather
     * than being quietly forwarded to the ORM as though it had been approved.
     */
    private function notifyBusinessUnitHead(RcsaAssessment $assessment, User $actor): int
    {
        $recipients = $this->businessUnitHeadRecipients($assessment, $actor);

        if ($recipients === []) {
            return 0;
        }

        $head = $recipients[0];

        NotificationService::send(
            organizationId: (int) $assessment->organization_id,
            userId: (int) $head,
            type: 'rcsa.assessment.awaiting_approval',
            subject: sprintf('%s needs your approval', $this->unitName($assessment)),
            body: sprintf(
                '%s completed the %s assessment for %s: %d %s, %d above appetite. It reaches ORM once you approve it.',
                $actor->name,
                $this->cycleName($assessment),
                $this->unitName($assessment),
                $lineCount = $assessment->lines()->count(),
                Str::plural('risk', $lineCount),
                $this->aboveAppetiteCount($assessment),
            ),
            metadata: ['assessment_id' => $assessment->id, 'cycle_id' => $assessment->cycle_id],
            actionUrl: route('rcsa.assessments.show', $assessment, absolute: false),
            priority: 'high',
            category: 'workflow',
        );

        return 1;
    }

    /* ------------------------------------------------------------------ */
    /*  The snapshot */
    /* ------------------------------------------------------------------ */

    /**
     * Render the assessment to a PDF on the private disk.
     *
     * Failure to render does NOT fail the submission. A submitted assessment
     * whose PDF could not be produced is a missing document; a submission
     * refused because of it is a risk champion blocked at the end of a
     * quarter's work by a rendering library. The path stays null and the
     * failure is logged.
     */
    private function writeSnapshot(RcsaAssessment $assessment): ?string
    {
        try {
            $assessment->loadMissing(['cycle', 'businessUnit', 'organization']);

            $lines = $assessment->lines()->with('actionPlans.owner:id,name')->get();

            // Under reports/pdf/ with every other PDF in the product, and not
            // only for tidiness: AuthPagesTest::bladePageViews() forbids Blade
            // outside the sanctioned locations, because the Blade→Inertia
            // migration deleted every page view and a new one appearing is
            // how that would quietly regress.
            $contents = $this->renderer->pdf('reports.pdf.rcsa-snapshot', [
                'title' => sprintf('%s — %s', $assessment->cycle->name, $this->unitName($assessment)),
                // The ORGANIZATION MODEL, not its id: DocumentRenderer passes
                // this to the branding resolver, which returns nothing for
                // anything that is not an Organization — and the shared PDF
                // layout then fails on a missing `primary_colour`.
                'organization' => $assessment->getRelationValue('organization'),
                'assessment' => $assessment,
                'cycle' => $assessment->cycle,
                'businessUnit' => $assessment->businessUnit,
                'lines' => $lines,
                'orientation' => 'landscape',
            ]);

            $path = sprintf(
                'rcsa/snapshots/%d/assessment-%d-%s.pdf',
                $assessment->organization_id,
                $assessment->id,
                now()->format('Ymd-His'),
            );

            Storage::disk('local')->put($path, $contents);

            return $path;
        } catch (\Throwable $e) {
            logger()->error('RCSA submission snapshot could not be rendered', [
                'assessment_id' => $assessment->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Tell the ORM there is something to review.
     *
     * WHO IS "THE ORM"? The assessment's own `reviewer_id` when one is set —
     * a reviewer who claimed it in an earlier round, or the Head of ORM having
     * assigned it. Until then, everyone holding `rcsa_assessment.review`, which
     * is P5's permission for exactly this function. That is a deliberate
     * over-notification rather than an under-one: an assessment submitted into
     * silence is the failure mode that matters.
     */
    private function notifyReviewers(RcsaAssessment $assessment, User $actor): int
    {
        $recipients = $this->reviewerRecipients($assessment, $actor);

        if ($recipients === []) {
            return 0;
        }

        NotificationService::sendMany(
            organizationId: (int) $assessment->organization_id,
            userIds: $recipients,
            type: 'rcsa.assessment.submitted',
            subject: sprintf('%s submitted their RCSA', $this->unitName($assessment)),
            body: sprintf(
                '%s submitted the %s assessment for %s: %d %s, %d above appetite.',
                $actor->name,
                $this->cycleName($assessment),
                $this->unitName($assessment),
                $lineCount = $assessment->lines()->count(),
                Str::plural('risk', $lineCount),
                $this->aboveAppetiteCount($assessment),
            ),
            metadata: ['assessment_id' => $assessment->id, 'cycle_id' => $assessment->cycle_id],
            actionUrl: route('rcsa.assessments.show', $assessment, absolute: false),
            priority: 'medium',
            category: 'workflow',
        );

        return count($recipients);
    }

    /**
     * Names for the snapshot title and the notification, defensively.
     *
     * Explicit null checks rather than `?->name ?? '...'`: larastan types a
     * `belongsTo` as non-nullable and rejects the nullsafe as dead code, while
     * the relation genuinely is null when a caller has not loaded it. Both of
     * these end up in text a person reads, so a fallback is better than a
     * fatal.
     */
    private function unitName(RcsaAssessment $assessment): string
    {
        $unit = $assessment->getRelationValue('businessUnit');

        return $unit === null ? 'A business unit' : (string) $unit->name;
    }

    private function cycleName(RcsaAssessment $assessment): string
    {
        $cycle = $assessment->getRelationValue('cycle');

        return $cycle === null ? 'RCSA' : (string) $cycle->name;
    }

    private function aboveAppetiteCount(RcsaAssessment $assessment): int
    {
        return $assessment->lines()
            ->get()
            ->filter(fn (RcsaAssessmentLine $line) => $this->assessments->isAboveAppetite($line))
            ->count();
    }
}
