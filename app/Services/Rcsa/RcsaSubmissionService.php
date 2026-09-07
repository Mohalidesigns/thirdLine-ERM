<?php

namespace App\Services\Rcsa;

use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaAssessmentLine;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
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
    public function __construct(
        private readonly RcsaAssessmentService $assessments,
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
     * @return array{lines: int, snapshot: string|null, notified: int}
     */
    public function submit(RcsaAssessment $assessment, User $actor): array
    {
        if (! in_array($assessment->status, RcsaAssessment::EDITABLE, true)) {
            throw new RuntimeException('This assessment has already been submitted.');
        }

        if (! $assessment->acceptsEdits()) {
            throw new RuntimeException('This assessment is closed and cannot be submitted.');
        }

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

        DB::transaction(function () use ($assessment, $actor, $snapshot) {
            $assessment->lines()->update([
                'locked_at' => now(),
                'locked_by' => null,
                'lock_expires_at' => null,
            ]);

            $assessment->forceFill([
                'status' => RcsaAssessment::SUBMITTED,
                'submitted_by' => $actor->id,
                'submitted_at' => now(),
                'snapshot_path' => $snapshot,
                // A resubmission after a return must not still show the reason
                // it was returned for.
                'returned_reason' => null,
            ])->save();
        });

        $assessment->loadMissing(['cycle', 'businessUnit']);

        $notified = $this->notifyReviewers($assessment, $actor);

        return [
            'lines' => $assessment->lines()->count(),
            'snapshot' => $snapshot,
            'notified' => $notified,
        ];
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
     * P5 assigns them. Until then, everyone who may close a cycle, which is
     * the operational-risk function in every role map this product ships. That
     * is a deliberate over-notification rather than an under-one: an
     * assessment submitted into silence is the failure mode that matters.
     */
    private function notifyReviewers(RcsaAssessment $assessment, User $actor): int
    {
        $recipients = $assessment->reviewer_id !== null
            ? [$assessment->reviewer_id]
            : User::query()
                ->where('organization_id', $assessment->organization_id)
                ->where('is_active', true)
                ->whereKeyNot($actor->id)
                ->get()
                ->filter(fn (User $user) => $user->can('rcsa_cycle.close'))
                ->pluck('id')
                ->all();

        if ($recipients === []) {
            return 0;
        }

        NotificationService::sendMany(
            organizationId: (int) $assessment->organization_id,
            userIds: array_values(array_map('intval', $recipients)),
            type: 'rcsa.assessment.submitted',
            subject: sprintf('%s submitted their RCSA', $this->unitName($assessment)),
            body: sprintf(
                '%s submitted the %s assessment for %s: %d risks, %d above appetite.',
                $actor->name,
                $this->cycleName($assessment),
                $this->unitName($assessment),
                $assessment->lines()->count(),
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
