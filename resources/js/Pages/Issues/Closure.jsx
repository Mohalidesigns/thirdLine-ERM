import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import InputError from '@thirdline/ui/Components/InputError';
import KpiCard from '@thirdline/ui/Components/KpiCard';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import Pagination from '@thirdline/ui/Components/Pagination';
import { priorityTone, titleCase } from './format';

/**
 * The closure queue (migration Phase 4.4: risk/issues/closure.blade.php).
 *
 * `avgClosureTime` used MySQL-only `DATEDIFF()`, so this screen threw on SQLite
 * and had never been tested; it is computed portably now.
 */
function ClosureRow({ issue }) {
    const [rejecting, setRejecting] = useState(false);

    const approve = useForm({});
    const reject = useForm({ rejection_reason: '' });

    return (
        <div className="bg-white rounded-xl border border-gray-200 p-5 mb-4">
            <div className="flex items-start justify-between gap-4">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-3 mb-1">
                        <Link href={issue.url} className="text-sm font-semibold text-[#1A365D] hover:underline">
                            {issue.title}
                        </Link>
                        <span className="text-xs font-mono text-gray-400">{issue.reference}</span>
                        <span className={`badge ${priorityTone(issue.priority)}`}>{titleCase(issue.priority)}</span>
                    </div>
                    <p className="text-xs text-gray-500">
                        {issue.owner ?? 'Unassigned'}
                        {issue.businessUnit && ` · ${issue.businessUnit}`}
                        {issue.requestedAt && ` · requested ${issue.requestedAt}`}
                    </p>
                    {issue.justification && (
                        <p className="text-sm text-gray-700 mt-3 whitespace-pre-line">{issue.justification}</p>
                    )}
                </div>

                {issue.canDecide && !rejecting && (
                    <div className="flex flex-col gap-2 shrink-0">
                        <button
                            type="button"
                            disabled={approve.processing}
                            onClick={() => approve.post(route('risk.issues.approve-closure', issue.id), { preserveScroll: true })}
                            className="px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700 disabled:opacity-50"
                        >
                            Accept closure
                        </button>
                        <button
                            type="button"
                            onClick={() => setRejecting(true)}
                            className="px-4 py-2 border border-red-300 text-red-600 rounded-lg text-sm font-medium hover:bg-red-50"
                        >
                            Send back
                        </button>
                    </div>
                )}
            </div>

            {rejecting && (
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        reject.post(route('risk.issues.reject-closure', issue.id), {
                            preserveScroll: true,
                            onSuccess: () => setRejecting(false),
                        });
                    }}
                    className="mt-4 pt-4 border-t border-gray-100"
                >
                    <label className="block text-xs font-medium text-gray-700 mb-1">
                        Why it is going back <span className="text-red-500">*</span>
                    </label>
                    <textarea
                        rows={3}
                        value={reject.data.rejection_reason}
                        onChange={(e) => reject.setData('rejection_reason', e.target.value)}
                        className="w-full px-3 py-2 border border-red-200 rounded-lg text-sm focus:border-red-400"
                    />
                    <InputError message={reject.errors.rejection_reason} className="mt-1" />
                    <div className="flex justify-end gap-2 mt-2">
                        <button type="button" onClick={() => setRejecting(false)} className="btn-secondary text-sm">Cancel</button>
                        <button type="submit" disabled={reject.processing} className="px-4 py-2 bg-red-600 text-white rounded-lg text-sm font-medium disabled:opacity-50">
                            Send back
                        </button>
                    </div>
                </form>
            )}
        </div>
    );
}

export default function Closure({ stats = {}, pending = {} }) {
    const rows = pending.data ?? [];

    return (
        <AuthenticatedLayout title="Issue Closure">
            <Head title="Issue Closure" />

            <PageHeader
                title="Closure Requests"
                subtitle="Issues whose owners say the remediation is done"
                breadcrumbs={[{ label: 'Issues', href: route('risk.issues.index') }, { label: 'Closure' }]}
            />

            <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <KpiCard title="Awaiting Decision" value={stats.pending ?? 0} icon="how_to_reg" color="warning" />
                <KpiCard title="Closed This Month" value={stats.closedThisMonth ?? 0} icon="task_alt" color="success" />
                <KpiCard title="Sent Back" value={stats.returned ?? 0} icon="undo" color="danger" subtitle="Still open" />
                <KpiCard
                    title="Avg. Days to Close"
                    value={stats.avgClosureTime ?? 0}
                    icon="timer"
                    color="info"
                    unavailable={(stats.avgClosureTime ?? 0) === 0}
                    unavailableLabel="Nothing closed yet"
                />
            </div>

            {rows.length === 0 ? (
                <div className="bg-white rounded-xl border border-gray-200 p-12 text-center">
                    <span className="material-symbols-outlined text-4xl text-green-300 mb-3 block">task_alt</span>
                    <p className="text-gray-500">No closure requests are waiting.</p>
                </div>
            ) : (
                <>
                    {rows.map((issue) => <ClosureRow key={issue.id} issue={issue} />)}
                    <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                        <Pagination links={pending.links} meta={pending.meta} />
                    </div>
                </>
            )}
        </AuthenticatedLayout>
    );
}
