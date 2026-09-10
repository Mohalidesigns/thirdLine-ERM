import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';

/**
 * One finding, its remediation and the decision not to remediate it.
 *
 * THE ACCEPTANCE FORM STATES ITS OWN LIMITS BEFORE IT IS FILLED IN. A form
 * that lets somebody write a justification and pick a three-year expiry, then
 * refuses on submit, has wasted their time and taught them the tool is
 * obstructive. The maximum period and the permission required are on the page
 * from the start, with the reason.
 *
 * THE LIFECYCLE IS WALKED, NOT JUMPED. There is no "close" button on an open
 * finding, because a finding closed by the person who owns it with nothing
 * behind it is how a remediation register becomes fiction. The path is: record
 * the plan, attach the evidence, send it for verification, then close.
 */
export default function Show({ finding, acceptances = [], acceptanceLimits = {}, can = {} }) {
    const [accepting, setAccepting] = useState(false);

    return (
        <AppLayout title={finding.reference}>
            <Head title={`${finding.reference} — ${finding.title}`} />

            <PageHeader
                title={finding.title}
                subtitle={
                    <span className="flex flex-wrap items-center gap-2">
                        <span className="font-mono text-xs">{finding.reference}</span>
                        <span className="text-gray-300">·</span>
                        <span>{finding.third_party}</span>
                        {finding.engagement && (
                            <>
                                <span className="text-gray-300">·</span>
                                <span>{finding.engagement}</span>
                            </>
                        )}
                    </span>
                }
                actions={
                    <Link href={route('tprm.findings.index')} className="btn btn-secondary">
                        Back to the board
                    </Link>
                }
            />

            <ClockBanner finding={finding} />

            <div className="grid gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <div className="card p-5">
                        <h3 className="text-sm font-semibold text-gray-900">What was found</h3>
                        <p className="mt-2 whitespace-pre-line text-sm text-gray-700">
                            {finding.description || 'No description recorded.'}
                        </p>

                        {finding.regulatory_citation && (
                            <p className="mt-3 text-xs text-gray-500">{finding.regulatory_citation}</p>
                        )}

                        {finding.control_refs.length > 0 && (
                            <p className="mt-1 text-xs text-gray-500">
                                Controls: {finding.control_refs.join(', ')}
                            </p>
                        )}
                    </div>

                    <RemediationPanel finding={finding} can={can} />

                    {acceptances.length > 0 && <AcceptanceHistory finding={finding} acceptances={acceptances} can={can} />}
                </div>

                <div className="space-y-6">
                    <FactsPanel finding={finding} />

                    {can.acceptRisk && finding.status !== 'closed_risk_accepted' && (
                        <div className="card p-5">
                            <h3 className="text-sm font-semibold text-gray-900">Accept this risk</h3>
                            <p className="mt-1 text-xs text-gray-600">
                                Accepting decides whether to remediate, not whether the gap exists — the finding
                                still counts at half weight in the vendor&rsquo;s residual score, and it reopens
                                by itself when the acceptance expires.
                            </p>
                            <p className="mt-2 text-xs text-gray-500">
                                A {finding.severity_label.toLowerCase()} finding may be accepted for at most{' '}
                                {acceptanceLimits.maximum_months} months.
                            </p>
                            <button type="button" className="btn btn-secondary mt-3 w-full text-xs"
                                onClick={() => setAccepting(true)}>
                                Record an acceptance
                            </button>
                        </div>
                    )}
                </div>
            </div>

            {accepting && (
                <AcceptanceDialog finding={finding} limits={acceptanceLimits} onClose={() => setAccepting(false)} />
            )}
        </AppLayout>
    );
}

function ClockBanner({ finding }) {
    if (finding.risk_accepted) {
        return (
            <Banner tone="neutral">
                This risk is accepted until {finding.acceptance_expires}. It still counts at half weight in the
                residual score, and it reopens automatically on that date — nothing has to be remembered.
            </Banner>
        );
    }

    if (!finding.is_overdue) {
        return null;
    }

    return (
        <Banner tone={finding.is_overdue_beyond ? 'critical' : 'warn'}>
            {finding.is_overdue_beyond ? (
                <>
                    <span className="font-medium">
                        Overdue by more than twice its {finding.sla_days}-day remediation SLA.
                    </span>{' '}
                    Its penalty in the residual score is multiplied by 1.5 — the register treats this as a
                    finding nobody is working, rather than one that is merely late.
                </>
            ) : (
                <>
                    Overdue since {finding.target_date}, {Math.abs(finding.days_until_target)} days ago.
                </>
            )}
        </Banner>
    );
}

function Banner({ tone, children }) {
    const classes = {
        critical: 'border-red-200 bg-red-50 text-red-900',
        warn: 'border-amber-200 bg-amber-50 text-amber-900',
        neutral: 'border-gray-200 bg-gray-50 text-gray-800',
    }[tone];

    return <div className={`mb-6 rounded-md border p-4 text-sm ${classes}`}>{children}</div>;
}

function FactsPanel({ finding }) {
    const rows = [
        ['Severity', finding.severity_label],
        ['Status', finding.status_label],
        ['Source', finding.source],
        ['Owner', finding.owner ?? 'Unassigned'],
        ['Identified', finding.identified_at],
        ['Target', finding.target_date ?? '—'],
        ['SLA', finding.sla_days ? `${finding.sla_days} days` : '—'],
        ['Verified by', finding.verified_by ?? '—'],
        ['Closure', finding.closure_type ?? '—'],
    ];

    return (
        <div className="card p-5">
            <h3 className="text-sm font-semibold text-gray-900">Finding</h3>
            <dl className="mt-3 space-y-2 text-sm">
                {rows.map(([label, value]) => (
                    <div key={label} className="flex justify-between gap-4">
                        <dt className="text-gray-500">{label}</dt>
                        <dd className="text-right text-gray-900">{value || '—'}</dd>
                    </div>
                ))}
            </dl>

            {finding.erm_issue && (
                <p className="mt-4 border-t border-gray-100 pt-4 text-xs text-gray-600">
                    Mirrored in the ERM issue register as{' '}
                    <span className="font-mono">{finding.erm_issue.reference}</span> ({finding.erm_issue.status}).
                    Closing either closes the other.
                </p>
            )}
        </div>
    );
}

function RemediationPanel({ finding, can }) {
    const planForm = useForm({ remediation_plan: finding.remediation_plan ?? '', vendor_response: finding.vendor_response ?? '' });
    const closeForm = useForm({ closure_type: 'remediated', evidence_document_id: '' });

    const canClose = finding.next_statuses.some((status) => status.value === 'closed_remediated');
    const canVerify = ['in_remediation', 'evidence_submitted'].includes(finding.status);

    return (
        <div className="card p-5">
            <h3 className="text-sm font-semibold text-gray-900">Remediation</h3>

            {can.manage ? (
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        planForm.post(route('tprm.findings.plan', finding.id));
                    }}
                    className="mt-3 space-y-3"
                >
                    <label className="block">
                        <span className="text-xs font-medium text-gray-700">The plan</span>
                        <textarea
                            rows={4}
                            className="input mt-1 text-sm"
                            placeholder="What the vendor will do, and by when."
                            value={planForm.data.remediation_plan}
                            onChange={(event) => planForm.setData('remediation_plan', event.target.value)}
                        />
                        <p className="mt-1 text-xs text-gray-500">
                            A finding inside its SLA with an accepted plan counts at half weight in the residual
                            score. Being early is not mitigation on its own — the discount is for a gap somebody
                            has decided how to fix.
                        </p>
                    </label>

                    <label className="block">
                        <span className="text-xs font-medium text-gray-700">The vendor&rsquo;s response</span>
                        <textarea
                            rows={3}
                            className="input mt-1 text-sm"
                            value={planForm.data.vendor_response}
                            onChange={(event) => planForm.setData('vendor_response', event.target.value)}
                        />
                    </label>

                    <div className="flex flex-wrap gap-2">
                        <button type="submit" className="btn btn-primary text-xs" disabled={planForm.processing}>
                            Save the plan
                        </button>

                        {canVerify && (
                            <button
                                type="button"
                                className="btn btn-secondary text-xs"
                                onClick={() => router.post(route('tprm.findings.verify', finding.id))}
                            >
                                Send for verification
                            </button>
                        )}
                    </div>
                </form>
            ) : (
                <p className="mt-2 whitespace-pre-line text-sm text-gray-700">
                    {finding.remediation_plan || 'No plan recorded yet.'}
                </p>
            )}

            {can.manage && canClose && (
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        closeForm.post(route('tprm.findings.close', finding.id));
                    }}
                    className="mt-5 border-t border-gray-100 pt-4"
                >
                    <h4 className="text-xs font-semibold text-gray-900">Close it</h4>
                    <div className="mt-2 flex flex-wrap items-end gap-2">
                        <label className="block">
                            <span className="text-xs text-gray-600">As</span>
                            <select
                                className="input mt-1 text-sm"
                                value={closeForm.data.closure_type}
                                onChange={(event) => closeForm.setData('closure_type', event.target.value)}
                            >
                                <option value="remediated">Remediated</option>
                                <option value="false_positive">A false positive</option>
                            </select>
                        </label>

                        <label className="block">
                            <span className="text-xs text-gray-600">Evidence document ID</span>
                            <input
                                type="number"
                                className="input mt-1 text-sm"
                                value={closeForm.data.evidence_document_id}
                                onChange={(event) => closeForm.setData('evidence_document_id', event.target.value)}
                            />
                        </label>

                        <button type="submit" className="btn btn-primary text-xs" disabled={closeForm.processing}>
                            Close
                        </button>
                    </div>
                    {['critical', 'high'].includes(finding.severity) && (
                        <p className="mt-2 text-xs text-gray-500">
                            A {finding.severity_label.toLowerCase()} finding needs evidence attached before it
                            can be closed as remediated.
                        </p>
                    )}
                </form>
            )}
        </div>
    );
}

function AcceptanceHistory({ finding, acceptances, can }) {
    return (
        <div className="card p-5">
            <h3 className="text-sm font-semibold text-gray-900">Risk acceptances</h3>
            <ul className="mt-3 divide-y divide-gray-100">
                {acceptances.map((acceptance) => (
                    <li key={acceptance.id} className="py-3 text-sm">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <span className={acceptance.in_force ? 'font-medium text-gray-900' : 'text-gray-500'}>
                                {acceptance.in_force
                                    ? `In force until ${acceptance.expires_at}`
                                    : `${acceptance.status} — expired ${acceptance.expires_at}`}
                            </span>
                            <span className="text-xs text-gray-500">
                                {acceptance.approver}{acceptance.approver_role ? `, ${acceptance.approver_role}` : ''}
                            </span>
                        </div>
                        <p className="mt-1 text-gray-700">{acceptance.justification}</p>
                        {acceptance.compensating_controls && (
                            <p className="mt-1 text-xs text-gray-600">
                                Compensating controls: {acceptance.compensating_controls}
                            </p>
                        )}
                        {acceptance.in_force && can.acceptRisk && (
                            <button
                                type="button"
                                className="btn btn-secondary mt-2 text-xs"
                                onClick={() => {
                                    const reason = window.prompt('Why is the acceptance being withdrawn?');
                                    if (reason) {
                                        router.post(
                                            route('tprm.findings.acceptances.withdraw', [finding.id, acceptance.id]),
                                            { reason },
                                        );
                                    }
                                }}
                            >
                                Withdraw
                            </button>
                        )}
                    </li>
                ))}
            </ul>
        </div>
    );
}

function AcceptanceDialog({ finding, limits, onClose }) {
    const form = useForm({
        justification: '',
        compensating_controls: '',
        residual_impact: '',
        approver_role: '',
        expires_at: '',
    });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('tprm.findings.accept-risk', finding.id), { onSuccess: onClose });
    };

    return (
        <div className="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-black/30 p-6">
            <form onSubmit={submit} className="card w-full max-w-2xl p-6">
                <h2 className="text-base font-semibold text-gray-900">
                    Accept the risk — {finding.reference}
                </h2>
                <p className="mt-1 text-sm text-gray-600">
                    This records a decision not to remediate, for a stated period. The finding stays on the
                    register at half weight, appears on the acceptance report, and reopens by itself when the
                    period ends.
                </p>
                <p className="mt-2 text-xs text-gray-500">
                    A {finding.severity_label.toLowerCase()} finding may be accepted for at most{' '}
                    {limits.maximum_months} months, and requires the{' '}
                    <span className="font-mono">{limits.permission}</span> permission.
                </p>

                <div className="mt-5 space-y-4">
                    <label className="block">
                        <span className="text-sm font-medium text-gray-700">Why is this acceptable?</span>
                        <textarea
                            rows={4}
                            className="input mt-1"
                            value={form.data.justification}
                            onChange={(event) => form.setData('justification', event.target.value)}
                        />
                        {form.errors.justification && (
                            <p className="mt-1 text-xs text-red-600">{form.errors.justification}</p>
                        )}
                    </label>

                    <label className="block">
                        <span className="text-sm font-medium text-gray-700">Compensating controls</span>
                        <textarea
                            rows={3}
                            className="input mt-1"
                            value={form.data.compensating_controls}
                            onChange={(event) => form.setData('compensating_controls', event.target.value)}
                        />
                    </label>

                    <div className="grid grid-cols-2 gap-4">
                        <label className="block">
                            <span className="text-sm font-medium text-gray-700">Expires</span>
                            <input
                                type="date"
                                className="input mt-1"
                                value={form.data.expires_at}
                                onChange={(event) => form.setData('expires_at', event.target.value)}
                            />
                            {form.errors.expires_at && (
                                <p className="mt-1 text-xs text-red-600">{form.errors.expires_at}</p>
                            )}
                        </label>

                        <label className="block">
                            <span className="text-sm font-medium text-gray-700">Approver role</span>
                            <input
                                type="text"
                                className="input mt-1"
                                placeholder="Chief Risk Officer"
                                value={form.data.approver_role}
                                onChange={(event) => form.setData('approver_role', event.target.value)}
                            />
                        </label>
                    </div>
                </div>

                <div className="mt-6 flex justify-end gap-2">
                    <button type="button" className="btn btn-secondary" onClick={onClose}>Cancel</button>
                    <button type="submit" className="btn btn-primary" disabled={form.processing}>
                        Record the acceptance
                    </button>
                </div>
            </form>
        </div>
    );
}
