<?php

namespace App\Services\Bcms\Integration;

use App\Enums\Bcms\FindingClassification;
use App\Models\Bcms\CorrectiveAction;
use App\Models\Bcms\Finding;
use App\Models\Issue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Where BCMS stops being a module beside the ERM programme and becomes part of
 * it (ADR 0001). Modelled on TPRM's bridge, which settled the shape.
 *
 * ONE JOIN, AND IT IS DELIBERATELY NARROWER THAN TPRM'S. A BCMS finding becomes
 * an ERM **issue**, because the issue register is where an institution tracks
 * things that need fixing and a continuity gap is one of those. Without the
 * mirror a bank runs two remediation registers and the board pack from one does
 * not reconcile to the other.
 *
 * BCMS DOES **NOT** CREATE ERM RISKS. TPRM does, because a critical vendor is a
 * concentration a risk committee must see in the register. A missed fire drill
 * is not a new risk — the disruption risks it exercises are already in the
 * register, and creating "Fire drill overdue at Kano branch" as a risk would
 * fill the register with programme-management noise and make it unreadable. The
 * continuity exposure reaches the register through the **KRIs** the objectives
 * are measured by (Blueprint §4.2), which is the join the blueprint actually
 * asks for.
 *
 * ONE-WAY FOR CONTENT, TWO-WAY FOR CLOSURE. BCMS owns the finding's
 * description, classification and dates; the issue mirrors them. Closing either
 * closes the other, because a bank showing the same gap open in one register
 * and closed in the other stops trusting both.
 *
 * TOLERANT OF A MISSING TARGET. A deployment with the mirror switched off, or
 * where the write fails, logs and continues. BCMS working without the ERM link
 * is a smaller problem than BCMS refusing to record the finding that triggered
 * it.
 */
class ErmBridge
{
    /**
     * Mirror a finding into the ERM issue register.
     *
     * Idempotent: a finding already mirrored is UPDATED, never duplicated. A
     * re-sync after an edit is an ordinary thing to happen, and two issues for
     * one finding is the state that makes the reconciliation impossible.
     */
    public function mirrorFinding(Finding $finding): ?Issue
    {
        if (! config('bcms.integration.mirror_findings_to_issues', true)) {
            return null;
        }

        // Only nonconformities and improvements reach the issue register. An
        // OBSERVATION is a fact recorded so the next exercise can look at it
        // again; mirroring every observation would bury the issues somebody has
        // to act on under the ones nobody does.
        if ($finding->classification === FindingClassification::Observation) {
            return null;
        }

        try {
            return DB::transaction(function () use ($finding) {
                $issue = $finding->erm_issue_id === null
                    ? null
                    : Issue::query()->find($finding->erm_issue_id);

                $attributes = [
                    'organization_id' => $finding->organization_id,
                    'title' => Str::limit($this->title($finding), 250),
                    'description' => $finding->description,
                    'issue_source' => $finding->source?->value === 'audit' ? 'audit' : 'self_identified',
                    'issue_category' => 'business_continuity',
                    'priority' => $this->priorityFor($finding),
                    'responsible_owner_id' => $finding->correctiveActions()->value('owner_id'),
                    'business_unit_id' => $finding->affected_business_unit_id,
                    'issue_status' => $finding->status === 'open' ? 'OPEN' : 'IN_PROGRESS',
                ];

                if ($issue === null) {
                    // NO `date_identified`. `issues` has no such column — that
                    // one is on `risks` — and the identification date stays on
                    // `bcms_findings.raised_at`, which is the record an auditor
                    // asks about anyway.
                    //
                    // Worth knowing WHY this survived being written: a key for
                    // a column that does not exist is silently dropped by
                    // mass-assignment guarding in a request, and THROWS inside
                    // a seeder, where Laravel unguards. A dead write that only
                    // fails in one of the two contexts is one nobody finds
                    // (development standard §6).
                    $issue = Issue::query()->create($attributes + [
                        'issue_reference' => $finding->reference,
                        'created_by' => $finding->created_by,
                    ]);
                } else {
                    $issue->fill($attributes)->save();
                }

                $finding->forceFill(['erm_issue_id' => $issue->getKey()])->save();

                return $issue;
            });
        } catch (\Throwable $e) {
            Log::error('A BCMS finding could not be mirrored into the ERM issue register', [
                'finding_id' => $finding->getKey(),
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** Closing a finding closes its mirrored issue. */
    public function syncClosure(Finding $finding): void
    {
        $issue = $finding->erm_issue_id === null ? null : Issue::query()->find($finding->erm_issue_id);

        if ($issue === null || $issue->issue_status === 'CLOSED') {
            return;
        }

        $issue->forceFill([
            'issue_status' => 'CLOSED',
            'actual_close_date' => now()->toDateString(),
            'closure_justification' => sprintf(
                'Closed in the business continuity register as %s.',
                str_replace('_', ' ', (string) $finding->status),
            ),
        ])->save();
    }

    /**
     * A verified corrective action closes its finding's issue when nothing is
     * left open against it.
     */
    public function syncActionClosure(CorrectiveAction $action): void
    {
        $finding = $action->finding;

        if ($finding === null) {
            return;
        }

        $stillOpen = $finding->correctiveActions()
            ->whereNotIn('status', ['verified', 'accepted_risk'])
            ->exists();

        if (! $stillOpen) {
            $this->syncClosure($finding);
        }
    }

    /**
     * The reverse direction: an issue closed in the ERM register closes its
     * finding.
     *
     * Called from the nightly sweep rather than an observer on `Issue`,
     * deliberately — and for the reason TPRM records. An observer on a core
     * model would make every issue save in the product pay for a BCMS lookup,
     * in a deployment that may not have BCMS switched on at all.
     *
     * @return Collection<int, Finding>
     */
    public function pullClosedIssues(?int $organizationId = null): Collection
    {
        $closed = Finding::query()
            ->withoutGlobalScopes()
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->whereIn('status', ['open', 'in_progress'])
            ->whereNotNull('erm_issue_id')
            ->whereIn('erm_issue_id', Issue::query()->withoutGlobalScopes()->where('issue_status', 'CLOSED')->select('id'))
            ->get();

        foreach ($closed as $finding) {
            $finding->forceFill([
                'status' => 'closed',
                'closed_at' => now(),
            ])->save();
        }

        return $closed;
    }

    private function title(Finding $finding): string
    {
        $prefix = match ($finding->classification) {
            FindingClassification::Nonconformity => 'BCMS nonconformity',
            FindingClassification::Improvement => 'BCMS improvement',
            default => 'BCMS observation',
        };

        return $prefix.' — '.Str::limit($finding->description, 180);
    }

    /**
     * A nonconformity is at least High: it is a stated requirement the
     * institution is not meeting. Severity refines it upward, never down.
     */
    private function priorityFor(Finding $finding): string
    {
        $fromSeverity = match ($finding->severity) {
            'critical' => 'critical',
            'high' => 'high',
            'medium' => 'medium',
            default => 'low',
        };

        if ($finding->classification !== FindingClassification::Nonconformity) {
            return $fromSeverity;
        }

        return in_array($fromSeverity, ['critical', 'high'], true) ? $fromSeverity : 'high';
    }
}
