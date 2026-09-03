import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';

const plural = (n, word) => `${n} ${word}${n === 1 ? '' : 's'}`;

function Row({ row }) {
    return (
        <li>
            <Link href={row.editUrl} className="flex items-start gap-3 px-4 py-3 hover:bg-gray-50">
                <span className="material-symbols-outlined shrink-0 rounded-lg bg-gray-100 p-2 text-[20px] leading-none text-gray-500">dashboard</span>

                <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-medium text-gray-800">{row.name}</span>
                    <span className="block text-xs text-gray-400">
                        {plural(row.tabCount, 'tab')}, {plural(row.draftWidgetCount, 'widget')} · {row.roleCount === 0 ? 'every role' : plural(row.roleCount, 'role')}
                        {row.isSystem && <> · <span className="text-blue-600">system</span></>}
                    </span>
                    <span className="mt-1 block text-xs text-gray-600">
                        Shows on <b>{row.binding.name}</b>
                        {row.binding.nodeCount !== null && <> — {plural(row.binding.nodeCount, 'node')}</>}
                    </span>
                    {row.warning && (
                        <span className={`mt-0.5 flex items-start gap-1 text-[11px] ${row.warning.tone === 'warn' ? 'text-amber-700' : 'text-gray-400'}`}>
                            {row.warning.tone === 'warn' && <span className="material-symbols-outlined text-[13px] leading-[1.3]">warning</span>}
                            <span>{row.warning.text}</span>
                        </span>
                    )}
                </span>

                <span className="shrink-0 pt-0.5 text-right">
                    {row.isPublished && row.hasUnpublishedChanges ? (
                        <span className="rounded-full bg-blue-50 px-2 py-0.5 text-[10px] font-semibold text-blue-700">Unpublished changes</span>
                    ) : row.isPublished ? (
                        <span className="rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">Published · v{row.version}</span>
                    ) : (
                        <span className="rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-700">Draft</span>
                    )}
                    {row.isPublished && row.binding.firstNodeId && (
                        <span className="mt-1 block text-[11px] text-gray-400">{plural(row.publishedWidgetCount, 'live widget')}</span>
                    )}
                </span>

                <span className="material-symbols-outlined shrink-0 pt-1 text-[18px] text-gray-300">chevron_right</span>
            </Link>
        </li>
    );
}

/**
 * Dashboards list — live separated from drafts, each row printing its
 * binding's reach in nodes (migration Phase 2: risk/dashboards/index.blade.php).
 */
export default function Index({ live, drafts, createUrl }) {
    const sections = [
        ['Live in Business HQ', 'Rendering right now on every node of their type.', live],
        ['Drafts', 'Not visible to anyone until published.', drafts],
    ];

    return (
        <AuthenticatedLayout title="Dashboards">
            <Head title="Dashboards" />
            <div className="mx-auto max-w-5xl">
                <PageHeader
                    title="Dashboards"
                    subtitle="A dashboard is a composition of widgets, bound to an object type and published to roles. Business HQ renders the published dashboard matching each node's type — so a dashboard is only ever seen if nodes of its type exist."
                    actions={
                        <Link href={createUrl} className="inline-flex shrink-0 items-center gap-1 rounded-md bg-[var(--color-primary)] px-3 py-1.5 text-xs font-semibold text-white hover:opacity-90">
                            <span className="material-symbols-outlined text-[16px]">add</span> New dashboard
                        </Link>
                    }
                />

                {sections.map(([title, blurb, rows]) => rows.length > 0 && (
                    <div key={title}>
                        <div className="mb-2 mt-6 flex items-baseline gap-2 first:mt-0">
                            <h2 className="text-xs font-semibold uppercase tracking-wide text-gray-500">{title}</h2>
                            <span className="text-[11px] text-gray-400">{blurb}</span>
                        </div>
                        <ul className="divide-y divide-gray-100 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                            {rows.map((row) => <Row key={row.id} row={row} />)}
                        </ul>
                    </div>
                ))}

                {live.length === 0 && drafts.length === 0 ? (
                    <div className="rounded-xl border border-dashed border-gray-300 bg-white px-4 py-12 text-center">
                        <span className="material-symbols-outlined text-4xl text-gray-300">dashboard_customize</span>
                        <p className="mt-2 text-sm text-gray-500">No dashboards yet. Create the first one.</p>
                    </div>
                ) : live.length === 0 ? (
                    <p className="mt-4 flex items-center gap-1.5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] text-amber-800">
                        <span className="material-symbols-outlined text-[15px]">info</span>
                        Nothing is published, so every node in Business HQ shows an empty state. Publish one of the drafts above.
                    </p>
                ) : null}
            </div>
        </AuthenticatedLayout>
    );
}
