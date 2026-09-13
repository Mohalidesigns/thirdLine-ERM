<?php

namespace App\Services\Bcms\Plans;

use App\Enums\Bcms\FindingClassification;
use App\Enums\Bcms\FindingSource;
use App\Enums\Bcms\IsoClauseRef;
use App\Models\Bcms\Plan;
use App\Services\Bcms\Findings\FindingService;
use Illuminate\Support\Facades\DB;

/**
 * Detects when a plan has stopped agreeing with the data it was assembled from.
 *
 * THIS IS THE MECHANISM THE PRODUCT IS SOLD ON. Every bank has continuity plans
 * and every bank's plans are out of date, because nothing tells anybody when the
 * BIA moved underneath them. Here, each bound section carries the SHA-256 of
 * what it resolved to when it was last verified; a sweep re-resolves and
 * compares. A section whose source has changed is flagged, the plan reads as
 * needing review, and the builder highlights exactly which section moved and
 * which did not.
 *
 * IT RUNS OVER APPROVED PLANS TOO, AND THAT IS THE POINT. A draft that has
 * drifted is a draft; an approved plan that has drifted is a plan the bank is
 * relying on and which no longer matches its own recovery objectives. The flag
 * is written; the approved version's frozen `content` is NOT touched, because
 * an approved version is immutable and the remedy for a drifted one is a v2.
 *
 * NOTHING IS AUTO-CORRECTED. Standing rule 4's reasoning applies beyond AI: the
 * system says a plan has drifted, and a human decides whether that means a new
 * version, a strategy change or nothing at all. A plan that silently rewrote
 * itself would be one nobody had read.
 */
class PlanDriftDetector
{
    public function __construct(
        private readonly SourceResolver $resolver,
        private readonly PlanAssembler $assembler,
        private readonly FindingService $findings,
    ) {}

    /**
     * Check one plan. Returns the section keys that have drifted.
     *
     * @return list<string>
     */
    public function check(Plan $plan): array
    {
        $drifted = [];

        foreach ($this->assembler->boundSections($plan) as ['section' => $section, 'binding' => $binding]) {
            $current = $this->resolver->fingerprint($plan, $binding);

            // A section that has never been verified has no fingerprint to
            // compare against. It is not drifted — it is unassembled, which the
            // builder shows differently, because "we have never checked" and
            // "we checked and it moved" are different sentences to a reviewer.
            if ($section->source_fingerprint === null) {
                continue;
            }

            if (hash_equals($section->source_fingerprint, $current)) {
                if ($section->needs_review) {
                    // The source moved and moved back. Clearing the flag is
                    // right: an `updated_at` watermark could not do this, and a
                    // reviewer chasing a change that no longer exists loses
                    // faith in the flag.
                    $section->forceFill(['needs_review' => false])->save();
                }

                continue;
            }

            $section->forceFill(['needs_review' => true])->save();
            $drifted[] = $section->section_key;
        }

        return $drifted;
    }

    /**
     * Sweep every plan that could drift.
     *
     * Drafts and approved plans; not archived ones, which are historical
     * records and are supposed to disagree with today.
     *
     * @return array{checked: int, drifted: int, sections: int, findings: int}
     */
    public function sweep(bool $raiseFindings = false): array
    {
        $checked = 0;
        $driftedPlans = 0;
        $driftedSections = 0;
        $raised = 0;

        Plan::query()
            ->whereIn('status', ['draft', 'review', 'approved'])
            ->orderBy('id')
            ->chunkById(100, function ($plans) use (
                &$checked, &$driftedPlans, &$driftedSections, &$raised, $raiseFindings
            ) {
                foreach ($plans as $plan) {
                    $checked++;
                    $drifted = $this->check($plan);

                    if ($drifted === []) {
                        continue;
                    }

                    $driftedPlans++;
                    $driftedSections += count($drifted);

                    if ($raiseFindings && $plan->status === 'approved') {
                        $raised += $this->raiseFinding($plan, $drifted) ? 1 : 0;
                    }
                }
            });

        return [
            'checked' => $checked,
            'drifted' => $driftedPlans,
            'sections' => $driftedSections,
            'findings' => $raised,
        ];
    }

    /**
     * Every plan currently carrying a drifted section.
     *
     * `review_required` is DERIVED, not stored (ADR 0011). Two facts that can
     * disagree, and the one that would be wrong is the one the dashboard reads.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Plan>
     */
    public function needsReviewQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return Plan::query()
            ->whereIn('status', ['draft', 'review', 'approved'])
            ->whereHas('sections', fn ($q) => $q->where('needs_review', true));
    }

    /**
     * Raise a finding for an approved plan that has drifted — once.
     *
     * ONLY FOR APPROVED PLANS, and only one open finding per plan. A drifted
     * draft is somebody's work in progress; a drifted approved plan is a
     * document the bank is relying on that no longer describes it.
     *
     * IT IS AN OBSERVATION, NOT A NONCONFORMITY, and that is a deliberate limit
     * on what a machine may assert. A fingerprint mismatch is evidence that
     * something moved; whether the movement makes the plan non-compliant with
     * clause 8.4.4 is a judgement — a new process joining a unit drifts a
     * section without making the plan wrong. Nonconformities also cannot be
     * closed without a verified corrective action (`FindingService::close()`),
     * so raising one nightly from a hash comparison would fill the register
     * with items nobody can clear. The system observes; a human classifies.
     *
     * Observations are not mirrored into the ERM issue register either, which
     * is `ErmBridge`'s own rule and the right one here: this belongs in front of
     * the BC officer, not in the enterprise issue log.
     *
     * Raising a second finding every night is how a register becomes noise
     * nobody reads, so there is one open finding per plan and no more.
     */
    private function raiseFinding(Plan $plan, array $sections): bool
    {
        $exists = DB::table('bcms_findings')
            ->where('organization_id', $plan->organization_id)
            ->where('affected_plan_id', $plan->getKey())
            ->where('source', FindingSource::PlanReview->value)
            ->whereNotIn('status', ['closed', 'accepted'])
            ->exists();

        if ($exists) {
            return false;
        }

        $this->findings->raise(
            source: FindingSource::PlanReview,
            classification: FindingClassification::Observation,
            description: 'Plan "'.$plan->title.'" v'.$plan->version.' no longer matches the data it was '
                .'assembled from. These bound sections have drifted since it was approved: '
                .implode(', ', $sections).'. An approved plan version is immutable, so the correction is a new '
                .'version rather than an edit.',
            sourceRecord: $plan,
            // `bcms_findings` has no title and no owner of its own: the
            // description IS the finding, and ownership arrives with the
            // corrective action. Passing either would be silently dropped in a
            // request and would throw in a seeder.
            attributes: [
                'organization_id' => $plan->organization_id,
                'iso_clause_ref' => IsoClauseRef::Iso22301_8_4_4->value,
            ],
        );

        return true;
    }
}
