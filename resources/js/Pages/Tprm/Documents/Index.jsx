import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import DataGrid from '@thirdline/ui/Components/DataGrid/DataGrid';
import PageHeader from '@thirdline/ui/Components/PageHeader';

/**
 * The evidence library — FR-EVD-01, with the expiry heat map above it.
 *
 * The tiles are the heat map. They are ordered worst-first because that is the
 * order the work needs doing in, and "no expiry recorded" sits at the end as a
 * neutral count rather than a warning: a penetration test report has no expiry
 * printed on it, and colouring that red would train people to ignore the tiles
 * that matter.
 *
 * THE CAPABILITY BANNER IS NOT DECORATION. An installation with AI switched
 * off, or without poppler on the host, cannot read a PDF — and the honest
 * failure mode is to say so once, at the top, rather than let somebody upload
 * forty documents and wait for extraction panels that will never populate.
 */
export default function Index({ summary = {}, grid, documentTypes = [], capabilities = {}, can = {} }) {
    const [uploading, setUploading] = useState(false);

    const tiles = [
        {
            label: 'Expired',
            value: summary.expired ?? 0,
            hint: 'no longer evidences anything',
            tone: summary.expired ? 'critical' : null,
        },
        {
            label: 'Expiring in 30 days',
            value: summary.expiring_30 ?? 0,
            hint: 'chase the renewal now',
            tone: summary.expiring_30 ? 'warn' : null,
        },
        { label: 'Expiring in 90 days', value: summary.expiring_90 ?? 0, hint: 'first notice sent' },
        { label: 'No expiry recorded', value: summary.no_expiry ?? 0, hint: 'not a gap in itself' },
    ];

    return (
        <AppLayout title="Evidence library">
            <Head title="Evidence library" />

            <PageHeader
                title="Evidence library"
                subtitle="Certificates, assurance reports and agreements, with what they cover and when they lapse. A control evidenced by an expired report is not evidenced today."
                actions={can.upload ? (
                    <button type="button" className="btn btn-primary" onClick={() => setUploading(true)}>
                        Upload evidence
                    </button>
                ) : null}
            />

            <CapabilityBanner capabilities={capabilities} />

            <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                {tiles.map((tile) => (
                    <div key={tile.label} className="card p-4">
                        <p className="text-xs font-medium uppercase tracking-wide text-gray-500">{tile.label}</p>
                        <p className={`mt-1 text-2xl font-semibold ${
                            tile.tone === 'critical' ? 'text-red-700' : tile.tone === 'warn' ? 'text-amber-700' : 'text-gray-900'
                        }`}>
                            {tile.value}
                        </p>
                        <p className="mt-0.5 text-xs text-gray-500">{tile.hint}</p>
                    </div>
                ))}
            </div>

            <DataGrid grid={grid} />

            {uploading && (
                <UploadDialog
                    documentTypes={documentTypes}
                    onClose={() => setUploading(false)}
                />
            )}
        </AppLayout>
    );
}

function CapabilityBanner({ capabilities }) {
    const notices = [];

    if (!capabilities.ai_enabled) {
        notices.push(
            'Automatic document reading is switched off for this installation. Every field on every document '
            + 'can be entered by hand, and the register behaves identically.',
        );
    } else if (!capabilities.pdf_readable) {
        notices.push(
            'This server cannot read text out of PDFs — the pdftotext utility (poppler-utils) is not installed, '
            + 'so uploads will need their fields entered by hand until it is.',
        );
    }

    if (notices.length === 0) {
        return null;
    }

    return (
        <div className="mb-6 rounded-md border border-gray-200 bg-gray-50 p-4">
            {notices.map((notice) => (
                <p key={notice} className="text-sm text-gray-700">{notice}</p>
            ))}
        </div>
    );
}

/**
 * The upload form.
 *
 * The type is chosen first because it decides everything else: which extractor
 * runs, whether an expiry is expected, and how far the document can raise an
 * answer's assurance. That last one is shown as plain text beside the choice —
 * it is what stops somebody filing an NDA as independent assurance.
 */
function UploadDialog({ documentTypes, onClose }) {
    const form = useForm({
        file: null,
        owner_type: 'engagement',
        owner_id: '',
        document_type_id: '',
        title: '',
        issuer: '',
        scope_text: '',
        issue_date: '',
        valid_from: '',
        valid_to: '',
    });

    const selected = documentTypes.find((type) => String(type.id) === String(form.data.document_type_id));

    const submit = (event) => {
        event.preventDefault();
        form.post(route('tprm.documents.store'), {
            forceFormData: true,
            onSuccess: onClose,
        });
    };

    return (
        <div className="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-black/30 p-6">
            <form onSubmit={submit} className="card w-full max-w-2xl p-6">
                <h2 className="text-base font-semibold text-gray-900">Upload evidence</h2>
                <p className="mt-1 text-sm text-gray-600">
                    Nothing is applied to the register on upload. Anything read out of the document is shown for
                    confirmation first.
                </p>

                <div className="mt-5 space-y-4">
                    <Field label="File" error={form.errors.file}>
                        <input
                            type="file"
                            className="input"
                            accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.txt"
                            onChange={(event) => form.setData('file', event.target.files[0])}
                        />
                    </Field>

                    <Field label="Document type" error={form.errors.document_type_id}>
                        <select
                            className="input"
                            value={form.data.document_type_id}
                            onChange={(event) => form.setData('document_type_id', event.target.value)}
                        >
                            <option value="">Uncategorised</option>
                            {documentTypes.map((type) => (
                                <option key={type.id} value={type.id}>{type.name}</option>
                            ))}
                        </select>
                        {selected && (
                            <p className="mt-1.5 text-xs text-gray-600">
                                {selected.assurance_ceiling_label
                                    ? `A document of this type can support assurance up to "${selected.assurance_ceiling_label}".`
                                    : 'A document of this type is filed as a record. It does not raise the assurance level of any answer.'}
                                {selected.has_expiry && selected.default_validity_months
                                    ? ` Valid for ${selected.default_validity_months} months from its issue date unless you set an expiry below.`
                                    : ''}
                            </p>
                        )}
                    </Field>

                    <div className="grid grid-cols-2 gap-4">
                        <Field label="Attached to" error={form.errors.owner_type}>
                            <select
                                className="input"
                                value={form.data.owner_type}
                                onChange={(event) => form.setData('owner_type', event.target.value)}
                            >
                                <option value="engagement">An engagement</option>
                                <option value="third_party">A third party</option>
                                <option value="assessment">An assessment</option>
                            </select>
                        </Field>

                        <Field label="Record ID" error={form.errors.owner_id}>
                            <input
                                type="number"
                                className="input"
                                value={form.data.owner_id}
                                onChange={(event) => form.setData('owner_id', event.target.value)}
                            />
                        </Field>
                    </div>

                    <Field label="Title" error={form.errors.title}>
                        <input
                            type="text"
                            className="input"
                            placeholder="Taken from the filename if left blank"
                            value={form.data.title}
                            onChange={(event) => form.setData('title', event.target.value)}
                        />
                    </Field>

                    <div className="grid grid-cols-3 gap-4">
                        <Field label="Issued" error={form.errors.issue_date}>
                            <input type="date" className="input" value={form.data.issue_date}
                                onChange={(event) => form.setData('issue_date', event.target.value)} />
                        </Field>
                        <Field label="Valid from" error={form.errors.valid_from}>
                            <input type="date" className="input" value={form.data.valid_from}
                                onChange={(event) => form.setData('valid_from', event.target.value)} />
                        </Field>
                        <Field label="Expires" error={form.errors.valid_to}>
                            <input type="date" className="input" value={form.data.valid_to}
                                onChange={(event) => form.setData('valid_to', event.target.value)} />
                        </Field>
                    </div>
                </div>

                <div className="mt-6 flex justify-end gap-2">
                    <button type="button" className="btn btn-secondary" onClick={onClose}>Cancel</button>
                    <button type="submit" className="btn btn-primary" disabled={form.processing}>
                        {form.processing ? 'Uploading…' : 'Upload'}
                    </button>
                </div>
            </form>
        </div>
    );
}

function Field({ label, error, children }) {
    return (
        <label className="block">
            <span className="text-sm font-medium text-gray-700">{label}</span>
            <div className="mt-1">{children}</div>
            {error && <p className="mt-1 text-xs text-red-600">{error}</p>}
        </label>
    );
}
