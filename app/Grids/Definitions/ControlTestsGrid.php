<?php

namespace App\Grids\Definitions;

use App\Enums\ControlTestStatus;
use App\Grids\Column;
use App\Grids\Filter;
use App\Grids\GridDefinition;
use App\Grids\RowAction;
use App\Models\Control;
use App\Models\ControlTest;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * The control testing register (WP-09 migration of
 * resources/views/risk/controls/tests/index.blade.php).
 *
 * The old table had no actions column and navigated by a whole-row onclick,
 * which is invisible to the keyboard and to screen readers; the grid gives
 * the test code a real link and a ⋮ menu instead.
 *
 * Schema note: the old filter offered the five values of the original
 * `status` ENUM. The column is a plain string now and App\Enums\ControlTestStatus
 * is the authority — it carries a sixth value, `rejected`, which the review
 * workflow writes and which the old filter could therefore never select.
 */
class ControlTestsGrid extends GridDefinition
{
    public function name(): string
    {
        return 'control_tests';
    }

    public function permission(): string
    {
        return 'control_test.view';
    }

    public function query(): Builder
    {
        return ControlTest::query()
            ->with(['control', 'tester', 'reviewer'])
            ->where('organization_id', TenantContext::organizationId());
    }

    public function columns(): array
    {
        return [
            Column::make('test_code', 'Test Code')
                ->sortable()->searchable()
                ->linkTo(fn (ControlTest $t) => route('risk.control-tests.show', $t)),

            Column::make('title', 'Title')
                ->sortable()->searchable()
                ->using(fn (ControlTest $t) => str($t->title)->limit(40)),

            Column::make('control.name', 'Control'),

            Column::make('test_type', 'Type')->sortable()->badge([
                'design_effectiveness' => 'bg-blue-100 text-blue-700',
                'operating_effectiveness' => 'bg-indigo-100 text-indigo-700',
                'walkthrough' => 'bg-purple-100 text-purple-700',
                'substantive' => 'bg-teal-100 text-teal-700',
                '*' => 'bg-gray-100 text-gray-700',
            ]),

            Column::make('tester.name', 'Tester'),

            Column::make('scheduled_date', 'Scheduled')->sortable()->date(),

            Column::make('status', 'Status')->sortable()->badge([
                'scheduled' => 'bg-gray-100 text-gray-700',
                'in_progress' => 'bg-yellow-100 text-yellow-700',
                'pending_review' => 'bg-blue-100 text-blue-700',
                'completed' => 'bg-green-100 text-green-700',
                'rejected' => 'bg-orange-100 text-orange-700',
                'cancelled' => 'bg-red-100 text-red-700',
                '*' => 'bg-gray-100 text-gray-700',
            ]),

            Column::make('result', 'Result')->sortable()->rag([
                'effective' => 'green',
                'partially_effective' => 'amber',
                'ineffective' => 'red',
                'not_tested' => 'neutral',
            ]),

            Column::make('reviewer.name', 'Reviewer')->hiddenByDefault(),

            Column::make('completed_date', 'Completed')
                ->hiddenByDefault()->sortable()->date(),

            Column::make('created_at', 'Logged')
                ->hiddenByDefault()->sortable()->date(),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('status', 'All Statuses')->options(
                collect(ControlTestStatus::cases())
                    ->mapWithKeys(fn (ControlTestStatus $s) => [$s->value => $s->label()])
                    ->all()
            ),

            Filter::make('result', 'All Results')->options([
                'effective' => 'Effective',
                'partially_effective' => 'Partially Effective',
                'ineffective' => 'Ineffective',
                'not_tested' => 'Not Tested',
            ]),

            // Named `control` because that is the query parameter the old
            // controller read, so existing links keep working.
            Filter::make('control', 'All Controls')
                ->column('control_id')
                ->options(fn () => Control::query()
                    ->where('organization_id', TenantContext::organizationId())
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all()),
        ];
    }

    public function rowActions(): array
    {
        return [
            RowAction::make('View', 'visibility', fn (ControlTest $t) => route('risk.control-tests.show', $t)),
            RowAction::make('Edit', 'edit', fn (ControlTest $t) => route('risk.control-tests.edit', $t))
                ->can('control_test.edit'),
        ];
    }

    public function defaultSort(): array
    {
        return ['scheduled_date', 'desc'];
    }

    public function emptyMessage(): string
    {
        return 'No control tests found.';
    }

    public function emptyIcon(): string
    {
        return 'fact_check';
    }
}
