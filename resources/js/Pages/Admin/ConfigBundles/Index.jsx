import { useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Modal from '@/Components/Modal';

const dateTime = (value) =>
    value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—';

const OUTCOME_TONES = {
    ok: 'bg-green-100 text-green-800',
    no_changes: 'bg-gray-100 text-gray-700',
    conflict: 'bg-amber-100 text-amber-800',
    error: 'bg-red-100 text-red-800',
};

/**
 * Configuration bundles (migration Phase 6.6).
 *
 * The narrowest grant in the product: an import rewrites this organisation's
 * whole definition set — object types, fields, lifecycles, relationship types,
 * scoring profiles — in one transaction.
 *
 * THE DRY RUN IS ALWAYS THE FIRST STEP and never writes configuration. Applying
 * is a separate, confirmed action taken from the diff you have already read.
 */
export default function Index({ bundles, applications }) {
    const { flash, errors } = usePage().props;
    const [exporting, setExporting] = useState(false);
    const [applying, setApplying] = useState(null);

    const exportForm = useForm({ code: '', name: '', description: '' });
    const diffForm = useForm({ bundle_id: '', file: null });
    const applyForm = useForm({ force: false, prune: false, confirm: false });

    const submitExport = (event) => {
        event.preventDefault();
        exportForm.post(route('admin.configuration.export'), {
            preserveScroll: true,
            onSuccess: () => {
                exportForm.reset();
                setExporting(false);
            },
        });
    };

    const submitDiff = (event) => {
        event.preventDefault();
        diffForm.post(route('admin.configuration.diff'), { forceFormData: true });
    };

    const submitApply = (event) => {
        event.preventDefault();
        applyForm.post(route('admin.configuration.apply', applying.id), {
            preserveScroll: true,
            onSuccess: () => {
                applyForm.reset();
                setApplying(null);
            },
        });
    };

    const rollback = (application) => {
        if (!window.confirm('Restore the configuration as it was before this entry?')) return;

        router.post(route('admin.configuration.rollback', application.id), {}, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout title="Configuration Bundles">
            <Head title="Configuration Bundles" />

            <PageHeader
                title="Configuration bundles"
                subtitle="Export this configuration, compare it against another environment, and apply it with a dry run first"
                breadcrumbs={[{ label: 'Configuration Builder', href: route('admin.builder') }, { label: 'Bundles' }]}
                actions={<PrimaryButton onClick={() => setExporting(true)}>Export current configuration</PrimaryButton>}
            />

            {flash?.success && (
                <div className="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                    {flash.success}
                </div>
            )}
            {Object.entries(errors ?? {}).length > 0 && (
                <div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 space-y-1">
                    {Object.entries(errors).map(([key, message]) => (
                        <p key={key} className="text-sm text-red-800">
                            {message}
                        </p>
                    ))}
                </div>
            )}

            <section className="bg-white rounded-xl border border-gray-200 p-6 mb-4">
                <h2 className="text-sm font-semibold text-[#1A365D]">Compare a bundle against this environment</h2>
                <p className="text-xs text-gray-500 mt-1 mb-4">
                    A dry run. It reads, reports and records what it found — it never changes configuration.
                </p>

                <form onSubmit={submitDiff} className="flex flex-col lg:flex-row gap-3 items-start">
                    <div className="flex-1 w-full">
                        <InputLabel htmlFor="bundle_id" value="A stored bundle" />
                        <select
                            id="bundle_id"
                            className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm text-sm"
                            value={diffForm.data.bundle_id}
                            onChange={(e) => diffForm.setData({ bundle_id: e.target.value, file: null })}
                        >
                            <option value="">Choose one…</option>
                            {bundles.map((bundle) => (
                                <option key={bundle.id} value={bundle.id}>
                                    {bundle.label} — {bundle.total_rows} rows
                                </option>
                            ))}
                        </select>
                        <InputError message={diffForm.errors.bundle_id} className="mt-1" />
                    </div>

                    <div className="flex-1 w-full">
                        <InputLabel htmlFor="file" value="…or a file from another environment" />
                        <input
                            id="file"
                            type="file"
                            accept="application/json,.json"
                            className="mt-1 block w-full text-sm text-gray-600 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border file:border-gray-300 file:text-sm file:bg-white"
                            onChange={(e) => diffForm.setData({ bundle_id: '', file: e.target.files[0] ?? null })}
                        />
                        <InputError message={diffForm.errors.file} className="mt-1" />
                    </div>

                    <div className="pt-6">
                        <PrimaryButton disabled={diffForm.processing}>Dry run</PrimaryButton>
                    </div>
                </form>
            </section>

            <section className="bg-white rounded-xl border border-gray-200 mb-4 overflow-x-auto">
                <h2 className="text-sm font-semibold text-[#1A365D] px-6 pt-5">Exported bundles</h2>
                <table className="data-table w-full mt-3">
                    <thead>
                        <tr>
                            <th>Bundle</th>
                            <th>Exported</th>
                            <th className="text-right">Rows</th>
                            <th>Sections</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        {bundles.length === 0 && (
                            <tr>
                                <td colSpan={5} className="text-sm text-gray-500 text-center py-8">
                                    Nothing exported yet.
                                </td>
                            </tr>
                        )}
                        {bundles.map((bundle) => (
                            <tr key={bundle.id}>
                                <td>
                                    <p className="text-sm font-medium text-gray-900">{bundle.name}</p>
                                    <p className="text-xs text-gray-500 font-mono">
                                        {bundle.code} v{bundle.version}
                                    </p>
                                </td>
                                <td className="text-sm text-gray-600">
                                    {dateTime(bundle.exported_at)}
                                    {bundle.source_environment && (
                                        <span className="block text-xs text-gray-400">
                                            from {bundle.source_environment}
                                        </span>
                                    )}
                                </td>
                                <td className="text-sm text-gray-600 text-right">{bundle.total_rows}</td>
                                <td className="text-xs text-gray-500">
                                    {Object.entries(bundle.section_counts ?? {})
                                        .map(([section, count]) => `${section} ${count}`)
                                        .join(', ')}
                                </td>
                                <td className="text-right whitespace-nowrap">
                                    <a
                                        href={bundle.download_url}
                                        className="text-xs text-[#1A365D] font-medium hover:opacity-80 mr-3"
                                    >
                                        Download
                                    </a>
                                    <button
                                        type="button"
                                        onClick={() => setApplying(bundle)}
                                        className="text-xs text-[#1A365D] font-medium hover:opacity-80"
                                    >
                                        Apply
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </section>

            <section className="bg-white rounded-xl border border-gray-200 overflow-x-auto">
                <h2 className="text-sm font-semibold text-[#1A365D] px-6 pt-5">History</h2>
                <p className="text-xs text-gray-500 px-6 mt-1">
                    Every apply and every dry run is recorded. A rollback restores what preceded an entry, and is itself
                    recorded.
                </p>
                <table className="data-table w-full mt-3">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Bundle</th>
                            <th>Mode</th>
                            <th>Outcome</th>
                            <th>What changed</th>
                            <th>By</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        {applications.length === 0 && (
                            <tr>
                                <td colSpan={7} className="text-sm text-gray-500 text-center py-8">
                                    Nothing applied yet.
                                </td>
                            </tr>
                        )}
                        {applications.map((application) => (
                            <tr key={application.id}>
                                <td className="text-sm text-gray-600">{dateTime(application.applied_at)}</td>
                                <td className="text-sm text-gray-600">
                                    {application.bundle
                                        ? `${application.bundle.name} v${application.bundle.version}`
                                        : 'Uploaded file'}
                                </td>
                                <td className="text-xs text-gray-500">{application.mode?.replace(/_/g, ' ')}</td>
                                <td>
                                    <span
                                        className={`inline-flex px-2 py-0.5 rounded-full text-xs font-medium ${
                                            OUTCOME_TONES[application.outcome] ?? 'bg-gray-100 text-gray-700'
                                        }`}
                                    >
                                        {application.outcome?.replace(/_/g, ' ')}
                                    </span>
                                </td>
                                <td className="text-xs text-gray-600">{application.summary}</td>
                                <td className="text-xs text-gray-500">{application.actor ?? '—'}</td>
                                <td className="text-right">
                                    {application.can_rollback && (
                                        <button
                                            type="button"
                                            onClick={() => rollback(application)}
                                            className="text-xs text-red-600 font-medium hover:opacity-80"
                                        >
                                            Roll back
                                        </button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </section>

            <Modal show={exporting} onClose={() => setExporting(false)} maxWidth="lg">
                <form onSubmit={submitExport} className="p-6 space-y-4">
                    <h2 className="text-lg font-semibold text-[#1A365D]">Export this configuration</h2>
                    <p className="text-sm text-gray-600">
                        Re-exporting an existing code produces a new version rather than replacing it.
                    </p>

                    <div>
                        <InputLabel htmlFor="export_code" value="Code" />
                        <TextInput
                            id="export_code"
                            className="mt-1 block w-full font-mono text-sm"
                            value={exportForm.data.code}
                            onChange={(e) => exportForm.setData('code', e.target.value)}
                            required
                        />
                        <InputError message={exportForm.errors.code} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel htmlFor="export_name" value="Name" />
                        <TextInput
                            id="export_name"
                            className="mt-1 block w-full"
                            value={exportForm.data.name}
                            onChange={(e) => exportForm.setData('name', e.target.value)}
                            required
                        />
                        <InputError message={exportForm.errors.name} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel htmlFor="export_description" value="Description" />
                        <textarea
                            id="export_description"
                            rows={2}
                            className="mt-1 block w-full text-sm border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                            value={exportForm.data.description}
                            onChange={(e) => exportForm.setData('description', e.target.value)}
                        />
                        <InputError message={exportForm.errors.description} className="mt-1" />
                    </div>

                    <div className="flex justify-end gap-3">
                        <SecondaryButton type="button" onClick={() => setExporting(false)}>
                            Cancel
                        </SecondaryButton>
                        <PrimaryButton disabled={exportForm.processing}>Export</PrimaryButton>
                    </div>
                </form>
            </Modal>

            <Modal show={applying !== null} onClose={() => setApplying(null)} maxWidth="lg">
                <form onSubmit={submitApply} className="p-6 space-y-4">
                    <h2 className="text-lg font-semibold text-gray-900">Apply {applying?.label}?</h2>
                    <p className="text-sm text-gray-600">
                        This rewrites this organisation's definition set in one transaction. A snapshot is taken first,
                        so it can be rolled back from the history.
                    </p>
                    <p className="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg p-3">
                        Run a dry run first if you have not. It shows exactly what would change.
                    </p>

                    <label className="flex items-start gap-2 cursor-pointer">
                        <input
                            type="checkbox"
                            className="mt-0.5 rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                            checked={applyForm.data.force}
                            onChange={(e) => applyForm.setData('force', e.target.checked)}
                        />
                        <span className="text-sm text-gray-700">
                            Proceed through conflicts
                            <span className="block text-xs text-gray-500">
                                A conflict is a row changed on both sides since the last apply. Without this, the apply
                                stops and reports them.
                            </span>
                        </span>
                    </label>

                    <label className="flex items-start gap-2 cursor-pointer">
                        <input
                            type="checkbox"
                            className="mt-0.5 rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                            checked={applyForm.data.prune}
                            onChange={(e) => applyForm.setData('prune', e.target.checked)}
                        />
                        <span className="text-sm text-gray-700">
                            Remove what the bundle does not carry
                            <span className="block text-xs text-gray-500">
                                Removals are reported but never applied without this.
                            </span>
                        </span>
                    </label>

                    <label className="flex items-start gap-2 cursor-pointer">
                        <input
                            type="checkbox"
                            className="mt-0.5 rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                            checked={applyForm.data.confirm}
                            onChange={(e) => applyForm.setData('confirm', e.target.checked)}
                        />
                        <span className="text-sm text-gray-700">I have read what this will change.</span>
                    </label>
                    <InputError message={applyForm.errors.confirm} />

                    <div className="flex justify-end gap-3">
                        <SecondaryButton type="button" onClick={() => setApplying(null)}>
                            Cancel
                        </SecondaryButton>
                        <PrimaryButton disabled={applyForm.processing || !applyForm.data.confirm}>Apply</PrimaryButton>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
