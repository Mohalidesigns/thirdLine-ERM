<?php

namespace App\Grids\Definitions;

use App\Grids\Column;
use App\Grids\Filter;
use App\Grids\GridDefinition;
use App\Grids\RowAction;
use App\Models\BusinessUnit;
use App\Models\Sm\Scenario;
use App\Services\Sm\OpEventTypeCatalogue;
use App\Support\Kri\KriWorkingCalendar;
use Illuminate\Database\Eloquent\Builder;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The scenario work queue (spec 00, `docs/ui/scenario-modelling/00-scenario-index.md`).
 *
 * A WORK QUEUE, NOT A FOLDER. Spec 00 §1: "An alphabetical list of scenarios is a
 * filing cabinet; the thing that makes it a queue is that the statuses and the review
 * dates are on the surface." That is why `defaultSort()` is `updated_at desc` and why
 * Review due is TWO columns rather than one — the date and the judgement about the date
 * are different things, and merging them would put a red word where a user expects a
 * number.
 *
 * NO BULK ACTIONS, so no row checkboxes (spec 00 §3.4). `GridPresenter` sets
 * `selection.mode = 'multiple'` only when `bulkActions()` is non-empty, and Phase 1
 * declares none: a bulk lifecycle transition applied across a selection that happens to
 * include a signed-off scenario is precisely the irreversible-by-accident case the
 * module's freeze rule exists to prevent.
 *
 * NO `visibleTo()` NODE SCOPING. `sm_scenarios` has no `entity_id` — §A1.3 refused it —
 * so there is no subtree to narrow to, and `NodeScopeEnforcementTest`'s provider list is
 * (correctly) unchanged. Tenancy is the whole reach.
 *
 * THE JOINS. `op_event_type` and `business_unit.name` are sortable and searchable per
 * spec 00 §3.2, and `GridQuery` sorts and searches on `Column::orderByColumn()` — a plain
 * column-name string, so a relation needs a real join. `select('sm_scenarios.*')` keeps
 * the joined tables' columns out of the hydrated models, and `OrganizationScope` qualifies
 * its predicate (`sm_scenarios.organization_id`) so the join introduces no ambiguity.
 */
class ScenariosGrid extends GridDefinition
{
    /**
     * Spec 00 §3.3's status map — the `01` §2 colour language.
     *
     * `signed_off` uses the CSS CLASS `badge-status-frozen` rather than an inline
     * `bg-[var(--color-primary)] text-white`, because the `@source` globs in
     * resources/css/app.css cover blade, js and jsx under the project root and NOT
     * `app/` — and standard §10 records that "a purged Tailwind class is a silent visual
     * regression" with no test able to catch it. A class defined in the stylesheet cannot
     * be purged away. The one line that defines it is the frontend's to add (spec 00
     * §3.3); [verify at integration].
     *
     * The `'*'` fallback is required: an unmapped status must not fall through to no class
     * at all, and it must not be styled as an error.
     */
    private const STATUS_CLASSES = [
        'draft' => 'bg-gray-100 text-gray-700',
        'in_design' => 'bg-blue-100 text-blue-700',
        'designed' => 'bg-green-100 text-green-700',
        'in_validation' => 'bg-yellow-100 text-yellow-800',
        'validated' => 'bg-purple-100 text-purple-700',
        'signed_off' => 'badge-status-frozen',
        'returned_for_rework' => 'bg-red-100 text-red-700',
        'archived' => 'bg-gray-100 text-gray-700',
        '*' => 'bg-gray-100 text-gray-700',
    ];

    /**
     * Nature and weighting are CLASSIFICATIONS, not health signals, so all values share
     * one neutral grey (spec 00 §3.2): colouring them would compete with Status, and a
     * weighting is not a RAG rating and must not read as one.
     */
    private const NEUTRAL_CLASSES = [
        '*' => 'bg-gray-100 text-gray-700',
    ];

    /**
     * Spec 00 §3.3's review-state map. `using()` runs BEFORE the badge map
     * (`GridDefinition::valueOf()` honours `->using()`, then `cell()` maps on the
     * result), so the closure returns the KEY and the map colours it;
     * `GridPresenter::humanise()` supplies the words.
     */
    private const REVIEW_STATE_CLASSES = [
        'overdue' => 'bg-red-100 text-red-800',
        'due' => 'bg-yellow-100 text-yellow-800',
        'no_date' => 'bg-gray-100 text-gray-600',
        'not_applicable' => 'bg-gray-100 text-gray-600',
        '*' => 'bg-gray-100 text-gray-600',
    ];

    public function name(): string
    {
        return 'scenarios';
    }

    public function permission(): string
    {
        // Spec 00 §3.5: the grid's CSV/XLSX export is gated by `scenario.view`, NOT
        // `scenario.run_export` — routes/web.php puts the whole `risk.grids.{grid}`
        // group behind `can:view-grid,grid`, which resolves here.
        // `scenario.run_export` is reserved for Phase 5's LS-workbook and board-pack
        // documents.
        return 'scenario.view';
    }

    public function query(): Builder
    {
        return Scenario::query()
            ->select('sm_scenarios.*')
            ->leftJoin('risk_taxonomies', 'risk_taxonomies.id', '=', 'sm_scenarios.risk_taxonomy_node_id')
            ->leftJoin('business_units', 'business_units.id', '=', 'sm_scenarios.business_unit_id')
            ->with(['taxonomyNode', 'businessUnit'])
            ->where('sm_scenarios.organization_id', TenantContext::organizationId());
    }

    public function columns(): array
    {
        $today = KriWorkingCalendar::today()->toDateString();

        return [
            // The row's primary link, and mono on screen: the reference is what a board
            // pack cites.
            Column::make('reference', 'Reference')
                ->sortable()->searchable()
                ->linkTo(fn (Scenario $s) => route('scenario.show', $s)),

            Column::make('name', 'Scenario')
                ->sortable()->searchable()
                ->using(fn (Scenario $s) => str((string) $s->name)->limit(60))
                ->linkTo(fn (Scenario $s) => route('scenario.show', $s)),

            // §A1.9: `risk_taxonomies` has NO `code` column — the name already begins
            // "OP5 ", so this cell is the node's name verbatim and NOT a concatenation.
            // "—" when unmapped: a scenario with no OP mapping is a real state, not an
            // error, and a blank cell would read as a rendering bug.
            Column::make('op_event_type', 'OP event type')
                ->sortable('risk_taxonomies.name')
                ->searchable('risk_taxonomies.name')
                ->using(fn (Scenario $s) => $s->taxonomyNode?->name === null
                    ? '—'
                    : str((string) $s->taxonomyNode->name)->limit(45)),

            Column::make('nature', 'Nature')
                ->sortable()
                ->using(fn (Scenario $s) => $s->natureLabel() ?? '—')
                ->badge(self::NEUTRAL_CLASSES),

            // Words come from GridPresenter::humanise(), which turns `in_design` into
            // "In design" — exactly spec 01 §2's canonical chip words, and exactly what
            // config('sm.status_labels') ships. The badge map keys on the RAW value, so
            // `using()` must return it unchanged.
            Column::make('status', 'Status')
                ->sortable()
                ->using(fn (Scenario $s) => $s->status)
                ->badge(self::STATUS_CLASSES),

            // "—" when unset, because §A1.2 makes `weighting` nullable with NO default
            // precisely so an unweighted scenario does not carry a fabricated figure.
            Column::make('weighting', 'Weighting')
                ->sortable()->hiddenByDefault()
                ->using(fn (Scenario $s) => $s->weightingLabel() ?? '—')
                ->badge(self::NEUTRAL_CLASSES),

            // §A1.3: the owning unit is `business_units`, and `entity_id` was refused —
            // so this column says Business unit and must not be labelled "Entity", which
            // would invite a user to look for a legal entity the schema does not store.
            Column::make('business_unit.name', 'Business unit')
                ->sortable('business_units.name')
                ->searchable('business_units.name')
                ->hiddenByDefault()
                ->using(fn (Scenario $s) => $s->businessUnit?->name ?? '—'),

            // `date('d M Y')` renders "09 Nov 2026", and GridTable already prints "—"
            // for a null cell. These are `_on` columns: Lagos calendar DAYS, never
            // instants, and never read with ->isPast() (standard §14).
            Column::make('designed_on', 'Designed')
                ->sortable()->hiddenByDefault()->date('d M Y'),

            Column::make('validated_on', 'Validated')
                ->sortable()->hiddenByDefault()->date('d M Y'),

            // Indexed for exactly this query: `sm_scenarios_org_review_due_idx` (§A1.2).
            // Never "0 days", never "Current".
            Column::make('review_due_on', 'Review due')
                ->sortable()->date('d M Y'),

            // Derived, so NOT sortable: GridQuery sorts a plain column
            // (app/Grids/GridQuery.php) and this is computed. Spec 00 §3.3 gives the
            // four states and their words.
            Column::make('review_state', 'Review status')
                ->using(fn (Scenario $s) => self::reviewState($s, $today))
                ->badge(self::REVIEW_STATE_CLASSES),
        ];
    }

    public function filters(): array
    {
        $organizationId = TenantContext::organizationId();

        return [
            // The eight lifecycle statuses in spec 01 §2's order — which is
            // Scenario::STATUSES' order, so the const is the source and a Phase 2 stage
            // appears here the day it is added.
            Filter::make('status', 'All statuses')
                ->options(array_combine(
                    Scenario::STATUSES,
                    array_map(
                        fn (string $status) => (string) config('sm.status_labels.'.$status, ucfirst(str_replace('_', ' ', $status))),
                        Scenario::STATUSES
                    )
                )),

            // Reads THE TAXONOMY, not a constant (spec 02 §5.4): "or an extension would
            // be invisible". Nodes under the tenant's 'Basel OP event types' parent
            // (§A1.9), labelled with `name` verbatim and ordered by `sort_order`.
            Filter::make('op_event_type', 'All OP event types')
                ->column('risk_taxonomy_node_id')
                ->options(fn () => app(OpEventTypeCatalogue::class)
                    ->nodesFor($organizationId)
                    ->pluck('name', 'id')
                    ->map(fn ($name) => (string) $name)
                    ->all()),

            Filter::make('nature', 'All natures')
                ->options(Scenario::NATURES),

            // The fourth filter is a BUSINESS UNIT, not an entity (spec 00 §3.4) — the
            // same source RisksGrid's business-unit filter uses.
            Filter::make('business_unit', 'All business units')
                ->column('business_unit_id')
                ->options(fn () => BusinessUnit::query()
                    ->where('organization_id', $organizationId)
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->map(fn ($name) => (string) $name)
                    ->all()),
        ];
    }

    public function rowActions(): array
    {
        // ONE action, because the row's Reference and Scenario cells already link to the
        // same place and a second edit action would be a third route into the same
        // workspace (spec 00 §3.6).
        return [
            RowAction::make('Open', 'visibility', fn (Scenario $s) => route('scenario.show', $s))
                ->can('scenario.view'),
        ];
    }

    /**
     * Most recently worked first — the work-queue behaviour (spec 00 §3.2).
     *
     * `review_due_on` ascending is NOT the default because BOTH engines put NULL first on
     * ASC, which would park every undated scenario at the top of the queue ahead of the
     * ones that are actually overdue. Nulls-last needs an expression sort, which
     * `Column::orderByColumn()` (a column-name string) cannot express.
     */
    public function defaultSort(): array
    {
        return ['updated_at', 'desc'];
    }

    public function emptyMessage(): string
    {
        return 'No scenarios match those filters.';
    }

    public function emptyIcon(): string
    {
        // The icon the risk register uses; no new glyph (spec 00 §3.6).
        return 'assessment';
    }

    /**
     * Spec 00 §3.3's review-state keys.
     *
     * DAY TO DAY against a Lagos `Y-m-d` string, never `->isPast()` and never a bare
     * `now()` against a DATE column (standard §14): a `date` cast comes back as midnight
     * at the START of the day, so `isPast()` is true for the whole of the last day — the
     * defect that showed every user the historic "as at" view on the last day of every
     * month.
     *
     * `not_applicable` covers `draft` (no cadence yet) and `archived` (none any more),
     * which is also why `reviewCounts.undated` excludes both (spec 00 §2).
     */
    public static function reviewState(Scenario $scenario, string $today): string
    {
        if (in_array($scenario->status, ['draft', 'archived'], true)) {
            return 'not_applicable';
        }

        $dueOn = $scenario->review_due_on?->toDateString();

        if ($dueOn === null) {
            return 'no_date';
        }

        return strcmp($dueOn, $today) < 0 ? 'overdue' : 'due';
    }
}
