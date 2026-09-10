<?php

namespace App\Grids\Definitions;

use App\Enums\Tprm\ObligationStatus;
use App\Grids\Column;
use App\Grids\Filter;
use App\Grids\GridDefinition;
use App\Grids\RowAction;
use App\Models\Tprm\Obligation;
use Illuminate\Database\Eloquent\Builder;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The obligation register — FR-CTR-06.
 *
 * `obligor` IS A FIRST-CLASS COLUMN AND A DEFAULT FILTER, because the register
 * answers two different questions and mixing them makes it answer neither.
 * "What is the vendor supposed to be doing" is a supplier-management question.
 * "What are we supposed to be doing" is the one a supervisor asks, and the
 * duties an institution is found to have breached are almost always its own.
 */
class TprmObligationsGrid extends GridDefinition
{
    public function name(): string
    {
        return 'tprm_obligations';
    }

    public function permission(): string
    {
        return 'tprm.contract.view';
    }

    public function query(): Builder
    {
        return Obligation::query()
            ->with([
                // `uuid` is not optional in this select: it is the
                // engagement's route key, and omitting it makes every row
                // action build a URL with a null parameter — a page that
                // throws rather than a link that 404s.
                'engagement:id,uuid,reference,name,third_party_id',
                'engagement.thirdParty:id,legal_name',
                'owner:id,name',
            ])
            ->where('tp_obligations.organization_id', TenantContext::organizationId());
    }

    public function columns(): array
    {
        return [
            Column::make('title', 'Obligation')->sortable()->searchable(),

            Column::make('obligor', 'Owed by')
                ->sortable()
                ->using(fn (Obligation $o) => $o->obligor === Obligation::OBLIGOR_ENTITY ? 'Us' : 'The provider'),

            Column::make('engagement.thirdParty.legal_name', 'Third party')
                ->searchable('tp_third_parties.legal_name')
                ->using(fn (Obligation $o) => $o->engagement->thirdParty->legal_name ?? '—'),

            Column::make('owner.name', 'Owner')
                // An unowned duty is a duty nobody does, and it prints as such
                // rather than as a blank cell.
                ->using(fn (Obligation $o) => $o->owner->name ?? 'Unassigned'),

            Column::make('frequency', 'Frequency')
                ->sortable()
                ->using(fn (Obligation $o) => match ($o->frequency) {
                    'one_off' => 'One-off',
                    'on_event' => 'On event',
                    'semi_annual' => 'Every 6 months',
                    default => ucfirst((string) $o->frequency),
                }),

            Column::make('next_due_date', 'Next due')
                ->sortable()->date()
                ->using(fn (Obligation $o) => $o->next_due_date?->format('d M Y')
                    // Correct rather than empty: an on-event duty has no clock
                    // until something happens.
                    ?? ($o->frequency === 'on_event' ? 'When it happens' : '—')),

            Column::make('status', 'Status')
                ->sortable()
                ->using(fn (Obligation $o) => $o->isOverdue() ? 'Overdue' : $o->status->label())
                ->rag([
                    'Overdue' => 'red',
                    'Breached' => 'red',
                    'Due' => 'amber',
                    'Pending' => 'neutral',
                    'Satisfied' => 'green',
                    'Waived' => 'neutral',
                ]),

            Column::make('breach_count', 'Breaches')
                ->sortable()
                ->using(fn (Obligation $o) => $o->breach_count > 0 ? (string) $o->breach_count : '—'),

            Column::make('source_reference', 'Source')
                ->hiddenByDefault()
                ->using(fn (Obligation $o) => $o->citation ?: ($o->source_reference ?: ucfirst((string) $o->source))),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('obligor', 'Owed by anyone')
                ->column('tp_obligations.obligor')
                ->options([
                    Obligation::OBLIGOR_ENTITY => 'Owed by us',
                    Obligation::OBLIGOR_PROVIDER => 'Owed by the provider',
                ]),

            Filter::make('status', 'All statuses')
                ->column('tp_obligations.status')
                ->options(collect(ObligationStatus::cases())
                    ->mapWithKeys(fn (ObligationStatus $s) => [$s->value => $s->label()])->all()),

            Filter::make('timing', 'Timing')
                ->options([
                    'overdue' => 'Overdue',
                    'due_30' => 'Due within 30 days',
                    'unowned' => 'Nobody owns it',
                    'breached' => 'Has been breached',
                ])
                ->apply(function (Builder $query, string $value): void {
                    /** @var Builder<Obligation> $query */
                    match ($value) {
                        'overdue' => $query->overdue(),
                        'due_30' => $query->dueWithin(30),
                        'unowned' => $query->outstanding()->whereNull('owner_id'),
                        default => $query->where('breach_count', '>', 0),
                    };
                }),

            Filter::make('source', 'All sources')
                ->column('tp_obligations.source')
                ->options([
                    'contract' => 'Contract',
                    'regulation' => 'Regulation',
                    'policy' => 'Policy',
                    'assessment' => 'Assurance report',
                    'finding' => 'Finding',
                ]),
        ];
    }

    public function rowActions(): array
    {
        return [
            RowAction::make('Open engagement', 'visibility', fn (Obligation $o) => $o->engagement
                ? route('tprm.engagements.show', $o->engagement)
                : route('tprm.obligations.index')),
        ];
    }

    public function defaultSort(): array
    {
        return ['next_due_date', 'asc'];
    }

    public function emptyMessage(): string
    {
        return 'No obligations yet. They are generated from a contract\'s accepted clauses, and from the '
            .'complementary user entity controls in an assurance report.';
    }
}
