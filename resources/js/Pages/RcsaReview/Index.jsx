import { Head, Link, router, usePage } from "@inertiajs/react";
import AppLayout from "@/Layouts/AppLayout";
import PageHeader from "@thirdline/ui/Components/PageHeader";
import StatusBadge from "@thirdline/ui/Components/StatusBadge";

/**
 * The ORM review queue (§9.2).
 *
 * THE SORT IS EXPLAINED, NOT JUST APPLIED. A work queue whose order nobody can
 * account for is one people re-sort by name and then work top to bottom. The
 * priority column shows the number and the header says how it is made, so a
 * reviewer can see why Treasury is above Retail today.
 */
export default function Index({
    queue = [],
    mine = [],
    filters = {},
    cycles = [],
    weights = {},
    scopeNotice = null,
}) {
    const { flash } = usePage().props;

    const filter = (patch) => {
        router.get(
            route("rcsa.review.index"),
            { ...filters, ...patch },
            { preserveState: true, replace: true },
        );
    };

    return (
        <AppLayout
            header={
                <PageHeader
                    title="ORM review"
                    subtitle="Assessments the business has filed, most pressing first"
                    breadcrumbs={[{ label: "RCSA" }, { label: "ORM review" }]}
                />
            }
        >
            <Head title="ORM review" />

            {scopeNotice && (
                <div className="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    {scopeNotice}
                </div>
            )}

            {flash?.success && (
                <div className="mb-4 rounded-lg border border-green-200 bg-green-50 p-4 text-sm font-medium text-green-800">
                    {flash.success}
                </div>
            )}
            {flash?.error && (
                <div className="mb-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm font-medium text-red-800">
                    {flash.error}
                </div>
            )}

            <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4">
                <Tile label="Waiting" value={queue.length} />
                <Tile label="Yours" value={mine.length} />
                <Tile
                    label="Escalated"
                    value={queue.filter((a) => a.escalated).length}
                />
                <Tile
                    label="Risks above appetite"
                    value={queue.reduce(
                        (n, a) => n + (a.above_appetite_count || 0),
                        0,
                    )}
                />
            </div>

            <div className="card mb-4">
                <div className="flex flex-wrap items-end gap-3 p-4">
                    <label className="text-xs text-gray-600">
                        <span className="mb-1 block uppercase tracking-wider">
                            Cycle
                        </span>
                        <select
                            className="form-select text-sm"
                            value={filters.cycle ?? ""}
                            onChange={(e) =>
                                filter({ cycle: e.target.value || undefined })
                            }
                        >
                            <option value="">All cycles</option>
                            {cycles.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </select>
                    </label>

                    <label className="text-xs text-gray-600">
                        <span className="mb-1 block uppercase tracking-wider">
                            State
                        </span>
                        <select
                            className="form-select text-sm"
                            value={filters.status ?? ""}
                            onChange={(e) =>
                                filter({ status: e.target.value || undefined })
                            }
                        >
                            <option value="">Waiting and in progress</option>
                            <option value="submitted">Not yet picked up</option>
                            <option value="under_review">Being reviewed</option>
                        </select>
                    </label>

                    {(filters.cycle || filters.status) && (
                        <button
                            type="button"
                            className="btn-secondary text-xs"
                            onClick={() =>
                                router.get(route("rcsa.review.index"))
                            }
                        >
                            Clear
                        </button>
                    )}
                </div>
            </div>

            <div className="card">
                <div className="overflow-x-auto">
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th>Business unit</th>
                                <th>Cycle</th>
                                <th>Filed</th>
                                <th className="text-right">Waiting</th>
                                <th className="text-right">Risks</th>
                                <th className="text-right">Above appetite</th>
                                <th className="text-right">Flagged</th>
                                <th>Reviewer</th>
                                <th>Status</th>
                                <th
                                    className="text-right"
                                    title={`${weights.above_appetite} per risk above appetite, plus one per day waiting, plus ${weights.escalation} if escalated`}
                                >
                                    Priority
                                </th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            {queue.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={11}
                                        className="py-10 text-center text-sm text-gray-400"
                                    >
                                        Nothing is waiting for review.
                                    </td>
                                </tr>
                            )}

                            {queue.map((row) => (
                                <tr
                                    key={row.id}
                                    className={
                                        row.escalated
                                            ? "bg-red-50/50"
                                            : undefined
                                    }
                                >
                                    <td className="text-sm font-medium text-gray-700">
                                        {row.business_unit}
                                        {row.escalated && (
                                            <span
                                                className="ml-2 rounded bg-red-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-red-700"
                                                title={
                                                    row.escalation_reason ??
                                                    undefined
                                                }
                                            >
                                                Escalated
                                            </span>
                                        )}
                                    </td>
                                    <td className="text-sm text-gray-600">
                                        {row.cycle}
                                    </td>
                                    <td className="text-sm text-gray-600">
                                        {row.submitted_by ?? "—"}
                                        <span className="block text-xs text-gray-400">
                                            {row.submitted_at ?? ""}
                                        </span>
                                    </td>
                                    <td className="text-right text-sm text-gray-600">
                                        {row.age_days}{" "}
                                        {row.age_days === 1 ? "day" : "days"}
                                    </td>
                                    <td className="text-right text-sm text-gray-600">
                                        {row.lines_count}
                                    </td>
                                    <td className="text-right text-sm font-semibold text-gray-700">
                                        {row.above_appetite_count > 0 ? (
                                            <span className="text-red-700">
                                                {row.above_appetite_count}
                                            </span>
                                        ) : (
                                            <span className="text-gray-400">
                                                0
                                            </span>
                                        )}
                                    </td>
                                    <td className="text-right text-sm text-gray-600">
                                        {row.flagged_count}
                                    </td>
                                    <td className="text-sm text-gray-600">
                                        {row.reviewer ?? "Unclaimed"}
                                    </td>
                                    <td>
                                        <StatusBadge status={row.status} />
                                    </td>
                                    <td className="text-right text-sm tabular-nums text-gray-500">
                                        {row.priority}
                                    </td>
                                    <td className="text-right">
                                        <Link
                                            href={route(
                                                "rcsa.review.show",
                                                row.id,
                                            )}
                                            className="text-xs text-[var(--color-primary)] hover:underline"
                                        >
                                            Review
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <p className="mt-3 text-xs text-gray-500">
                Sorted by risk-weighted priority: {weights.above_appetite}{" "}
                points for every risk above appetite, one for every day it has
                been waiting, and {weights.escalation} if a reviewer has
                escalated it.
            </p>
        </AppLayout>
    );
}

function Tile({ label, value }) {
    return (
        <div className="rounded-lg border border-gray-200 bg-white p-4">
            <p className="text-xs uppercase tracking-wider text-gray-500">
                {label}
            </p>
            <p className="mt-1 text-2xl font-semibold text-gray-800">{value}</p>
        </div>
    );
}
