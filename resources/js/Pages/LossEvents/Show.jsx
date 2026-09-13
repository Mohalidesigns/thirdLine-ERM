import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import InputError from '@thirdline/ui/Components/InputError';
import KpiCard from '@thirdline/ui/Components/KpiCard';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import { naira, severityTone, statusTone, titleCase } from './format';

const TABS = [
    { key: 'details', label: 'Details', icon: 'info' },
    { key: 'rca', label: 'Root Cause', icon: 'psychology' },
    { key: 'controls', label: 'Failed Controls', icon: 'shield' },
    { key: 'attachments', label: 'Attachments', icon: 'attach_file' },
    { key: 'approvals', label: 'Approvals', icon: 'approval' },
];

const INPUT = 'w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]';

function Card({ title, actions, children }) {
    return (
        <div className="bg-white rounded-xl border border-gray-200 overflow-hidden mb-6">
            <div className="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                <h3 className="text-sm font-semibold text-[#1A365D]">{title}</h3>
                {actions}
            </div>
            <div className="p-5">{children}</div>
        </div>
    );
}

function Row({ label, children }) {
    return (
        <div className="flex justify-between items-start gap-4 py-1.5">
            <dt className="text-xs text-gray-500 shrink-0">{label}</dt>
            <dd className="text-xs font-medium text-right">{children}</dd>
        </div>
    );
}

/** Record or revise the root cause analysis. */
function RcaForm({ event, options, onDone }) {
    const { data, setData, post, processing, errors } = useForm({
        root_cause_category: event.rca?.category ?? '',
        root_cause_description: event.rca?.description ?? '',
        contributing_factors: event.rca?.contributingFactors ?? '',
        methodology: event.rca?.methodology ?? '',
        analysis_details: event.rca?.analysisDetails ?? '',
        recommendations: event.rca?.recommendations ?? '',
        lessons_learned: event.rca?.lessonsLearned ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('risk.loss-events.store-rca', event.id), { preserveScroll: true, onSuccess: onDone });
    };

    const field = (name, label, rows = 3) => (
        <div className={rows > 0 ? 'lg:col-span-2' : ''}>
            <label className="block text-xs font-medium text-gray-600 mb-1">{label}</label>
            <textarea rows={rows} value={data[name]} onChange={(e) => setData(name, e.target.value)} className={INPUT} />
            <InputError message={errors[name]} className="mt-1" />
        </div>
    );

    return (
        <form onSubmit={submit} className="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div>
                <label className="block text-xs font-medium text-gray-600 mb-1">Root Cause Category <span className="text-red-500">*</span></label>
                <select value={data.root_cause_category} onChange={(e) => setData('root_cause_category', e.target.value)} className={INPUT}>
                    <option value="">Select</option>
                    {(options.rcaCategories ?? []).map((c) => <option key={c} value={c}>{titleCase(c)}</option>)}
                </select>
                <InputError message={errors.root_cause_category} className="mt-1" />
            </div>

            <div>
                <label className="block text-xs font-medium text-gray-600 mb-1">Methodology <span className="text-red-500">*</span></label>
                <select value={data.methodology} onChange={(e) => setData('methodology', e.target.value)} className={INPUT}>
                    <option value="">Select</option>
                    {(options.rcaMethodologies ?? []).map((m) => <option key={m} value={m}>{titleCase(m)}</option>)}
                </select>
                <InputError message={errors.methodology} className="mt-1" />
            </div>

            <div className="lg:col-span-2">
                <label className="block text-xs font-medium text-gray-600 mb-1">Root Cause <span className="text-red-500">*</span></label>
                <textarea rows={4} value={data.root_cause_description} onChange={(e) => setData('root_cause_description', e.target.value)} className={INPUT} />
                <InputError message={errors.root_cause_description} className="mt-1" />
            </div>

            {field('contributing_factors', 'Contributing Factors')}
            {field('analysis_details', 'Analysis Detail', 4)}
            {field('recommendations', 'Recommendations')}
            {field('lessons_learned', 'Lessons Learned')}

            <div className="lg:col-span-2 flex justify-end gap-2">
                <button type="button" onClick={onDone} className="btn-secondary text-sm">Cancel</button>
                <button type="submit" disabled={processing} className="btn-primary text-sm disabled:opacity-50">Save Analysis</button>
            </div>
        </form>
    );
}

/** Move the event along its lifecycle. */
function StatusForm({ event, statuses }) {
    const { data, setData, patch, processing, errors } = useForm({ status: '', status_notes: '' });

    const submit = (e) => {
        e.preventDefault();
        patch(route('risk.loss-events.update-status', event.id), { preserveScroll: true, onSuccess: () => setData('status', '') });
    };

    return (
        <form onSubmit={submit} className="flex flex-wrap items-start gap-2">
            <div>
                <select value={data.status} onChange={(e) => setData('status', e.target.value)} className={`${INPUT} w-56`}>
                    <option value="">Move to…</option>
                    {statuses.map((status) => <option key={status} value={status}>{titleCase(status)}</option>)}
                </select>
                <InputError message={errors.status} className="mt-1" />
            </div>
            <div className="flex-1 min-w-[12rem]">
                <input type="text" value={data.status_notes} onChange={(e) => setData('status_notes', e.target.value)} placeholder="Note (optional)" className={INPUT} />
                <InputError message={errors.status_notes} className="mt-1" />
            </div>
            <button type="submit" disabled={processing || !data.status} className="btn-primary text-sm disabled:opacity-50">Update</button>
        </form>
    );
}

/** Record an approval decision. */
function ApprovalForm({ event }) {
    const { data, setData, post, processing, errors } = useForm({
        decision: 'approved',
        comments: '',
        rejection_reason: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('risk.loss-events.submit-approval', event.id), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="space-y-3">
            <div className="flex flex-wrap gap-4">
                {['approved', 'rejected', 'escalated'].map((decision) => (
                    <label key={decision} className="flex items-center gap-2 text-sm text-gray-700">
                        <input
                            type="radio"
                            name="decision"
                            value={decision}
                            checked={data.decision === decision}
                            onChange={(e) => setData('decision', e.target.value)}
                            className="text-[#1A365D]"
                        />
                        {titleCase(decision)}
                    </label>
                ))}
            </div>
            <InputError message={errors.decision} />

            {data.decision === 'rejected' ? (
                <div>
                    <label className="block text-xs font-medium text-gray-600 mb-1">Reason for rejection <span className="text-red-500">*</span></label>
                    <textarea rows={3} value={data.rejection_reason} onChange={(e) => setData('rejection_reason', e.target.value)} className={INPUT} />
                    <InputError message={errors.rejection_reason} className="mt-1" />
                </div>
            ) : (
                <div>
                    <label className="block text-xs font-medium text-gray-600 mb-1">Comments</label>
                    <textarea rows={3} value={data.comments} onChange={(e) => setData('comments', e.target.value)} className={INPUT} />
                    <InputError message={errors.comments} className="mt-1" />
                </div>
            )}

            <div className="flex justify-end">
                <button type="submit" disabled={processing} className="btn-primary text-sm disabled:opacity-50">Record Decision</button>
            </div>
        </form>
    );
}

/** Migration Phase 4.3: risk/loss-events/show.blade.php (687 lines of Blade). */
export default function Show({ event, can = {}, options = {} }) {
    const [tab, setTab] = useState(new URLSearchParams(window.location.search).get('tab') ?? 'details');
    const [editingRca, setEditingRca] = useState(false);

    const approveRca = useForm({});

    return (
        <AuthenticatedLayout title={event.reference}>
            <Head title={`${event.reference} — ${event.title}`} />

            <PageHeader
                title={event.title}
                subtitle={`${event.reference}${event.businessUnit ? ` · ${event.businessUnit}` : ''}`}
                breadcrumbs={[{ label: 'Loss Events', href: route('risk.loss-events.index') }, { label: event.reference }]}
                actions={
                    can.update && (
                        <Link href={route('risk.loss-events.edit', event.id)} className="btn-secondary inline-flex items-center gap-2 text-sm">
                            <span className="material-symbols-outlined text-lg">edit</span> Amend
                        </Link>
                    )
                }
            />

            <div className="flex flex-wrap items-center gap-3 mb-6">
                <span className={`badge ${statusTone(event.status)}`}>{titleCase(event.status)}</span>
                <span className={`badge ${severityTone(event.severity)}`}>{titleCase(event.severity)}</span>
                {event.isNearMiss && <span className="badge bg-blue-100 text-blue-700">Near miss</span>}
                {event.isRegulatoryReportable && <span className="badge bg-red-100 text-red-700">Regulatory reportable</span>}
            </div>

            <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <KpiCard title="Gross Loss" value={naira(event.grossLoss, event.currency)} icon="trending_down" color="danger" />
                <KpiCard title="Insurance Recovery" value={naira(event.insuranceRecovery, event.currency)} icon="shield" color="info" />
                <KpiCard title="Other Recovery" value={naira(event.otherRecovery, event.currency)} icon="savings" color="success" />
                <KpiCard title="Net Loss" value={naira(event.netLoss, event.currency)} icon="account_balance" color="warning" />
            </div>

            <div className="flex flex-wrap gap-1 border-b border-gray-200 mb-6">
                {TABS.map((entry) => (
                    <button
                        key={entry.key}
                        type="button"
                        onClick={() => setTab(entry.key)}
                        className={`inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors ${
                            tab === entry.key
                                ? 'border-[#1A365D] text-[#1A365D]'
                                : 'border-transparent text-gray-500 hover:text-gray-700'
                        }`}
                    >
                        <span className="material-symbols-outlined text-lg">{entry.icon}</span>
                        {entry.label}
                    </button>
                ))}
            </div>

            {tab === 'details' && (
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div className="lg:col-span-2">
                        <Card title="What Happened">
                            <p className="text-sm text-gray-700 whitespace-pre-line">{event.description}</p>
                        </Card>

                        {event.initialRootCause && (
                            <Card title="Initial Root Cause">
                                <p className="text-sm text-gray-700 whitespace-pre-line">{event.initialRootCause}</p>
                            </Card>
                        )}

                        {event.correctiveActionSummary && (
                            <Card title="Corrective Action">
                                <p className="text-sm text-gray-700 whitespace-pre-line">{event.correctiveActionSummary}</p>
                            </Card>
                        )}

                        {can.update && (
                            <Card title="Status">
                                <StatusForm event={event} statuses={options.statuses ?? []} />
                            </Card>
                        )}
                    </div>

                    <div>
                        <Card title="Event">
                            <dl>
                                <Row label="Reference">{event.reference}</Row>
                                <Row label="Date of loss">{event.dateOfLoss ?? '—'}</Row>
                                <Row label="Discovered">{event.dateDiscovered ?? '—'}</Row>
                                <Row label="Business unit">{event.businessUnit ?? '—'}</Row>
                                <Row label="Reported by">{event.reporter ?? '—'}</Row>
                                <Row label="Basel category">{titleCase(event.baselCategory)}</Row>
                                <Row label="CBN category">{titleCase(event.cbnCategory)}</Row>
                                <Row label="Loss category">{titleCase(event.lossCategory)}</Row>
                            </dl>
                        </Card>

                        <Card title="Regulatory">
                            <dl>
                                <Row label="Reportable">{event.isRegulatoryReportable ? 'Yes' : 'No'}</Row>
                                <Row label="Body">{event.regulatoryBody ?? '—'}</Row>
                                <Row label="Deadline">{event.reportingDeadline ?? '—'}</Row>
                                <Row label="CBN">{event.cbnReportable ? 'Reportable' : '—'}</Row>
                                <Row label="NFIU">{event.nfiuReportable ? 'Reportable' : '—'}</Row>
                            </dl>
                        </Card>

                        <Card title="Linked Risk">
                            {event.risk ? (
                                <Link href={event.risk.url} className="block p-3 bg-blue-50 rounded-lg hover:bg-blue-100">
                                    <p className="text-sm font-semibold text-[#1A365D]">{event.risk.code}</p>
                                    <p className="text-xs text-gray-600 mt-1">{event.risk.title}</p>
                                </Link>
                            ) : (
                                <p className="text-sm text-gray-400">Not linked to the register</p>
                            )}
                        </Card>
                    </div>
                </div>
            )}

            {tab === 'rca' && (
                <Card
                    title="Root Cause Analysis"
                    actions={
                        <div className="flex items-center gap-2">
                            {event.rca?.status && <span className="badge bg-gray-100 text-gray-600">{titleCase(event.rca.status)}</span>}
                            {can.recordRca && !editingRca && (
                                <button type="button" onClick={() => setEditingRca(true)} className="btn-secondary text-xs">
                                    {event.rca ? 'Revise' : 'Record analysis'}
                                </button>
                            )}
                            {can.approveRca && event.rca && event.rca.status !== 'APPROVED' && (
                                <button
                                    type="button"
                                    disabled={approveRca.processing}
                                    onClick={() => approveRca.post(route('risk.loss-events.approve-rca', event.id), { preserveScroll: true })}
                                    className="btn-primary text-xs disabled:opacity-50"
                                >
                                    Approve
                                </button>
                            )}
                        </div>
                    }
                >
                    {editingRca ? (
                        <RcaForm event={event} options={options} onDone={() => setEditingRca(false)} />
                    ) : event.rca ? (
                        <dl className="space-y-4">
                            <div>
                                <dt className="text-xs text-gray-500 mb-1">Root cause ({titleCase(event.rca.category)}, {titleCase(event.rca.methodology)})</dt>
                                <dd className="text-sm text-gray-700 whitespace-pre-line">{event.rca.description}</dd>
                            </div>
                            {['contributingFactors', 'analysisDetails', 'recommendations', 'lessonsLearned']
                                .filter((key) => event.rca[key])
                                .map((key) => (
                                    <div key={key}>
                                        <dt className="text-xs text-gray-500 mb-1">
                                            {titleCase(key.replace(/([A-Z])/g, ' $1'))}
                                        </dt>
                                        <dd className="text-sm text-gray-700 whitespace-pre-line">{event.rca[key]}</dd>
                                    </div>
                                ))}
                        </dl>
                    ) : (
                        <div className="text-center py-8 text-gray-400">
                            <span className="material-symbols-outlined text-3xl mb-2 block">psychology</span>
                            <p className="text-sm">No root cause analysis recorded yet.</p>
                        </div>
                    )}
                </Card>
            )}

            {tab === 'controls' && (
                <Card title="Controls That Failed">
                    {event.failedControls.length === 0 ? (
                        <p className="text-sm text-gray-400 py-6 text-center">No failed controls recorded against this event.</p>
                    ) : (
                        <ul className="divide-y divide-gray-100">
                            {event.failedControls.map((control) => (
                                <li key={control.id} className="py-3">
                                    <p className="text-sm font-medium text-[#1A365D]">{control.name ?? '—'}</p>
                                    <p className="text-xs text-gray-400">{control.code}</p>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            )}

            {tab === 'attachments' && (
                <Card title="Attachments">
                    {event.attachments.length === 0 ? (
                        <p className="text-sm text-gray-400 py-6 text-center">No documents attached.</p>
                    ) : (
                        <ul className="divide-y divide-gray-100">
                            {event.attachments.map((attachment) => (
                                <li key={attachment.id} className="flex items-center justify-between gap-4 py-3">
                                    <div className="min-w-0">
                                        <p className="text-sm font-medium text-gray-800 truncate">{attachment.name}</p>
                                        <p className="text-xs text-gray-500 mt-0.5">
                                            {titleCase(attachment.documentType)}
                                            {attachment.isRegulatory && ' · regulatory'}
                                        </p>
                                    </div>
                                    {/* A plain anchor: the download is a file
                                        response, not an Inertia visit. */}
                                    <a href={attachment.downloadUrl} className="text-xs text-[#1A365D] font-medium hover:underline shrink-0">
                                        Download
                                    </a>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            )}

            {tab === 'approvals' && (
                <>
                    <Card title="Approval History">
                        {event.approvals.length === 0 ? (
                            <p className="text-sm text-gray-400 py-6 text-center">No decisions recorded.</p>
                        ) : (
                            <ul className="divide-y divide-gray-100">
                                {event.approvals.map((approval) => (
                                    <li key={approval.id} className="py-3">
                                        <div className="flex items-center justify-between gap-3">
                                            <span className="text-sm font-medium text-gray-800">
                                                {titleCase(approval.decision)}
                                                <span className="text-gray-400 font-normal"> · {titleCase(approval.stage)}</span>
                                            </span>
                                            <span className="text-xs text-gray-500">{approval.actionedAt ?? '—'}</span>
                                        </div>
                                        <p className="text-xs text-gray-500 mt-1">
                                            {approval.actionedBy ?? 'System'}
                                            {approval.comments ? ` — ${approval.comments}` : ''}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>

                    {can.approve && (
                        <Card title="Record a Decision">
                            <ApprovalForm event={event} />
                        </Card>
                    )}
                </>
            )}
        </AuthenticatedLayout>
    );
}
