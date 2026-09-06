import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import KpiCard from '@thirdline/ui/Components/KpiCard';
import EmptyState from '@thirdline/ui/Components/EmptyState';
import FilterBar from '@thirdline/ui/Components/FilterBar';
import { number } from '@/Components/Quantification/figures';

/** Bytes, in the units a person reads. */
function fileSize(bytes) {
    if (!bytes) return '—';

    const units = ['B', 'KB', 'MB', 'GB'];
    let value = Number(bytes);
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${value.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
}

const shortDate = (value) =>
    value ? new Date(value).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';

/**
 * The document repository (migration Phase 5.5).
 *
 * Read-only, by design: every file uploaded anywhere in the platform —
 * loss-event attachments, control-test evidence, issue attachments — listed
 * together. The source tables stay authoritative; this only queries and
 * presents them, and each row's download goes back through the owning module's
 * own route, which is where the permission check for that file lives.
 */
export default function Index({ folders, summary, docTypes, sources, filters }) {
    const [open, setOpen] = useState(() => folders.filter((f) => f.count > 0).map((f) => f.key));

    const toggle = (key) => setOpen(open.includes(key) ? open.filter((k) => k !== key) : [...open, key]);

    return (
        <AuthenticatedLayout title="Document Repository">
            <Head title="Document Repository" />

            <PageHeader
                title="Document Repository"
                subtitle="Every file uploaded across the platform, in one place"
            />

            <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <KpiCard title="Documents" value={number(summary.total)} icon="folder" color="primary" />
                <KpiCard title="Regulatory" value={number(summary.regulatory)} icon="gavel" color="warning" />
                <KpiCard title="Total size" value={fileSize(summary.total_size)} icon="storage" color="info" />
                <KpiCard title="Sources" value={number(sources.length)} icon="account_tree" color="primary" />
            </div>

            <FilterBar
                route={route('risk.documents.index')}
                currentFilters={filters}
                searchPlaceholder="File name or description"
                filters={[
                    { name: 'q', type: 'search' },
                    {
                        name: 'source',
                        type: 'select',
                        label: 'Source',
                        options: sources.map((s) => ({ value: s.key, label: s.label })),
                    },
                    {
                        name: 'document_type',
                        type: 'select',
                        label: 'Document type',
                        options: docTypes.map((t) => ({ value: t, label: t })),
                    },
                    { name: 'regulatory', type: 'checkbox', label: 'Regulatory only' },
                ]}
            />

            {summary.total === 0 ? (
                <EmptyState
                    icon={<span className="material-symbols-outlined text-3xl text-gray-400">folder_open</span>}
                    title="No documents"
                    description="Files attached to loss events, control tests and issues appear here as they are uploaded."
                />
            ) : (
                <div className="space-y-4">
                    {folders.map((folder) => (
                        <section key={folder.key} className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                            <button
                                type="button"
                                onClick={() => toggle(folder.key)}
                                className="w-full flex items-center gap-3 px-5 py-4 hover:bg-gray-50 text-left"
                            >
                                <span className="material-symbols-outlined text-gray-400">{folder.icon}</span>
                                <span className="text-sm font-semibold text-[#1A365D] flex-1">{folder.label}</span>
                                <span className="text-xs text-gray-500">
                                    {folder.count} file{folder.count === 1 ? '' : 's'} · {fileSize(folder.total_size)}
                                </span>
                                <span className="material-symbols-outlined text-gray-400">
                                    {open.includes(folder.key) ? 'expand_less' : 'expand_more'}
                                </span>
                            </button>

                            {open.includes(folder.key) && folder.count > 0 && (
                                <div className="overflow-x-auto border-t border-gray-100">
                                    <table className="data-table">
                                        <thead>
                                            <tr>
                                                <th>File</th>
                                                <th>Attached to</th>
                                                <th>Type</th>
                                                <th>Uploaded by</th>
                                                <th>Uploaded</th>
                                                <th className="text-right">Size</th>
                                                <th />
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {folder.files.map((file) => (
                                                <tr key={`${file.source}-${file.id}`}>
                                                    <td className="text-sm font-medium text-[#1A365D]">
                                                        {file.file_name}
                                                        {file.is_regulatory && (
                                                            <span className="ml-2 badge bg-yellow-100 text-yellow-700 text-[10px]">
                                                                Regulatory
                                                            </span>
                                                        )}
                                                        {file.description && (
                                                            <span className="block text-xs font-normal text-gray-500">
                                                                {file.description}
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className="text-xs">
                                                        {file.parent_link ? (
                                                            <Link href={file.parent_link} className="text-[#1A365D] hover:underline">
                                                                {file.parent_label}
                                                            </Link>
                                                        ) : (
                                                            file.parent_label
                                                        )}
                                                    </td>
                                                    <td className="text-xs">{file.document_type ?? '—'}</td>
                                                    <td className="text-xs">{file.uploaded_by}</td>
                                                    <td className="text-xs text-gray-500">{shortDate(file.uploaded_at)}</td>
                                                    <td className="text-right text-xs">{fileSize(file.size_bytes)}</td>
                                                    <td>
                                                        {file.download_link && (
                                                            // A plain anchor: the response is a file, and the
                                                            // owning module's route is where its permission
                                                            // check lives.
                                                            <a
                                                                href={file.download_link}
                                                                className="p-1 hover:bg-gray-100 rounded inline-flex"
                                                                title={`Download ${file.file_name}`}
                                                            >
                                                                <span className="material-symbols-outlined text-gray-400 text-lg">download</span>
                                                            </a>
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}

                            {open.includes(folder.key) && folder.count === 0 && (
                                <p className="px-5 py-6 text-sm text-gray-400 text-center border-t border-gray-100">
                                    Nothing uploaded from this source yet.
                                </p>
                            )}
                        </section>
                    ))}
                </div>
            )}
        </AuthenticatedLayout>
    );
}
