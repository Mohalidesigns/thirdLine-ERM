import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import InputError from '@thirdline/ui/Components/InputError';
import Modal from '@thirdline/ui/Components/Modal';
import PageHeader from '@thirdline/ui/Components/PageHeader';

function Stat({ label, value, tone, icon }) {
    return (
        <div className="bg-white rounded-xl border border-gray-200 p-5">
            <div className="flex items-center justify-between">
                <div>
                    <p className="text-xs text-gray-500 uppercase tracking-wide">{label}</p>
                    <p className={`text-3xl font-bold mt-1 ${tone}`}>{value}</p>
                </div>
                <span className={`material-symbols-outlined opacity-50 ${tone}`} style={{ fontSize: 36 }}>{icon}</span>
            </div>
        </div>
    );
}

function DecisionModal({ approval, mode, onClose }) {
    const form = useForm(mode === 'approve' ? { comments: '' } : { rejection_reason: '' });
    const submit = (e) => {
        e.preventDefault();
        form.post(mode === 'approve' ? approval.urls.approve : approval.urls.reject, { preserveScroll: true, onSuccess: onClose });
    };
    const reject = mode === 'reject';

    return (
        <Modal show onClose={onClose} maxWidth="lg">
            <form onSubmit={submit}>
                <div className="px-5 py-4 border-b flex items-center justify-between">
                    <h5 className="text-base font-semibold text-[var(--color-primary)]">{reject ? 'Reject request' : 'Approve request'}</h5>
                    <button type="button" onClick={onClose} className="text-gray-400 hover:text-gray-600"><span className="material-symbols-outlined">close</span></button>
                </div>
                <div className="px-5 py-4">
                    {reject ? (
                        <>
                            <label className="block text-xs font-semibold text-gray-600 mb-1">Rejection reason</label>
                            <textarea value={form.data.rejection_reason} onChange={(e) => form.setData('rejection_reason', e.target.value)} rows={3} required placeholder="Explain why you're rejecting this request…" className="form-textarea w-full text-sm rounded-lg border-gray-300" />
                            <InputError message={form.errors.rejection_reason} className="mt-1" />
                        </>
                    ) : (
                        <>
                            <p className="text-sm text-gray-700 mb-3">Are you sure you want to approve this request?</p>
                            <label className="block text-xs font-semibold text-gray-600 mb-1">Comments (optional)</label>
                            <textarea value={form.data.comments} onChange={(e) => form.setData('comments', e.target.value)} rows={3} placeholder="Add any comments…" className="form-textarea w-full text-sm rounded-lg border-gray-300" />
                            <InputError message={form.errors.comments} className="mt-1" />
                        </>
                    )}
                </div>
                <div className="px-5 py-3 border-t bg-gray-50 flex justify-end gap-2">
                    <button type="button" onClick={onClose} className="px-3 py-1.5 text-sm rounded-md border border-gray-300 text-gray-700 hover:bg-gray-100">Cancel</button>
                    <button type="submit" disabled={form.processing} className={`px-3 py-1.5 text-sm rounded-md text-white inline-flex items-center gap-1 ${reject ? 'bg-red-600 hover:bg-red-700' : 'bg-green-600 hover:bg-green-700'}`}>
                        <span className="material-symbols-outlined" style={{ fontSize: 16 }}>{reject ? 'close' : 'check'}</span> {reject ? 'Reject' : 'Approve'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}

/** Pending approvals grouped by record type (migration Phase 3.7: risk/approvals/dashboard.blade.php). */
export default function Dashboard({ stats, groups, canAct, historyUrl }) {
    const { flash } = usePage().props;
    const [decision, setDecision] = useState(null);
    const title = (s) => (s ? s.charAt(0).toUpperCase() + s.slice(1) : '');

    return (
        <AuthenticatedLayout title="Approvals">
            <Head title="Approvals" />
            <PageHeader
                title="Approval dashboard"
                subtitle="Review and manage pending approvals across the platform."
                actions={<Link href={historyUrl} className="btn-secondary text-sm inline-flex items-center gap-2"><span className="material-symbols-outlined text-lg">history</span> View history</Link>}
            />

            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <Stat label="Pending" value={stats.pending} tone="text-yellow-600" icon="hourglass_empty" />
                <Stat label="Approved" value={stats.approved} tone="text-green-600" icon="check_circle" />
                <Stat label="Rejected" value={stats.rejected} tone="text-red-600" icon="cancel" />
                <Stat label="Total" value={stats.total} tone="text-[var(--color-primary)]" icon="inventory_2" />
            </div>

            {flash?.error && <div className="mb-4 p-4 rounded-lg bg-red-50 border border-red-200 text-sm text-red-700">{flash.error}</div>}

            {groups.length === 0 ? (
                <div className="bg-white rounded-xl border border-gray-200 py-16 text-center">
                    <span className="material-symbols-outlined text-green-500" style={{ fontSize: 56 }}>check_circle</span>
                    <h5 className="mt-3 text-lg font-semibold text-[var(--color-primary)]">All caught up</h5>
                    <p className="text-sm text-gray-500">No pending approvals at this time.</p>
                </div>
            ) : groups.map((group) => (
                <div key={group.entity_type} className="bg-white rounded-xl border border-gray-200 mb-6 overflow-hidden">
                    <div className="px-5 py-3 border-b border-gray-200 bg-gray-50 flex items-center gap-2">
                        <span className="px-2 py-0.5 rounded-full bg-yellow-100 text-yellow-700 text-xs font-semibold">{group.count}</span>
                        <h6 className="text-sm font-semibold text-[var(--color-primary)]">{group.entity_type} approvals</h6>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-gray-50 text-xs text-gray-600 uppercase">
                                <tr><th className="px-5 py-3 text-left">Entity</th><th className="px-5 py-3 text-left">Action</th><th className="px-5 py-3 text-left">Requested by</th><th className="px-5 py-3 text-left">Requested at</th><th className="px-5 py-3 text-right">Actions</th></tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {group.items.map((a) => (
                                    <tr key={a.id} className="hover:bg-gray-50">
                                        <td className="px-5 py-3"><div className="font-semibold text-[var(--color-primary)]">#{a.entity_id}</div><div className="text-xs text-gray-500">{a.entity_type}</div></td>
                                        <td className="px-5 py-3"><span className="inline-block px-2 py-0.5 rounded-full bg-blue-100 text-blue-700 text-xs font-medium">{title(a.action)}</span></td>
                                        <td className="px-5 py-3"><div className="text-gray-800">{a.requested_by.name}</div><div className="text-xs text-gray-500">{a.requested_by.email}</div></td>
                                        <td className="px-5 py-3"><div className="text-gray-800">{a.requested_at ?? '-'}</div><div className="text-xs text-gray-500">{a.requested_human}</div></td>
                                        <td className="px-5 py-3 text-right">
                                            {canAct && (
                                                <div className="inline-flex gap-2">
                                                    <button type="button" onClick={() => setDecision({ approval: a, mode: 'approve' })} className="px-3 py-1 text-xs rounded-md border border-green-600 text-green-700 hover:bg-green-600 hover:text-white inline-flex items-center gap-1"><span className="material-symbols-outlined" style={{ fontSize: 14 }}>check</span> Approve</button>
                                                    <button type="button" onClick={() => setDecision({ approval: a, mode: 'reject' })} className="px-3 py-1 text-xs rounded-md border border-red-600 text-red-700 hover:bg-red-600 hover:text-white inline-flex items-center gap-1"><span className="material-symbols-outlined" style={{ fontSize: 14 }}>close</span> Reject</button>
                                                </div>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            ))}

            {decision && <DecisionModal key={`${decision.mode}-${decision.approval.id}`} approval={decision.approval} mode={decision.mode} onClose={() => setDecision(null)} />}
        </AuthenticatedLayout>
    );
}
