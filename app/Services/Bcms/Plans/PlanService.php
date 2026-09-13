<?php

namespace App\Services\Bcms\Plans;

use App\Enums\Bcms\IsoClauseRef;
use App\Enums\Bcms\PlanType;
use App\Models\Bcms\Plan;
use App\Models\Bcms\PlanSection;
use App\Models\User;
use App\Support\Bcms\PlanTemplates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The plan lifecycle: draft → review → approved → archived.
 *
 * THE SAME DOCUMENT CONTROL AS THE POLICY, DELIBERATELY. `PolicyService` runs
 * this lifecycle for the one plan of type `policy`, and it got there first;
 * this runs it for the other eleven types. They share the rules — an approved
 * version is immutable, it is superseded rather than edited, the approver is
 * not the author — and share the table, and neither delegates to the other,
 * because the policy has clause 5.2 obligations (a board attestation, the
 * "current policy" query) that the others do not and the merged class would be
 * a set of conditionals on `plan_type`. What they must not do is DISAGREE, and
 * `PlanDocumentControlTest` is what stops that.
 *
 * A PLAN PAST ITS REVIEW DATE IS STALE WHETHER OR NOT ANYBODY LOOKED. Staleness
 * is a query, not a flag somebody sets — a status column would need a nightly
 * job to stay honest and would be wrong between runs, on exactly the dashboard
 * that is supposed to say whether the estate is current.
 */
class PlanService
{
    /** @param array<string, mixed> $attributes */
    public function create(
        PlanType $type,
        string $title,
        array $attributes = [],
        ?string $templateKey = null,
        ?int $userId = null,
    ): Plan {
        return DB::transaction(function () use ($type, $title, $attributes, $templateKey, $userId) {
            $plan = Plan::query()->create(array_merge([
                'plan_type' => $type->value,
                'title' => $title,
                'version' => '1.0',
                'status' => 'draft',
                'iso_clause_ref' => $type->clauseRef()->value,
                'created_by' => $userId ?? auth()->id(),
            ], $attributes));

            if ($templateKey !== null) {
                app(PlanAssembler::class)->applyTemplate($plan, $templateKey, $userId);
            }

            return $plan->refresh();
        });
    }

    public function submitForReview(Plan $plan, ?int $userId = null): Plan
    {
        $this->assertEditable($plan);

        if ($plan->sections()->count() === 0) {
            throw new InvalidArgumentException(
                'A plan with no sections cannot go to review. Apply a template or add sections first.'
            );
        }

        $plan->update(['status' => 'review', 'updated_by' => $userId ?? auth()->id()]);

        return $plan->refresh();
    }

    /**
     * Approve the plan, freezing what it renders.
     *
     * TWO THINGS HAPPEN HERE AND BOTH MATTER. The status moves, and the live
     * render is SNAPSHOTTED into `content` — see `PlanAssembler::document()`.
     * Without the snapshot an approved plan would silently rewrite itself every
     * time the BIA moved, and "v1 remains retrievable and printable" would be
     * false in the only sense an auditor means it.
     *
     * THE APPROVER IS NOT THE AUTHOR, the same rule `PolicyService` applies to
     * the policy. A plan approved by the person who wrote it has had no
     * oversight, and a bank's examiner asks who approved it before they ask
     * what it says.
     */
    public function approve(Plan $plan, User $approver, ?string $effectiveFrom = null, ?int $reviewMonths = null): Plan
    {
        if ($plan->status === 'approved') {
            throw new InvalidArgumentException('This plan version is already approved.');
        }

        if ($plan->status === 'archived') {
            throw new InvalidArgumentException('An archived plan version cannot be approved.');
        }

        if ((int) $approver->getKey() === (int) $plan->created_by) {
            throw new InvalidArgumentException(
                'A plan must be approved by somebody other than its author.'
            );
        }

        return DB::transaction(function () use ($plan, $approver, $effectiveFrom, $reviewMonths) {
            $effective = $effectiveFrom === null ? Carbon::now()->startOfDay() : Carbon::parse($effectiveFrom);
            $months = $reviewMonths ?? $plan->review_frequency_months;

            $plan->update([
                'status' => 'approved',
                'approver_id' => $approver->getKey(),
                'approved_at' => now(),
                'effective_from' => $effective->toDateString(),
                'review_frequency_months' => $months,
                // Null months means null date. A plan with no declared review
                // cycle gets no review date rather than one we invented for it.
                'next_review_date' => $months === null
                    ? $plan->next_review_date
                    : $effective->copy()->addMonths((int) $months)->toDateString(),
                'content' => app(PlanAssembler::class)->freeze($plan),
                'updated_by' => $approver->getKey(),
            ]);

            return $plan->refresh();
        });
    }

    /**
     * Create the next version, archiving the one it replaces.
     *
     * The new row starts as a DRAFT COPY — sections, bindings, bodies and
     * overrides — because the realistic act is "amend last year's plan", and a
     * blank page produces a plan that quietly loses the clause somebody added
     * in 2025. `content` is deliberately NOT copied: the draft renders live
     * again, and freezes afresh when it is approved.
     */
    public function supersede(Plan $approved, string $newVersion, ?int $userId = null): Plan
    {
        if ($approved->status !== 'approved') {
            throw new InvalidArgumentException('Only an approved plan version can be superseded.');
        }

        return DB::transaction(function () use ($approved, $newVersion, $userId) {
            $userId ??= auth()->id();

            $next = Plan::query()->create([
                'plan_type' => $approved->plan_type->value,
                'title' => $approved->title,
                'version' => $newVersion,
                'status' => 'draft',
                'supersedes_plan_id' => $approved->getKey(),
                'business_unit_id' => $approved->business_unit_id,
                'site_id' => $approved->site_id,
                'owner_id' => $approved->owner_id,
                'review_frequency_months' => $approved->review_frequency_months,
                'distribution_rule' => $approved->distribution_rule,
                'iso_clause_ref' => $approved->iso_clause_ref,
                'created_by' => $userId,
            ]);

            foreach ($approved->sections()->orderBy('sort_order')->orderBy('id')->get() as $section) {
                PlanSection::query()->create([
                    'organization_id' => $next->organization_id,
                    'plan_id' => $next->getKey(),
                    'section_key' => $section->section_key,
                    'title' => $section->title,
                    'body' => $section->body,
                    'sort_order' => $section->sort_order,
                    'source_binding' => $section->source_binding,
                    'is_overridden' => $section->is_overridden,
                    'ai_generated' => $section->ai_generated,
                    // The new draft has not been verified against live data
                    // yet. Copying v1's stamp would date the new version's
                    // figures to when the OLD one was assembled.
                    'needs_review' => false,
                ]);
            }

            // v1 is archived, NOT deleted. It stays retrievable and printable
            // for ever: an examiner asking what the plan said in 2026 is asking
            // for this row and its frozen `content`.
            $approved->update(['status' => 'archived', 'updated_by' => $userId]);

            return $next->refresh();
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Staleness and review */
    /* ------------------------------------------------------------------ */

    /**
     * The plans this module's library and KRI are about.
     *
     * THE BC POLICY IS EXCLUDED, and it is a `bcms_plans` row on purpose — it is
     * a versioned, approved, supersedable document and that table already models
     * one (ADR 0008). But it has its own screen, its own clause (5.2), its own
     * board attestation and no sections at all, and letting it into the plan
     * library shows a document with "0 sections" beside sixteen real plans while
     * quietly moving a KRI called "plans current". Policy currency is a
     * governance number reported on the programme screen; plan currency is an
     * operational one. Two numbers, not one average.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Plan>
     */
    public function libraryQuery(): Builder
    {
        return Plan::query()->where('plan_type', '!=', PlanType::Policy->value);
    }

    /**
     * Approved plans past their review date.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Plan>
     */
    public function staleQuery(?Carbon $asOf = null): Builder
    {
        $asOf ??= Carbon::now();

        return $this->libraryQuery()
            ->where('status', 'approved')
            ->whereNotNull('next_review_date')
            ->whereDate('next_review_date', '<', $asOf->toDateString());
    }

    /**
     * The "plans current" KRI: the share of approved plans not past review.
     *
     * NULL WHEN THERE ARE NO PLANS, never 100%. An organisation with no plans
     * has not achieved perfect currency, and a dashboard that says it has is
     * one somebody will quote in a board paper (development standard §5).
     *
     * @return array{approved: int, current: int, stale: int, undated: int, percentage: ?float}
     */
    public function currency(?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::now();

        $approved = (int) $this->libraryQuery()->where('status', 'approved')->count();
        $stale = (int) $this->staleQuery($asOf)->count();
        $undated = (int) $this->libraryQuery()
            ->where('status', 'approved')
            ->whereNull('next_review_date')
            ->count();

        return [
            'approved' => $approved,
            'current' => $approved - $stale,
            'stale' => $stale,
            // An approved plan with no review date is not current and not
            // stale; it is undated, and hiding that in either bucket is how a
            // board pack reports a number nobody can defend.
            'undated' => $undated,
            'percentage' => $approved === 0 ? null : round((($approved - $stale) / $approved) * 100, 1),
        ];
    }

    /**
     * Set the review cycle on several plans at once.
     *
     * THE NEW DATE IS DERIVED FROM `effective_from`, NEVER FROM TODAY, and that
     * is the whole design of this action. A "bulk review cycle" button that
     * counted forward from the moment it was pressed would let anybody clear the
     * stale list by pressing it — which is exactly the gaming a staleness KRI
     * invites, and the reason a lot of GRC tools' currency figures are worthless.
     * Shortening a cycle moves a plan's date closer; lengthening it moves it
     * further out, but only as far as the approval it is measured from allows.
     *
     * A plan with no `effective_from` gets a frequency and no date. It has never
     * been approved, so there is nothing to count from, and inventing a date
     * would be inventing the approval.
     *
     * @param  list<int>  $planIds
     * @return array{updated: int, undated: int}
     */
    public function setReviewCycle(array $planIds, ?int $months, ?int $userId = null): array
    {
        if ($planIds === []) {
            return ['updated' => 0, 'undated' => 0];
        }

        $updated = 0;
        $undated = 0;

        foreach ($this->libraryQuery()->whereIn('id', $planIds)->get() as $plan) {
            $from = $plan->effective_from;

            $plan->update([
                'review_frequency_months' => $months,
                'next_review_date' => ($months === null || $from === null)
                    ? null
                    : $from->copy()->addMonths($months)->toDateString(),
                'updated_by' => $userId ?? auth()->id(),
            ]);

            $updated++;

            if ($months !== null && $from === null) {
                $undated++;
            }
        }

        return ['updated' => $updated, 'undated' => $undated];
    }

    /** @return list<array<string, mixed>> */
    public function templates(): array
    {
        return array_map(fn (array $t) => [
            'key' => $t['key'],
            'label' => $t['label'],
            'plan_type' => $t['plan_type'],
            'standard' => $t['standard'],
            'clause' => $t['clause'],
            'summary' => $t['summary'],
            'section_count' => count($t['sections']),
            'bound_section_count' => count(array_filter($t['sections'], fn (array $s) => $s['binding'] !== null)),
            'sections' => array_map(fn (array $s) => [
                'key' => $s['key'],
                'title' => $s['title'],
                'source' => $s['binding']['source'] ?? null,
            ], $t['sections']),
        ], PlanTemplates::all());
    }

    /** The mandatory-record clause a plan of this type satisfies. */
    public function clauseFor(PlanType $type): IsoClauseRef
    {
        return $type->clauseRef();
    }

    private function assertEditable(Plan $plan): void
    {
        if ($plan->isImmutable()) {
            throw new InvalidArgumentException(
                'An approved plan version cannot be edited. Supersede it with a new version instead — the '
                .'supersession chain is the history an auditor reads.'
            );
        }
    }
}
