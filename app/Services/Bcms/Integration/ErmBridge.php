<?php

namespace App\Services\Bcms\Integration;

use App\Enums\Bcms\FindingClassification;
use App\Enums\Bcms\FindingSeverity;
use App\Models\Bcms\CorrectiveAction;
use App\Models\Bcms\Finding;
use App\Models\Issue;
use App\Models\LossEvent;
use App\Models\User;
use App\Services\Bcms\Findings\FindingService;
use App\Services\LossEvents\LossEventService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

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
     * A3 (code review #3 advisory): whether the MOST RECENT
     * `mirrorRealisedLoss()` call attempted a write and it failed — reset at
     * the top of every call, so a caller that finalises more than once in
     * the same request (a reopen-and-refinalise) never reads a stale
     * failure from an earlier, now-superseded call. Every other outcome
     * (nothing to mirror, the feature switched off) is an intentional skip,
     * not a failure, and leaves this `false`. `PirService::mirrorFailed()`
     * proxies this so `IncidentReviewController::finalise()` can flash a
     * warning rather than leave the officer believing an unmirrored figure
     * reached the loss register.
     */
    private bool $lastMirrorFailed = false;

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
     * `$organizationId` IS REQUIRED, NOT DEFAULTED. A bare call used to mean
     * "every tenant" through `withoutGlobalScopes()` on both `Finding` and
     * `Issue` — a cross-tenant write reachable by omitting an argument. The
     * one real caller, `bcms:sweep-actions`, already loops over organisations
     * one at a time under `TenantContext::set()`; there is no legitimate
     * caller that wants every tenant at once, so that mode has been removed
     * rather than guarded.
     *
     * CLOSURE IS ROUTED THROUGH `FindingService::close()`, not a `forceFill`.
     * That method is where clause 10.1's guard lives — a nonconformity
     * refuses to close while any corrective action against it is unverified
     * — and the person who closed the mirrored ERM issue may hold no BCMS
     * grant at all and has no way to know that guarantee exists. A finding
     * the guard refuses stays open here too: an ERM register that shows
     * CLOSED against a BCMS register that still shows OPEN is a smaller
     * problem than silently closing a nonconformity clause 10.1 says must
     * not close yet, and it is logged so the mismatch does not sit
     * unnoticed. Resolved from the container rather than injected, because
     * `FindingService` already depends on `ErmBridge` — a constructor cycle.
     *
     * @return Collection<int, Finding>
     */
    public function pullClosedIssues(int $organizationId): Collection
    {
        $candidates = Finding::query()
            ->where('organization_id', $organizationId)
            ->whereIn('status', ['open', 'in_progress'])
            ->whereNotNull('erm_issue_id')
            ->whereIn('erm_issue_id', Issue::query()
                ->where('organization_id', $organizationId)
                ->where('issue_status', 'CLOSED')
                ->select('id'))
            ->get();

        $findings = app(FindingService::class);
        $closed = new Collection;

        foreach ($candidates as $finding) {
            try {
                $closed->push($findings->close($finding));
            } catch (InvalidArgumentException $e) {
                Log::warning(
                    'An ERM issue closed but its mirrored BCMS finding could not follow: '
                    .'the clause 10.1 guard refused it.',
                    [
                        'finding_id' => $finding->getKey(),
                        'organization_id' => $organizationId,
                        'reason' => $e->getMessage(),
                    ]
                );
            }
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
     * Mirror a PIR-CONFIRMED realised loss into the ERM loss-event register,
     * THROUGH `LossEventService::report()`/`amend()` (Gate 1 re-gate, code
     * review #2, defect 7).
     *
     * REPLACES A DIRECT `LossEvent` WRITE THIS CLASS USED TO MAKE FOR THIS
     * PURPOSE, which mirrored the DECLARATION-TIME ESTIMATE and wrote
     * `current_status => 'reported'` (lower case, invisible to
     * `LossEventDashboardService::OPEN_STATUSES`, which matches the
     * canonical `'REPORTED'`), no `AuditTrailService` row, no
     * `LossEventCreated`/`LossEventAmountChanged`, and the CBN loss
     * thresholds never evaluated.
     *
     * IDEMPOTENT ON THE SAME COLUMN, NO SCHEMA CHANGE:
     * `bcms_incidents.erm_loss_event_id`. The first confirmed realised loss
     * calls `report()`; a later re-finalisation (after a reopen) finds the
     * existing pointer and calls `amend()`, which itself only dispatches
     * `LossEventAmountChanged` when the kobo figure actually changed — a
     * re-finalise with the SAME confirmed figure amends nothing new.
     *
     * CODE REVIEW #3, PROBE 2: `LossEventService::amend()` runs its ENTIRE
     * `$validated` array through `canonicalAttributes()`, unconditionally —
     * there is no partial-update path. Sending the same fixed payload
     * `report()` used (`basel_event_type => 'UNCLASSIFIED'`, no
     * `root_cause_summary`, no `insurance_recovery`/`recovery_amount`, this
     * class's own guess at `severity`/`title`/`description`) resets every
     * one of those columns to that guess on EVERY re-finalisation — wiping
     * whatever an ERM analyst had since classified, investigated or
     * recovered against the SAME event. `amendPayload()` below hydrates
     * every `canonicalAttributes()` key BACK from the existing row before
     * merging in the one key that is genuinely allowed to change — the
     * confirmed amount — so `amend()`'s unconditional merge is a no-op on
     * everything else, whatever ERM has done to the row since `report()`
     * created it. `risk_register_id` is preserved the same way: `amend()`
     * sets it from `$validated['risk_id'] ?? null` unconditionally too.
     *
     * A2 (code review #3 advisory): a re-finalisation confirming NO
     * realised loss after an earlier positive figure still calls `amend()`
     * down to zero rather than being skipped — the early return below only
     * fires for a zero/negative figure with NOTHING mirrored yet (nothing
     * to correct). ERM represents the correction as the SAME actual-loss
     * event with `gross_loss_amount_kobo = 0`, not as a near-miss:
     * `loss_category`/`is_near_miss` are `report()`-time classifications
     * this narrower amend does not touch, for the same reason it does not
     * touch Basel/CBN category or title — an examiner reading the loss
     * register sees the event that was reported, corrected to zero, not a
     * different kind of record.
     *
     * TOLERANT OF A MISSING TARGET, SAME PATTERN AS EVERY OTHER METHOD ON
     * THIS CLASS. `PirService::finalise()` must not fail — and must not roll
     * its own transaction back — because the ERM side of the mirror could
     * not be written.
     */
    public function mirrorRealisedLoss(\App\Models\Bcms\Incident $incident, int $realisedLossMinor, User $by): ?LossEvent
    {
        $this->lastMirrorFailed = false;

        if (! config('bcms.integration.mirror_findings_to_issues', true)) {
            return null;
        }

        // Clause map §4.3 / ADR 0020 §4: a drill's simulated figure must
        // never reach the loss register (the same "is_exercise never
        // pollutes a live aggregate" rule every other Phase 10 aggregate
        // follows).
        if ($incident->is_exercise) {
            return null;
        }

        $existingLossEventId = $incident->erm_loss_event_id;

        // A zero/negative figure with nothing mirrored yet is a confirmed
        // "no realised loss" — there is nothing to create and nothing to
        // correct. A zero/negative figure correcting an ALREADY-mirrored
        // event (A2) falls through to `amend()` below instead.
        if ($realisedLossMinor <= 0 && $existingLossEventId === null) {
            return null;
        }

        try {
            return DB::transaction(function () use ($incident, $realisedLossMinor, $by, $existingLossEventId) {
                $lossEventService = app(LossEventService::class);

                $existing = $existingLossEventId === null
                    ? null
                    : LossEvent::query()->find($existingLossEventId);

                if ($existing !== null) {
                    return $lossEventService->amend(
                        $existing,
                        $this->amendPayload($existing, $realisedLossMinor),
                        $by->getKey(),
                    );
                }

                $lossEvent = $lossEventService->report(
                    $this->reportPayload($incident, $realisedLossMinor, $by),
                    $by->getKey(),
                );

                $incident->forceFill(['erm_loss_event_id' => $lossEvent->getKey()])->save();

                return $lossEvent;
            });
        } catch (\Throwable $e) {
            $this->lastMirrorFailed = true;

            Log::error('A BCMS incident\'s realised loss could not be mirrored into the ERM loss-event register', [
                'incident_id' => $incident->getKey(),
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** A3: whether the most recent `mirrorRealisedLoss()` call attempted a write and it failed. */
    public function lastMirrorFailed(): bool
    {
        return $this->lastMirrorFailed;
    }

    /**
     * The `$validated` shape for a first mirror, via `LossEventService::
     * report()`. Every `canonicalAttributes()`/passthrough key is this
     * class's own best guess at the time — there is no existing row yet to
     * defer to.
     *
     * @return array<string, mixed>
     */
    private function reportPayload(\App\Models\Bcms\Incident $incident, int $realisedLossMinor, User $by): array
    {
        return [
            'event_title' => Str::limit('BCMS incident: '.$incident->title, 250),
            'event_description' => sprintf(
                'Business continuity incident %s (%s), declared %s. Realised loss confirmed at '
                .'post-incident review.',
                $incident->reference,
                $incident->incident_type ?? 'unclassified',
                $incident->declared_at?->toDateString() ?? 'unknown date',
            ),
            // `LossEventService::kobo()` multiplies by 100 — its `$validated`
            // shape is naira (major units), never minor units, which is the
            // one conversion this call site owns.
            'gross_loss_amount' => $realisedLossMinor / 100,
            'basel_event_type' => 'UNCLASSIFIED',
            'cbn_loss_category' => 'UNCLASSIFIED',
            'event_type' => 'actual_loss',
            'severity' => $this->lossEventSeverityFor($incident),
            'date_of_loss' => ($incident->detected_at ?? $incident->declared_at)?->toDateString(),
            'date_discovered' => ($incident->detected_at ?? $incident->declared_at)?->toDateString(),
            'business_unit_id' => $incident->business_unit_id,
            'currency' => $incident->currency ?? 'NGN',
            'reported_by' => $by->getKey(),
            // ADR 0020 §4 / "It does not write ERM's reportability fields":
            // `is_regulatory_reportable` is deliberately ABSENT here —
            // `LossEventService::isReportable()` derives it from the
            // reported amount against ERM's own configured threshold, which
            // is ERM's judgement to make, not a value BCMS hands it.
        ];
    }

    /**
     * The `$validated` shape for a re-finalisation, via `LossEventService::
     * amend()`. Every key `canonicalAttributes()`/`amend()`'s own inline
     * merge reads is hydrated BACK from `$existing` so the merge changes
     * nothing but the confirmed amount — see `mirrorRealisedLoss()`'s
     * docblock, probe 2. No passthrough field (`date_of_loss`,
     * `business_unit_id`, `currency`, …) is included at all: `amend()`'s
     * `passthroughAttributes()` uses `array_intersect_key`, so an ABSENT key
     * is left untouched, unlike `canonicalAttributes()`, which computes
     * every key it reads regardless of presence.
     *
     * @return array<string, mixed>
     */
    private function amendPayload(LossEvent $existing, int $realisedLossMinor): array
    {
        return [
            'event_title' => $existing->title,
            'event_description' => $existing->description,
            'root_cause_summary' => $existing->initial_root_cause,
            'basel_event_type' => $existing->basel_l1_category,
            'cbn_loss_category' => $existing->cbn_risk_category,
            'event_type' => $existing->loss_category,
            'severity' => $existing->event_severity,
            'insurance_recovery' => $existing->insurance_recovery_kobo / 100,
            'recovery_amount' => $existing->other_recovery_kobo / 100,
            // `amend()`'s own inline merge, not `canonicalAttributes()`:
            // `'risk_register_id' => $validated['risk_id'] ?? null` runs
            // unconditionally too, so an ERM-linked risk is preserved the
            // same way.
            'risk_id' => $existing->risk_register_id,
            // The one key genuinely allowed to change.
            'gross_loss_amount' => max(0, $realisedLossMinor) / 100,
        ];
    }

    /**
     * `loss_events.event_severity` against `LossEvent::SEVERITIES`. BCMS's
     * four sev bands and ERM's five-point scale do not line up one-to-one;
     * this maps conservatively (a BCMS sev is about disruption, not
     * necessarily financial loss) rather than inventing a false precision.
     */
    private function lossEventSeverityFor(\App\Models\Bcms\Incident $incident): string
    {
        // Stored upper case — the same convention `LossEventController`
        // already uses converting a near-miss (`strtoupper($nearMiss->
        // severity ?? 'MEDIUM')`); the model's own `severity()` accessor
        // lower-cases on read, so upper case is what canonical storage means.
        return strtoupper(match ($incident->severity?->value) {
            'sev1' => 'major',
            'sev2' => 'moderate',
            'sev3' => 'minor',
            default => 'insignificant',
        });
    }

    /**
     * A nonconformity is at least High: it is a stated requirement the
     * institution is not meeting. Severity refines it upward, never down.
     */
    private function priorityFor(Finding $finding): string
    {
        $fromSeverity = match ($finding->severity) {
            FindingSeverity::Critical => 'critical',
            FindingSeverity::High => 'high',
            FindingSeverity::Medium => 'medium',
            default => 'low',
        };

        if ($finding->classification !== FindingClassification::Nonconformity) {
            return $fromSeverity;
        }

        return in_array($fromSeverity, ['critical', 'high'], true) ? $fromSeverity : 'high';
    }
}
