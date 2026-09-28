<?php

namespace App\Services\Bcms\Findings;

use App\Enums\Bcms\FindingClassification;
use App\Enums\Bcms\FindingSource;
use App\Enums\Bcms\IsoClauseRef;
use App\Models\Bcms\Aar;
use App\Models\Bcms\CallTreeTest;
use App\Models\Bcms\Finding;
use App\Models\Bcms\Incident;
use App\Models\Bcms\Plan;
use App\Models\Bcms\Process;
use App\Services\Bcms\Integration\ErmBridge;
use App\Services\ReferenceCodeService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Raising and closing BCMS findings.
 *
 * TRACK A IS THE SOLE OWNER OF THIS SERVICE AND FOUR TRACKS ARE CONSUMERS
 * (Orchestration §5): the exercise AAR (P9), the broken-branch screen (P6), the
 * post-incident review (P10) and thirdLine. **Producers only call `raise()`.**
 * Nothing outside this service writes `bcms_findings`, and nothing at all except
 * the exercise engine writes `carried_to_occurrence_id`.
 *
 * RAISING IS IDEMPOTENT ON ITS SOURCE. A call-tree test re-scored, an AAR
 * regenerated, a maturity run repeated — each must not raise the same gap twice.
 * Without this the register fills with duplicates on the second run and the
 * overdue count doubles for a reason nobody can see. TPRM's `FindingService`
 * learned this the same way and the rule is copied deliberately.
 *
 * A NONCONFORMITY CANNOT EXIST WITHOUT A CLAUSE. ISO 22301 10.1 defines a
 * nonconformity as a failure to meet **a requirement**; one recorded without
 * naming the requirement is an opinion. `raise()` refuses it, and the refusal is
 * an exception rather than a silent downgrade to `observation` — quietly
 * reclassifying somebody's finding is worse than making them say which clause.
 *
 * A NONCONFORMITY IS NEVER CLOSED WITHOUT A VERIFIED CORRECTIVE ACTION. Clause
 * 10.1 requires the action's effectiveness to be reviewed; a finding that can be
 * closed by the person who raised it, with nothing done, is a finding that gets
 * closed.
 */
class FindingService
{
    public function __construct(private ErmBridge $erm) {}

    /**
     * Raise a finding, or return the one already raised for this source.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function raise(
        FindingSource $source,
        FindingClassification $classification,
        string $description,
        ?Model $sourceRecord = null,
        array $attributes = [],
        ?int $userId = null,
    ): Finding {
        $clauseRef = $this->resolveClauseRef($classification, $source, $attributes);

        $existing = $this->existingFor($source, $sourceRecord, $description);

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($source, $classification, $description, $sourceRecord, $attributes, $userId, $clauseRef) {
            $data = array_merge([
                'reference' => $this->nextReference(),
                'source' => $source->value,
                'classification' => $classification->value,
                'description' => $description,
                'iso_clause_ref' => $clauseRef,
                'status' => 'open',
                'raised_by' => $userId ?? auth()->id(),
                'raised_at' => now(),
                'created_by' => $userId ?? auth()->id(),
            ], $this->sourceLink($source, $sourceRecord), $attributes);

            // ADR 0017 Amendment 2: `Finding::orgScopeColumn()` now points at
            // this column, and `grep -rn affected_business_unit_id app` showed
            // exactly one writer before this — `CallTreeRemediation.php:193`,
            // which sets it explicitly and is left alone below. Every other
            // producer left it null, and a null unit is visible to the whole
            // tenant by ADR 0006's null arm — deliberately, for the group BCP,
            // and accidentally here. Derived only when the caller did not
            // already supply one, and left null when the source genuinely
            // carries no unit (audit, gap analysis, an incident or DR test
            // with neither process nor plan named).
            if (($data['affected_business_unit_id'] ?? null) === null) {
                $data['affected_business_unit_id'] = $this->deriveBusinessUnitId($source, $sourceRecord, $data);
            }

            $finding = Finding::query()->create($data);

            // The ERM bridge is one-way and tolerant: a deployment whose issue
            // register is not configured must still be able to record a
            // finding. BCMS working without the ERM link is a smaller problem
            // than BCMS refusing the finding that triggered it.
            $this->erm->mirrorFinding($finding);

            return $finding;
        });
    }

    /**
     * @param  array<string, mixed>  $data  the finding's about-to-be-created
     *                                      attributes, sourceLink already merged in
     */
    private function deriveBusinessUnitId(FindingSource $source, ?Model $sourceRecord, array $data): ?int
    {
        // A finding named against a specific process or plan — whether
        // picked by hand on the raise-a-finding screen or carried by the
        // source itself (`plan_review`'s sourceLink lands here as
        // `affected_plan_id`) — takes that record's own unit, regardless of
        // which of the eight sources raised it.
        if (! empty($data['affected_process_id'])) {
            return Process::query()->find($data['affected_process_id'])?->business_unit_id;
        }

        if (! empty($data['affected_plan_id'])) {
            return Plan::query()->find($data['affected_plan_id'])?->business_unit_id;
        }

        if ($source === FindingSource::Aar && $sourceRecord instanceof Aar) {
            return $sourceRecord->occurrence?->definition?->business_unit_id;
        }

        if ($source === FindingSource::CallTreeTest && $sourceRecord instanceof CallTreeTest) {
            return $sourceRecord->callTree?->business_unit_id;
        }

        // ADR 0020 gave `Incident` its own `business_unit_id` (Phase 10, the
        // same anchor treatment as `Plan`). A finding raised from a PIR
        // (`source = incident`, `$sourceRecord` the incident itself — see
        // `FindingController::store()`'s `case FindingSource::Incident`)
        // takes that incident's unit. This was previously left to fall
        // through to the null return below, which — combined with
        // `ScopedToOrgHierarchy`'s null-is-visible-to-the-whole-tenant rule —
        // showed every PIR finding's full description to any
        // `bcms.finding.view` holder in the tenant regardless of which
        // branch's incident it came from (ADR 0017 Amendment 2). An incident
        // genuinely declared with no unit (`business_unit_id` is nullable —
        // see `IncidentService::declare()`) is an organisation-wide incident,
        // and its findings stay organisation-wide too, which is correct: not
        // every incident belongs to one division.
        if ($source === FindingSource::Incident && $sourceRecord instanceof Incident) {
            return $sourceRecord->business_unit_id;
        }

        // `dr_test`, `management_review`, `audit` and `gap_analysis`
        // genuinely carry no unit unless one of the attribute checks above
        // already caught it — `DrTest` is organisation-level (no
        // `business_unit_id` column of its own), and a management review,
        // audit or gap-analysis finding is enterprise-wide risk information,
        // not one division's; a null unit is the organisation-level answer
        // ADR 0006 already gives it.
        return null;
    }

    /**
     * Close a finding.
     *
     * @throws InvalidArgumentException when clause 10.1 would not accept it
     */
    public function close(Finding $finding, ?int $userId = null): Finding
    {
        if ($finding->classification === FindingClassification::Nonconformity) {
            $unverified = $finding->correctiveActions()
                ->whereNotIn('status', ['verified', 'accepted_risk'])
                ->count();

            if ($unverified > 0 || $finding->correctiveActions()->count() === 0) {
                throw new InvalidArgumentException(
                    'A nonconformity cannot be closed until every corrective action against it is verified or '
                    .'formally accepted as a risk (ISO 22301 clause 10.1).'
                );
            }
        }

        $finding->update([
            'status' => 'closed',
            'closed_at' => now(),
            'updated_by' => $userId ?? auth()->id(),
        ]);

        $this->erm->syncClosure($finding);

        return $finding->refresh();
    }

    /** Accept the risk rather than correcting it — an attributable decision. */
    public function acceptRisk(Finding $finding, string $rationale, ?int $userId = null): Finding
    {
        $finding->update([
            'status' => 'accepted_risk',
            'root_cause' => $finding->root_cause,
            'closed_at' => now(),
            'updated_by' => $userId ?? auth()->id(),
        ]);

        $finding->correctiveActions()
            ->whereIn('status', ['open', 'in_progress', 'overdue'])
            ->update([
                'status' => 'accepted_risk',
                'acceptance_rationale' => $rationale,
                'accepted_by' => $userId ?? auth()->id(),
                'accepted_at' => now(),
            ]);

        $this->erm->syncClosure($finding);

        return $finding->refresh();
    }

    /* ------------------------------------------------------------------ */
    /*  Internals */
    /* ------------------------------------------------------------------ */

    /**
     * The clause this finding evidences.
     *
     * A nonconformity MUST name one and the caller must supply it — the source
     * default would file every exercise nonconformity under 8.5, which is where
     * it was found rather than what it failed.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function resolveClauseRef(FindingClassification $classification, FindingSource $source, array $attributes): string
    {
        $supplied = $attributes['iso_clause_ref'] ?? null;

        if ($supplied instanceof IsoClauseRef) {
            return $supplied->value;
        }

        if (is_string($supplied) && $supplied !== '') {
            if (IsoClauseRef::tryFrom($supplied) === null) {
                throw new InvalidArgumentException("'{$supplied}' is not a published clause reference. The taxonomy is App\\Enums\\Bcms\\IsoClauseRef and nobody adds to it but the compliance analyst.");
            }

            return $supplied;
        }

        if ($classification === FindingClassification::Nonconformity) {
            throw new InvalidArgumentException(
                'A nonconformity must name the requirement it failed (ISO 22301 clause 10.1). '
                .'Pass an iso_clause_ref.'
            );
        }

        return $source->clauseRef()->value;
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceLink(FindingSource $source, ?Model $record): array
    {
        $key = $source->foreignKey();

        if ($key === null || $record === null) {
            return [];
        }

        return [$key => $record->getKey()];
    }

    /**
     * Has this source already raised this finding?
     *
     * Matched on the source, its record and the description. Not on the
     * description alone — two branches of a call tree can break for the same
     * stated reason and both are real.
     */
    private function existingFor(FindingSource $source, ?Model $record, string $description): ?Finding
    {
        $query = Finding::query()
            ->where('source', $source->value)
            ->where('description', $description);

        $key = $source->foreignKey();

        if ($key !== null) {
            if ($record === null) {
                return null;
            }

            $query->where($key, $record->getKey());
        } elseif ($record !== null) {
            // `audit` and `gap_analysis` have no foreign key. There is nothing
            // to match on beyond source and description, which is why those two
            // callers pass a stable description.
            return $query->first();
        }

        return $query->first();
    }

    /**
     * `BCF-2026-0001`, allocated through the platform's sequence counter rather
     * than by `MAX(reference) + 1` — the counter holds its row `FOR UPDATE`, so
     * two findings raised in the same second cannot claim the same number.
     */
    private function nextReference(): string
    {
        return ReferenceCodeService::generate('bcms_findings', 'reference', 'BCF');
    }
}
