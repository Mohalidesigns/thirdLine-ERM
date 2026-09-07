<?php

namespace App\Grids\Definitions;

use App\Enums\Tprm\EngagementStatus;
use App\Enums\Tprm\RiskTier;
use App\Enums\Tprm\ThirdPartyStatus;
use App\Grids\Column;
use App\Grids\Filter;
use App\Grids\GridDefinition;
use App\Grids\RowAction;
use App\Models\BusinessUnit;
use App\Models\Tprm\Category;
use App\Models\Tprm\ThirdParty;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The Third-Party Register — FR-TPR-07, and the CBN Cyber Framework
 * Appendix II §1.4 artefact (FR-TPR-08).
 *
 * Built on the product's shared grid rather than a hand-rolled list, because
 * FR-TPR-07 asks for exactly what the grid already provides: saved views,
 * faceted filters, a column chooser and XLSX/CSV/PDF export. Re-implementing
 * those would be a second, worse copy of `DataGridView`.
 *
 * THE HARD PART IS THAT THIS LIST IS OF ENTITIES AND ITS FILTERS ARE ABOUT
 * ENGAGEMENTS. Tier, PCI scope, data processing and assessment currency are
 * all engagement-level (TRD §5.1: risk is assessed at the engagement, never at
 * the entity), but a user filtering the register for "Critical vendors" means
 * "vendors with at least one Critical engagement". Every such filter is
 * therefore an EXISTS against `tp_engagements`, not a join — a join would
 * multiply a vendor row by its engagements and make the count on the page
 * wrong.
 *
 * `tier_rank` is a correlated subquery rather than a stored column because the
 * highest tier across a vendor's engagements changes whenever any one of them
 * is retiered, and a denormalised copy would need invalidating from five
 * places. It is a CASE expression so that ordering is by severity rather than
 * alphabetically — where 'critical' sorts before 'high' before 'low', which
 * would put the most dangerous vendors in the middle of the list.
 */
class TprmThirdPartiesGrid extends GridDefinition
{
    public function name(): string
    {
        return 'tprm_third_parties';
    }

    public function permission(): string
    {
        return 'tprm.view';
    }

    public function query(): Builder
    {
        $organizationId = TenantContext::organizationId();

        // ORDER MATTERS HERE. `select()` REPLACES the select list, so calling
        // it after `withCount()` silently discards the count's subquery and
        // every row reports zero engagements — which is exactly what happened,
        // and it looked like a data problem rather than a query-builder one.
        // Base columns first, then the two subquery selects, which append.
        return ThirdParty::query()
            ->select('tp_third_parties.*')
            ->with(['category:id,name', 'relationshipOwner:id,name'])
            ->where('tp_third_parties.organization_id', $organizationId)
            ->withCount(['engagements as engagement_count' => fn (Builder $q) => $q->whereIn(
                'status',
                $this->countedStatuses()
            )])
            ->selectSub($this->tierRankSubquery($organizationId), 'tier_rank');
    }

    public function columns(): array
    {
        return [
            Column::make('legal_name', 'Third party')
                ->sortable()->searchable()
                ->linkTo(fn (ThirdParty $t) => route('tprm.third-parties.show', $t)),

            Column::make('registration_number', 'RC number')
                ->sortable()->searchable()
                ->using(fn (ThirdParty $t) => $t->registration_number ?: '—'),

            Column::make('category.name', 'Category'),

            Column::make('tier_rank', 'Highest tier')
                ->sortable()
                // Rendered from the rank so the label and the sort order can
                // never disagree.
                ->using(fn (ThirdParty $t) => $this->tierFromRank((int) ($t->tier_rank ?? 0))?->label() ?? '—')
                ->rag([
                    'Critical' => 'red',
                    'High' => 'amber',
                    'Moderate' => 'amber',
                    'Low' => 'green',
                    '—' => 'neutral',
                ]),

            Column::make('engagement_count', 'Engagements')
                ->sortable()
                ->count(),

            Column::make('status', 'Status')
                ->sortable()
                ->using(fn (ThirdParty $t) => $t->status?->label() ?? '—')
                ->rag([
                    'Active' => 'green',
                    'Approved supplier' => 'green',
                    'Under review' => 'amber',
                    'Screening' => 'amber',
                    'Prospect' => 'neutral',
                    'Suspended' => 'red',
                    'Blacklisted' => 'red',
                    'Exiting' => 'amber',
                    'Exited' => 'neutral',
                ]),

            Column::make('country_of_incorporation', 'Country')
                ->sortable()
                ->using(fn (ThirdParty $t) => $t->country_of_incorporation ?: '—'),

            Column::make('relationshipOwner.name', 'Relationship owner')
                ->using(fn (ThirdParty $t) => $t->relationshipOwner->name ?? 'Unassigned'),

            Column::make('aggregate_residual', 'Aggregate residual')
                ->sortable()
                ->hiddenByDefault()
                // A vendor with no scored engagement has no residual. An
                // absent score renders as absent, never as zero — an empty
                // register does not have a residual risk of nought.
                ->using(fn (ThirdParty $t) => $t->aggregate_residual === null
                    ? 'Not scored'
                    : number_format((float) $t->aggregate_residual, 1)),

            // FR-TPR-10: the freshness badge is on the register, not buried in
            // a report.
            Column::make('data_confidence', 'Data confidence')
                ->sortable()
                ->using(fn (ThirdParty $t) => $this->confidenceLabel($t->data_confidence))
                ->rag([
                    'Current' => 'green',
                    'Ageing' => 'amber',
                    'Stale' => 'red',
                    'Not assessed' => 'neutral',
                ]),

            Column::make('last_screened_at', 'Last screened')
                ->sortable()
                ->hiddenByDefault()
                ->date(),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('tier', 'All tiers')
                ->options(fn () => collect(RiskTier::cases())
                    ->mapWithKeys(fn (RiskTier $t) => [$t->value => $t->label()])->all())
                ->apply(function (Builder $query, string $value): void {
                    $query->whereHas(
                        'engagements',
                        fn (Builder $q) => $q->where('effective_tier', $value)->whereIn('status', $this->countedStatuses())
                    );
                }),

            Filter::make('category', 'All categories')
                ->column('tp_third_parties.category_id')
                ->options(fn () => Category::query()
                    ->where('organization_id', TenantContext::organizationId())
                    ->where('is_active', true)
                    ->orderBy('sort_order')->pluck('name', 'id')->all()),

            Filter::make('status', 'All statuses')
                ->column('tp_third_parties.status')
                ->options(fn () => collect(ThirdPartyStatus::cases())
                    ->mapWithKeys(fn (ThirdPartyStatus $s) => [$s->value => $s->label()])->all()),

            Filter::make('business_unit', 'All business units')
                ->options(fn () => BusinessUnit::query()
                    ->where('organization_id', TenantContext::organizationId())
                    ->orderBy('name')->pluck('name', 'id')->all())
                ->apply(function (Builder $query, string $value): void {
                    $query->whereHas('engagements', fn (Builder $q) => $q->where('business_unit_id', $value));
                }),

            Filter::make('country', 'All countries')
                ->column('tp_third_parties.country_of_incorporation')
                ->options(fn () => ThirdParty::query()
                    ->where('organization_id', TenantContext::organizationId())
                    ->whereNotNull('country_of_incorporation')
                    ->distinct()->orderBy('country_of_incorporation')
                    ->pluck('country_of_incorporation', 'country_of_incorporation')->all()),

            Filter::make('personal_data', 'Personal data')
                ->options(['yes' => 'Processes personal data', 'no' => 'No personal data'])
                ->apply(function (Builder $query, string $value): void {
                    $query->whereHas('engagements', fn (Builder $q) => $q->where('processes_personal_data', $value === 'yes'));
                }),

            Filter::make('pci', 'PCI scope')
                ->options(['yes' => 'In PCI scope', 'no' => 'Out of PCI scope'])
                ->apply(function (Builder $query, string $value): void {
                    $query->whereHas('engagements', fn (Builder $q) => $q->where('pci_in_scope', $value === 'yes'));
                }),

            Filter::make('criticality', 'Critical function support')
                ->options(['yes' => 'Supports a critical function', 'no' => 'No critical function'])
                ->apply(function (Builder $query, string $value): void {
                    $query->whereHas('engagements', fn (Builder $q) => $q->where('supports_critical_function', $value === 'yes'));
                }),

            // The two operational filters a risk officer actually opens the
            // register to answer.
            Filter::make('overdue_assessment', 'Assessment currency')
                ->options(['overdue' => 'Assessment overdue', 'current' => 'Assessment current'])
                ->apply(function (Builder $query, string $value): void {
                    $overdue = fn (Builder $q) => $q
                        ->whereNotNull('next_assessment_due')
                        ->whereDate('next_assessment_due', '<', now()->toDateString());

                    $value === 'overdue'
                        ? $query->whereHas('engagements', $overdue)
                        : $query->whereDoesntHave('engagements', $overdue);
                }),

            Filter::make('expiring_evidence', 'Evidence')
                ->options([
                    'expired' => 'Has expired evidence',
                    'expiring' => 'Expiring within 90 days',
                ])
                ->apply(function (Builder $query, string $value): void {
                    $query->whereExists(function (QueryBuilder $sub) use ($value) {
                        $sub->selectRaw('1')
                            ->from('tp_documents')
                            ->whereColumn('tp_documents.owner_id', 'tp_third_parties.id')
                            ->where('tp_documents.owner_type', 'third_party')
                            ->where('tp_documents.organization_id', TenantContext::organizationId())
                            ->whereNull('tp_documents.deleted_at')
                            ->where('tp_documents.is_superseded', false)
                            ->whereNotNull('tp_documents.valid_to');

                        $value === 'expired'
                            ? $sub->whereDate('tp_documents.valid_to', '<', now()->toDateString())
                            : $sub->whereBetween('tp_documents.valid_to', [
                                now()->toDateString(),
                                now()->addDays(90)->toDateString(),
                            ]);
                    });
                }),
        ];
    }

    public function rowActions(): array
    {
        return [
            RowAction::make('Open', 'visibility', fn (ThirdParty $t) => route('tprm.third-parties.show', $t)),
            RowAction::make('Edit', 'edit', fn (ThirdParty $t) => route('tprm.third-parties.edit', $t))
                ->can('tprm.edit'),
        ];
    }

    public function defaultSort(): array
    {
        // Most dangerous first. A register that opens alphabetically makes the
        // reader do the prioritising the module exists to do for them.
        return ['tier_rank', 'desc'];
    }

    public function emptyMessage(): string
    {
        return 'No third parties are registered yet. Raise an intake to add the first one.';
    }

    /* ------------------------------------------------------------------ */

    /**
     * The highest effective tier across a vendor's live engagements, as a
     * sortable integer.
     *
     * A CASE expression rather than MAX() on the string, because the tier
     * names sort alphabetically — 'critical' before 'high' before 'low' before
     * 'moderate' — which would scatter severity through the list.
     */
    private function tierRankSubquery(?int $organizationId): QueryBuilder
    {
        return \Illuminate\Support\Facades\DB::table('tp_engagements')
            ->selectRaw(
                'COALESCE(MAX(CASE effective_tier '
                ."WHEN 'critical' THEN 4 WHEN 'high' THEN 3 WHEN 'moderate' THEN 2 WHEN 'low' THEN 1 "
                .'ELSE 0 END), 0)'
            )
            ->whereColumn('tp_engagements.third_party_id', 'tp_third_parties.id')
            ->where('tp_engagements.organization_id', $organizationId)
            ->whereNull('tp_engagements.deleted_at')
            ->whereIn('tp_engagements.status', $this->countedStatuses());
    }

    private function tierFromRank(int $rank): ?RiskTier
    {
        return match ($rank) {
            4 => RiskTier::Critical,
            3 => RiskTier::High,
            2 => RiskTier::Moderate,
            1 => RiskTier::Low,
            default => null,
        };
    }

    /**
     * The freshness badge of TRD §7.6 — Current / Ageing / Stale.
     *
     * A null confidence is "Not assessed", never "Stale": a vendor nobody has
     * scored yet has no data confidence, and calling that stale asserts a
     * measurement that was never taken.
     */
    private function confidenceLabel(mixed $confidence): string
    {
        if ($confidence === null) {
            return 'Not assessed';
        }

        $value = (float) $confidence;
        $threshold = (float) config('tprm.scoring.data_confidence.assertion_threshold');

        return match (true) {
            $value >= 0.8 => 'Current',
            $value >= $threshold => 'Ageing',
            default => 'Stale',
        };
    }

    /**
     * The engagements a vendor's register row speaks for.
     *
     * NOT `isLive()`. That set excludes everything still in intake, due
     * diligence, tiering, assessment, contracting and onboarding — so a
     * register of vendors mid-onboarding showed "—" in the Highest tier column
     * while the counter above it said four were Critical. The column and the
     * counter contradicted each other on the same screen, and the column was
     * the wrong one: a Critical vendor being onboarded is precisely the row a
     * risk officer opens the register to find.
     *
     * Terminated and archived engagements are excluded, because a vendor whose
     * only engagement ended last year is not a current Critical exposure.
     *
     * @return list<string>
     */
    private function countedStatuses(): array
    {
        return array_values(array_filter(
            EngagementStatus::values(),
            fn (string $status) => ! EngagementStatus::from($status)->isTerminal()
        ));
    }
}
