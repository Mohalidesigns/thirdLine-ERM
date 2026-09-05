import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputError from '@/Components/InputError';
import KpiCard from '@/Components/KpiCard';
import PageHeader from '@/Components/PageHeader';
import { priorityTone, statusTone, titleCase } from './format';

const TABS = [
    { key: 'details', label: 'Details', icon: 'info' },
    { key: 'actions', label: 'Remediation', icon: 'checklist' },
    { key: 'progress', label: 'Progress', icon: 'timeline' },
    { key: 'attachments', label: 'Evidence', icon: 'attach_file' },
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

function Prose({ title, body }) {
    if (!body) return null;

    return (
        <Card title={title}>
            <p className="text-sm text-gray-700 whitespace-pre-line">{body}</p>
        </Card>
    );
}

/** Record progress against the issue. */
function ProgressForm({ issue, updateTypes }) {
    const { data, setData, post, processing, errors } = useForm({
        description: '',
        update_type: 'progress',
        progress_pct: issue.progressPct ?? 0,
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('risk.issues.add-update', issue.id), { preserveScroll: true, onSuccess: () => setData('description', '') });
    };

    return (
        <form onSubmit={submit} className="space-y-3">
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-3">
                <div>
                    <label className="block text-xs font-medium text-gray-600 mb-1">Type</label>
                    <select value={data.update_type} onChange={(e) => setData('update_type', e.target.value)} className={INPUT}>
                        {updateTypes.map((type) => <option key={type} value={type}>{titleCase(type)}</option>)}
                    </select>
                    <InputError message={errors.update_type} className="mt-1" />
                </div>
                <div>
                    <label className="block text-xs font-medium text-gray-600 mb-1">Progress %</label>
                    <input type="number" min="0" max="100" value={data.progress_pct} onChange={(e) => setData('progress_pct', e.target.value)} className={INPUT} />
                    <InputError message={errors.progress_pct} className="mt-1" />
                </div>
            </div>
            <div>
                <label className="block text-xs font-medium text-gray-600 mb-1">Update <span className="text-red-500">*</span></label>
                <textarea rows={3} value={data.description} onChange={(e) => setData('description', e.target.value)} className={INPUT} />
                <InputError message={errors.description} className="mt-1" />
            </div>
            <div className="flex justify-end">
                <button type="submit" disabled={processing} className="btn-primary text-sm disabled:opacity-50">Record Update</button>
            </div>
        </form>
    );
}

/** Add a remediation action. */
function ActionForm({ issue, users }) {
    const { data, setData, post, processing, errors } = useForm({ description: '', owner_id: '', target_date: '' });

    const submit = (e) => {
        e.preventDefault();
        post(route('risk.issues.add-action', issue.id), { preserveScroll: true, onSuccess: () => setData('description', '') });
    };

    return (
        <form onSubmit={submit} className="space-y-3">
            <div>
                <label className="block text-xs font-medium text-gray-600 mb-1">Action <span className="text-red-500">*</span></label>
                <textarea rows={2} value={data.description} onChange={(e) => setData('description', e.target.value)} className={INPUT} />
                <InputError message={errors.description} className="mt-1" />
            </div>
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-3">
                <div>
                    <label className="block text-xs font-medium text-gray-600 mb-1">Owner <span className="text-red-500">*</span></label>
                    <select value={data.owner_id} onChange={(e) => setData('owner_id', e.target.value)} className={INPUT}>
                        <option value="">Select</option>
                        {users.map((user) => <option key={user.id} value={user.id}>{user.name}</option>)}
                    </select>
                    <InputError message={errors.owner_id} className="mt-1" />
                </div>
                <div>
                    <label className="block text-xs font-medium text-gray-600 mb-1">Target Date <span className="text-red-500">*</span></label>
                    <input type="date" value={data.target_date} onChange={(e) => setData('target_date', e.target.value)} className={INPUT} />
                    <InputError message={errors.target_date} className="mt-1" />
                </div>
            </div>
            <div className="flex justify-end">
                <button type="submit" disabled={processing} className="btn-primary text-sm disabled:opacity-50">Add Action</button>
            </div>
        </form>
    );
}

/** Ask for the issue to be closed. */
function ClosureForm({ issue }) {
    const { data, setData, post, processing, errors } = useForm({ closure_justification: '', evidence_of_resolution: '' });

    const submit = (e) => {
        e.preventDefault();
        post(route('risk.issues.request-closure', issue.id), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="space-y-3">
            <div>
                <label className="block text-xs font-medium text-gray-600 mb-1">
                    Why this can be closed <span className="text-red-500">*</span>
                </label>
                <textarea rows={3} value={data.closure_justification} onChange={(e) => setData('closure_justification', e.target.value)} className={INPUT} />
                <InputError message={errors.closure_justification} className="mt-1" />
            </div>
            <div>
                <label className="block text-xs font-medium text-gray-600 mb-1">Evidence of Resolution</label>
                <textarea rows={2} value={data.evidence_of_resolution} onChange={(e) => setData('evidence_of_resolution', e.target.value)} className={INPUT} />
                <InputError message={errors.evidence_of_resolution} className="mt-1" />
            </div>
            <div className="flex justify-end">
                <button type="submit" disabled={processing} className="btn-primary text-sm disabled:opacity-50">Request Closure</button>
            </div>
        </form>
    );
}

/** Migration Phase 4.4: risk/issues/show.blade.php. */
export default function Show({ issue, can = {}, options = {}, users = [] }) {
    const [tab, setTab] = useState('details');

    const status = useForm({ issue_status: '', status_notes: '' });

    const changeStatus = (e) => {
        e.preventDefault();
        status.patch(route('risk.issues.update-status', issue.id), {
            preserveScroll: true,
            onSuccess: () => status.setData('issue_status', ''),
        });
    };

    return (
        <AuthenticatedLayout title={issue.reference}>
            <Head title={`${issue.reference} — ${issue.title}`} />

            <PageHeader
                title={issue.title}
                subtitle={`${issue.reference}${issue.businessUnit ? ` · ${issue.businessUnit}` : ''}`}
                breadcrumbs={[{ label: 'Issues', href: route('risk.issues.index') }, { label: issue.reference }]}
                actions={
                    can.update && (
                        <Link href={route('risk.issues.edit', issue.id)} className="btn-secondary inline-flex items-center gap-2 text-sm">
                            <span className="material-symbols-outlined text-lg">edit</span> Amend
                        </Link>
                    )
                }
            />

            {/* The overdue banner the Blade page could never show: it gated on
                `$issue->is_overdue`, which was neither a column nor an
                accessor, so it was null on every issue ever rendered. */}
            {issue.isOverdue && (
                <div className="mb-6 bg-red-50 border border-red-200 rounded-xl p-4 flex items-start gap-3">
                    <span className="material-symbols-outlined text-red-600">error</span>
                    <div>
                        <p className="text-sm font-semibold text-red-800">
                            Remediation is {issue.daysOverdue} {issue.daysOverdue === 1 ? 'day' : 'days'} overdue
                        </p>
                        <p className="text-xs text-red-700 mt-0.5">It was due on {issue.dueDate}.</p>
                    </div>
                </div>
            )}

            <div className="flex flex-wrap items-center gap-3 mb-6">
                <span className={`badge ${statusTone(issue.status)}`}>{titleCase(issue.status)}</span>
                <span className={`badge ${priorityTone(issue.priority)}`}>{titleCase(issue.priority)}</span>
                {issue.regulatoryReportable && <span className="badge bg-red-100 text-red-700">Regulatory</span>}
                {issue.cbnReportable && <span className="badge bg-orange-100 text-orange-700">CBN reportable</span>}
            </div>

            <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <KpiCard title="Progress" value={`${issue.progressPct}%`} icon="donut_large" color="primary" />
                <KpiCard title="Due" value={issue.dueDate ?? '—'} icon="event" color={issue.isOverdue ? 'danger' : 'info'} />
                <KpiCard title="Open Actions" value={issue.remediationActions.filter((a) => a.status !== 'completed').length} icon="checklist" color="warning" />
                <KpiCard title="Escalation Level" value={issue.escalationLevel ?? 0} icon="trending_up" color="danger" />
            </div>

            <div className="flex flex-wrap gap-1 border-b border-gray-200 mb-6">
                {TABS.map((entry) => (
                    <button
                        key={entry.key}
                        type="button"
                        onClick={() => setTab(entry.key)}
                        className={`inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors ${
                            tab === entry.key ? 'border-[#1A365D] text-[#1A365D]' : 'border-transparent text-gray-500 hover:text-gray-700'
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
                        <Prose title="What Was Found" body={issue.description} />
                        <Prose title="Root Cause" body={issue.rootCause} />
                        <Prose title="Impact" body={issue.impactDescription} />
                        <Prose title="Recommended Action" body={issue.recommendedAction} />
                        <Prose title="Management Response" body={issue.managementResponse} />
                        <Prose title="Action Plan" body={issue.actionPlan} />
                        <Prose title="Interim Controls" body={issue.interimControls} />

                        {can.update && (
                            <Card title="Status">
                                <form onSubmit={changeStatus} className="flex flex-wrap items-start gap-2">
                                    <div>
                                        <select
                                            value={status.data.issue_status}
                                            onChange={(e) => status.setData('issue_status', e.target.value)}
                                            className={`${INPUT} w-56`}
                                        >
                                            <option value="">Move to…</option>
                                            {(options.statuses ?? []).map((s) => <option key={s} value={s}>{titleCase(s)}</option>)}
                                        </select>
                                        <InputError message={status.errors.issue_status} className="mt-1" />
                                    </div>
                                    <div className="flex-1 min-w-[12rem]">
                                        <input
                                            type="text"
                                            value={status.data.status_notes}
                                            onChange={(e) => status.setData('status_notes', e.target.value)}
                                            placeholder="Note (optional)"
                                            className={INPUT}
                                        />
                                        <InputError message={status.errors.status_notes} className="mt-1" />
                                    </div>
                                    <button type="submit" disabled={status.processing || !status.data.issue_status} className="btn-primary text-sm disabled:opacity-50">
                                        Update
                                    </button>
                                </form>
                            </Card>
                        )}

                        {can.update && issue.status !== 'PENDING_CLOSURE' && issue.status !== 'CLOSED' && (
                            <Card title="Request Closure">
                                <ClosureForm issue={issue} />
                            </Card>
                        )}
                    </div>

                    <div>
                        <Card title="Issue">
                            <dl>
                                <Row label="Reference">{issue.reference}</Row>
                                <Row label="Source">{titleCase(issue.source)}</Row>
                                <Row label="Category">{issue.category ?? '—'}</Row>
                                <Row label="Owner">{issue.owner ?? '—'}</Row>
                                <Row label="Business unit">{issue.businessUnit ?? '—'}</Row>
                                <Row label="Department">{issue.department ?? '—'}</Row>
                                <Row label="Raised">{issue.createdAt ?? '—'}</Row>
                                <Row label="Due">{issue.dueDate ?? '—'}</Row>
                                <Row label="Response due">{issue.managementResponseDue ?? '—'}</Row>
                            </dl>
                        </Card>

                        <Card title="Regulatory">
                            <dl>
                                <Row label="Reportable">{issue.regulatoryReportable ? 'Yes' : 'No'}</Row>
                                <Row label="CBN reportable">{issue.cbnReportable ? 'Yes' : 'No'}</Row>
                                {/* The Blade page read `cbn_regulatory_deadline`;
                                    the column is `cbn_response_deadline`, so
                                    this never rendered. */}
                                <Row label="CBN response due">{issue.cbnResponseDeadline ?? '—'}</Row>
                                <Row label="Examination ref">{issue.examinationRef ?? '—'}</Row>
                            </dl>
                        </Card>

                        <Card title="Linked Risk">
                            {issue.risk ? (
                                <Link href={issue.risk.url} className="block p-3 bg-blue-50 rounded-lg hover:bg-blue-100">
                                    <p className="text-sm font-semibold text-[#1A365D]">{issue.risk.code}</p>
                                    <p className="text-xs text-gray-600 mt-1">{issue.risk.title}</p>
                                </Link>
                            ) : (
                                <p className="text-sm text-gray-400">Not linked to the register</p>
                            )}
                        </Card>

                        {issue.escalationLogs.length > 0 && (
                            <Card title="Escalations">
                                <ul className="divide-y divide-gray-100">
                                    {issue.escalationLogs.map((log) => (
                                        <li key={log.id} className="py-2">
                                            <p className="text-xs font-medium text-gray-800">
                                                Escalated to level {log.level}
                                                {log.escalatedTo ? ` · ${log.escalatedTo}` : ''}
                                                {log.isAutomatic && <span className="text-gray-400 font-normal"> (automatic)</span>}
                                            </p>
                                            <p className="text-[11px] text-gray-500 mt-0.5">
                                                {log.escalatedAt}{log.reason ? ` — ${log.reason}` : ''}
                                            </p>
                                        </li>
                                    ))}
                                </ul>
                            </Card>
                        )}
                    </div>
                </div>
            )}

            {tab === 'actions' && (
                <>
                    <Card title="Remediation Actions">
                        {issue.remediationActions.length === 0 ? (
                            <p className="text-sm text-gray-400 py-6 text-center">No remediation actions recorded.</p>
                        ) : (
                            <ul className="divide-y divide-gray-100">
                                {issue.remediationActions.map((action) => (
                                    <li key={action.id} className="py-3">
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="min-w-0">
                                                <p className="text-sm text-gray-800">
                                                    <span className="text-gray-400 font-mono text-xs mr-2">#{action.number}</span>
                                                    {action.description}
                                                </p>
                                                <p className="text-xs text-gray-500 mt-1">
                                                    {action.owner ?? 'Unassigned'} · due {action.targetDate ?? '—'}
                                                    {action.completedAt && ` · completed ${action.completedAt}`}
                                                </p>
                                                {action.completionNotes && (
                                                    <p className="text-xs text-gray-500 mt-1 italic">{action.completionNotes}</p>
                                                )}
                                            </div>
                                            <span className={`badge shrink-0 ${action.status === 'completed' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700'}`}>
                                                {titleCase(action.status)}
                                            </span>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>

                    {can.recordProgress && (
                        <Card title="Add an Action">
                            <ActionForm issue={issue} users={users} />
                        </Card>
                    )}
                </>
            )}

            {tab === 'progress' && (
                <>
                    <Card title="Progress Updates">
                        {issue.progressUpdates.length === 0 ? (
                            <p className="text-sm text-gray-400 py-6 text-center">No updates recorded.</p>
                        ) : (
                            <ul className="divide-y divide-gray-100">
                                {issue.progressUpdates.map((update) => (
                                    <li key={update.id} className="py-3">
                                        <div className="flex items-center justify-between gap-3">
                                            <span className="badge bg-gray-100 text-gray-600">{titleCase(update.type)}</span>
                                            <span className="text-xs text-gray-500">{update.createdAt}</span>
                                        </div>
                                        <p className="text-sm text-gray-700 mt-2 whitespace-pre-line">{update.content}</p>
                                        <p className="text-xs text-gray-500 mt-1">{update.author ?? 'System'}</p>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>

                    {can.recordProgress && (
                        <Card title="Record an Update">
                            <ProgressForm issue={issue} updateTypes={options.updateTypes ?? []} />
                        </Card>
                    )}
                </>
            )}

            {tab === 'attachments' && (
                <Card title="Evidence">
                    {issue.attachments.length === 0 ? (
                        <p className="text-sm text-gray-400 py-6 text-center">No evidence attached.</p>
                    ) : (
                        <ul className="divide-y divide-gray-100">
                            {issue.attachments.map((attachment) => (
                                <li key={attachment.id} className="flex items-center justify-between gap-4 py-3">
                                    <div className="min-w-0">
                                        <p className="text-sm font-medium text-gray-800 truncate">{attachment.name}</p>
                                        <p className="text-xs text-gray-500 mt-0.5">
                                            {titleCase(attachment.documentType)}
                                            {attachment.isRegulatory && ' · regulatory'}
                                        </p>
                                    </div>
                                    {/* A file response, not an Inertia visit. */}
                                    <a href={attachment.downloadUrl} className="text-xs text-[#1A365D] font-medium hover:underline shrink-0">
                                        Download
                                    </a>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            )}
        </AuthenticatedLayout>
    );
}
