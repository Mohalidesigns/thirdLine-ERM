import { Head, Link, router } from "@inertiajs/react";
import AppLayout from "@/Layouts/AppLayout";
import PageHeader from "@thirdline/ui/Components/PageHeader";
import Pagination from "@thirdline/ui/Components/Pagination";
import StatusBadge from "@thirdline/ui/Components/StatusBadge";

/**
 * The assessment picker — steps 1 and 2 of the process flow in one screen.
 *
 * Work in progress sorts first, because an assessor arriving here almost always
 * wants the thing they were in the middle of.
 */
export default function Index({
    assessments,
    filters = {},
    scopeNotice = null,
}) {
    return (
        <AppLayout
            header={
                <PageHeader
                    title="RCSA Assessments"
                    subtitle="Choose the exercise and business unit you are assessing"
                />
            }
        >
            <Head title="RCSA Assessments" />

            {/*
             * §11. An empty table because you are assigned to no business unit
             * is indistinguishable from an empty table because the bank has no
             * risks, and the second reading gets filed as a bug every time.
             */}
            {scopeNotice && (
                <div className="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    {scopeNotice}
                </div>
            )}

            <div className="filter-bar">
                <div className="filter-bar-inner">
                    <div className="filter-group min-w-[180px]">
                        <label className="filter-label">Status</label>
                        <select aria-label="Status"
                            className="filter-select"
                            value={filters.status ?? ""}
                            onChange={(e) =>
                                router.get(
                                    route("rcsa.assessments.index"),
                                    e.target.value
                                        ? { status: e.target.value }
                                        : {},
                                    { preserveState: true },
                                )
                            }
                        >
                            <option value="">All statuses</option>
                            {[
                                "in_progress",
                                "returned",
                                "submitted",
                                "under_review",
                                "validated",
                                "closed",
                            ].map((s) => (
                                <option key={s} value={s}>
                                    {s.replace(/_/g, " ")}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>
            </div>

            <div className="card">
                <div className="overflow-x-auto">
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th>Cycle</th>
                                <th>Business unit</th>
                                <th>Risks</th>
                                <th className="w-48">Progress</th>
                                <th>Due</th>
                                <th>Status</th>
                                <th className="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {(assessments?.data ?? []).length === 0 && (
                                <tr>
                                    <td
                                        colSpan={7}
                                        className="text-center py-12"
                                    >
                                        <div className="text-gray-400">
                                            <p className="text-sm font-medium">Nothing to assess</p>
                                            <p className="text-xs mt-1">An assessment appears here once a cycle is opened</p>
                                        </div>
                                    </td>
                                </tr>
                            )}

                            {(assessments?.data ?? []).map((assessment) => (
                                <tr key={assessment.id}>
                                    <td className="cell-muted">
                                        {assessment.cycle}
                                    </td>
                                    <td className="cell-title">
                                        {assessment.business_unit}
                                    </td>
                                    <td className="cell-muted">
                                        {assessment.lines_count}
                                    </td>
                                    <td>
                                        <div className="flex items-center gap-2">
                                            <div className="h-2 w-24 rounded-full bg-gray-100">
                                                <div
                                                    className="h-2 rounded-full bg-[var(--color-primary)]"
                                                    style={{
                                                        width: `${assessment.completion_pct}%`,
                                                    }}
                                                />
                                            </div>
                                            <span className="text-xs text-gray-600">
                                                {assessment.completion_pct}%
                                            </span>
                                        </div>
                                    </td>
                                    <td className="cell-muted">
                                        {assessment.due_date ?? "—"}
                                    </td>
                                    <td>
                                        <StatusBadge
                                            status={assessment.status}
                                        />
                                    </td>
                                    <td className="text-right">
                                        <div className="row-actions">
                                            <Link
                                                href={route(
                                                    "rcsa.assessments.show",
                                                    assessment.id,
                                                )}
                                                className="row-action"
                                                aria-label="Open" title="Open"
                                            >
                                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" /><path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                                            </Link>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {assessments?.links?.length > 3 && (
                    <div className="border-t border-gray-100 px-4 py-3">
                        <Pagination links={assessments.links} />
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
