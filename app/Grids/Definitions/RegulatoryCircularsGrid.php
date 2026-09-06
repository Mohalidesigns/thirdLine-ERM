<?php

namespace App\Grids\Definitions;

use App\Grids\Column;
use App\Grids\Filter;
use App\Grids\GridDefinition;
use App\Grids\RowAction;
use App\Models\RegulatoryCircular;
use Illuminate\Database\Eloquent\Builder;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The regulatory circular register (WP-09 migration of
 * resources/views/risk/regulatory/circulars.blade.php).
 *
 * Schema notes (verified against
 * 2026_03_26_000006_create_regulatory_compliance_tables): the reference column
 * is `circular_ref`, impact is the enum `impact_level`
 * (critical|high|medium|low) and compliance is `compliance_status`
 * (not_assessed|compliant|partially_compliant|non_compliant|not_applicable).
 * `regulator` is a free varchar(50); the option list mirrors the fixed choice
 * offered by the record-circular form.
 *
 * The controller has always read `regulator` and `status` from the query
 * string, but the old table rendered no filter form at all — so both were
 * dead code reachable only by hand-editing the URL. They are declared here
 * with their real values, which is what the controller intended.
 */
class RegulatoryCircularsGrid extends GridDefinition
{
    /** The regulators the record-circular form offers. */
    private const REGULATORS = ['CBN', 'NFIU', 'SEC', 'NDPA', 'NAICOM', 'NDIC'];

    public function name(): string
    {
        return 'circulars';
    }

    public function permission(): string
    {
        return 'regulatory.view';
    }

    public function query(): Builder
    {
        return RegulatoryCircular::query()
            ->with('assignee')
            ->where('organization_id', TenantContext::organizationId());
    }

    public function columns(): array
    {
        return [
            Column::make('regulator', 'Regulator')->sortable()->searchable()->badge([
                'CBN' => 'bg-blue-100 text-blue-700',
                'NFIU' => 'bg-indigo-100 text-indigo-700',
                'SEC' => 'bg-purple-100 text-purple-700',
                'NDPA' => 'bg-teal-100 text-teal-700',
                'NAICOM' => 'bg-cyan-100 text-cyan-700',
                'NDIC' => 'bg-emerald-100 text-emerald-700',
                '*' => 'bg-gray-100 text-gray-700',
            ]),

            Column::make('circular_ref', 'Reference')->sortable()->searchable(),

            Column::make('title', 'Title')
                ->sortable()->searchable()
                ->using(fn (RegulatoryCircular $c) => str($c->title)->limit(50))
                ->linkTo(fn (RegulatoryCircular $c) => route('risk.regulatory.show-circular', $c)),

            // The old cell painted critical red / high orange / medium yellow /
            // low green; the RAG scale has four levels, so high joins critical
            // on red and medium takes amber.
            Column::make('impact_level', 'Impact')->sortable()->rag([
                'critical' => 'red',
                'high' => 'red',
                'medium' => 'amber',
                'low' => 'green',
            ]),

            Column::make('compliance_status', 'Compliance')->sortable()->badge([
                'compliant' => 'bg-green-100 text-green-700',
                'partially_compliant' => 'bg-yellow-100 text-yellow-700',
                'non_compliant' => 'bg-red-100 text-red-700',
                'not_assessed' => 'bg-gray-100 text-gray-600',
                'not_applicable' => 'bg-gray-100 text-gray-600',
                '*' => 'bg-gray-100 text-gray-600',
            ]),

            Column::make('date_issued', 'Date Issued')->sortable()->date(),

            Column::make('assignee.name', 'Assigned To')->hiddenByDefault(),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('regulator', 'All Regulators')
                ->options(array_combine(self::REGULATORS, self::REGULATORS)),

            Filter::make('status', 'All Compliance Statuses')
                ->column('compliance_status')
                ->options([
                    'not_assessed' => 'Not Assessed',
                    'compliant' => 'Compliant',
                    'partially_compliant' => 'Partially Compliant',
                    'non_compliant' => 'Non-Compliant',
                    'not_applicable' => 'Not Applicable',
                ]),
        ];
    }

    public function rowActions(): array
    {
        return [
            RowAction::make('View', 'visibility', fn (RegulatoryCircular $c) => route('risk.regulatory.show-circular', $c)),
        ];
    }

    public function defaultSort(): array
    {
        return ['date_issued', 'desc'];
    }

    public function emptyMessage(): string
    {
        return 'No circulars recorded.';
    }

    public function emptyIcon(): string
    {
        return 'gavel';
    }
}
