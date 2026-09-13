<?php

namespace App\Services\Tprm\Integration;

use App\Enums\Tprm\FindingSeverity;
use App\Enums\Tprm\FindingStatus;
use App\Enums\Tprm\RiskTier;
use App\Models\Issue;
use App\Models\Risk;
use App\Models\RiskCategory;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\Finding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Where TPRM stops being a module beside the ERM programme and becomes part of
 * it — TRD §15, and the requirement this whole build was asked for: it must
 * feed into the key risk areas.
 *
 * TWO JOINS, AND THEY POINT DIFFERENT WAYS ON PURPOSE.
 *
 *   A FINDING BECOMES AN ISSUE. The issue register is where an institution
 *   tracks things that need fixing, and a third-party control gap is one of
 *   those. Without the mirror, a bank runs two remediation registers and the
 *   board pack from one does not reconcile to the other.
 *
 *   AN ENGAGEMENT BECOMES A RISK, but only above a threshold. Every Critical
 *   or High tier engagement, and every Critical finding, creates or updates a
 *   risk in the ERM register under "Third-Party and Outsourcing Risk". NOT
 *   every engagement: a register with four hundred stationery suppliers in it
 *   is a register nobody reads, and the ERM risk register is a governance
 *   artefact rather than an inventory.
 *
 * THE SYNC IS ONE-WAY FOR CONTENT AND TWO-WAY FOR CLOSURE. TPRM owns the
 * finding's description, severity and dates; the issue mirrors them. But
 * closing either should close the other, because the alternative is a bank
 * that has fixed something and still shows it open in one of its two
 * registers — and whichever register is wrong, somebody stops trusting both.
 *
 * IT IS TOLERANT OF A MISSING CATEGORY. A deployment where the TPRM reference
 * seeder has not run has no `OR-TP` category, and the right behaviour is to
 * skip the mirror and log it rather than to fail the finding that triggered
 * it. TPRM working without the ERM link is a smaller problem than TPRM
 * refusing to record a finding.
 */
class ErmBridge
{
    /** The ERM risk category the TPRM reference seeder creates. */
    public const CATEGORY_CODE = 'OR-TP';

    /**
     * Mirror a finding into the ERM issue register — FR-FND and TRD §15.
     *
     * Idempotent: a finding already mirrored is UPDATED rather than duplicated,
     * because a re-sync after an edit is an ordinary thing to happen and two
     * issues for one finding is the state that makes the reconciliation
     * impossible.
     */
    public function mirrorFinding(Finding $finding): ?Issue
    {
        if (! config('tprm.integration.mirror_findings_to_issues', true)) {
            return null;
        }

        $finding->loadMissing(['engagement', 'thirdParty']);

        return DB::transaction(function () use ($finding) {
            $issue = $finding->erm_issue_id === null
                ? null
                : Issue::query()->find($finding->erm_issue_id);

            $attributes = [
                'organization_id' => $finding->organization_id,
                'title' => Str::limit($finding->title, 250),
                'description' => $this->issueDescription($finding),
                'issue_source' => 'risk_assessment',
                'issue_category' => 'third_party',
                'priority' => $this->priorityFor($finding->severity),
                'responsible_owner_id' => $finding->owner_id,
                'business_unit_id' => $finding->engagement?->business_unit_id,
                'remediation_due_date' => $finding->target_date?->toDateString(),
                'issue_status' => $this->issueStatusFor($finding),
            ];

            if ($issue === null) {
                $issue = Issue::create($attributes + [
                    'issue_reference' => $this->issueReference($finding),
                    'created_by' => $finding->created_by,
                    'date_identified' => $finding->identified_at?->toDateString(),
                ]);
            } else {
                $issue->fill($attributes)->save();
            }

            $finding->forceFill([
                'erm_issue_id' => $issue->getKey(),
                'erm_synced_at' => now(),
            ])->save();

            return $issue;
        });
    }

    /**
     * Close the mirrored issue when the finding closes, and the reverse.
     *
     * The reverse direction is the one worth having: an issue closed in the
     * ERM register by somebody who has never opened the TPRM module should not
     * leave a third-party finding sitting open for another year.
     */
    public function syncClosure(Finding $finding): void
    {
        $issue = $finding->erm_issue_id === null ? null : Issue::query()->find($finding->erm_issue_id);

        if ($issue === null) {
            return;
        }

        if (! $finding->isOpen() && $issue->issue_status !== 'CLOSED') {
            $issue->forceFill([
                'issue_status' => 'CLOSED',
                'actual_close_date' => now()->toDateString(),
                'closure_justification' => sprintf(
                    'Closed in the third-party register as %s.',
                    str_replace('_', ' ', (string) $finding->closure_type),
                ),
            ])->save();

            $finding->forceFill(['erm_synced_at' => now()])->save();
        }
    }

    /**
     * The reverse: an issue closed in the ERM register closes its finding.
     *
     * Called from the nightly sweep rather than an observer on `Issue`,
     * deliberately. An observer on a core model would make every issue save in
     * the product pay for a TPRM lookup, in a deployment that may not have
     * TPRM switched on at all.
     *
     * @return \Illuminate\Support\Collection<int, Finding>
     */
    public function pullClosedIssues(?int $organizationId = null)
    {
        $closed = Finding::query()
            ->withoutGlobalScopes()
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->open()
            ->whereNotNull('erm_issue_id')
            ->whereIn('erm_issue_id', Issue::query()->where('issue_status', 'CLOSED')->select('id'))
            ->get();

        foreach ($closed as $finding) {
            $finding->forceFill([
                'status' => FindingStatus::ClosedRemediated->value,
                'closure_type' => 'remediated',
                'erm_synced_at' => now(),
            ])->save();

            $finding->writeAuditRow('closed_from_erm_issue', null, [
                'issue_id' => $finding->erm_issue_id,
                'note' => 'The mirrored issue was closed in the ERM register, so this finding was closed to '
                    .'match. A bank showing the same gap open in one register and closed in the other is a '
                    .'bank that stops trusting both.',
            ]);
        }

        return $closed;
    }

    /**
     * Create or update the ERM risk this engagement rolls into — TRD §15.
     *
     * ONLY ABOVE THE THRESHOLD. Critical and High tiers, or any Critical
     * finding. The ERM risk register is a governance artefact, and putting
     * four hundred stationery suppliers in it is how it stops being read.
     */
    public function mirrorEngagementRisk(Engagement $engagement): ?Risk
    {
        if (! config('tprm.integration.mirror_engagements_to_risks', true)) {
            return null;
        }

        if (! $this->warrantsErmRisk($engagement)) {
            return null;
        }

        $category = RiskCategory::query()
            ->where('organization_id', $engagement->organization_id)
            ->where('code', self::CATEGORY_CODE)
            ->first();

        if ($category === null) {
            // The seeder has not run. Skipped and logged rather than fatal:
            // TPRM working without the ERM link is a far smaller problem than
            // TPRM refusing to record anything.
            logger()->warning('TPRM could not mirror an engagement to the ERM register', [
                'engagement_id' => $engagement->getKey(),
                'reason' => 'No "'.self::CATEGORY_CODE.'" risk category exists for this organisation.',
            ]);

            return null;
        }

        $engagement->loadMissing('thirdParty');

        return DB::transaction(function () use ($engagement, $category) {
            $risk = $engagement->erm_risk_id === null
                ? null
                : Risk::query()->find($engagement->erm_risk_id);

            $attributes = [
                'organization_id' => $engagement->organization_id,
                'title' => Str::limit(sprintf(
                    'Third-party dependency: %s (%s)',
                    $engagement->thirdParty->legal_name ?? 'provider',
                    $engagement->name,
                ), 250),
                'description' => $this->riskDescription($engagement),
                'category_id' => $category->getKey(),
                'business_unit_id' => $engagement->business_unit_id,
                'risk_owner_id' => $engagement->relationship_owner_id,
                'risk_source' => 'third_party',
                // The TPRM scores, carried across on the 0–100 scale the ERM
                // register uses for its own. They are the same quantity: this
                // engagement's risk before and after the assurance held.
                'inherent_score' => $engagement->inherent_score === null
                    ? null
                    : (int) round((float) $engagement->inherent_score),
                'residual_score' => $engagement->residual_score === null
                    ? null
                    : (int) round((float) $engagement->residual_score),
                'status' => 'active',
                'last_assessment_date' => now()->toDateString(),
            ];

            if ($risk === null) {
                $risk = Risk::create($attributes + [
                    'risk_code' => $this->riskCode($engagement),
                    'date_identified' => now()->toDateString(),
                    'created_by' => $engagement->created_by,
                ]);
            } else {
                $risk->fill($attributes)->save();
            }

            $engagement->forceFill(['erm_risk_id' => $risk->getKey()])->save();

            return $risk;
        });
    }

    /**
     * Whether this engagement belongs in the ERM risk register.
     */
    public function warrantsErmRisk(Engagement $engagement): bool
    {
        $tier = $engagement->effectiveTier();

        if (in_array($tier, [RiskTier::Critical, RiskTier::High], true)) {
            return true;
        }

        return Finding::query()
            ->where('engagement_id', $engagement->getKey())
            ->where('severity', FindingSeverity::Critical->value)
            ->open()
            ->exists();
    }

    /* ------------------------------------------------------------------ */

    private function issueDescription(Finding $finding): string
    {
        return implode("\n\n", array_filter([
            $finding->description,
            sprintf(
                'Raised in the third-party register as %s against %s%s.',
                $finding->reference,
                $finding->thirdParty->legal_name ?? 'a third party',
                $finding->engagement ? ' ('.$finding->engagement->reference.')' : '',
            ),
            $finding->regulatory_citation ? 'Citation: '.$finding->regulatory_citation : null,
        ]));
    }

    private function riskDescription(Engagement $engagement): string
    {
        return sprintf(
            "Risk arising from the institution's dependency on %s for %s.\n\n"
            .'Tier %s. This risk is maintained from the third-party register: its scores move when that '
            ."engagement's assessment, findings or monitoring signals change, and it should be edited there "
            .'rather than here.',
            $engagement->thirdParty->legal_name ?? 'this provider',
            $engagement->service_description ?: $engagement->name,
            $engagement->effectiveTier()?->label() ?? 'not yet assigned',
        );
    }

    private function priorityFor(FindingSeverity $severity): string
    {
        return match ($severity) {
            FindingSeverity::Critical => 'critical',
            FindingSeverity::High => 'high',
            FindingSeverity::Medium => 'medium',
            FindingSeverity::Low => 'low',
        };
    }

    private function issueStatusFor(Finding $finding): string
    {
        return match (true) {
            ! $finding->isOpen() => 'CLOSED',
            $finding->isOverdue() => 'OVERDUE',
            $finding->status === FindingStatus::Open => 'OPEN',
            default => 'IN_PROGRESS',
        };
    }

    /**
     * The issue's own reference, derived from the finding's.
     *
     * Derived rather than sequential so the two registers can be reconciled by
     * eye: an examiner holding a TPRM board pack and an issues report should
     * be able to see that TPF-2026-0007 and ISS-TPF-2026-0007 are the same
     * thing without a lookup table.
     */
    private function issueReference(Finding $finding): string
    {
        return 'ISS-'.$finding->reference;
    }

    private function riskCode(Engagement $engagement): string
    {
        return 'TPR-'.$engagement->reference;
    }
}
