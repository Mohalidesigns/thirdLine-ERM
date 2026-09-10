<?php

namespace App\Services\Bcms;

use App\Enums\Bcms\IsoClauseRef;
use App\Models\Bcms\ClauseRef;
use App\Models\Bcms\ManagementReview;
use App\Models\Bcms\Process;
use App\Models\Bcms\Programme;
use App\Models\Bcms\ProgrammeObligation;
use App\Models\Bcms\ProgrammeScopeItem;
use App\Models\BusinessUnit;
use App\Services\ReferenceCodeService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The programme, its scope, its obligation register and its management reviews
 * — ISO 22301 clauses 4, 6.1 and 9.3.
 *
 * SCOPE IS A SET, NOT A PARAGRAPH. `scope_statement` is what the policy prints;
 * `bcms_programme_scope` is what the BIA, the calendar and the evidence pack
 * query. An exclusion is a **row with a rationale** (clause 4.3 requires the
 * boundary to be justified), because silence is indistinguishable from nobody
 * having considered it.
 *
 * THE OBLIGATION REGISTER IS AN APPLICABILITY DECISION, NOT A LIBRARY.
 * `bcms_clause_refs` ships what exists in the world; this records which of those
 * bind THIS institution, who owns each and what cadence it drives. A bank with
 * no open-banking licence marks those three not-applicable with a reason, and
 * the evidence pack stops demanding quarterly failover evidence it will never
 * have.
 *
 * MANAGEMENT REVIEW INPUTS ARE SNAPSHOTTED AT CAPTURE. A review held in March
 * considered March's CAPA status. Re-deriving it for a reader in December would
 * rewrite what the meeting looked at, which is the one thing the record exists
 * to preserve.
 */
class ProgrammeService
{
    public function __construct(private MaturityService $maturity) {}

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes, ?int $userId = null): Programme
    {
        return Programme::query()->create(array_merge([
            'status' => 'draft',
            'iso_clause_ref' => IsoClauseRef::Iso22301_4_3->value,
            'created_by' => $userId ?? auth()->id(),
        ], $attributes));
    }

    /**
     * Approve the programme.
     *
     * The approver is named and is not the owner, for the reason clause 5.2
     * gives about the policy: a programme signed off by the person who wrote it
     * has had no oversight.
     */
    public function approve(Programme $programme, int $approverId): Programme
    {
        if ($programme->status === 'approved' || $programme->status === 'active') {
            throw new InvalidArgumentException('This programme is already approved.');
        }

        if ($approverId === (int) $programme->owner_id) {
            throw new InvalidArgumentException('A programme must be approved by somebody other than its owner.');
        }

        $programme->update([
            'status' => 'approved',
            'approved_by' => $approverId,
            'approved_at' => now(),
            'updated_by' => $approverId,
        ]);

        return $programme->refresh();
    }

    public function activate(Programme $programme, ?int $userId = null): Programme
    {
        if ($programme->status !== 'approved') {
            throw new InvalidArgumentException('Only an approved programme can be activated.');
        }

        $programme->update(['status' => 'active', 'updated_by' => $userId ?? auth()->id()]);

        return $programme->refresh();
    }

    /* ------------------------------------------------------------------ */
    /*  Scope */
    /* ------------------------------------------------------------------ */

    public function setScope(Programme $programme, Model $scopable, bool $inScope, ?string $rationale = null): ProgrammeScopeItem
    {
        if (! $scopable instanceof BusinessUnit && ! $scopable instanceof Process) {
            throw new InvalidArgumentException('A programme scope item is a business unit or a BCMS process.');
        }

        if (! $inScope && blank($rationale)) {
            throw new InvalidArgumentException(
                'An exclusion from the BCMS scope must be justified (ISO 22301 clause 4.3).'
            );
        }

        return ProgrammeScopeItem::query()->updateOrCreate(
            [
                'programme_id' => $programme->getKey(),
                'scopable_type' => $scopable->getMorphClass(),
                'scopable_id' => $scopable->getKey(),
            ],
            ['in_scope' => $inScope, 'rationale' => $rationale, 'created_by' => auth()->id()]
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Obligations */
    /* ------------------------------------------------------------------ */

    /**
     * Seed the tenant's obligation register from the shipped clause library.
     *
     * IDEMPOTENT, and it never overwrites an applicability decision somebody has
     * already made. A re-run after the library grows adds the new obligations
     * and leaves the twelve a compliance officer has already marked
     * not-applicable exactly as they left them.
     */
    public function seedObligations(Programme $programme): int
    {
        $existing = ProgrammeObligation::query()
            ->where('programme_id', $programme->getKey())
            ->pluck('clause_ref')
            ->all();

        $added = 0;

        // Only the regulator-facing refs. Seeding all 52 would put ISO clause
        // 4.1 in a register whose purpose is "which rules bind us", and a
        // register of everything is a register nobody reads.
        $refs = ClauseRef::query()
            ->whereIn('standard', ['CBN', 'BOFIA 2020 / NDIC', 'NDPA 2023'])
            ->orderBy('sort_order')
            ->get();

        foreach ($refs as $ref) {
            if (in_array($ref->code, $existing, true)) {
                continue;
            }

            ProgrammeObligation::query()->create([
                'programme_id' => $programme->getKey(),
                'clause_ref' => $ref->code,
                // TRUE BY DEFAULT, and that is the safe direction: an
                // obligation wrongly marked applicable produces an evidence
                // request somebody dismisses; one wrongly marked not applicable
                // produces silence until an examination.
                'applies' => true,
                'cadence' => $this->cadenceFor($ref->code),
                'cadence_per_year' => $this->cadencePerYearFor($ref->code),
                'created_by' => auth()->id(),
            ]);

            $added++;
        }

        return $added;
    }

    private function cadenceFor(string $code): ?string
    {
        return match ($code) {
            IsoClauseRef::Cbn_ob_failover->value => 'Quarterly failover exercise',
            IsoClauseRef::Cbn_ob_dr_test->value => 'Disaster recovery test every six months',
            IsoClauseRef::Cbn_rcf_csat->value => 'Annual self-assessment',
            IsoClauseRef::Cbn_rcf_drills->value => 'At least annually, plus industry exercises',
            IsoClauseRef::Cbn_cg_board->value => 'Board reporting at the board cycle',
            default => null,
        };
    }

    private function cadencePerYearFor(string $code): ?int
    {
        // Only where a named rule states the number. Inventing one for the
        // qualitative obligations would put our opinion in a compliance file.
        return match ($code) {
            IsoClauseRef::Cbn_ob_failover->value => 4,
            IsoClauseRef::Cbn_ob_dr_test->value => 2,
            IsoClauseRef::Cbn_rcf_csat->value => 1,
            default => null,
        };
    }

    /* ------------------------------------------------------------------ */
    /*  Management review — clause 9.3 */
    /* ------------------------------------------------------------------ */

    /** @param array<string, mixed> $attributes */
    public function openManagementReview(Programme $programme, string $title, array $attributes = [], ?int $userId = null): ManagementReview
    {
        return ManagementReview::query()->create(array_merge([
            'programme_id' => $programme->getKey(),
            'reference' => ReferenceCodeService::generate('bcms_management_reviews', 'reference', 'BCMR'),
            'title' => $title,
            'held_on' => now()->toDateString(),
            'status' => 'draft',
            'iso_clause_ref' => IsoClauseRef::Iso22301_9_3_results->value,
            'created_by' => $userId ?? auth()->id(),
        ], $attributes));
    }

    /**
     * Capture the clause 9.3 inputs as they stand right now.
     *
     * A SNAPSHOT, and the timestamp beside it is what makes it one. The
     * alternative — a screen that re-queries on every open — shows a reader in
     * December a meeting that considered December's numbers, which is not what
     * happened.
     */
    public function captureReviewInputs(ManagementReview $review): ManagementReview
    {
        $maturity = $this->maturity->latest();

        $review->update([
            'inputs' => [
                'captured_at' => now()->toIso8601String(),
                'maturity' => $maturity === null ? null : [
                    'assessed_at' => $maturity->assessed_at?->toIso8601String(),
                    'overall_score' => $maturity->overall_score,
                    'method_version' => $maturity->method_version,
                ],
                'findings' => [
                    'open' => DB::table('bcms_findings')->whereNull('deleted_at')->where('status', 'open')->count(),
                    'nonconformities_open' => DB::table('bcms_findings')->whereNull('deleted_at')
                        ->where('status', 'open')->where('classification', 'nonconformity')->count(),
                ],
                'corrective_actions' => [
                    'open' => DB::table('bcms_corrective_actions')->whereNull('deleted_at')->whereIn('status', ['open', 'in_progress'])->count(),
                    'overdue' => DB::table('bcms_corrective_actions')->whereNull('deleted_at')->where('status', 'overdue')->count(),
                    'verified' => DB::table('bcms_corrective_actions')->whereNull('deleted_at')->where('status', 'verified')->count(),
                ],
                'exercises' => [
                    'planned' => DB::table('bcms_exercise_occurrences')->whereNull('deleted_at')->whereYear('scheduled_date', now()->year)->count(),
                    'completed' => DB::table('bcms_exercise_occurrences')->whereNull('deleted_at')->whereYear('scheduled_date', now()->year)->where('status', 'completed')->count(),
                    'missed' => DB::table('bcms_exercise_occurrences')->whereNull('deleted_at')->whereYear('scheduled_date', now()->year)->whereIn('status', ['missed', 'cancelled'])->count(),
                ],
                'plans' => [
                    'approved' => DB::table('bcms_plans')->whereNull('deleted_at')->where('status', 'approved')->count(),
                    'review_overdue' => DB::table('bcms_plans')->whereNull('deleted_at')->where('status', 'approved')
                        ->whereNotNull('next_review_date')->whereDate('next_review_date', '<', now()->toDateString())->count(),
                ],
            ],
            'inputs_captured_at' => now(),
        ]);

        return $review->refresh();
    }

    public function approveManagementReview(ManagementReview $review, int $approverId): ManagementReview
    {
        if ($review->inputs_captured_at === null) {
            throw new InvalidArgumentException(
                'A management review cannot be approved before its clause 9.3 inputs have been captured — '
                .'the record has to show what the meeting actually considered.'
            );
        }

        $review->update([
            'status' => 'approved',
            'approved_by' => $approverId,
            'approved_at' => now(),
            'updated_by' => $approverId,
        ]);

        return $review->refresh();
    }
}
