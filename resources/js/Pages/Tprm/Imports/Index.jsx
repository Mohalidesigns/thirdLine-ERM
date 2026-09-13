import { useRef } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import Pagination from '@thirdline/ui/Components/Pagination';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * Bulk import history — FR-TPR-09.
 *
 * Every batch stays listed after it commits, because the rollback lives here
 * and an import that cannot be found cannot be undone.
 */
export default function Index({ batches }) {
    const fileInput = useRef(null);
    const { setData, post, processing, errors } = useForm({ file: null });

    const upload = (event) => {
        const file = event.target.files?.[0];
        if (!file) return;

        setData('file', file);
        post(tryRoute('tprm.imports.store'), {
            forceFormData: true,
            // `setData` is asynchronous (standard §9), so the file is injected
            // through transform rather than read back out of `data`.
            transform: (payload) => ({ ...payload, file }),
        });
    };

    const rows = batches?.data ?? [];

    return (
        <AppLayout title="Bulk import">
            <Head title="Bulk import" />

            <PageHeader
                title="Bulk import"
                subtitle="Load an existing vendor list. Nothing is written until you have seen the validation."
                actions={
                    <>
                        <input ref={fileInput} type="file" accept=".csv,.xlsx,.xls" className="hidden" onChange={upload} />
                        <button type="button" disabled={processing} onClick={() => fileInput.current?.click()}
                            className="btn-primary text-sm inline-flex items-center gap-2">
                            <span className="material-symbols-outlined text-lg">upload_file</span>
                            Upload a file
                        </button>
                    </>
                }
            />

            {errors.file && (
                <div className="card mb-4 border-l-4 border-red-500 p-4 text-sm text-red-800">{errors.file}</div>
            )}

            <div className="card mb-6 p-5">
                <h3 className="text-sm font-semibold text-gray-900">What the file needs</h3>
                <p className="mt-1 text-xs text-gray-600">
                    One row per third party, with a header row. Only the legal name is required — a migration that
                    demanded the full field set would be a cliff rather than a ramp, and the register's data-quality
                    view is how the rest gets filled in. Everything else is mapped on the next screen.
                </p>
            </div>

            <div className="card overflow-hidden">
                {rows.length ? (
                    <table className="w-full text-sm">
                        <thead className="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th className="px-4 py-3 font-medium">File</th>
                                <th className="px-4 py-3 font-medium">Status</th>
                                <th className="px-4 py-3 text-right font-medium">Rows</th>
                                <th className="px-4 py-3 text-right font-medium">Imported</th>
                                <th className="px-4 py-3 font-medium">By</th>
                                <th className="px-4 py-3 font-medium">When</th>
                                <th className="px-4 py-3" />
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {rows.map((batch) => (
                                <tr key={batch.id} className="hover:bg-gray-50">
                                    <td className="px-4 py-3">
                                        <a href={batch.url} className="text-blue-700 hover:underline">{batch.filename}</a>
                                    </td>
                                    <td className="px-4 py-3">
                                        <StatusChip status={batch.status} />
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums">
                                        {batch.rows_total || '—'}
                                        {batch.rows_failed > 0 && (
                                            <span className="ml-1 text-xs text-red-700">({batch.rows_failed} failed)</span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums">{batch.created_count || '—'}</td>
                                    <td className="px-4 py-3 text-xs text-gray-600">{batch.created_by}</td>
                                    <td className="px-4 py-3 text-xs text-gray-500">
                                        {batch.committed_at ?? batch.created_at}
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        {batch.can_roll_back && (
                                            <button type="button"
                                                onClick={() => router.post(tryRoute('tprm.imports.roll-back', batch.id), {}, { preserveScroll: true })}
                                                className="text-xs font-medium text-red-700 hover:underline">
                                                Roll back
                                            </button>
                                        )}
                                        {batch.rolled_back_at && (
                                            <span className="text-xs text-gray-400">Rolled back</span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                ) : (
                    <div className="p-8 text-center text-sm text-gray-500">No imports yet.</div>
                )}
            </div>

            {batches?.links && <Pagination links={batches.links} className="mt-4" />}
        </AppLayout>
    );
}

function StatusChip({ status }) {
    const map = {
        draft: ['Awaiting mapping', 'bg-gray-100 text-gray-700'],
        validated: ['Validated', 'bg-blue-100 text-blue-800'],
        committed: ['Imported', 'bg-green-100 text-green-800'],
        rolled_back: ['Rolled back', 'bg-gray-100 text-gray-500'],
    };
    const [label, classes] = map[status] ?? [status, 'bg-gray-100 text-gray-700'];

    return <span className={`rounded px-1.5 py-0.5 text-xs font-medium ${classes}`}>{label}</span>;
}
