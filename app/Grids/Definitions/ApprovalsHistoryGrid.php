<?php

namespace App\Grids\Definitions;

use App\Grids\Column;
use App\Grids\Filter;
use App\Grids\GridDefinition;
use App\Models\ApprovalRequest;
use Illuminate\Database\Eloquent\Builder;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The approval history register (WP-09 migration of
 * resources/views/risk/approvals/history.blade.php).
 *
 * The base query is ApprovalService::getHistoryPaginated's, verbatim: history
 * means the decided requests — approved, rejected, superseded — and never the
 * pending queue, which has its own screen.
 *
 * entity_type holds the canonical snake_case morph alias since
 * 2026_08_10_110003_normalise_entity_type_to_morph_aliases, so the filter's
 * options are aliases. The old view built its option list from the DISTINCT
 * values present in the tenant's own rows; a fixed list of the types the
 * application actually raises approvals for is used instead, so the dropdown
 * does not change shape depending on what happens to be in the table.
 *
 * There are no row actions: an entry in the history is a decided record, and
 * the entity it refers to is polymorphic — there is no single show route to
 * point at.
 */
class ApprovalsHistoryGrid extends GridDefinition
{
    /**
     * The entity types this application raises approval requests for
     * (ApprovalService::requestApproval callers), as morph aliases.
     */
    private const ENTITY_TYPES = [
        'risk' => 'Risk',
        'risk_assessment' => 'Risk Assessment',
        'treatment_plan' => 'Treatment Plan',
        'control' => 'Control',
        'control_test' => 'Control Test',
        'loss_event' => 'Loss Event',
        'issue' => 'Issue',
        'measure_threshold' => 'Measure Threshold',
    ];

    public function name(): string
    {
        return 'approvals_history';
    }

    public function permission(): string
    {
        return 'approval.view';
    }

    public function query(): Builder
    {
        return ApprovalRequest::query()
            ->with(['requestedBy', 'reviewedBy'])
            ->where('organization_id', TenantContext::organizationId())
            ->whereIn('status', ['approved', 'rejected', 'superseded']);
    }

    public function columns(): array
    {
        return [
            Column::make('entity', 'Entity')
                ->using(fn (ApprovalRequest $a) => $a->entity_type.' #'.$a->entity_id),

            Column::make('action', 'Action')
                ->sortable()->searchable()
                ->using(fn (ApprovalRequest $a) => ucfirst((string) $a->action)),

            Column::make('status', 'Status')->sortable()->badge([
                'approved' => 'bg-green-100 text-green-700',
                'rejected' => 'bg-red-100 text-red-700',
                'superseded' => 'bg-gray-100 text-gray-600',
                '*' => 'bg-gray-100 text-gray-700',
            ]),

            Column::make('requested_by', 'Requested By')
                ->using(fn (ApprovalRequest $a) => $a->requestedBy?->name ?? 'Unknown'),

            Column::make('reviewed_by', 'Reviewed By')
                ->using(fn (ApprovalRequest $a) => $a->reviewedBy?->name ?? 'N/A'),

            Column::make('reviewed_at', 'Reviewed At')->sortable()->datetime('d M Y H:i'),

            Column::make('requested_at', 'Requested At')->sortable()->hiddenByDefault()->datetime('d M Y H:i'),

            // The old cell showed the rejection reason when there was one and
            // fell back to the free-text comments.
            Column::make('notes', 'Notes')
                ->hiddenByDefault()
                ->using(function (ApprovalRequest $a) {
                    $note = $a->status === 'rejected' && $a->rejection_reason
                        ? $a->rejection_reason
                        : $a->comments;

                    return $note ? (string) str($note)->limit(40) : '—';
                }),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('entity_type', 'All Entity Types')
                ->options(self::ENTITY_TYPES),
        ];
    }

    public function defaultSort(): array
    {
        return ['reviewed_at', 'desc'];
    }

    public function emptyMessage(): string
    {
        return 'No approval history available yet.';
    }

    public function emptyIcon(): string
    {
        return 'inbox';
    }
}
