import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';

/**
 * One engagement's connections and access grants — FR-ACC-01, FR-ACC-02, and
 * the termination verdict from AC-10.
 *
 * THE TERMINATION VERDICT SITS AT THE TOP, not behind the terminate button.
 * The thing a person needs to know before they start closing an engagement is
 * what will stop them, and discovering it at the end — after the offboarding
 * pack has gone out — is how a relationship ends up "terminated" in one system
 * and live in the firewall.
 */
export default function Engagement({
    engagement = {}, connections = [], grants = [], terminationVerdict = {},
    connectionTypes = [], accessLevels = [], can = {},
}) {
    const [addingConnection, setAddingConnection] = useState(false);
    const [addingGrant, setAddingGrant] = useState(false);
    const [closing, setClosing] = useState(null);
    const [revoking, setRevoking] = useState(null);

    return (
        <AppLayout title="Access">
            <Head title={`Access — ${engagement.name ?? ''}`} />

            <PageHeader
                title="Connections and access"
                subtitle={`${engagement.third_party ?? ''} · ${engagement.name ?? ''} (${engagement.status_label ?? ''})`}
            />

            <TerminationPanel verdict={terminationVerdict} />

            <section className="mt-6">
                <div className="mb-2 flex items-center justify-between">
                    <div>
                        <h2 className="text-sm font-semibold text-gray-900">Connections</h2>
                        <p className="text-xs text-gray-600">
                            Every technical path between us and this vendor. Closing one needs evidence that the
                            path is gone, not a status change.
                        </p>
                    </div>
                    {can.manage && (
                        <button type="button" className="btn btn-sm btn-secondary" onClick={() => setAddingConnection(true)}>
                            Record connection
                        </button>
                    )}
                </div>

                {connections.length === 0 ? (
                    <div className="card p-6 text-center text-sm text-gray-500">
                        No connections recorded. If this vendor touches a system of ours, something here is missing —
                        an unrecorded path cannot be reconciled when the contract ends.
                    </div>
                ) : (
                    <div className="card overflow-x-auto">
                        <table className="min-w-full divide-y divide-gray-200 text-sm">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Name</th>
                                    <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Type</th>
                                    <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Endpoint</th>
                                    <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Encryption</th>
                                    <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Owner</th>
                                    <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Status</th>
                                    {can.manage && <th scope="col" className="px-4 py-2" />}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {connections.map((row) => (
                                    <tr key={row.id}>
                                        <th scope="row" className="px-4 py-2 text-left font-medium text-gray-900">{row.name}</th>
                                        <td className="px-4 py-2">{row.type_label}</td>
                                        <td className="px-4 py-2 font-mono text-xs text-gray-600">{row.endpoint ?? '—'}</td>
                                        <td className="px-4 py-2 text-xs">{row.encryption ?? '—'}</td>
                                        <td className="px-4 py-2 text-xs">{row.owner ?? '—'}</td>
                                        <td className="px-4 py-2">
                                            <Pill tone={row.status_color}>{row.status_label}</Pill>
                                            {row.status === 'closed' && !row.has_closure_evidence && (
                                                <span className="ml-1 text-xs text-amber-700">no evidence</span>
                                            )}
                                        </td>
                                        {can.manage && (
                                            <td className="px-4 py-2 text-right">
                                                {row.status !== 'closed' && (
                                                    <button
                                                        type="button"
                                                        className="btn btn-sm btn-ghost"
                                                        onClick={() => setClosing(row)}
                                                    >
                                                        Close
                                                    </button>
                                                )}
                                            </td>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>

            <section className="mt-8">
                <div className="mb-2 flex items-center justify-between">
                    <div>
                        <h2 className="text-sm font-semibold text-gray-900">Access grants</h2>
                        <p className="text-xs text-gray-600">
                            Named people at the vendor, with an approver, a window and a monitoring method — CBN
                            Cyber Framework, Appendix III §1.3.
                        </p>
                    </div>
                    {can.manage && (
                        <button type="button" className="btn btn-sm btn-secondary" onClick={() => setAddingGrant(true)}>
                            Grant access
                        </button>
                    )}
                </div>

                {grants.length === 0 ? (
                    <div className="card p-6 text-center text-sm text-gray-500">
                        Nobody at this vendor holds a recorded login.
                    </div>
                ) : (
                    <div className="card overflow-x-auto">
                        <table className="min-w-full divide-y divide-gray-200 text-sm">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Grantee</th>
                                    <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">System</th>
                                    <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Access</th>
                                    <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Window</th>
                                    <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Approved by</th>
                                    <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Status</th>
                                    {can.manage && <th scope="col" className="px-4 py-2" />}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {grants.map((row) => (
                                    <tr key={row.id}>
                                        <th scope="row" className="px-4 py-2 text-left font-medium text-gray-900">
                                            {row.grantee_name}
                                            {row.escort_required && (
                                                <span className="block text-xs font-normal text-gray-500">escort required</span>
                                            )}
                                        </th>
                                        <td className="px-4 py-2">{row.system_name}</td>
                                        <td className="px-4 py-2">
                                            <span className={row.is_privileged ? 'font-semibold text-red-700' : ''}>
                                                {row.access_level_label}
                                            </span>
                                        </td>
                                        <td className="px-4 py-2 text-xs">
                                            {row.valid_from ?? '—'} → {row.valid_to ?? (
                                                <span className="text-amber-700">no end date</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-2 text-xs">
                                            {row.approved_by ?? <span className="text-amber-700">not approved</span>}
                                        </td>
                                        <td className="px-4 py-2">
                                            <Pill tone={row.status_color}>{row.status_label}</Pill>
                                        </td>
                                        {can.manage && (
                                            <td className="px-4 py-2 text-right">
                                                {row.status === 'requested' && (
                                                    <button
                                                        type="button"
                                                        className="btn btn-sm btn-ghost"
                                                        onClick={() => router.post(route('tprm.access.grants.approve', row.id))}
                                                    >
                                                        Approve
                                                    </button>
                                                )}
                                                {row.status !== 'revoked' && (
                                                    <button
                                                        type="button"
                                                        className="btn btn-sm btn-ghost"
                                                        onClick={() => setRevoking(row)}
                                                    >
                                                        Revoke
                                                    </button>
                                                )}
                                            </td>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>

            {addingConnection && (
                <ConnectionDialog
                    engagement={engagement}
                    types={connectionTypes}
                    onClose={() => setAddingConnection(false)}
                />
            )}
            {addingGrant && (
                <GrantDialog
                    engagement={engagement}
                    levels={accessLevels}
                    connections={connections.filter((row) => row.status !== 'closed')}
                    onClose={() => setAddingGrant(false)}
                />
            )}
            {closing && <CloseDialog connection={closing} onClose={() => setClosing(null)} />}
            {revoking && <RevokeDialog grant={revoking} onClose={() => setRevoking(null)} />}
        </AppLayout>
    );
}

function Pill({ tone, children }) {
    const classes = {
        critical: 'bg-red-50 text-red-800',
        high: 'bg-orange-50 text-orange-800',
        medium: 'bg-amber-50 text-amber-800',
        low: 'bg-green-50 text-green-800',
    }[tone] ?? 'bg-gray-100 text-gray-700';

    return <span className={`rounded px-1.5 py-0.5 text-xs font-medium ${classes}`}>{children}</span>;
}

/** AC-10, stated before somebody starts rather than after. */
function TerminationPanel({ verdict }) {
    if (verdict.allowed) {
        return (
            <div className="card border-l-4 border-green-500 p-4">
                <h2 className="text-sm font-semibold text-green-800">Clear to terminate</h2>
                <p className="mt-0.5 text-xs text-gray-600">
                    Nothing is plugged in. Every connection is closed and every grant revoked with evidence.
                    {verdict.excepted?.length > 0 && ` ${verdict.excepted.length} item(s) released by an approved exception.`}
                </p>
            </div>
        );
    }

    return (
        <div className="card border-l-4 border-red-500 p-4">
            <h2 className="text-sm font-semibold text-red-800">Termination is blocked</h2>
            <p className="mt-0.5 text-xs text-gray-600">{verdict.reason}</p>
            <ul className="mt-3 space-y-2">
                {(verdict.blockers ?? []).map((blocker) => (
                    <li key={`${blocker.kind}-${blocker.id}`} className="text-sm">
                        <span className="font-medium text-gray-900">{blocker.label}</span>
                        <span className="block text-xs text-gray-600">{blocker.action}</span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function ConnectionDialog({ engagement, types, onClose }) {
    const form = useForm({
        type: types[0]?.value ?? 'api',
        name: '',
        endpoint: '',
        direction: 'bidirectional',
        data_flows: '',
        encryption: '',
        authentication_method: '',
        firewall_rule_ref: '',
    });

    const selected = types.find((type) => type.value === form.data.type);

    return (
        <Dialog title="Record a connection" onClose={onClose} onSubmit={(event) => {
            event.preventDefault();
            form.post(route('tprm.access.connections.store', engagement.uuid), { onSuccess: onClose });
        }} processing={form.processing}>
            <Field label="Type" error={form.errors.type}>
                <select className="form-select w-full" value={form.data.type}
                    onChange={(event) => form.setData('type', event.target.value)}>
                    {types.map((type) => <option key={type.value} value={type.value}>{type.label}</option>)}
                </select>
            </Field>
            {selected && (
                <p className="-mt-2 mb-3 text-xs text-gray-500">
                    Closing this later will need: {selected.closure_hint}
                </p>
            )}
            <Field label="Name" error={form.errors.name}>
                <input className="form-input w-full" value={form.data.name}
                    onChange={(event) => form.setData('name', event.target.value)} />
            </Field>
            <Field label="Endpoint" error={form.errors.endpoint}>
                <input className="form-input w-full font-mono text-sm" value={form.data.endpoint}
                    onChange={(event) => form.setData('endpoint', event.target.value)} />
            </Field>
            <Field label="Direction" error={form.errors.direction}>
                <select className="form-select w-full" value={form.data.direction}
                    onChange={(event) => form.setData('direction', event.target.value)}>
                    <option value="inbound">Inbound</option>
                    <option value="outbound">Outbound</option>
                    <option value="bidirectional">Bidirectional</option>
                </select>
            </Field>
            <Field label="Encryption" error={form.errors.encryption}>
                <input className="form-input w-full" value={form.data.encryption}
                    onChange={(event) => form.setData('encryption', event.target.value)} />
            </Field>
            <Field label="Authentication" error={form.errors.authentication_method}>
                <input className="form-input w-full" value={form.data.authentication_method}
                    onChange={(event) => form.setData('authentication_method', event.target.value)} />
            </Field>
            <Field label="Firewall rule reference" error={form.errors.firewall_rule_ref}>
                <input className="form-input w-full" value={form.data.firewall_rule_ref}
                    onChange={(event) => form.setData('firewall_rule_ref', event.target.value)} />
            </Field>
            <Field label="Data flows" error={form.errors.data_flows}>
                <textarea className="form-textarea w-full" rows="2" value={form.data.data_flows}
                    onChange={(event) => form.setData('data_flows', event.target.value)} />
            </Field>
        </Dialog>
    );
}

function GrantDialog({ engagement, levels, connections, onClose }) {
    const form = useForm({
        grantee_name: '',
        grantee_email: '',
        system_name: '',
        access_level: 'read',
        justification: '',
        connection_id: '',
        valid_from: new Date().toISOString().slice(0, 10),
        valid_to: '',
        escort_required: false,
        monitoring_method: '',
    });

    const privileged = levels.find((level) => level.value === form.data.access_level)?.privileged;

    return (
        <Dialog title="Grant access" onClose={onClose} onSubmit={(event) => {
            event.preventDefault();
            form.post(route('tprm.access.grants.store', engagement.uuid), { onSuccess: onClose });
        }} processing={form.processing}>
            <Field label="Grantee" error={form.errors.grantee_name}>
                <input className="form-input w-full" value={form.data.grantee_name}
                    onChange={(event) => form.setData('grantee_name', event.target.value)} />
            </Field>
            <Field label="Grantee email" error={form.errors.grantee_email}>
                <input type="email" className="form-input w-full" value={form.data.grantee_email}
                    onChange={(event) => form.setData('grantee_email', event.target.value)} />
            </Field>
            <Field label="System" error={form.errors.system_name}>
                <input className="form-input w-full" value={form.data.system_name}
                    onChange={(event) => form.setData('system_name', event.target.value)} />
            </Field>
            <Field label="Access level" error={form.errors.access_level}>
                <select className="form-select w-full" value={form.data.access_level}
                    onChange={(event) => form.setData('access_level', event.target.value)}>
                    {levels.map((level) => <option key={level.value} value={level.value}>{level.label}</option>)}
                </select>
            </Field>
            {connections.length > 0 && (
                <Field label="Over which connection" error={form.errors.connection_id}>
                    <select className="form-select w-full" value={form.data.connection_id}
                        onChange={(event) => form.setData('connection_id', event.target.value)}>
                        <option value="">Not tied to one</option>
                        {connections.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}
                    </select>
                </Field>
            )}
            <div className="grid grid-cols-2 gap-3">
                <Field label="Valid from" error={form.errors.valid_from}>
                    <input type="date" className="form-input w-full" value={form.data.valid_from}
                        onChange={(event) => form.setData('valid_from', event.target.value)} />
                </Field>
                <Field label="Valid to" error={form.errors.valid_to}>
                    <input type="date" className="form-input w-full" value={form.data.valid_to}
                        onChange={(event) => form.setData('valid_to', event.target.value)} />
                </Field>
            </div>
            <Field label="Justification" error={form.errors.justification}>
                <textarea className="form-textarea w-full" rows="2" value={form.data.justification}
                    onChange={(event) => form.setData('justification', event.target.value)} />
            </Field>
            {privileged && (
                <Field label="How is this monitored?" error={form.errors.monitoring_method}>
                    <input className="form-input w-full" value={form.data.monitoring_method}
                        onChange={(event) => form.setData('monitoring_method', event.target.value)} />
                </Field>
            )}
            <label className="flex items-center gap-2 text-sm">
                <input type="checkbox" className="form-checkbox" checked={form.data.escort_required}
                    onChange={(event) => form.setData('escort_required', event.target.checked)} />
                Escort or supervision required
            </label>
        </Dialog>
    );
}

function CloseDialog({ connection, onClose }) {
    const form = useForm({ closure_evidence_document_id: '', reason: '' });
    const needsEvidence = ['active', 'suspended'].includes(connection.status);

    return (
        <Dialog title={`Close ${connection.name}`} onClose={onClose} onSubmit={(event) => {
            event.preventDefault();
            form.post(route('tprm.access.connections.close', connection.id), { onSuccess: onClose });
        }} processing={form.processing}>
            <p className="mb-3 text-sm text-gray-600">{connection.closure_hint}</p>
            <Field label={needsEvidence ? 'Evidence document ID' : 'Evidence document ID (optional)'}
                error={form.errors.closure_evidence_document_id}>
                <input className="form-input w-full" value={form.data.closure_evidence_document_id}
                    onChange={(event) => form.setData('closure_evidence_document_id', event.target.value)} />
            </Field>
            {!needsEvidence && (
                <Field label="Reason" error={form.errors.reason}>
                    <textarea className="form-textarea w-full" rows="2" value={form.data.reason}
                        onChange={(event) => form.setData('reason', event.target.value)} />
                </Field>
            )}
        </Dialog>
    );
}

function RevokeDialog({ grant, onClose }) {
    const form = useForm({ revocation_evidence_document_id: '' });

    return (
        <Dialog title={`Revoke access for ${grant.grantee_name}`} onClose={onClose} onSubmit={(event) => {
            event.preventDefault();
            form.post(route('tprm.access.grants.revoke', grant.id), { onSuccess: onClose });
        }} processing={form.processing}>
            <p className="mb-3 text-sm text-gray-600">
                Attach evidence that the account was disabled — an access review extract or the ticket showing the
                removal. A date with nothing behind it is what an examiner asks about.
            </p>
            <Field label="Evidence document ID" error={form.errors.revocation_evidence_document_id}>
                <input className="form-input w-full" value={form.data.revocation_evidence_document_id}
                    onChange={(event) => form.setData('revocation_evidence_document_id', event.target.value)} />
            </Field>
        </Dialog>
    );
}

function Dialog({ title, children, onClose, onSubmit, processing }) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4">
            <form onSubmit={onSubmit} className="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-lg bg-white p-5 shadow-xl">
                <h2 className="mb-4 text-base font-semibold text-gray-900">{title}</h2>
                {children}
                <div className="mt-5 flex justify-end gap-2">
                    <button type="button" className="btn btn-ghost" onClick={onClose}>Cancel</button>
                    <button type="submit" className="btn btn-primary" disabled={processing}>Save</button>
                </div>
            </form>
        </div>
    );
}

function Field({ label, error, children }) {
    return (
        <div className="mb-3">
            <label className="mb-1 block text-sm font-medium text-gray-700">{label}</label>
            {children}
            {error && <p className="mt-1 text-xs text-red-700">{error}</p>}
        </div>
    );
}
