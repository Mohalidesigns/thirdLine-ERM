import Countdown from '@/Components/Countdown';
import RichTextViewer from '@/Components/RichTextViewer';
import SeverityBadge from '@/Components/SeverityBadge';
import VerificationBadge from '@/Components/VerificationBadge';
import AppLayout from '@/Layouts/AppLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { Modal, PageHeader, StatusBadge } from '@thirdline/ui';
import { formatDateTime } from '@thirdline/ui/utils';
import { useState } from 'react';

export default function Show({
    incident,
    linkedControls = [],
    linkedRisks = [],
    users = [],
    controls = [],
    actionTypes = [],
    can = {},
}) {
    const [notifying, setNotifying] = useState(null);
    const [addingAction, setAddingAction] = useState(false);
    const [triaging, setTriaging] = useState(false);
    const [editingLoss, setEditingLoss] = useState(false);

    const notification = useForm({ notification_reference: '', notes: '' });
    const action = useForm({
        action_type: 'corrective',
        title: '',
        description: '',
        owner_id: '',
        due_at: '',
        is_mandatory: true,
    });

    const triageForm = useForm({
        severity: incident.severity || 'Medium',
        incident_type: incident.incident_type || 'operational',
        basel_event_type: incident.basel_event_type || '',
        owner_id: incident.owner_id || '',
        investigator_id: incident.investigator_id || '',
        business_unit_id: incident.business_unit_id || '',
        near_miss: incident.near_miss || false,
    });

    const lossForm = useForm({
        currency: incident.currency || 'NGN',
        gross_loss_minor: incident.gross_loss_minor || 0,
        recovery_minor: incident.recovery_minor || 0,
    });

    const submitNotification = (e) => {
        e.preventDefault();
        notification.post(route('risk.incidents.notifications.record', [incident.id, notifying.id]), {
            preserveScroll: true,
            onSuccess: () => {
                notification.reset();
                setNotifying(null);
            },
        });
    };

    const submitAction = (e) => {
        e.preventDefault();
        action.post(route('risk.incidents.actions.store', incident.id), {
            preserveScroll: true,
            onSuccess: () => {
                action.reset();
                setAddingAction(false);
            },
        });
    };

    const submitTriage = (e) => {
        e.preventDefault();
        triageForm.post(route('risk.incidents.triage', incident.id), {
            preserveScroll: true,
            onSuccess: () => setTriaging(false),
        });
    };

    const submitLoss = (e) => {
        e.preventDefault();
        lossForm.post(route('risk.incidents.loss', incident.id), {
            preserveScroll: true,
            onSuccess: () => setEditingLoss(false),
        });
    };

    const openMandatory = (incident.actions ?? []).filter(
        (a) => a.is_mandatory && !['Completed', 'Verified', 'Cancelled'].includes(a.status),
    ).length;

    const outstanding = (incident.notifications ?? []).filter(
        (n) => n.is_required && n.status === 'Pending',
    ).length;

    return (
        <AppLayout title={incident.title}>
            <Head title={incident.title} />

            <PageHeader
                title={incident.title}
                subtitle={`${incident.incident_ref} · ${incident.incident_type?.replace('_', ' ')}${
                    incident.near_miss ? ' · near miss' : ''
                }`}
                breadcrumbs={[
                    { label: 'Incident Register', href: route('risk.incidents.index') },
                    { label: incident.incident_ref },
                ]}
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        <SeverityBadge severity={incident.severity} />
                        <StatusBadge status={incident.status} />

                        {can.triage && incident.status === 'Reported' && (
                            <button
                                type="button"
                                className="px-3 py-1.5 rounded-md text-sm font-medium bg-amber-50 text-amber-700 border border-amber-200 hover:bg-amber-100"
                                onClick={() => setTriaging(true)}
                            >
                                Triage Incident
                            </button>
                        )}

                        {can.investigate && ['Triaged', 'Reopened'].includes(incident.status) && (
                            <button
                                type="button"
                                className="px-3 py-1.5 rounded-md text-sm font-medium bg-blue-50 text-blue-700 border border-blue-200 hover:bg-blue-100"
                                onClick={() =>
                                    router.post(
                                        route('risk.incidents.progress', incident.id),
                                        { action: 'investigate' },
                                        { preserveScroll: true }
                                    )
                                }
                            >
                                Open Investigation
                            </button>
                        )}

                        {can.close && incident.status !== 'Closed' && (
                            <button
                                type="button"
                                className={`px-3 py-1.5 rounded-md text-sm font-medium ${
                                    openMandatory > 0 || outstanding > 0
                                        ? 'bg-gray-100 text-gray-400 cursor-not-allowed border border-gray-200'
                                        : 'bg-emerald-600 text-white hover:bg-emerald-700'
                                }`}
                                disabled={openMandatory > 0 || outstanding > 0}
                                title={
                                    openMandatory > 0 || outstanding > 0
                                        ? 'Settle the open mandatory actions and regulatory notifications first.'
                                        : undefined
                                }
                                onClick={() =>
                                    router.post(route('risk.incidents.close', incident.id), {}, { preserveScroll: true })
                                }
                            >
                                Close Incident
                            </button>
                        )}

                        {can.reopen && incident.status === 'Closed' && (
                            <button
                                type="button"
                                className="px-3 py-1.5 rounded-md text-sm font-medium bg-gray-100 text-gray-700 hover:bg-gray-200 border border-gray-300"
                                onClick={() =>
                                    router.post(route('risk.incidents.reopen', incident.id), {}, { preserveScroll: true })
                                }
                            >
                                Reopen Incident
                            </button>
                        )}
                    </div>
                }
            />

            {(openMandatory > 0 || outstanding > 0) && incident.status !== 'Closed' && (
                <div className="mb-6 rounded-lg border-l-4 border-amber-500 bg-amber-50 p-4 text-sm text-amber-900">
                    <p className="font-semibold">This incident cannot be closed yet</p>
                    <ul className="mt-1 list-inside list-disc text-amber-800">
                        {openMandatory > 0 && <li>{openMandatory} mandatory action(s) still open</li>}
                        {outstanding > 0 && <li>{outstanding} regulatory notification(s) outstanding</li>}
                    </ul>
                </div>
            )}

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3 pb-12">
                <div className="space-y-6 lg:col-span-2">
                    {/* Regulatory Notifications with Live Countdowns */}
                    <div className="card bg-white p-6 shadow-sm rounded-lg border border-gray-200">
                        <div className="flex items-center justify-between border-b border-gray-100 pb-3 mb-4">
                            <span className="text-base font-semibold text-gray-900">
                                Regulatory Notifications & Statutory Clocks
                            </span>
                            {can.notify && (
                                <button
                                    type="button"
                                    onClick={() =>
                                        router.post(
                                            route('risk.incidents.notifications.refresh', incident.id),
                                            {},
                                            { preserveScroll: true }
                                        )
                                    }
                                    className="text-xs text-blue-600 hover:underline inline-flex items-center gap-1"
                                >
                                    <span className="material-symbols-outlined text-sm">refresh</span> Refresh Obligations
                                </button>
                            )}
                        </div>

                        <div className="space-y-4">
                            {(incident.notifications ?? []).length === 0 ? (
                                <p className="text-sm text-gray-400">
                                    No statutory reporting obligations triggered for this incident type.
                                </p>
                            ) : (
                                (incident.notifications ?? []).map((row) => (
                                    <div key={row.id} className="rounded-lg border border-gray-200 p-4 bg-gray-50/50">
                                        <div className="flex flex-wrap items-start justify-between gap-3">
                                            <div>
                                                <p className="font-semibold text-gray-900">
                                                    {row.regulator_name || 'Regulator'} ({row.regulator_code})
                                                </p>
                                                <p className="text-xs text-gray-500">
                                                    <span className="font-mono font-medium">{row.obligation_ref}</span>
                                                    {row.legal_reference ? ` · ${row.legal_reference}` : ''}
                                                </p>
                                                <p className="mt-1 text-sm text-gray-700">{row.obligation_title}</p>
                                                <div className="mt-2 flex items-center gap-2">
                                                    <StatusBadge status={row.status} />
                                                    <VerificationBadge status={row.verification_status} />
                                                </div>
                                            </div>

                                            <div className="text-right">
                                                {row.status === 'Pending' ? (
                                                    <Countdown dueAt={row.due_at} />
                                                ) : (
                                                    <div>
                                                        <p className="text-xs uppercase tracking-wide text-gray-400">
                                                            {row.status}
                                                        </p>
                                                        <p className="font-mono text-sm font-semibold text-gray-800">
                                                            {row.notification_reference ?? '—'}
                                                        </p>
                                                    </div>
                                                )}
                                                <p className="mt-1 text-xs text-gray-500">
                                                    Due {formatDateTime(row.due_at)}
                                                </p>
                                                {can.notify && row.status === 'Pending' && (
                                                    <button
                                                        type="button"
                                                        className="btn-primary mt-2 px-3 py-1 text-xs rounded-md"
                                                        onClick={() => setNotifying(row)}
                                                    >
                                                        Record Notification
                                                    </button>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                ))
                            )}
                            <p className="text-xs text-gray-400">
                                Statutory deadlines (CBN, NDPC) start counting immediately from when the incident was detected.
                            </p>
                        </div>
                    </div>

                    {/* Incident Actions */}
                    <div className="card bg-white p-6 shadow-sm rounded-lg border border-gray-200">
                        <div className="flex items-center justify-between border-b border-gray-100 pb-3 mb-4">
                            <span className="text-base font-semibold text-gray-900">Remedial Actions</span>
                            {can.update && (
                                <button
                                    type="button"
                                    className="px-3 py-1.5 text-xs font-medium rounded-md bg-gray-100 hover:bg-gray-200 text-gray-700"
                                    onClick={() => setAddingAction(true)}
                                >
                                    + Raise Action
                                </button>
                            )}
                        </div>

                        {(incident.actions ?? []).length === 0 ? (
                            <p className="text-sm text-gray-400">No actions raised yet.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="data-table w-full">
                                    <thead>
                                        <tr>
                                            <th>Type</th>
                                            <th>Action</th>
                                            <th>Owner</th>
                                            <th>Due</th>
                                            <th>Status</th>
                                            <th />
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-100">
                                        {incident.actions.map((row) => (
                                            <tr key={row.id}>
                                                <td className="capitalize font-medium text-xs text-gray-700">
                                                    {row.action_type}
                                                </td>
                                                <td>
                                                    <span className="text-sm text-gray-900 font-medium">{row.title}</span>
                                                    {row.is_mandatory && (
                                                        <span className="ml-2 inline-flex items-center px-1.5 py-0.2 rounded text-[10px] font-semibold bg-amber-100 text-amber-800">
                                                            Mandatory
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="text-sm text-gray-600">{row.owner?.name ?? '—'}</td>
                                                <td className="text-xs text-gray-500">{formatDateTime(row.due_at)}</td>
                                                <td>
                                                    <StatusBadge status={row.status} />
                                                </td>
                                                <td className="text-right">
                                                    {can.update && row.status === 'Open' && (
                                                        <button
                                                            type="button"
                                                            className="text-xs font-semibold text-blue-600 hover:underline mr-2"
                                                            onClick={() =>
                                                                router.put(
                                                                    route('risk.incidents.actions.update', [incident.id, row.id]),
                                                                    { action: 'complete' },
                                                                    { preserveScroll: true }
                                                                )
                                                            }
                                                        >
                                                            Complete
                                                        </button>
                                                    )}
                                                    {can.update && row.status === 'Completed' && (
                                                        <button
                                                            type="button"
                                                            className="text-xs font-semibold text-emerald-600 hover:underline"
                                                            onClick={() =>
                                                                router.put(
                                                                    route('risk.incidents.actions.update', [incident.id, row.id]),
                                                                    { action: 'verify' },
                                                                    { preserveScroll: true }
                                                                )
                                                            }
                                                        >
                                                            Verify
                                                        </button>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>

                    {/* Timeline */}
                    <div className="card bg-white p-6 shadow-sm rounded-lg border border-gray-200">
                        <div className="text-base font-semibold text-gray-900 border-b border-gray-100 pb-3 mb-4">
                            Investigator Narrative & Timeline
                        </div>
                        <ol className="space-y-4">
                            {(incident.timeline ?? []).map((entry) => (
                                <li key={entry.id} className="flex gap-3">
                                    <div
                                        className={`mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full ${
                                            entry.is_material ? 'bg-amber-500 ring-4 ring-amber-100' : 'bg-gray-400'
                                        }`}
                                    />
                                    <div>
                                        <p className="text-sm text-gray-900">{entry.description}</p>
                                        <p className="text-xs text-gray-400">
                                            {formatDateTime(entry.occurred_at)}
                                            {entry.actor ? ` · ${entry.actor.name}` : ''}
                                            {` · ${entry.event_type}`}
                                        </p>
                                    </div>
                                </li>
                            ))}
                            {(incident.timeline ?? []).length === 0 && (
                                <p className="text-sm text-gray-400">Nothing recorded in timeline yet.</p>
                            )}
                        </ol>
                    </div>

                    {/* Root Cause & Lessons */}
                    {(incident.root_cause || incident.lessons_learned) && (
                        <div className="card bg-white p-6 shadow-sm rounded-lg border border-gray-200 space-y-4">
                            <div className="text-base font-semibold text-gray-900 border-b border-gray-100 pb-3">
                                Root Cause & Lessons Learned
                            </div>
                            {incident.root_cause && (
                                <div>
                                    <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">Root Cause</p>
                                    <RichTextViewer
                                        className="mt-1"
                                        value={incident.root_cause_rich}
                                        fallback={incident.root_cause}
                                    />
                                </div>
                            )}
                            {incident.lessons_learned && (
                                <div>
                                    <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                        Lessons Learned
                                    </p>
                                    <RichTextViewer
                                        className="mt-1"
                                        value={incident.lessons_learned_rich}
                                        fallback={incident.lessons_learned}
                                    />
                                </div>
                            )}
                        </div>
                    )}
                </div>

                {/* Right Column / Sidebar */}
                <div className="space-y-6">
                    <div className="card bg-white p-6 shadow-sm rounded-lg border border-gray-200">
                        <div className="text-base font-semibold text-gray-900 border-b border-gray-100 pb-3 mb-4">
                            Incident Details
                        </div>
                        <div className="space-y-3 text-sm">
                            <DetailRow label="Occurred" value={formatDateTime(incident.occurred_at)} />
                            <DetailRow label="Detected" value={formatDateTime(incident.detected_at)} />
                            <DetailRow label="Reported" value={formatDateTime(incident.reported_at)} />
                            <DetailRow label="Detection Method" value={incident.detection_method} />
                            <DetailRow label="Business Unit" value={incident.business_unit?.name || incident.entity?.name} />
                            <DetailRow label="Process" value={incident.process?.name} />
                            <DetailRow label="Owner" value={incident.owner?.name} />
                            <DetailRow label="Investigator" value={incident.investigator?.name} />
                            <DetailRow label="Basel Event Type" value={incident.basel_event_type} />
                        </div>
                    </div>

                    <div className="card bg-white p-6 shadow-sm rounded-lg border border-gray-200">
                        <div className="flex items-center justify-between border-b border-gray-100 pb-3 mb-4">
                            <span className="text-base font-semibold text-gray-900">Loss & Recovery</span>
                            {can.update && (
                                <button
                                    type="button"
                                    onClick={() => setEditingLoss(true)}
                                    className="text-xs text-blue-600 hover:underline"
                                >
                                    Update Loss
                                </button>
                            )}
                        </div>
                        <div className="space-y-3 text-sm">
                            <DetailRow
                                label="Net Loss"
                                value={
                                    incident.net_loss?.formatted ? (
                                        <span className="font-semibold text-gray-900">{incident.net_loss.formatted}</span>
                                    ) : (
                                        <span className="text-gray-400">—</span>
                                    )
                                }
                            />
                            <DetailRow
                                label="Gross Loss"
                                value={incident.gross_loss?.formatted || '—'}
                            />
                            <DetailRow
                                label="Recovery"
                                value={incident.recovery?.formatted || '—'}
                            />
                            <p className="text-xs text-gray-400 pt-2 border-t border-gray-100">
                                Stored in minor units ({incident.currency || 'NGN'}). Net is gross less recovery.
                            </p>
                        </div>
                    </div>

                    {/* Failed Controls */}
                    <div className="card bg-white p-6 shadow-sm rounded-lg border border-gray-200">
                        <div className="text-base font-semibold text-gray-900 border-b border-gray-100 pb-3 mb-3">
                            Controls that Failed
                        </div>
                        {linkedControls.length === 0 ? (
                            <p className="text-sm text-gray-400">No controls flagged.</p>
                        ) : (
                            <div className="space-y-2">
                                {linkedControls.map((c) => (
                                    <div key={c.id} className="p-2 bg-gray-50 rounded border border-gray-100 text-sm">
                                        <span className="font-mono text-xs font-semibold text-blue-700">{c.control_ref}</span>
                                        <p className="text-gray-800 font-medium text-xs mt-0.5">{c.title}</p>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>

                    {/* Linked Risks */}
                    <div className="card bg-white p-6 shadow-sm rounded-lg border border-gray-200">
                        <div className="text-base font-semibold text-gray-900 border-b border-gray-100 pb-3 mb-3">
                            Risks this Bears On
                        </div>
                        {linkedRisks.length === 0 ? (
                            <p className="text-sm text-gray-400">No risks linked.</p>
                        ) : (
                            <div className="space-y-2">
                                {linkedRisks.map((r) => (
                                    <div key={r.id} className="p-2 bg-gray-50 rounded border border-gray-100 text-sm">
                                        <span className="font-mono text-xs font-semibold text-indigo-700">{r.code}</span>
                                        <p className="text-gray-800 font-medium text-xs mt-0.5">{r.title}</p>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            </div>

            {/* Modal: Record Regulatory Notification */}
            <Modal show={notifying !== null} onClose={() => setNotifying(null)} maxWidth="lg">
                <form onSubmit={submitNotification} className="space-y-4 p-6">
                    <h2 className="text-lg font-semibold text-gray-900">Record Regulatory Notification</h2>
                    <p className="text-sm text-gray-500">
                        {notifying?.regulator_name} · {notifying?.obligation_ref}
                    </p>

                    <div>
                        <label className="block text-sm font-medium text-gray-700">Acknowledgement Reference</label>
                        <input
                            type="text"
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                            value={notification.data.notification_reference}
                            onChange={(e) => notification.setData('notification_reference', e.target.value)}
                            placeholder="e.g. CBN/BSD/REF/2026/001"
                            required
                        />
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700">Notes / Narrative</label>
                        <textarea
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                            rows={3}
                            value={notification.data.notes}
                            onChange={(e) => notification.setData('notes', e.target.value)}
                            placeholder="Add submission method, officers informed, or regulator response..."
                        />
                    </div>

                    <div className="flex justify-end gap-2 pt-2">
                        <button
                            type="button"
                            className="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50"
                            onClick={() => setNotifying(null)}
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            className="btn-primary px-4 py-2 text-sm"
                            disabled={notification.processing}
                        >
                            Record Notification
                        </button>
                    </div>
                </form>
            </Modal>

            {/* Modal: Raise Action */}
            <Modal show={addingAction} onClose={() => setAddingAction(false)} maxWidth="lg">
                <form onSubmit={submitAction} className="space-y-4 p-6">
                    <h2 className="text-lg font-semibold text-gray-900">Raise Remedial Action</h2>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700">Type</label>
                            <select
                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                                value={action.data.action_type}
                                onChange={(e) => action.setData('action_type', e.target.value)}
                            >
                                {actionTypes.map((type) => (
                                    <option key={type} value={type}>
                                        {type}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700">Owner</label>
                            <select
                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                                value={action.data.owner_id}
                                onChange={(e) => action.setData('owner_id', e.target.value)}
                            >
                                <option value="">— Assign Owner —</option>
                                {users.map((user) => (
                                    <option key={user.id} value={user.id}>
                                        {user.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700">Title</label>
                        <input
                            type="text"
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                            value={action.data.title}
                            onChange={(e) => action.setData('title', e.target.value)}
                            required
                        />
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700">Due At</label>
                        <input
                            type="datetime-local"
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                            value={action.data.due_at}
                            onChange={(e) => action.setData('due_at', e.target.value)}
                            required
                        />
                    </div>

                    <label className="flex items-center gap-2 text-sm text-gray-700">
                        <input
                            type="checkbox"
                            className="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                            checked={action.data.is_mandatory}
                            onChange={(e) => action.setData('is_mandatory', e.target.checked)}
                        />
                        <span>Mandatory — the incident cannot close until this is completed and verified</span>
                    </label>

                    <div className="flex justify-end gap-2 pt-2">
                        <button
                            type="button"
                            className="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50"
                            onClick={() => setAddingAction(false)}
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            className="btn-primary px-4 py-2 text-sm"
                            disabled={action.processing}
                        >
                            Save Action
                        </button>
                    </div>
                </form>
            </Modal>

            {/* Modal: Triage */}
            <Modal show={triaging} onClose={() => setTriaging(false)} maxWidth="lg">
                <form onSubmit={submitTriage} className="space-y-4 p-6">
                    <h2 className="text-lg font-semibold text-gray-900">Triage Incident</h2>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700">Severity</label>
                            <select
                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                                value={triageForm.data.severity}
                                onChange={(e) => triageForm.setData('severity', e.target.value)}
                            >
                                {['Critical', 'High', 'Medium', 'Low'].map((s) => (
                                    <option key={s} value={s}>{s}</option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700">Type</label>
                            <select
                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                                value={triageForm.data.incident_type}
                                onChange={(e) => triageForm.setData('incident_type', e.target.value)}
                            >
                                {['operational', 'cyber', 'data_breach', 'fraud', 'conduct', 'third_party'].map((t) => (
                                    <option key={t} value={t}>{t.replace('_', ' ')}</option>
                                ))}
                            </select>
                        </div>
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700">Investigator</label>
                        <select
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                            value={triageForm.data.investigator_id}
                            onChange={(e) => triageForm.setData('investigator_id', e.target.value)}
                        >
                            <option value="">— Select Investigator —</option>
                            {users.map((u) => (
                                <option key={u.id} value={u.id}>{u.name}</option>
                            ))}
                        </select>
                    </div>

                    <div className="flex justify-end gap-2 pt-2">
                        <button
                            type="button"
                            className="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50"
                            onClick={() => setTriaging(false)}
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            className="btn-primary px-4 py-2 text-sm"
                            disabled={triageForm.processing}
                        >
                            Submit Triage
                        </button>
                    </div>
                </form>
            </Modal>

            {/* Modal: Update Loss */}
            <Modal show={editingLoss} onClose={() => setEditingLoss(false)} maxWidth="md">
                <form onSubmit={submitLoss} className="space-y-4 p-6">
                    <h2 className="text-lg font-semibold text-gray-900">Record Loss & Recovery</h2>

                    <div>
                        <label className="block text-sm font-medium text-gray-700">Currency</label>
                        <select
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                            value={lossForm.data.currency}
                            onChange={(e) => lossForm.setData('currency', e.target.value)}
                        >
                            {['NGN', 'USD', 'GBP', 'EUR', 'GHS', 'KES', 'ZAR'].map((curr) => (
                                <option key={curr} value={curr}>{curr}</option>
                            ))}
                        </select>
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700">Gross Loss (minor units)</label>
                        <input
                            type="number"
                            min="0"
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                            value={lossForm.data.gross_loss_minor}
                            onChange={(e) => lossForm.setData('gross_loss_minor', e.target.value)}
                        />
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700">Recovery (minor units)</label>
                        <input
                            type="number"
                            min="0"
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                            value={lossForm.data.recovery_minor}
                            onChange={(e) => lossForm.setData('recovery_minor', e.target.value)}
                        />
                    </div>

                    <div className="flex justify-end gap-2 pt-2">
                        <button
                            type="button"
                            className="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50"
                            onClick={() => setEditingLoss(false)}
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            className="btn-primary px-4 py-2 text-sm"
                            disabled={lossForm.processing}
                        >
                            Save Loss
                        </button>
                    </div>
                </form>
            </Modal>
        </AppLayout>
    );
}

function DetailRow({ label, value }) {
    return (
        <div className="flex items-start justify-between gap-4 border-b border-gray-50 pb-2">
            <span className="text-xs uppercase tracking-wide text-gray-500">{label}</span>
            <span className="text-right text-sm font-medium text-gray-800">{value || <span className="text-gray-400">—</span>}</span>
        </div>
    );
}
