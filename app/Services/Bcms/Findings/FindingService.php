<?php

namespace App\Services\Bcms\Findings;

use App\Enums\Bcms\FindingClassification;
use App\Enums\Bcms\FindingSource;
use App\Enums\Bcms\IsoClauseRef;
use App\Models\Bcms\Finding;
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
            $finding = Finding::query()->create(array_merge([
                'reference' => $this->nextReference(),
                'source' => $source->value,
                'classification' => $classification->value,
                'description' => $description,
                'iso_clause_ref' => $clauseRef,
                'status' => 'open',
                'raised_by' => $userId ?? auth()->id(),
                'raised_at' => now(),
                'created_by' => $userId ?? auth()->id(),
            ], $this->sourceLink($source, $sourceRecord), $attributes));

            // The ERM bridge is one-way and tolerant: a deployment whose issue
            // register is not configured must still be able to record a
            // finding. BCMS working without the ERM link is a smaller problem
            // than BCMS refusing the finding that triggered it.
            $this->erm->mirrorFinding($finding);

            return $finding;
        });
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
