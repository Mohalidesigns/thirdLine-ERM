<?php

namespace App\Grids\Definitions;

use App\Grids\Column;
use App\Grids\Filter;
use App\Grids\GridDefinition;
use App\Grids\RowAction;
use App\Models\Tprm\Contract;
use Illuminate\Database\Eloquent\Builder;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The contract register — TRD §8.6.
 *
 * THE NOTICE COLUMN IS THE ONE THAT MATTERS, and it is why this grid is not
 * just a list of expiry dates. A contract expiring in ninety days with a
 * hundred-and-twenty-day notice period has already renewed, and a register
 * sorted by expiry would show it as comfortably distant on the day the
 * decision was lost. The default sort is by notice deadline for the same
 * reason.
 */
class TprmContractsGrid extends GridDefinition
{
    public function name(): string
    {
        return 'tprm_contracts';
    }

    public function permission(): string
    {
        return 'tprm.contract.view';
    }

    public function query(): Builder
    {
        return Contract::query()
            ->with([
                'engagement:id,uuid,reference,name,third_party_id',
                'engagement.thirdParty:id,legal_name',
            ])
            ->where('tp_contracts.organization_id', TenantContext::organizationId());
    }

    public function columns(): array
    {
        return [
            Column::make('reference', 'Reference')
                ->sortable()->searchable()
                ->linkTo(fn (Contract $c) => route('tprm.contracts.show', $c)),

            Column::make('title', 'Title')->sortable()->searchable(),

            Column::make('engagement.thirdParty.legal_name', 'Third party')
                ->searchable('tp_third_parties.legal_name')
                ->using(fn (Contract $c) => $c->engagement->thirdParty->legal_name ?? '—'),

            Column::make('contract_type', 'Type')
                ->sortable()
                ->using(fn (Contract $c) => match ($c->contract_type) {
                    'msa' => 'Master agreement',
                    'sow' => 'Statement of work',
                    'amendment' => 'Amendment',
                    'nda' => 'NDA',
                    'dpa' => 'DPA',
                    'order_form' => 'Order form',
                    'sla_schedule' => 'SLA schedule',
                    default => 'Other',
                }),

            Column::make('status', 'Status')
                ->sortable()
                ->using(fn (Contract $c) => ucwords(str_replace('_', ' ', (string) $c->status)))
                ->rag([
                    'Executed' => 'green',
                    'Draft' => 'neutral',
                    'In Negotiation' => 'amber',
                    'Expired' => 'red',
                    'Terminated' => 'neutral',
                ]),

            // Two columns rather than one: the RAG chip needs a fixed
            // vocabulary and the reader needs the date they act on.
            Column::make('notice_state', 'Notice')
                ->using(function (Contract $c) {
                    $days = $c->daysUntilNotice();

                    return match (true) {
                        $days === null => 'No notice period recorded',
                        $days < 0 && $c->renewsAutomatically() => 'Window missed — renewed',
                        $days < 0 => 'Window closed',
                        $days <= 30 => 'Serve within 30 days',
                        $days <= 90 => 'Serve within 90 days',
                        default => 'Not yet due',
                    };
                })
                ->rag([
                    'Window missed — renewed' => 'red',
                    'Serve within 30 days' => 'red',
                    'Serve within 90 days' => 'amber',
                    'Not yet due' => 'green',
                    'Window closed' => 'neutral',
                    // Not a failure, but not a pass either: a contract whose
                    // notice period nobody recorded cannot be alerted on, and
                    // the register says so rather than implying safety.
                    'No notice period recorded' => 'neutral',
                ]),

            Column::make('notice_deadline', 'Serve notice by')
                ->using(fn (Contract $c) => $c->noticeDeadline()?->format('d M Y') ?? '—'),

            Column::make('expiry_date', 'Expires')
                ->sortable()->date()
                ->using(fn (Contract $c) => $c->expiry_date?->format('d M Y') ?? 'No end date'),

            Column::make('blocking_gaps_count', 'Blocking gaps')
                ->sortable()
                ->using(fn (Contract $c) => $c->clause_analysis_status === 'not_started'
                    // "Not analysed" and "no gaps" must not look alike: a
                    // contract nobody has read has unknown gaps, not none.
                    ? 'Not analysed'
                    : ($c->blocking_gaps_count > 0 ? (string) $c->blocking_gaps_count : 'None'))
                ->rag(['Not analysed' => 'amber', 'None' => 'green']),

            Column::make('value_minor', 'Value')->sortable()->money()->hiddenByDefault(),

            Column::make('effective_date', 'Effective')->sortable()->date()->hiddenByDefault(),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('status', 'All statuses')
                ->column('tp_contracts.status')
                ->options([
                    Contract::STATUS_DRAFT => 'Draft',
                    Contract::STATUS_IN_NEGOTIATION => 'In negotiation',
                    Contract::STATUS_EXECUTED => 'Executed',
                    Contract::STATUS_EXPIRED => 'Expired',
                    Contract::STATUS_TERMINATED => 'Terminated',
                ]),

            Filter::make('type', 'All types')
                ->column('tp_contracts.contract_type')
                ->options(array_combine(Contract::TYPES, array_map(
                    fn (string $type) => ucwords(str_replace('_', ' ', $type)),
                    Contract::TYPES
                ))),

            Filter::make('notice', 'Notice period')
                ->options([
                    'due_30' => 'Serve notice within 30 days',
                    'due_90' => 'Serve notice within 90 days',
                    'missed' => 'Notice window missed',
                    'unrecorded' => 'No notice period recorded',
                ])
                ->apply(function (Builder $query, string $value): void {
                    /** @var Builder<Contract> $query */
                    match ($value) {
                        'due_30' => $query->inForce()->noticeDueWithin(30),
                        'due_90' => $query->inForce()->noticeDueWithin(90),
                        'missed' => $query->inForce()
                            ->whereIn('renewal_type', ['auto', 'evergreen'])
                            ->whereNotNull('expiry_date')
                            ->whereNotNull('notice_period_days_entity')
                            ->whereRaw(Contract::noticeDateExpression().' < ?', [now()->toDateString()]),
                        default => $query->whereNull('notice_period_days_entity'),
                    };
                }),

            Filter::make('gaps', 'Clause analysis')
                ->options([
                    'blocking' => 'Has blocking gaps',
                    'clear' => 'No blocking gaps',
                    'not_started' => 'Not yet analysed',
                ])
                ->apply(function (Builder $query, string $value): void {
                    match ($value) {
                        'blocking' => $query->where('blocking_gaps_count', '>', 0),
                        'clear' => $query->where('blocking_gaps_count', 0)
                            ->where('clause_analysis_status', '!=', 'not_started'),
                        default => $query->where('clause_analysis_status', 'not_started'),
                    };
                }),
        ];
    }

    public function rowActions(): array
    {
        return [
            RowAction::make('Open', 'visibility', fn (Contract $c) => route('tprm.contracts.show', $c)),
        ];
    }

    /**
     * By notice deadline, soonest first — the only ordering this register has
     * a reason to open in. Contracts with no notice period sort last, because
     * nothing about them is imminent by definition.
     */
    public function defaultSort(): array
    {
        return ['expiry_date', 'asc'];
    }

    public function emptyMessage(): string
    {
        return 'No contracts recorded yet. A contract is recorded against an engagement.';
    }
}
