import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';

/**
 * The PCI DSS 12.8.5 responsibility matrix — FR-CTR-08.
 *
 * A QSA asks for this at every assessment and almost nobody has it, so it gets
 * assembled in a spreadsheet the week before from memory.
 *
 * THE CONFIRMATION STATE IS ON EVERY ROW AND IN THE EXPORT. A row
 * pre-populated from the vendor's own assessment answers is the VENDOR's
 * opinion of who is responsible, and a vendor with a generous view of that can
 * quietly assign duties to us. A matrix that hid the distinction would be
 * worse than none, because a QSA relies on it.
 */
export default function Matrix({ matrix, engagementUrl, prepopulateUrl, exportUrl, can = {} }) {
    const [editing, setEditing] = useState(null);

    return (
        <AppLayout title="PCI responsibility matrix">
            <Head title={`PCI matrix — ${matrix.engagement.reference}`} />

            <PageHeader
                title="PCI DSS responsibility matrix"
                subtitle={
                    <Link className="underline" href={engagementUrl}>
                        {matrix.engagement.reference} — {matrix.engagement.name}
                    </Link>
                }
                actions={
                    <div className="flex gap-2">
                        {can.manage && (
                            <button
                                type="button"
                                className="btn-secondary"
                                onClick={() => router.post(prepopulateUrl)}
                            >
                                Pull the vendor&rsquo;s view
                            </button>
                        )}
                        <a href={exportUrl} className="btn-primary">
                            Export for the QSA
                        </a>
                    </div>
                }
            />

            {!matrix.engagement.pci_in_scope && (
                <div className="mb-6 rounded-md border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700">
                    This engagement is not marked as being in PCI DSS scope. The matrix is available anyway —
                    scope is a judgement that changes — but if account data is not involved, requirement 12.8.5
                    does not apply to it.
                </div>
            )}

            <div className={`mb-6 rounded-md border p-4 text-sm ${
                matrix.export_ready
                    ? 'border-green-200 bg-green-50 text-green-900'
                    : 'border-amber-200 bg-amber-50 text-amber-900'
            }`}>
                {matrix.export_ready ? (
                    <p>Every row has been confirmed. This matrix reflects an agreed position.</p>
                ) : (
                    <p>
                        <span className="font-medium">
                            {matrix.unconfirmed} of {matrix.total} rows have not been confirmed.
                        </span>{' '}
                        Rows sourced from the provider&rsquo;s assessment answers are the provider&rsquo;s view
                        of who is responsible until somebody here agrees with them. The export marks them, but a
                        QSA will ask.
                    </p>
                )}
            </div>

            <div className="card overflow-x-auto">
                <table className="data-table">
                    <thead className="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th>Req.</th>
                            <th>Requirement</th>
                            <th>Responsibility</th>
                            <th>Source</th>
                            <th>Confirmed</th>
                            {can.manage && <th />}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {matrix.rows.map((row) => (
                            <tr key={row.requirement} className={row.confirmed ? '' : 'bg-amber-50/40'}>
                                <td className="font-mono text-xs">{row.requirement}</td>
                                <td className="text-gray-700">
                                    {row.description}
                                    {row.notes && <p className="form-hint">{row.notes}</p>}
                                </td>
                                <td>
                                    <ResponsibilityChip value={row.responsibility} label={row.responsibility_label} />
                                </td>
                                <td className="text-xs text-gray-500">
                                    {row.source === 'caiq_ssrm' ? 'Provider’s answers' : 'Recorded here'}
                                </td>
                                <td className="text-xs">
                                    {row.confirmed
                                        ? <span className="text-gray-600">{row.confirmed_at} · {row.confirmed_by}</span>
                                        : <span className="text-amber-700">Not confirmed</span>}
                                </td>
                                {can.manage && (
                                    <td className="text-right">
                                        <button
                                            type="button"
                                            className="btn-secondary text-xs"
                                            onClick={() => setEditing(row)}
                                        >
                                            {row.confirmed ? 'Change' : 'Confirm'}
                                        </button>
                                    </td>
                                )}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {editing && (
                <ConfirmDialog
                    row={editing}
                    onClose={() => setEditing(null)}
                />
            )}
        </AppLayout>
    );
}

function ResponsibilityChip({ value, label }) {
    const tone = {
        tpsp: 'bg-blue-100 text-blue-800',
        entity: 'bg-purple-100 text-purple-800',
        shared: 'bg-amber-100 text-amber-800',
        na: 'bg-gray-100 text-gray-600',
    }[value] ?? 'bg-gray-100 text-gray-600';

    return <span className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${tone}`}>{label}</span>;
}

function ConfirmDialog({ row, onClose }) {
    const form = useForm({ responsibility: row.responsibility, notes: row.notes ?? '' });

    const submit = (event) => {
        event.preventDefault();
        form.post(row.confirm_url, { onSuccess: onClose });
    };

    return (
        <div className="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-black/30 p-6">
            <form onSubmit={submit} className="card w-full max-w-xl p-6">
                <h2 className="text-base font-semibold text-gray-900">
                    Requirement {row.requirement}
                </h2>
                <p className="mt-1 text-sm text-gray-600">{row.description}</p>

                <label className="form-label mt-5">
                    <span className="text-sm font-medium text-gray-700">Who is responsible?</span>
                    <select
                        className="form-select mt-1"
                        value={form.data.responsibility}
                        onChange={(event) => form.setData('responsibility', event.target.value)}
                    >
                        <option value="tpsp">The service provider</option>
                        <option value="entity">Us</option>
                        <option value="shared">Shared between us</option>
                        <option value="na">Not applicable to this service</option>
                    </select>
                    <p className="form-hint">
                        &ldquo;Shared&rdquo; needs a note saying which part each side holds — a shared
                        requirement nobody split is the shape of a duty each party believes the other has.
                    </p>
                </label>

                <label className="form-label mt-4">
                    <span className="text-sm font-medium text-gray-700">Notes</span>
                    <textarea
                        rows={3}
                        className="form-textarea mt-1"
                        value={form.data.notes}
                        onChange={(event) => form.setData('notes', event.target.value)}
                    />
                </label>

                <div className="mt-6 flex justify-end gap-2">
                    <button type="button" className="btn-secondary" onClick={onClose}>Cancel</button>
                    <button type="submit" className="btn-primary" disabled={form.processing}>Confirm</button>
                </div>
            </form>
        </div>
    );
}
