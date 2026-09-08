import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import PortalLayout from '@/Layouts/PortalLayout';

/**
 * FR-PRT-05 — evidence the vendor maintains.
 *
 * THE POINT IS WHOSE TASK CERTIFICATE FRESHNESS IS. Today it is the bank's:
 * somebody notices an ISO certificate expired, emails, waits, chases. Moving
 * the upload and the expiry date here moves the work to the only party who
 * knows when the replacement arrives, and the reminder goes to them.
 */
export default function Documents({ documents = [], types = [], limits = {} }) {
    const [uploading, setUploading] = useState(false);

    const expiring = documents.filter((d) => d.expired || d.expiring_soon);

    return (
        <PortalLayout title="Documents">
            <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="text-lg font-semibold text-gray-900">Your documents</h1>
                    <p className="text-sm text-gray-600">
                        Certificates, reports and policies your clients rely on. Keep the expiry dates current and
                        we will remind you before they lapse.
                    </p>
                </div>
                <button
                    type="button"
                    className="rounded bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700"
                    onClick={() => setUploading(true)}
                >
                    Upload
                </button>
            </div>

            {expiring.length > 0 && (
                <div className="mb-4 rounded border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    {expiring.length} document{expiring.length === 1 ? ' has' : 's have'} expired or expire within
                    90 days. A lapsed certificate becomes a finding against you.
                </div>
            )}

            {documents.length === 0 ? (
                <div className="rounded-lg border border-gray-200 bg-white p-8 text-center text-sm text-gray-500">
                    Nothing uploaded yet.
                </div>
            ) : (
                <div className="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                    <table className="min-w-full divide-y divide-gray-200 text-sm">
                        <thead className="bg-gray-50">
                            <tr>
                                <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Document</th>
                                <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Type</th>
                                <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Expires</th>
                                <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Added by</th>
                                <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Scan</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {documents.map((document) => (
                                <tr key={document.id}>
                                    <th scope="row" className="px-4 py-2 text-left font-medium text-gray-900">
                                        {document.title}
                                    </th>
                                    <td className="px-4 py-2">{document.type ?? '—'}</td>
                                    <td className={`px-4 py-2 ${document.expired ? 'font-semibold text-red-700' : document.expiring_soon ? 'text-amber-700' : ''}`}>
                                        {document.valid_to ?? 'Does not expire'}
                                    </td>
                                    <td className="px-4 py-2 text-xs text-gray-500">
                                        {document.uploaded_via === 'portal' ? 'You' : 'Your client'}
                                    </td>
                                    <td className="px-4 py-2 text-xs text-gray-500">
                                        {/* Never a green tick for a scan nobody ran. */}
                                        {document.scan_status === 'pending' ? 'Not scanned' : document.scan_status}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {uploading && <UploadDialog types={types} limits={limits} onClose={() => setUploading(false)} />}
        </PortalLayout>
    );
}

function UploadDialog({ types, limits, onClose }) {
    const form = useForm({ file: null, title: '', document_type_id: '', valid_from: '', valid_to: '' });

    const selected = types.find((type) => String(type.id) === String(form.data.document_type_id));

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4">
            <form
                className="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-lg bg-white p-5 shadow-xl"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(route('tprm-portal.documents.upload'), {
                        forceFormData: true,
                        onSuccess: onClose,
                    });
                }}
            >
                <h2 className="mb-4 text-base font-semibold text-gray-900">Upload a document</h2>

                <Field label="Type" error={form.errors.document_type_id}>
                    <select
                        className="w-full rounded border border-gray-200 p-2 text-sm"
                        value={form.data.document_type_id}
                        onChange={(event) => form.setData('document_type_id', event.target.value)}
                    >
                        <option value="">Not sure / other</option>
                        {types.map((type) => (
                            <option key={type.id} value={type.id}>{type.name}</option>
                        ))}
                    </select>
                </Field>

                <Field label="Title" error={form.errors.title}>
                    <input
                        className="w-full rounded border border-gray-200 p-2 text-sm"
                        value={form.data.title}
                        onChange={(event) => form.setData('title', event.target.value)}
                    />
                </Field>

                <Field label="File" error={form.errors.file}>
                    <input
                        type="file"
                        className="w-full text-sm"
                        onChange={(event) => form.setData('file', event.target.files[0])}
                    />
                    <p className="mt-1 text-xs text-gray-500">
                        {limits.extensions?.join(', ')} · up to {Math.round((limits.max_kilobytes ?? 0) / 1024)}MB
                    </p>
                </Field>

                <div className="grid grid-cols-2 gap-3">
                    <Field label="Valid from" error={form.errors.valid_from}>
                        <input
                            type="date"
                            className="w-full rounded border border-gray-200 p-2 text-sm"
                            value={form.data.valid_from}
                            onChange={(event) => form.setData('valid_from', event.target.value)}
                        />
                    </Field>
                    <Field
                        label={selected?.has_expiry ? 'Expires' : 'Expires (if it does)'}
                        error={form.errors.valid_to}
                    >
                        <input
                            type="date"
                            className="w-full rounded border border-gray-200 p-2 text-sm"
                            value={form.data.valid_to}
                            onChange={(event) => form.setData('valid_to', event.target.value)}
                        />
                    </Field>
                </div>

                {selected?.has_expiry && (
                    <p className="text-xs text-gray-600">
                        A {selected.name.toLowerCase()} expires, so the date is needed — it is what the reminder
                        is set from.
                    </p>
                )}

                <div className="mt-5 flex justify-end gap-2">
                    <button type="button" className="px-3 py-1.5 text-sm text-gray-600" onClick={onClose}>Cancel</button>
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="rounded bg-blue-600 px-3 py-1.5 text-sm text-white disabled:opacity-50"
                    >
                        Upload
                    </button>
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
