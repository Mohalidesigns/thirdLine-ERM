<?php

namespace App\Services\Rcsa;

use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaAssessmentLine;
use App\Models\Rcsa\RcsaAssessmentTransition;
use App\Models\Rcsa\RcsaCycle;
use App\Models\Rcsa\RcsaMethodology;
use App\Models\Rcsa\RcsaRegisterControl;
use App\Models\Rcsa\RcsaRegisterRisk;
use App\Models\Rcsa\RcsaSystem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Opening and closing an RCSA cycle.
 *
 * OPENING IS WHERE STEP 3 OF THE PROCESS FLOW HAPPENS — "the system populates
 * Process, Risk & Control". Every published universe row for every in-scope
 * business unit is copied into `rcsa_assessment_lines`, so a risk champion
 * opening their unit's RCSA finds it already filled in and only has to answer
 * columns J, K and O.
 *
 * IT COPIES, IT DOES NOT REFERENCE. The line carries the risk statement, the
 * driver, the category and the control text as TEXT, alongside the foreign key
 * they came from. That is what makes a closed cycle reproducible: editing the
 * universe in November cannot rewrite what June's assessment said.
 *
 * OPENING IS ONE-WAY AND IDEMPOTENT-BY-REFUSAL. A cycle that is already open
 * is not re-provisioned, because provisioning twice would either duplicate
 * every line or silently discard scoring already done. A risk added to the
 * universe after a cycle opened belongs to the NEXT cycle — which is the
 * honest behaviour: an assessment whose line count changes underneath the
 * assessor is not a document anybody can sign.
 */
class RcsaCycleService
{
    public function __construct(
        private readonly RcsaCalculationService $calculator,
        private readonly RcsaWorkflowService $workflow,
    ) {}

    /**
     * Open a cycle: provision an assessment per in-scope business unit and
     * snapshot the published universe into it.
     *
     * @param  list<int>|null  $businessUnitIds  Null means every unit that has published risks.
     * @return array{assessments: int, lines: int}
     */
    public function open(RcsaCycle $cycle, User $actor, ?array $businessUnitIds = null): array
    {
        if ($cycle->status !== RcsaCycle::DRAFT) {
            throw new RuntimeException('Only a draft cycle can be opened. This one is '.$cycle->status.'.');
        }

        $methodology = $this->calculator->methodology($cycle->methodology);

        if ($methodology === null) {
            throw new RuntimeException('This cycle has no methodology to score against.');
        }

        $risks = RcsaRegisterRisk::query()
            ->assessable()
            ->when($businessUnitIds !== null, fn ($q) => $q->whereIn('business_unit_id', $businessUnitIds))
            ->with(['businessUnit:id,name', 'process:id,name', 'subProcess:id,name', 'controls'])
            ->orderBy('business_unit_id')
            ->orderBy('risk_no')
            ->get();

        if ($risks->isEmpty()) {
            throw new RuntimeException(
                'There are no published risks in the RCSA Universe to assess. '
                .'Publish the universe rows first — a draft risk is not carried into a cycle.'
            );
        }

        $systems = RcsaSystem::query()->pluck('name', 'id')->all();
        $priorLines = $this->priorLinesByRisk($cycle);

        $result = DB::transaction(function () use ($cycle, $actor, $risks, $methodology, $systems, $priorLines) {
            $assessments = 0;
            $lines = 0;

            foreach ($risks->groupBy('business_unit_id') as $businessUnitId => $unitRisks) {
                $assessment = RcsaAssessment::create([
                    'organization_id' => $cycle->organization_id,
                    'cycle_id' => $cycle->id,
                    'business_unit_id' => $businessUnitId,
                    'status' => RcsaAssessment::IN_PROGRESS,
                    'completion_pct' => 0,
                ]);

                $assessments++;

                // §9.1's diagram calls this edge "open" — none of the assessor's
                // own state machine, since the row lands straight in
                // `in_progress` with no `draft` ever persisted, but it is the
                // reason the assessment exists and belongs in its history the
                // same as every later move.
                $this->workflow->recordTransition(
                    $assessment,
                    from: null,
                    to: RcsaAssessment::IN_PROGRESS,
                    actor: $actor,
                    event: RcsaAssessmentTransition::OPEN,
                );

                $sort = 0;

                foreach ($unitRisks as $risk) {
                    $this->lineFor($assessment, $risk, $methodology, $systems, $priorLines, $sort += 10);
                    $lines++;
                }
            }

            $cycle->forceFill([
                'status' => RcsaCycle::OPEN,
                'opened_by' => $actor->id,
                'opened_at' => now(),
            ])->save();

            // The methodology now has assessments behind it, so its scales and
            // bands are frozen: re-cutting a band under a live cycle would
            // silently re-rate every line already scored.
            if (! $methodology->is_locked) {
                $methodology->forceFill(['is_locked' => true, 'locked_at' => now()])->save();
            }

            return ['assessments' => $assessments, 'lines' => $lines];
        });

        return $result;
    }

    /**
     * Close a cycle. Everything under it becomes read-only.
     *
     * EVERY ASSESSMENT THE CLOSE TOUCHES GETS ONE HISTORY ROW, whether or not
     * its own `status` column moves. `validated`, `submitted` and
     * `under_review` land on `closed` — a decision was reached, or one was
     * pending, and the cycle ended it. `in_progress` and `returned` (and, if a
     * tenant enables the BU-head step, `bu_approval`) are NOT moved to
     * `closed`: `CycleProvisioningTest::closing_a_cycle_freezes_every_
     * assessment_under_it` pins an unfinished assessment staying exactly
     * `in_progress`, because it is the CYCLE that freezes it —
     * `RcsaAssessment::acceptsEdits()` consults both — not a status that
     * pretends the work was finished. But the freeze is still an event: a
     * from == to row, the same shape `escalate()` already writes, so the audit
     * view explains why a screen that let someone edit yesterday is read-only
     * today, even though `status` alone doesn't say so.
     *
     * THE "ALREADY CLOSED" CHECK IS RE-RUN INSIDE THE TRANSACTION, AGAINST A
     * LOCKED ROW. The cheap check above is for the ordinary case — refusing
     * before opening a transaction at all — but two requests from a doubled-up
     * click both pass it in the gap between the read and the write, and
     * without a lock both proceed to write a second set of close rows over
     * the same assessments. `lockForUpdate()` makes the second request wait
     * for the first to commit, then see `closed` and refuse.
     */
    public function close(RcsaCycle $cycle, User $actor, ?string $reason = null): void
    {
        if ($cycle->status === RcsaCycle::CLOSED) {
            throw new RuntimeException('This cycle is already closed.');
        }

        DB::transaction(function () use ($cycle, $actor, $reason) {
            $locked = RcsaCycle::query()->whereKey($cycle->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === RcsaCycle::CLOSED) {
                throw new RuntimeException('This cycle is already closed.');
            }

            $cycle->forceFill([
                'status' => RcsaCycle::CLOSED,
                'closed_by' => $actor->id,
                'closed_at' => now(),
            ])->save();

            $assessments = $cycle->assessments()
                ->whereNotIn('status', [RcsaAssessment::CLOSED])
                ->get(['id', 'organization_id', 'status']);

            foreach ($assessments as $assessment) {
                $from = (string) $assessment->status;
                $decided = in_array($from, [
                    RcsaAssessment::VALIDATED,
                    RcsaAssessment::SUBMITTED,
                    RcsaAssessment::UNDER_REVIEW,
                ], true);
                $to = $decided ? RcsaAssessment::CLOSED : $from;

                if ($decided) {
                    // The lines themselves are frozen by the cycle's status,
                    // not restamped here — a closing cycle that rewrote every
                    // line would be a large write whose only effect was to
                    // make `updated_at` lie about when the assessment was
                    // last worked on. Only the assessment's own status moves.
                    $assessment->forceFill(['status' => $to])->save();
                }

                $this->workflow->recordTransition(
                    $assessment,
                    from: $from,
                    to: $to,
                    actor: $actor,
                    event: RcsaAssessmentTransition::CLOSE,
                    reason: $reason,
                );
            }
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Provisioning */
    /* ------------------------------------------------------------------ */

    /**
     * Snapshot one universe row into a line.
     *
     * @param  array<int, string>  $systems
     * @param  array<int, int>  $priorLines  register_risk_id => prior line id
     */
    private function lineFor(
        RcsaAssessment $assessment,
        RcsaRegisterRisk $risk,
        RcsaMethodology $methodology,
        array $systems,
        array $priorLines,
        int $sort,
    ): RcsaAssessmentLine {
        $systemNames = array_values(array_filter(array_map(
            fn ($id) => $systems[(int) $id] ?? null,
            $risk->system_ids ?? []
        )));

        return RcsaAssessmentLine::create([
            'organization_id' => $assessment->organization_id,
            'assessment_id' => $assessment->id,
            'register_risk_id' => $risk->id,
            'business_unit_id' => $risk->business_unit_id,
            'process_id' => $risk->process_id,
            'sub_process_id' => $risk->sub_process_id,

            /* --- The snapshot ------------------------------------------ */
            'risk_no' => $risk->risk_no,
            'business_unit_name' => $this->unitName($risk),
            'process_name' => $risk->getRelationValue('process')?->name,
            'sub_process_name' => $risk->getRelationValue('subProcess')?->name,
            'system_names' => $systemNames,
            'potential_risk' => $risk->potential_risk,
            'risk_driver' => $risk->risk_driver,
            'risk_category' => $risk->risk_category,
            'secondary_categories' => $risk->secondary_categories,
            'existing_control' => $this->controlText($risk),

            /* --- The assessor's starting position ---------------------- */
            // Copied from the universe's defaults, which are a suggestion and
            // not an assessment: nothing scores or reports off them until the
            // assessor confirms them, and the line is not counted as complete
            // until control effectiveness is answered too.
            'inherent_likelihood' => $risk->default_likelihood,
            'inherent_impact' => $risk->default_impact,

            'prior_cycle_line_id' => $priorLines[$risk->id] ?? null,
            'methodology_id' => $methodology->id,
            'row_hash' => $risk->row_hash,
            'version' => 1,
            'sort_order' => $sort,
        ]);
    }

    /**
     * The business unit's name, defensively.
     *
     * Written as an explicit null check rather than `?->name ?? ''` because
     * larastan types a `belongsTo` as non-nullable and rejects the nullsafe as
     * dead — while the relation genuinely can be null if a caller forgets to
     * eager-load it. `business_unit_name` is NOT NULL on the line, so an empty
     * string is the only safe fallback.
     */
    private function unitName(RcsaRegisterRisk $risk): string
    {
        $unit = $risk->getRelationValue('businessUnit');

        return $unit === null ? '' : (string) $unit->name;
    }

    /**
     * The workbook's single "Existing Control" cell: every control on the risk,
     * newline-separated, in the order the universe holds them.
     *
     * Flattened at PROVISIONING time rather than read live, for the same
     * reason as everything else on the line — a control edited or removed in
     * the universe next quarter must not change what this assessment says was
     * in place.
     */
    private function controlText(RcsaRegisterRisk $risk): ?string
    {
        $descriptions = $risk->controls
            ->map(fn (RcsaRegisterControl $control) => trim((string) $control->description))
            ->filter()
            ->values();

        return $descriptions->isEmpty() ? null : $descriptions->implode("\n");
    }

    /**
     * The most recent previously-assessed line for each risk, so the workspace
     * can show last cycle's answer beside this one.
     *
     * Only lines that were actually SCORED count. A prior cycle where somebody
     * opened the assessment and never answered anything gives no basis for a
     * "moved since last time" comparison, and offering one would invite an
     * assessor to accept a blank as agreement.
     *
     * @return array<int, int>
     */
    private function priorLinesByRisk(RcsaCycle $cycle): array
    {
        $prior = [];

        RcsaAssessmentLine::query()
            ->whereNotNull('register_risk_id')
            ->whereNotNull('inherent_score')
            ->whereHas('assessment.cycle', fn ($q) => $q
                ->where('id', '!=', $cycle->id)
                ->where('period_start', '<=', $cycle->period_start))
            ->orderBy('id')
            ->select(['id', 'register_risk_id'])
            ->chunk(1000, function ($lines) use (&$prior) {
                foreach ($lines as $line) {
                    // Later ids win, so the newest scored line for a risk is
                    // the one carried forward.
                    $prior[(int) $line->register_risk_id] = (int) $line->id;
                }
            });

        return $prior;
    }
}
