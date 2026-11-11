import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import DataGrid from '@thirdline/ui/Components/DataGrid/DataGrid';
import EmptyState from '@thirdline/ui/Components/EmptyState';
import Icon from '@thirdline/ui/Components/Icon';
import PageHeader from '@thirdline/ui/Components/PageHeader';

/**
 * The scenario work queue (spec 00).
 *
 * A WORK QUEUE, NOT A FOLDER: the statuses and the review dates are on the
 * surface, and the whole list is `DataGrid` over `ScenariosGrid` — standard §8's
 * "any list" primitive, which is also what makes the CSV/XLSX export, saved
 * views, the column chooser and server-side paging exist with no new code here.
 * No bespoke table, no second filter bar, and NO STATUS PILL ROW (spec 00 §3.4):
 * two controls filtering one column is how a page ends up saying "24 scenarios"
 * above a grid showing 3, with the pill and the filter disagreeing about which is
 * active. `useGridState` is therefore not needed — `DataGrid` owns its state.
 *
 * NO DAY COUNT ON THIS SCREEN (spec 00 §3.3). "Overdue by 12 days" would need
 * either a per-row prop the grid's `{id, href, cells, actions}` shape does not
 * carry, or a browser-side date subtraction that breaks spec 01 §4.1's timezone
 * rule. The exact date is one cell to the left in **Review due**, and the
 * workspace header carries the day count where the controller computes it
 * properly. Cutting the number is the standard's instruction, not a compromise:
 * a figure with no safe source is absent.
 */
export default function Index({ grid, totals, reviewCounts, can, createUrl, roadmapNote }) {
    const empty = isRegisterEmpty(grid, totals);
    const view = activeView(grid);

    return (
        <AppLayout title="Scenario Modelling & Analysis">
            <Head title="Scenario Modelling & Analysis" />

            <PageHeader
                title="Scenario Modelling & Analysis"
                breadcrumbs={[{ label: 'Scenario modelling' }]}
                subtitle={subtitle(totals)}
                actions={
                    can?.create && createUrl ? (
                        <Link href={createUrl} className="btn-primary text-sm">
                            <Icon name="add_circle" className="h-4 w-4" />
                            <span className="ml-1.5">New scenario</span>
                        </Link>
                    ) : null
                }
            />

            <div className="space-y-3">
                <ReviewStrip counts={reviewCounts} />

                {roadmapNote && (
                    // Verbatim from the server, so the copy can change per phase
                    // with no front-end release (spec 00 §3.1). The same prop the
                    // workspace sends, so a user reads one consistent statement of
                    // what this release does and does not do.
                    <div
                        role="note"
                        className="rounded-lg border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-gray-700"
                    >
                        {roadmapNote}
                    </div>
                )}

                {view && grid.rows.data.length === 0 && (
                    // A view that empties and then refills is normal, and dropping
                    // it would lose the user's setup — so the view is NOT silently
                    // discarded, only explained (spec 00 §4).
                    <p role="status" className="text-sm text-gray-600">
                        The view '{view.name}' has no matches right now.
                    </p>
                )}

                {empty ? (
                    <div className="card">
                        <EmptyState
                            icon={<Icon name="assessment" className="h-8 w-8 text-gray-400" />}
                            title="No scenarios yet"
                            description="A scenario is one operational-risk event assessed end to end — its causes, the business lines it hits, its exposure, and what you decided about it. Create one, or wait for the scenario library in a later release."
                            actionLabel={can?.create && createUrl ? 'New scenario' : undefined}
                            actionHref={can?.create && createUrl ? createUrl : undefined}
                        />
                    </div>
                ) : (
                    <DataGrid grid={grid} />
                )}
            </div>
        </AppLayout>
    );
}

/**
 * The subtitle. `totals` IGNORES SEARCH AND FILTERS while `grid.rows.meta.total`
 * does not, and the two numbers are allowed to differ without looking like a bug
 * (spec 00 §2) — this describes the register, the grid describes the current view.
 *
 * Every clause is a COUNT OF A SET, so a true `0` is permitted (standard §5) —
 * but a clause whose count is `0` is OMITTED rather than printed as "0 in
 * validation", because the subtitle is a work queue and "0 returned for rework"
 * is noise. `{scenarios}` is always printed, including at `0`.
 *
 * There is deliberately NO RATE here — no "% reviewed", no "scenarios current".
 * A rate needs a denominator, and "which statuses count as in force" reads
 * differently to two people; a rate over nothing is undefined, not zero.
 */
function subtitle(totals) {
    const clauses = [`${totals?.scenarios ?? 0} scenarios`];

    if (totals?.signed_off) {
        clauses.push(`${totals.signed_off} signed off`);
    }

    if (totals?.in_validation) {
        clauses.push(`${totals.in_validation} in validation`);
    }

    if (totals?.returned) {
        clauses.push(`${totals.returned} returned for rework`);
    }

    return clauses.join(' · ');
}

/**
 * The review strip. Rendered ONLY when there is something to say, and never
 * replaced by "All reviews current": the absence of a problem is not a
 * measurement, and a green "all current" line would be the first thing somebody
 * pastes into a committee paper (spec 00 §3.1, the `Bcms/Plans/Index.jsx`
 * "PLANS CURRENT IS BLANK" rule).
 *
 * Both counts are SERVER-COMPUTED in Africa/Lagos against the day boundary
 * (standard §14). The browser never works out whether a date is past.
 */
function ReviewStrip({ counts }) {
    const overdue = counts?.overdue ?? 0;
    const undated = counts?.undated ?? 0;

    if (overdue === 0 && undated === 0) {
        return null;
    }

    return (
        <div role="note" className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
            {overdue > 0 && (
                <span className="flex items-center gap-1.5 text-gray-700">
                    <Icon name="warning" className="h-4 w-4 text-red-700" />
                    <span className="font-semibold text-red-700">{overdue}</span>
                    {overdue === 1 ? 'is past its review date' : 'are past their review date'}
                </span>
            )}
            {overdue > 0 && undated > 0 && <span className="text-gray-400" aria-hidden="true">·</span>}
            {undated > 0 && (
                <span className="text-amber-800">
                    <span className="font-semibold">{undated}</span>{' '}
                    {undated === 1 ? 'has no review date set' : 'have no review date set'}
                </span>
            )}
        </div>
    );
}

/**
 * "Nothing in the register at all" versus "the filters exclude everything" — the
 * two empty states spec 00 §4 keeps apart.
 *
 * The trigger is `totals.scenarios === 0` AND no search, no filter and no view
 * active, exactly as spec 00 §4 states it, and it is detectable from the payload
 * alone: `totals` ignores search and filters, so a register that is genuinely
 * empty is distinguishable from a narrowed one that returned nothing. The second
 * case falls through to the grid's own empty state — "No scenarios match those
 * filters." from `ScenariosGrid::emptyMessage()` — plus the toolbar's Clear
 * control, which is already visible whenever a filter is set.
 */
function isRegisterEmpty(grid, totals) {
    if ((totals?.scenarios ?? 0) !== 0) {
        return false;
    }

    const state = grid?.state;

    if (!state) {
        return true;
    }

    const filtersSet = Object.values(state.filters || {}).some((value) => value !== '' && value !== null && value !== undefined);

    return (state.search || '') === '' && !filtersSet && (state.viewId === null || state.viewId === undefined);
}

/** The saved view in force, if any — for the "view has no matches" line. */
function activeView(grid) {
    const viewId = grid?.state?.viewId;

    if (viewId === null || viewId === undefined) {
        return null;
    }

    return (grid?.views || []).find((candidate) => String(candidate.id) === String(viewId)) || null;
}
