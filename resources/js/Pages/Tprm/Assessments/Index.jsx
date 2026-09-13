import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import DataGrid from '@thirdline/ui/Components/DataGrid/DataGrid';
import PageHeader from '@thirdline/ui/Components/PageHeader';

/**
 * The Assessment Console — TRD §11's status board, reviewer workload and
 * ageing.
 *
 * The tiles split by WHO IS BLOCKED rather than by status name. "Awaiting
 * vendor" and "awaiting review" are the two questions a programme manager
 * actually has, and neither maps to a single status: awaiting-vendor spans
 * issued, in-progress and clarification-requested.
 */
export default function Index({ summary = {}, workload = [], grid, can = {} }) {
    const tiles = [
        { label: 'Assessments', value: summary.total ?? 0, hint: 'in the register' },
        { label: 'Awaiting vendor', value: summary.awaiting_vendor ?? 0, hint: 'issued, in progress or queried' },
        { label: 'Awaiting review', value: summary.awaiting_review ?? 0, hint: 'submitted, needs a reviewer', tone: summary.awaiting_review ? 'warn' : null },
        { label: 'Overdue', value: summary.overdue ?? 0, hint: 'past the due date', tone: summary.overdue ? 'critical' : null },
    ];

    return (
        <AppLayout title="Assessments">
            <Head title="Assessments" />

            <PageHeader
                title="Assessments"
                subtitle="Questionnaire cycles across the portfolio. A score is fixed when a reviewer validates it, not when the vendor submits."
            />

            <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                {tiles.map((tile) => (
                    <div key={tile.label} className="card p-4">
                        <p className="text-xs font-medium uppercase tracking-wide text-gray-500">{tile.label}</p>
                        <p className={`mt-1 text-2xl font-semibold ${
                            tile.tone === 'critical' ? 'text-red-700' : tile.tone === 'warn' ? 'text-amber-700' : 'text-gray-900'
                        }`}>
                            {tile.value}
                        </p>
                        <p className="mt-0.5 text-xs text-gray-500">{tile.hint}</p>
                    </div>
                ))}
            </div>

            {workload.length > 0 && (
                <div className="card mb-6 p-5">
                    <h3 className="text-sm font-semibold text-gray-900">Reviewer workload</h3>
                    <p className="mt-0.5 text-xs text-gray-500">Assessments submitted or under review, by reviewer.</p>
                    <ul className="mt-3 space-y-1.5">
                        {workload.map((row) => (
                            <li key={row.reviewer} className="flex items-center justify-between text-sm">
                                <span className={row.reviewer === 'Unassigned' ? 'text-amber-700' : 'text-gray-700'}>
                                    {row.reviewer}
                                </span>
                                <span className="font-medium tabular-nums text-gray-900">{row.open}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            <DataGrid grid={grid} />
        </AppLayout>
    );
}
