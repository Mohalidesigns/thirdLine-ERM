<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\MeasureThreshold;
use App\Models\Period;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * WP-04 TASK 5 — keeps formula-valued limits honest as their inputs move.
 *
 * THE PROBLEM THIS SOLVES. A naira threshold set in 2022 and never revisited
 * classifies an ordinary 2026 transaction as a severe breach, because the
 * naira it was written in is not the naira being measured. Across a register
 * the symptom is that everything is High, the RAG column stops carrying
 * information, and people start ignoring it — which is worse than having no
 * RAG column at all. The same happens to any limit expressed as a share of a
 * denominator that grows: 0.5% of qualifying capital is a different number
 * every quarter.
 *
 * WHAT IT DOES NOT DO. It does not move a limit. It computes what the limit
 * would be, compares it to the limit actually in force, and where the gap is
 * material it raises an approval task. A limit that changes without a named
 * human agreeing to it is not a limit, and a regulator asking "who approved
 * this band" must get an answer that is not "a cron job".
 *
 * On approval a NEW effective-dated row is written with supersedes_id pointing
 * at the old one, and the old row is closed rather than edited. A breach
 * recorded last quarter keeps pointing at the band that was in force when it
 * happened.
 */
class ThresholdRebaselineService
{
    /** The approval action these tasks carry. */
    public const ACTION = 'rebaseline_threshold';

    public function __construct(
        private FormulaEvaluator $formulas,
        private ApprovalService $approvals,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Evaluation */
    /* ------------------------------------------------------------------ */

    /**
     * Re-evaluate every formula threshold for an organisation against a closed
     * period, raising an approval task where a bound has moved materially.
     *
     * @return array{examined:int, drifted:int, raised:int, skipped:int}
     */
    public function review(Period $period, ?int $organizationId = null): array
    {
        $organizationId = $organizationId ?? $period->organization_id;
        $result = ['examined' => 0, 'drifted' => 0, 'raised' => 0, 'skipped' => 0];

        $thresholds = MeasureThreshold::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereNull('effective_to')
            ->with('measure')
            ->get()
            ->filter(fn (MeasureThreshold $threshold) => $threshold->hasFormulaBounds());

        foreach ($thresholds as $threshold) {
            $result['examined']++;

            $drift = $this->driftFor($threshold, $period);

            if ($drift === null) {
                // An input the expression needs has not been recorded for the
                // period. Ordinary at the start of a cycle, and never a reason
                // to move a limit.
                $result['skipped']++;

                continue;
            }

            if (! $drift['material']) {
                continue;
            }

            $result['drifted']++;

            if ($this->raise($threshold, $period, $drift)) {
                $result['raised']++;
            }
        }

        return $result;
    }

    /**
     * Every organisation, for the periods that closed on the given date.
     *
     * @return array{examined:int, drifted:int, raised:int, skipped:int}
     */
    public function reviewAllOrganizations(Period|string|null $period = null): array
    {
        $totals = ['examined' => 0, 'drifted' => 0, 'raised' => 0, 'skipped' => 0];

        TenantContext::bypass(function () use ($period, &$totals) {
            $organizationIds = MeasureThreshold::withoutGlobalScopes()
                ->distinct()
                ->pluck('organization_id')
                ->filter()
                ->all();

            foreach ($organizationIds as $organizationId) {
                $target = $period instanceof Period
                    ? $period
                    : $this->mostRecentClosedPeriod((int) $organizationId, $period);

                if ($target === null) {
                    continue;
                }

                $result = TenantContext::actingAs(
                    (int) $organizationId,
                    fn () => $this->review($target, (int) $organizationId)
                );

                foreach ($result as $key => $count) {
                    $totals[$key] += $count;
                }
            }
        }, 'WP-04 threshold re-baselining sweep');

        return $totals;
    }

    private function mostRecentClosedPeriod(int $organizationId, ?string $code = null): ?Period
    {
        return Period::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->when($code !== null, fn ($query) => $query->where('code', $code))
            ->when($code === null, fn ($query) => $query->where('is_closed', true))
            ->orderByDesc('end_date')
            ->first();
    }

    /**
     * What each formula bound would be now, and whether the move is material.
     *
     * Returns null when the expressions cannot be evaluated at all for this
     * period.
     *
     * @return array{bands: list<array<string, mixed>>, changes: list<array<string, mixed>>, material: bool}|null
     */
    public function driftFor(MeasureThreshold $threshold, Period $period): ?array
    {
        $context = [
            'organization_id' => $threshold->organization_id,
            'object_id' => $threshold->object_id,
            'period_id' => $period->id,
            'date' => $period->end_date?->toDateString(),
        ];

        $tolerance = (float) config('measures.rebaseline_tolerance', 0.05);
        $floor = (float) config('measures.rebaseline_absolute_floor', 0.000001);

        $bands = [];
        $changes = [];
        $material = false;
        $evaluatedAny = false;

        foreach ($threshold->bands ?? [] as $band) {
            foreach (['min', 'max'] as $bound) {
                $formula = $band[$bound.'_formula'] ?? null;

                if ($formula === null || $formula === '') {
                    continue;
                }

                try {
                    $computed = $this->formulas->evaluate((string) $formula, $context);
                } catch (FormulaEvaluationException $exception) {
                    Log::info('WP-04: formula threshold not evaluable for this period', [
                        'threshold_id' => $threshold->id,
                        'band' => $band['code'] ?? null,
                        'bound' => $bound,
                        'message' => $exception->getMessage(),
                    ]);

                    continue;
                }

                $evaluatedAny = true;
                $inForce = isset($band[$bound]) && $band[$bound] !== null ? (float) $band[$bound] : null;
                $delta = $this->relativeDelta($inForce, $computed, $floor);

                $band[$bound] = $computed;

                if ($delta === null || $delta > $tolerance) {
                    $material = true;
                    $changes[] = [
                        'band' => $band['code'] ?? null,
                        'bound' => $bound,
                        'formula' => $formula,
                        'in_force' => $inForce,
                        'computed' => $computed,
                        'relative_change' => $delta,
                    ];
                }
            }

            $bands[] = $band;
        }

        if (! $evaluatedAny) {
            return null;
        }

        return ['bands' => $bands, 'changes' => $changes, 'material' => $material];
    }

    /**
     * |computed - inForce| / |inForce|, or null when there is no bound in force
     * to compare against (which is always material — the band has never been
     * given a literal value).
     */
    private function relativeDelta(?float $inForce, float $computed, float $floor): ?float
    {
        if ($inForce === null) {
            return null;
        }

        if (abs($inForce) < $floor) {
            // Comparing against zero: any move away from it is material, and a
            // move that stays within the floor is not.
            return abs($computed) < $floor ? 0.0 : null;
        }

        return abs($computed - $inForce) / abs($inForce);
    }

    /* ------------------------------------------------------------------ */
    /*  Raising the approval */
    /* ------------------------------------------------------------------ */

    /**
     * Raise a re-baselining approval task, unless one is already pending for
     * this threshold.
     *
     * PAYLOAD KEYS ARE DELIBERATELY NOT FILLABLE ON MeasureThreshold.
     * ApprovalService::approve() applies a payload to the entity with
     * update($payload) — which for a threshold would rewrite the band IN PLACE
     * and destroy the effective-dated history this whole feature exists to
     * keep. Naming the proposal `proposed_bands` rather than `bands` means mass
     * assignment discards it and apply() below is the only path that can change
     * a limit.
     *
     * WP-06: the task is now raised on the workflow engine where the tenant has
     * published the re-baselining definition, so it appears in My Tasks with an
     * SLA and an escalation like every other decision. The approval_requests
     * path is kept for tenants mid-upgrade — drift detection must not stop
     * working because a definition has not been published yet.
     *
     * @param  array{bands: list<array<string, mixed>>, changes: list<array<string, mixed>>, material: bool}  $drift
     * @return bool whether a task was raised
     */
    public function raise(MeasureThreshold $threshold, Period $period, array $drift): bool
    {
        $payload = [
            'proposed_bands' => $drift['bands'],
            'changes' => $drift['changes'],
            'period_id' => $period->id,
            'period_code' => $period->code,
            'measure_code' => $threshold->measure?->code,
            'tolerance' => (float) config('measures.rebaseline_tolerance', 0.05),
            'computed_for_period_end' => $period->end_date?->toDateString(),
        ];

        $engine = app(\App\Services\Workflow\WorkflowEngine::class);

        if ($engine->openInstanceFor($threshold) !== null) {
            return false;
        }

        $pending = ApprovalRequest::withoutGlobalScopes()
            ->where('entity_type', $threshold->getMorphClass())
            ->where('entity_id', $threshold->id)
            ->where('action', self::ACTION)
            ->where('status', 'pending')
            ->exists();

        if ($pending) {
            return false;
        }

        if ($engine->startFor('threshold_rebaselining_approval', $threshold, $payload) !== null) {
            return true;
        }

        return $this->approvals->requestApproval(
            $threshold,
            self::ACTION,
            $payload,
            null,
            $threshold->measure?->owner_id
        ) !== null;
    }

    /* ------------------------------------------------------------------ */
    /*  Applying the approval */
    /* ------------------------------------------------------------------ */

    /**
     * Approve a re-baselining task: close the band in force and write its
     * replacement.
     *
     * The old row is never edited. effective_to is set to the day before the
     * new row's effective_from, so the two are contiguous and a lookup for any
     * date resolves to exactly one band set.
     */
    public function apply(ApprovalRequest $approval, User $actor, ?string $comments = null): MeasureThreshold
    {
        if ($approval->action !== self::ACTION) {
            throw new \InvalidArgumentException('This approval is not a threshold re-baselining request.');
        }

        $threshold = MeasureThreshold::withoutGlobalScopes()->findOrFail($approval->entity_id);
        $payload = $approval->payload ?? [];
        $bands = $payload['proposed_bands'] ?? null;

        if (! is_array($bands) || $bands === []) {
            throw new \RuntimeException('The re-baselining request carries no proposed bands.');
        }

        return $this->applyBands(
            $threshold,
            $bands,
            $actor,
            $payload['computed_for_period_end'] ?? null,
            $comments,
            $approval,
        );
    }

    /**
     * Put a proposed band set in force.
     *
     * Split out of apply() in WP-06 so the workflow engine can reach it without
     * an approval_requests row. The band swap — close the row in force, write
     * its effective-dated replacement, never edit either — is the part that
     * must not be reimplemented per caller, because getting it wrong rewrites
     * history rather than extending it.
     *
     * @param  list<array<string, mixed>>  $bands
     */
    public function applyBands(
        MeasureThreshold $threshold,
        array $bands,
        ?User $actor,
        ?string $periodEnd = null,
        ?string $comments = null,
        ?ApprovalRequest $approval = null,
    ): MeasureThreshold {
        if ($bands === []) {
            throw new \RuntimeException('There are no proposed bands to put in force.');
        }

        $effectiveFrom = $periodEnd !== null
            ? CarbonImmutable::parse($periodEnd)->addDay()
            : CarbonImmutable::now();

        return DB::transaction(function () use ($approval, $threshold, $bands, $effectiveFrom, $actor, $comments) {
            $replacement = MeasureThreshold::withoutGlobalScopes()->create([
                'organization_id' => $threshold->organization_id,
                'measure_id' => $threshold->measure_id,
                'object_id' => $threshold->object_id,
                'effective_from' => $effectiveFrom->toDateString(),
                'effective_to' => null,
                'bands' => $bands,
                'direction' => $threshold->direction,
                'approved_by' => $actor?->id,
                'approved_at' => now(),
                'supersedes_id' => $threshold->id,
            ]);

            // The superseded row is closed, not modified: its bands stay
            // exactly as they were so a historic breach still reads against
            // the limit that was actually in force.
            $threshold->forceFill([
                'effective_to' => $effectiveFrom->subDay()->toDateString(),
            ])->save();

            if ($approval !== null && $actor !== null) {
                $this->approvals->approve($approval, $actor->id, $comments);
            }

            AuditTrailService::record(
                $replacement,
                'threshold_rebaselined',
                'bands',
                $threshold->bands,
                $bands,
                'Re-baselined from threshold #'.$threshold->id
                    .($approval !== null ? ' following approval #'.$approval->id.'.' : ' following workflow approval.')
            );

            return $replacement;
        });
    }

    /**
     * Reject a re-baselining task. The band in force does not move.
     */
    public function reject(ApprovalRequest $approval, User $actor, string $reason): void
    {
        $this->approvals->reject($approval, $actor->id, $reason);
    }

    /**
     * Pending re-baselining tasks for an organisation.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, ApprovalRequest>
     */
    public function pending(?int $organizationId = null)
    {
        return ApprovalRequest::withoutGlobalScopes()
            ->where('organization_id', $organizationId ?? TenantContext::organizationId())
            ->where('action', self::ACTION)
            ->where('status', 'pending')
            ->orderByDesc('requested_at')
            ->get();
    }
}
