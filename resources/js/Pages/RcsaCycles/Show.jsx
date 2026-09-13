import { Head, Link, router, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import StatusBadge from '@thirdline/ui/Components/StatusBadge';

/**
 * One cycle: the completion tracker of §10.3, and the button that opens it.
 *
 * OPENING SAYS WHAT IT WILL DO BEFORE IT DOES IT. It copies the whole published
 * universe into an assessment for every business unit and cannot be undone, so
 * the confirmation names that consequence rather than asking "are you sure?".
 */
export default function Show({ cycle, assessments = [], can = {} }) {
    const { flash } = usePage().props;

    const open = () => {
        if (
            !window.confirm(
                `Open ${cycle.name}?\n\nThis copies every published risk in the RCSA Universe into an assessment for each business unit. It cannot be undone — risks added to the universe afterwards belong to the next cycle.`,
            )
        ) {
            return;
        }

        router.post(route('rcsa.cycles.open', cycle.id), {}, { preserveScroll: true });
    };

    const close = () => {
        if (
            !window.confirm(
                `Close ${cycle.name}?\n\nEvery assessment under it becomes read-only, including any a unit has not finished.`,
            )
        ) {
            return;
        }

        router.post(route('rcsa.cycles.close', cycle.id), {}, { preserveScroll: true });
    };

    const done = assessments.filter((a) => a.completion_pct >= 100).length;

    return (
        <AppLayout
            header={
                <PageHeader
                    title={cycle.name}
                    subtitle={`${cycle.period_start} → ${cycle.period_end}${cycle.due_date ? ` · due ${cycle.due_date}` : ''}`}
                    breadcrumbs={[{ label: 'RCSA cycles', href: route('rcsa.cycles.index') }, { label: cycle.name }]}
                    actions={
                        <div className="flex items-center gap-2">
                            <StatusBadge status={cycle.status} />
                            {cycle.status === 'draft' && can.open && (
                                <button type="button" onClick={open} className="btn-primary">
                                    Open cycle
                                </button>
                            )}
                            {cycle.status === 'open' && can.close && (
                                <button type="button" onClick={close} className="btn-secondary">
                                    Close cycle
                                </button>
                            )}
                        </div>
                    }
                />
            }
        >
            <Head title={cycle.name} />

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

            {cycle.status === 'draft' && (
                <div className="mb-4 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
                    This cycle is a draft. Opening it copies every <strong>published</strong> risk in the universe
                    into an assessment for each business unit — that is what fills in the process, risk and control
                    columns so an assessor only answers likelihood, impact and control effectiveness.
                </div>
            )}

            {assessments.length > 0 && (
                <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4">
                    <Tile label="Business units" value={assessments.length} />
                    <Tile label="Risks in scope" value={assessments.reduce((n, a) => n + a.lines_count, 0)} />
                    <Tile label="Units finished" value={`${done} of ${assessments.length}`} />
                    <Tile
                        label="Average progress"
                        value={`${Math.round(assessments.reduce((n, a) => n + a.completion_pct, 0) / assessments.length)}%`}
                    />
                </div>
            )}

            <div className="card">
                <div className="overflow-x-auto">
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th>Business unit</th>
                                <th>Risks</th>
                                <th className="w-48">Progress</th>
                                <th>Assigned to</th>
                                <th>Status</th>
                                <th className="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {assessments.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="py-10 text-center text-sm text-gray-400">
                                        Nothing provisioned yet — open the cycle to create the assessments.
                                    </td>
                                </tr>
                            )}

                            {assessments.map((assessment) => (
                                <tr key={assessment.id}>
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
                                    <td className="text-sm text-gray-600">{assessment.assignee ?? 'Unassigned'}</td>
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
            </div>
        </AppLayout>
    );
}

function Tile({ label, value }) {
    return (
        <div className="rounded-lg border border-gray-200 bg-white p-4">
            <p className="text-xs uppercase tracking-wider text-gray-500">{label}</p>
            <p className="mt-1 text-2xl font-semibold text-gray-800">{value}</p>
        </div>
    );
}
