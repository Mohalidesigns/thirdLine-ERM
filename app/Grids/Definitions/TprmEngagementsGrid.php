<?php

namespace App\Grids\Definitions;

use App\Enums\Tprm\EngagementStatus;
use App\Enums\Tprm\EngagementType;
use App\Enums\Tprm\RiskBand;
use App\Enums\Tprm\RiskTier;
use App\Grids\Column;
use App\Grids\Filter;
use App\Grids\GridDefinition;
use App\Grids\RowAction;
use App\Models\BusinessUnit;
use App\Models\Tprm\Engagement;
use Illuminate\Database\Eloquent\Builder;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The Engagement Register — "the real working list" in TRD §11's words.
 *
 * The third-party register is the entity view and the regulatory artefact; this
 * is the one a risk officer works from, because risk is assessed here (TRD
 * §5.1) and every operational question — what is overdue, what is untiered,
 * whose contract is expiring — is an engagement question.
 *
 * `residual_score` and `effective_tier` are read straight off the row rather
 * than derived, and that is deliberate: TRD §8.10 requires them stored so a
 * list of five thousand does not pay for the derivation, and §7.9 requires
 * that the figure shown is the one the score run recorded rather than a fresh
 * recomputation that might disagree with the board pack.
 */
class TprmEngagementsGrid extends GridDefinition
{
    public function name(): string
    {
        return 'tprm_engagements';
    }

    public function permission(): string
    {
        return 'tprm.view';
    }

    public function query(): Builder
    {
        return Engagement::query()
            // Eager-loaded because every one of these is rendered in a column.
            // Without it the list is four queries per row — the N+1 the phase
            // acceptance requires a query-count assertion against.
            ->with([
                'thirdParty:id,legal_name,slug,uuid',
                'businessUnit:id,name',
                'relationshipOwner:id,name',
            ])
            ->where('tp_engagements.organization_id', TenantContext::organizationId());
    }

    public function columns(): array
    {
        return [
            Column::make('reference', 'Reference')
                ->sortable()->searchable()
                ->linkTo(fn (Engagement $e) => route('tprm.engagements.show', $e)),

            Column::make('name', 'Engagement')
                ->sortable()->searchable(),

            Column::make('thirdParty.legal_name', 'Third party')
                ->searchable('tp_third_parties.legal_name'),

            Column::make('effective_tier', 'Tier')
                ->sortable()
                ->using(fn (Engagement $e) => $e->effective_tier?->label() ?? 'Not tiered')
                ->rag([
                    'Critical' => 'red',
                    'High' => 'amber',
                    'Moderate' => 'amber',
                    'Low' => 'green',
                    'Not tiered' => 'neutral',
                ]),

            Column::make('residual_score', 'Residual')
                ->sortable()
                // Phase 5 computes this. Until then it is genuinely absent,
                // and an absent score prints as absent — never as 0.0, which
                // would read as "no residual risk".
                ->using(fn (Engagement $e) => $e->residual_score === null
                    ? 'Not yet scored'
                    : number_format((float) $e->residual_score, 1)),

            Column::make('residual_band', 'Band')
                ->sortable()
                ->hiddenByDefault()
                ->using(fn (Engagement $e) => $e->residual_band?->label() ?? '—')
                ->rag(['Critical' => 'red', 'High' => 'amber', 'Moderate' => 'amber', 'Low' => 'green', '—' => 'neutral']),

            Column::make('status', 'Status')
                ->sortable()
                ->using(fn (Engagement $e) => $e->status->label())
                ->rag([
                    'Active' => 'green',
                    'Monitoring exception' => 'red',
                    'Terminated' => 'neutral',
                    'Archived' => 'neutral',
                    'Draft' => 'neutral',
                ]),

            Column::make('businessUnit.name', 'Business unit'),

            Column::make('relationshipOwner.name', 'Owner')
                ->using(fn (Engagement $e) => $e->relationshipOwner->name ?? 'Unassigned'),

            Column::make('next_assessment_due', 'Assessment due')
                ->sortable()
                ->date()
                ->using(fn (Engagement $e) => $e->next_assessment_due?->format('d M Y') ?? '—'),

            Column::make('annual_spend_minor', 'Annual spend')
                ->sortable()
                ->hiddenByDefault()
                ->money(),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('tier', 'All tiers')
                ->column('tp_engagements.effective_tier')
                ->options(fn () => collect(RiskTier::cases())
                    ->mapWithKeys(fn (RiskTier $t) => [$t->value => $t->label()])->all()),

            Filter::make('band', 'All bands')
                ->column('tp_engagements.residual_band')
                ->options(fn () => collect(RiskBand::cases())
                    ->mapWithKeys(fn (RiskBand $b) => [$b->value => $b->label()])->all()),

            Filter::make('status', 'All statuses')
                ->column('tp_engagements.status')
                ->options(fn () => collect(EngagementStatus::cases())
                    ->mapWithKeys(fn (EngagementStatus $s) => [$s->value => $s->label()])->all()),

            Filter::make('type', 'All types')
                ->column('tp_engagements.engagement_type')
                ->options(fn () => collect(EngagementType::cases())
                    ->mapWithKeys(fn (EngagementType $t) => [$t->value => $t->label()])->all()),

            Filter::make('business_unit', 'All business units')
                ->column('tp_engagements.business_unit_id')
                ->options(fn () => BusinessUnit::query()
                    ->where('organization_id', TenantContext::organizationId())
                    ->orderBy('name')->pluck('name', 'id')->all()),

            Filter::make('assessment', 'Assessment currency')
                ->options(['overdue' => 'Overdue', 'due_soon' => 'Due within 30 days', 'none' => 'Never assessed'])
                ->apply(function (Builder $query, string $value): void {
                    match ($value) {
                        'overdue' => $query->whereNotNull('next_assessment_due')
                            ->whereDate('next_assessment_due', '<', now()->toDateString()),
                        'due_soon' => $query->whereBetween('next_assessment_due', [
                            now()->toDateString(), now()->addDays(30)->toDateString(),
                        ]),
                        default => $query->whereNull('next_assessment_due'),
                    };
                }),

            Filter::make('tiering', 'Tiering')
                ->options(['untiered' => 'Not yet tiered', 'overridden' => 'Tier manually overridden'])
                ->apply(function (Builder $query, string $value): void {
                    // FR-TIER-04: overrides are reported separately to the
                    // risk committee, so they have to be findable.
                    $value === 'untiered'
                        ? $query->whereNull('effective_tier')
                        : $query->whereNotNull('tier_override');
                }),
        ];
    }

    public function rowActions(): array
    {
        return [
            RowAction::make('Open', 'visibility', fn (Engagement $e) => route('tprm.engagements.show', $e)),
        ];
    }

    public function defaultSort(): array
    {
        return ['reference', 'desc'];
    }

    public function emptyMessage(): string
    {
        return 'No engagements yet. An engagement is created by raising an intake.';
    }
}
