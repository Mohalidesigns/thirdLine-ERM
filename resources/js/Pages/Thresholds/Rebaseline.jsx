import { Head, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputError from '@/Components/InputError';
import PageHeader from '@/Components/PageHeader';

function titleCase(value) {
    return value ? value.charAt(0).toUpperCase() + value.slice(1) : '—';
}

function number(value, digits = 2) {
    return Number(value ?? 0).toLocaleString(undefined, {
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
    });
}

/** One queued re-baselining, with its own approve and reject forms. */
function PendingCard({ approval, canDecide }) {
    const approve = useForm({ comments: '' });
    const reject = useForm({ reason: '' });

    const submit = (form, routeName) => (e) => {
        e.preventDefault();
        form.post(route(routeName, approval.id), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    const field = 'border border-gray-300 rounded px-2 py-1.5 text-xs w-64';

    return (
        <div className="bg-white rounded-xl border border-gray-200 p-5 mb-4">
            <div className="flex items-start justify-between gap-4">
                <div>
                    <h2 className="text-sm font-semibold text-[#1A365D]">
                        {approval.measureName}{' '}
                        <span className="font-mono text-xs text-gray-400">{approval.measureCode}</span>
                    </h2>
                    <p className="text-xs text-gray-500 mt-0.5">
                        Computed at the close of {approval.periodCode ?? 'the period'}
                        {approval.tolerancePct !== null && ` · tolerance ${number(approval.tolerancePct, 1)}%`}
                        {approval.raisedAgo && ` · raised ${approval.raisedAgo}`}
                    </p>
                </div>
                <span className="badge bg-amber-100 text-amber-700 shrink-0">Pending approval</span>
            </div>

            <div className="overflow-x-auto">
                <table className="w-full mt-4 text-xs">
                    <thead>
                        <tr className="text-left text-gray-400 uppercase tracking-wide text-[10px]">
                            <th className="pb-2">Band</th>
                            <th className="pb-2">Bound</th>
                            <th className="pb-2">Expression</th>
                            <th className="pb-2 text-right">In force</th>
                            <th className="pb-2 text-right">Computed</th>
                            <th className="pb-2 text-right">Change</th>
                        </tr>
                    </thead>
                    <tbody>
                        {approval.changes.map((change, index) => (
                            <tr key={index} className="border-t border-gray-100">
                                <td className="py-2 font-medium">{titleCase(change.band)}</td>
                                <td className="py-2">{change.bound ?? '—'}</td>
                                <td className="py-2 font-mono text-[11px] text-gray-500">{change.formula}</td>
                                <td className="py-2 text-right">
                                    {change.inForce === null ? 'unset' : number(change.inForce)}
                                </td>
                                <td className="py-2 text-right font-semibold text-[#1A365D]">{number(change.computed)}</td>
                                <td className={`py-2 text-right ${(change.relativeChange ?? 0) > 0 ? 'text-amber-600' : 'text-gray-500'}`}>
                                    {change.relativeChange === null ? 'no prior bound' : `${number(change.relativeChange, 1)}%`}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {canDecide && (
                <div className="flex flex-wrap gap-3 mt-4 pt-4 border-t border-gray-100">
                    <form onSubmit={submit(approve, 'risk.thresholds.rebaseline.approve')} className="flex items-start gap-2">
                        <div>
                            <input
                                type="text"
                                maxLength={1000}
                                value={approve.data.comments}
                                onChange={(e) => approve.setData('comments', e.target.value)}
                                placeholder="Comment (optional)"
                                className={field}
                            />
                            <InputError message={approve.errors.comments} className="mt-1" />
                        </div>
                        <button type="submit" disabled={approve.processing} className="px-3 py-1.5 rounded-lg bg-[#1A365D] text-white text-xs font-medium disabled:opacity-50">
                            Approve re-baselining
                        </button>
                    </form>

                    <form onSubmit={submit(reject, 'risk.thresholds.rebaseline.reject')} className="flex items-start gap-2">
                        <div>
                            <input
                                type="text"
                                maxLength={1000}
                                value={reject.data.reason}
                                onChange={(e) => reject.setData('reason', e.target.value)}
                                placeholder="Reason for rejecting"
                                className={field}
                            />
                            <InputError message={reject.errors.reason} className="mt-1" />
                        </div>
                        <button type="submit" disabled={reject.processing} className="px-3 py-1.5 rounded-lg border border-gray-300 text-gray-700 text-xs font-medium disabled:opacity-50">
                            Reject
                        </button>
                    </form>
                </div>
            )}
        </div>
    );
}

/** Migration Phase 4.2: risk/thresholds/rebaseline.blade.php. */
export default function Rebaseline({ pending = [], history = [], canDecide = false }) {
    return (
        <AuthenticatedLayout title="Threshold Re-baselining">
            <Head title="Threshold Re-baselining" />

            <PageHeader
                title="Threshold Re-baselining"
                breadcrumbs={[{ label: 'Governance' }, { label: 'Threshold Re-baselining' }]}
            />

            <p className="text-sm text-gray-500 mb-6 max-w-3xl">
                Limits defined as a formula — a share of qualifying capital, an inflation-indexed naira figure — are
                re-evaluated when a period closes. Where the computed band has moved further than the configured
                tolerance from the band in force, it is queued here. Approving writes a{' '}
                <strong>new effective-dated band set</strong>; the band that was in force is retained, so breaches
                already recorded keep reading against the limit that applied when they happened.
            </p>

            {pending.length === 0 ? (
                <div className="bg-white rounded-xl border border-gray-200 text-center py-12">
                    <span className="material-symbols-outlined text-4xl text-green-300 mb-2 block">verified</span>
                    <p className="text-sm text-gray-500">No thresholds have drifted past tolerance.</p>
                </div>
            ) : (
                pending.map((approval) => <PendingCard key={approval.id} approval={approval} canDecide={canDecide} />)
            )}

            {history.length > 0 && (
                <>
                    <h2 className="text-sm font-semibold text-[#1A365D] mt-8 mb-3">Recent decisions</h2>
                    <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                        <div className="overflow-x-auto">
                            <table className="data-table">
                                <thead>
                                    <tr><th>Measure</th><th>Period</th><th>Decision</th><th>Decided by</th><th>When</th><th>Note</th></tr>
                                </thead>
                                <tbody>
                                    {history.map((decision) => (
                                        <tr key={decision.id}>
                                            <td className="text-xs font-mono">{decision.measureCode ?? '—'}</td>
                                            <td className="text-xs">{decision.periodCode ?? '—'}</td>
                                            <td>
                                                <span className={`badge ${decision.status === 'approved' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'}`}>
                                                    {titleCase(decision.status)}
                                                </span>
                                            </td>
                                            <td className="text-xs">{decision.reviewedBy ?? '—'}</td>
                                            <td className="text-xs text-gray-500">{decision.reviewedAt ?? '—'}</td>
                                            <td className="text-xs text-gray-500 max-w-xs truncate" title={decision.note ?? ''}>
                                                {decision.note ?? ''}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </>
            )}
        </AuthenticatedLayout>
    );
}
