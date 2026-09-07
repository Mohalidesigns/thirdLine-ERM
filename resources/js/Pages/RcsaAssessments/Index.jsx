import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import Pagination from '@thirdline/ui/Components/Pagination';
import StatusBadge from '@thirdline/ui/Components/StatusBadge';

/**
 * The assessment picker — steps 1 and 2 of the process flow in one screen.
 *
 * Work in progress sorts first, because an assessor arriving here almost always
 * wants the thing they were in the middle of.
 */
export default function Index({ assessments, filters = {} }) {
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

            <div className="filter-bar">
                <div className="filter-bar-inner">
                    <div className="filter-group min-w-[180px]">
                        <label className="filter-label">Status</label>
                        <select
                            className="filter-select"
                            value={filters.status ?? ''}
                            onChange={(e) =>
                                router.get(
                                    route('rcsa.assessments.index'),
                                    e.target.value ? { status: e.target.value } : {},
                                    { preserveState: true },
                                )
                            }
                        >
                            <option value="">All statuses</option>
                            {['in_progress', 'returned', 'submitted', 'under_review', 'validated', 'closed'].map((s) => (
                                <option key={s} value={s}>
                                    {s.replace(/_/g, ' ')}
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
                                    <td colSpan={7} className="py-10 text-center text-sm text-gray-400">
                                        Nothing to assess. An assessment appears here once a cycle is opened.
                                    </td>
                                </tr>
                            )}

                            {(assessments?.data ?? []).map((assessment) => (
                                <tr key={assessment.id}>
                                    <td className="text-sm text-gray-700">{assessment.cycle}</td>
                                    <td className="text-sm font-medium text-gray-700">{assessment.business_unit}</td>
                                    <td className="text-sm text-gray-600">{assessment.lines_count}</td>
                                    <td>
                                        <div className="flex items-center gap-2">
                                            <div className="h-2 w-24 rounded-full bg-gray-100">
                                                <div
                                                    className="h-2 rounded-full bg-[var(--color-primary)]"
                                                    style={{ width: `${assessment.completion_pct}%` }}
                                                />
                                            </div>
                                            <span className="text-xs text-gray-600">{assessment.completion_pct}%</span>
                                        </div>
                                    </td>
                                    <td className="text-sm text-gray-600">{assessment.due_date ?? '—'}</td>
                                    <td>
                                        <StatusBadge status={assessment.status} />
                                    </td>
                                    <td className="text-right">
                                        <Link
                                            href={route('rcsa.assessments.show', assessment.id)}
                                            className="text-xs text-[var(--color-primary)] hover:underline"
                                        >
                                            Open
                                        </Link>
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
