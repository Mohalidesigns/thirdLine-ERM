import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import OrgTree from '@thirdline/ui/Components/OrgTree';
import WidgetGrid from '@thirdline/ui/Components/WidgetGrid';

const article = (name) => (/^[aeiou]/i.test(name || '') ? 'an' : 'a');
const plural = (n, word) => `${n} ${word}${n === 1 ? '' : 's'}`;

function hqUrl(id, query = {}) {
    const params = new URLSearchParams();
    Object.entries(query).forEach(([k, v]) => v !== null && v !== undefined && v !== '' && params.set(k, v));
    const s = params.toString();
    return `/hq/${id}${s ? `?${s}` : ''}`;
}

/**
 * Business HQ — one node of the organisation graph, rendered through the
 * published dashboard for its type (migration Phase 2: hq/show.blade.php).
 * Every widget payload arrived with the page; the panels refresh themselves.
 */
export default function Show({
    object, dashboard, tabs, activeTab, payloads, ancestors, tree,
    preview, previewRefused, canManage, createUrl, draftsForType, nodeCountForType, roleBlocked,
}) {
    const [treeOpen, setTreeOpen] = useState(true);
    const typeName = object.type || 'this object type';

    const breadcrumbs = (
        <nav className="flex items-center gap-1 text-xs text-gray-500" aria-label="Breadcrumb">
            <Link href="/hq" className="hover:text-gray-800">Business HQ</Link>
            {ancestors.map((a) => (
                <span key={a.id} className="flex items-center gap-1">
                    <span className="text-gray-300">›</span>
                    <Link href={hqUrl(a.id)} className="hover:text-gray-800">{a.name}</Link>
                </span>
            ))}
            <span className="text-gray-300">›</span>
            <span className="font-medium text-gray-800">{object.name}</span>
        </nav>
    );

    return (
        <AuthenticatedLayout title="Business HQ" header={breadcrumbs}>
            <Head title={`${object.name} · Business HQ`} />

            {preview ? (
                <div className="mb-3 flex flex-wrap items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-3 py-2.5 text-xs text-blue-800">
                    <span className="material-symbols-outlined text-[18px]">visibility</span>
                    <span className="font-semibold">Preview</span>
                    <span>
                        “{preview.name}” as it would appear on {preview.objectTypeName ?? 'every'} nodes.{' '}
                        {preview.isPublished && preview.hasUnpublishedChanges
                            ? `Showing your unpublished edits — Business HQ still serves v${preview.version}.`
                            : !preview.isPublished
                                ? 'This dashboard is a draft and is not live anywhere.'
                                : `This matches the published v${preview.version}.`}
                    </span>
                    <span className="flex-1" />
                    <Link href={hqUrl(object.id)} className="rounded-md border border-blue-200 bg-white px-2.5 py-1 font-medium text-blue-700 hover:bg-blue-50">Exit preview</Link>
                    <Link href={preview.editUrl} className="rounded-md bg-blue-600 px-2.5 py-1 font-medium text-white hover:bg-blue-700">Back to editor</Link>
                </div>
            ) : previewRefused ? (
                <div className="mb-3 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs text-amber-800">
                    <span className="material-symbols-outlined text-[18px]">info</span>
                    <span>{previewRefused}</span>
                </div>
            ) : null}

            <div className="flex gap-4">
                <aside className={`shrink-0 transition-all ${treeOpen ? 'w-64' : 'w-8'}`}>
                    <div className="sticky top-20 rounded-lg border border-gray-200 bg-white shadow-sm">
                        <div className="flex items-center justify-between border-b border-gray-100 px-3 py-2">
                            {treeOpen && <span className="text-xs font-semibold uppercase tracking-wide text-gray-500">Organization</span>}
                            <button type="button" onClick={() => setTreeOpen((o) => !o)} className="text-gray-400 hover:text-gray-600" title={treeOpen ? 'Collapse' : 'Expand'}>
                                <span className="material-symbols-outlined text-[18px]">{treeOpen ? 'left_panel_close' : 'left_panel_open'}</span>
                            </button>
                        </div>
                        {treeOpen && (
                            <div className="max-h-[70vh] overflow-auto p-2">
                                <OrgTree nodes={tree} currentId={object.id} hrefFor={(n) => hqUrl(n.id)} />
                            </div>
                        )}
                    </div>
                </aside>

                <div className="min-w-0 flex-1">
                    {dashboard === null ? (
                        <div className="rounded-lg border border-dashed border-gray-300 bg-white p-10 text-center">
                            <span className="material-symbols-outlined text-4xl text-gray-300">dashboard_customize</span>

                            {roleBlocked.length > 0 ? (
                                <>
                                    <h2 className="mt-2 text-sm font-semibold text-gray-700">Published for {typeName} — but not for your roles</h2>
                                    <p className="mx-auto mt-1 max-w-lg text-xs text-gray-500">
                                        {roleBlocked.length === 1 ? 'A dashboard is' : `${roleBlocked.length} dashboards are`} published for {typeName} nodes,
                                        composed for roles you do not hold, so Business HQ has nothing to show <em>you</em> here. Other users will see{' '}
                                        {roleBlocked.length === 1 ? 'it' : 'them'} on this node.
                                    </p>
                                    <ul className="mx-auto mt-3 max-w-lg space-y-1 text-left">
                                        {roleBlocked.map((b) => (
                                            <li key={b.id} className="flex items-center gap-2 rounded-md border border-gray-100 bg-gray-50 px-3 py-1.5 text-xs">
                                                <span className="material-symbols-outlined text-[15px] text-gray-400">lock_person</span>
                                                <span className="min-w-0 flex-1 truncate font-medium text-gray-700">{b.name}</span>
                                                <span className="shrink-0 text-[11px] text-gray-500">{b.role_names}</span>
                                                {b.editUrl && <Link href={b.editUrl} className="shrink-0 font-medium text-[var(--color-primary)] hover:underline">Edit</Link>}
                                            </li>
                                        ))}
                                    </ul>
                                    {canManage && (
                                        <p className="mt-3 text-[11px] text-gray-400">Add one of your roles to a dashboard above, or clear its role list so it shows to everyone.</p>
                                    )}
                                </>
                            ) : (
                                <>
                                    <h2 className="mt-2 text-sm font-semibold text-gray-700">Nothing published for {typeName} nodes</h2>
                                    <p className="mx-auto mt-1 max-w-md text-xs text-gray-500">
                                        <span className="font-medium text-gray-700">{object.name}</span> is {article(typeName)} {typeName}. Publish a dashboard bound to {typeName} and it appears here
                                        {nodeCountForType > 1
                                            ? `, and on the other ${plural(nodeCountForType - 1, `${typeName} node`)} too.`
                                            : ', and on every other node of that type.'}
                                    </p>
                                </>
                            )}

                            {canManage && roleBlocked.length === 0 && (
                                <>
                                    <div className="mt-5 flex flex-wrap items-center justify-center gap-2">
                                        {draftsForType.map((d) => (
                                            <Link key={d.id} href={d.editUrl} className="inline-flex items-center gap-1 rounded-md border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                                                <span className="material-symbols-outlined text-[16px] text-amber-500">edit_note</span>
                                                Open draft “{d.name.length > 28 ? `${d.name.slice(0, 28)}…` : d.name}”
                                            </Link>
                                        ))}
                                        {createUrl && (
                                            <Link href={createUrl} className="inline-flex items-center gap-1 rounded-md bg-[var(--color-primary)] px-3 py-1.5 text-xs font-medium text-white hover:opacity-90">
                                                <span className="material-symbols-outlined text-[16px]">add</span>
                                                Create {article(object.type || '')} {object.type ?? 'node'} dashboard
                                            </Link>
                                        )}
                                    </div>
                                    {draftsForType.length > 0 && (
                                        <p className="mt-3 text-[11px] text-gray-400">
                                            {plural(draftsForType.length, 'draft')} already {draftsForType.length === 1 ? 'exists' : 'exist'} for this type — publishing one is probably what you want.
                                        </p>
                                    )}
                                </>
                            )}
                        </div>
                    ) : (
                        <>
                            <div className="mb-2 flex flex-wrap items-center gap-2 text-xs text-gray-500">
                                <span className="material-symbols-outlined text-[15px] text-gray-400">dashboard</span>
                                <span className="font-medium text-gray-700">{dashboard.name}</span>
                                {dashboard.object_type_id === null ? (
                                    <span className="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-500">default for every type</span>
                                ) : (
                                    <span className="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-500">composed for {dashboard.objectTypeName ?? 'this type'}</span>
                                )}
                                {dashboard.isSystem && <span className="rounded bg-blue-50 px-1.5 py-0.5 text-[10px] text-blue-700">system</span>}
                                {preview ? (
                                    <span className="rounded bg-blue-50 px-1.5 py-0.5 text-[10px] font-medium text-blue-700">draft layout</span>
                                ) : (
                                    <span className="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-500">v{dashboard.version}</span>
                                )}
                            </div>

                            <div className="mb-3 flex items-center justify-between gap-3">
                                <nav className="flex max-w-full items-center gap-1 overflow-x-auto rounded-lg border border-gray-200 bg-white p-1 shadow-sm" aria-label="Dashboard tabs">
                                    {tabs.map((tab) => (
                                        <Link
                                            key={tab.code}
                                            href={hqUrl(object.id, { tab: tab.code, preview: preview?.id })}
                                            className={`whitespace-nowrap rounded-md px-3 py-1.5 text-xs font-medium ${activeTab?.code === tab.code ? 'bg-[var(--color-primary)] text-white' : 'text-gray-600 hover:bg-gray-100'}`}
                                        >
                                            {tab.label}
                                        </Link>
                                    ))}
                                </nav>
                                {dashboard.editUrl && (
                                    <Link href={dashboard.editUrl} className="inline-flex shrink-0 items-center gap-1 rounded-md border border-gray-200 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-600 shadow-sm hover:bg-gray-50">
                                        <span className="material-symbols-outlined text-[16px]">edit</span>
                                        {preview ? 'Back to editor' : 'Edit layout'}
                                    </Link>
                                )}
                            </div>

                            {!activeTab || activeTab.layout.length === 0 ? (
                                <div className="rounded-lg border border-dashed border-gray-300 bg-white p-10 text-center text-xs text-gray-500">This tab has no widgets yet.</div>
                            ) : (
                                <WidgetGrid layout={activeTab.layout} payloads={payloads} keyPrefix={`${dashboard.id}-${activeTab.code}-${object.id}-`} />
                            )}
                        </>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
