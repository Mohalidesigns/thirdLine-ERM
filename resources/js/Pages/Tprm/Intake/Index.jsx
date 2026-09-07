import { useRef, useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import Pagination from '@thirdline/ui/Components/Pagination';
import TierBadge from '@/Components/Tprm/TierBadge';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * The Intake Queue — the approver's view (FR-INT-04).
 *
 * Ordered by tier descending, so the arrangements that need the most scrutiny
 * are read first rather than whichever happened to be submitted first.
 *
 * The reject dialog demands a reason code AND a rationale (FR-INT-07), and the
 * record is retained rather than deleted: a declined arrangement is
 * supervisory evidence that the institution considered it.
 */
export default function Index({ queue, can = {} }) {
    const [rejecting, setRejecting] = useState(null);
    const rows = queue?.data ?? [];

    return (
        <AppLayout title="Intake queue">
            <Head title="Intake queue" />

            <PageHeader
                title="Intake queue"
                subtitle="Submitted intakes awaiting a decision, highest tier first."
            />

            <div className="card overflow-hidden">
                {rows.length ? (
                    <table className="w-full text-sm">
                        <thead className="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th className="px-4 py-3 font-medium">Reference</th>
                                <th className="px-4 py-3 font-medium">Engagement</th>
                                <th className="px-4 py-3 font-medium">Third party</th>
                                <th className="px-4 py-3 font-medium">Tier</th>
                                <th className="px-4 py-3 font-medium">Approval chain</th>
                                <th className="px-4 py-3 font-medium">Submitted</th>
                                <th className="px-4 py-3" />
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {rows.map((row) => (
                                <tr key={row.id} className="hover:bg-gray-50">
                                    <td className="px-4 py-3">
                                        <a href={row.url} className="font-mono text-xs text-blue-700 hover:underline">{row.reference}</a>
                                    </td>
                                    <td className="px-4 py-3">{row.name}</td>
                                    <td className="px-4 py-3 text-gray-600">{row.third_party}</td>
                                    <td className="px-4 py-3">
                                        <TierBadge tier={row.tier} label={row.tier_label} size="sm" />
                                    </td>
                                    <td className="px-4 py-3">
                                        <span className="text-xs text-gray-500">
                                            {row.approval_chain?.length ? row.approval_chain.join(' → ') : '—'}
                                        </span>
                                        {/* FR-INT-08 refuses approval without a sponsor, so
                                            the queue says so before the approver tries. */}
                                        {row.supports_critical_function && !row.has_sponsor && (
                                            <p className="mt-1 text-xs font-medium text-amber-700">
                                                Needs an executive sponsor
                                            </p>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-xs text-gray-500">{row.submitted_at}</td>
                                    <td className="px-4 py-3 text-right">
                                        {can.approve && (
                                            <div className="flex items-center justify-end gap-2">
                                                <ApproveButton engagementId={row.id} />
                                                <button type="button" onClick={() => setRejecting(row)}
                                                    className="text-xs font-medium text-red-700 hover:underline">
                                                    Reject
                                                </button>
                                            </div>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                ) : (
                    <div className="p-8 text-center text-sm text-gray-500">
                        No intakes are awaiting a decision.
                    </div>
                )}
            </div>

            {queue?.links && <Pagination links={queue.links} className="mt-4" />}

            {rejecting && <RejectDialog row={rejecting} onClose={() => setRejecting(null)} />}
        </AppLayout>
    );
}

function ApproveButton({ engagementId }) {
    const [busy, setBusy] = useState(false);

    return (
        <button
            type="button"
            disabled={busy}
            onClick={() => {
                setBusy(true);
                router.post(tryRoute('tprm.intake.approve', engagementId), {}, {
                    preserveScroll: true,
                    onFinish: () => setBusy(false),
                });
            }}
            className="text-xs font-medium text-blue-700 hover:underline disabled:opacity-50"
        >
            Approve
        </button>
    );
}

function RejectDialog({ row, onClose }) {
    const { data, setData, post, processing, errors } = useForm({ reason_code: '', rationale: '' });

    // A single-purpose form, so `setData` is safe here — the two-submit-button
    // hazard in standard §9 applies where one handler must choose between
    // actions, which this dialog does not.
    const submit = (event) => {
        event.preventDefault();
        post(tryRoute('tprm.intake.reject', row.id), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
            <div className="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
                <h3 className="text-sm font-semibold text-gray-900">Reject {row.reference}</h3>
                <p className="mt-1 text-xs text-gray-500">
                    The intake is retained with its reason — a declined arrangement is supervisory
                    evidence, not something to delete (FR-INT-07).
                </p>

                <form onSubmit={submit} className="mt-4 space-y-3">
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Reason code</label>
                        <select value={data.reason_code} onChange={(e) => setData('reason_code', e.target.value)}
                            className="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm">
                            <option value="">Select…</option>
                            <option value="insufficient_controls">Insufficient controls</option>
                            <option value="duplicate_service">Duplicate service already contracted</option>
                            <option value="concentration">Unacceptable concentration</option>
                            <option value="commercial">Commercial terms</option>
                            <option value="incomplete_information">Incomplete information</option>
                            <option value="prohibited">Prohibited arrangement</option>
                        </select>
                        {errors.reason_code && <p className="mt-1 text-xs text-red-600">{errors.reason_code}</p>}
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700">Rationale</label>
                        <textarea rows="3" value={data.rationale} onChange={(e) => setData('rationale', e.target.value)}
                            className="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm" />
                        {errors.rationale && <p className="mt-1 text-xs text-red-600">{errors.rationale}</p>}
                    </div>

                    <div className="flex justify-end gap-2 pt-2">
                        <button type="button" onClick={onClose} className="btn-secondary text-sm">Cancel</button>
                        <button type="submit" disabled={processing} className="btn-primary text-sm">Reject intake</button>
                    </div>
                </form>
            </div>
        </div>
    );
}
