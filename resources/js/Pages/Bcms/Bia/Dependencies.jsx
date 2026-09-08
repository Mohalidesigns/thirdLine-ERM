import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * The dependency explorer, the SPOF register and the shared-dependency view.
 *
 * THE SHARED LIST IS THE ONE NOBODY HAS. Each assessor recorded a reasonable
 * dependency; the concentration exists only in the aggregate, and no spreadsheet
 * written per process can show it. It is the same argument the third-party
 * concentration analysis makes about sub-processors.
 *
 * The graph is laid out as concentric rings rather than force-directed: a
 * deterministic layout means two people looking at the same estate see the same
 * picture, and a screenshot in a board pack still matches the screen next month.
 */
export default function Dependencies({ graph, spof_register: spofRegister = [], shared = [], filters = {}, types = [] }) {
    const filter = (key, value) => router.get(
        tryRoute('bcms.dependencies.index'),
        { ...filters, [key]: value || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );

    const dependencyNodes = (graph?.nodes ?? []).filter((n) => n.kind === 'dependency');

    return (
        <AppLayout title="Dependencies">
            <Head title="Dependencies" />

            <PageHeader
                title="Dependencies"
                subtitle="What the estate rests on, what rests on one thing only, and what several processes quietly share."
            />

            <div className="mb-4 flex flex-wrap gap-3">
                <select className="rounded border-gray-300 text-sm" value={filters.type ?? ''} onChange={(e) => filter('type', e.target.value)}>
                    <option value="">Any type</option>
                    {types.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                </select>
                <select className="rounded border-gray-300 text-sm" value={filters.criticality ?? ''} onChange={(e) => filter('criticality', e.target.value)}>
                    <option value="">Any criticality</option>
                    {['critical', 'high', 'medium', 'low'].map((c) => <option key={c} value={c}>{c}</option>)}
                </select>
                <select className="rounded border-gray-300 text-sm" value={filters.spof_only ?? ''} onChange={(e) => filter('spof_only', e.target.value)}>
                    <option value="">All dependencies</option>
                    <option value="yes">Single points of failure only</option>
                </select>
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <section className="rounded-lg border border-gray-200 bg-white p-5">
                    <h2 className="text-sm font-semibold text-gray-900">Single points of failure</h2>
                    <p className="mt-1 text-xs text-gray-500">
                        Ordered by consequence — how critical, then how much stops with it.
                    </p>

                    {spofRegister.length === 0 ? (
                        <p className="mt-3 text-sm text-gray-500">
                            None recorded. That is either good news or a BIA that has not looked; the register only
                            knows what assessors flagged.
                        </p>
                    ) : (
                        <ul className="mt-3 divide-y divide-gray-100 text-sm">
                            {spofRegister.map((s) => (
                                <li key={`${s.type}:${s.id}`} className="py-3">
                                    <div className="flex flex-wrap items-start justify-between gap-2">
                                        <div>
                                            <Link href={tryRoute('bcms.dependencies.impact-of', [s.type, s.id])} className="text-gray-900 underline">
                                                {s.name}
                                            </Link>
                                            <span className="block text-xs text-gray-500">
                                                {s.type_label} · {s.criticality} · {s.process_count} process(es)
                                                {s.tier1_count > 0 ? `, ${s.tier1_count} at tier 1` : ''}
                                            </span>
                                        </div>
                                        {s.is_finding && (
                                            <span className="rounded bg-red-50 px-2 py-0.5 text-xs text-red-800">raise a finding</span>
                                        )}
                                    </div>
                                    {s.recovery_notes.length > 0 && (
                                        <p className="mt-1 text-xs text-gray-600">{s.recovery_notes[0]}</p>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className="rounded-lg border border-gray-200 bg-white p-5">
                    <h2 className="text-sm font-semibold text-gray-900">Shared across processes</h2>
                    <p className="mt-1 text-xs text-gray-500">
                        A concentration nobody chose: each assessor recorded a reasonable dependency, and the exposure
                        exists only in the aggregate.
                    </p>

                    {shared.length === 0 ? (
                        <p className="mt-3 text-sm text-gray-500">No dependency is shared by more than one process yet.</p>
                    ) : (
                        <ul className="mt-3 divide-y divide-gray-100 text-sm">
                            {shared.map((s) => (
                                <li key={`${s.type}:${s.id}`} className="py-3">
                                    <Link href={tryRoute('bcms.dependencies.impact-of', [s.type, s.id])} className="text-gray-900 underline">
                                        {s.name}
                                    </Link>
                                    <span className="block text-xs text-gray-500">
                                        {s.type_label} · {s.process_count} processes
                                        {s.tier1_count > 0 ? `, ${s.tier1_count} at tier 1` : ''}
                                    </span>
                                    <p className="mt-1 text-xs text-gray-600">
                                        {s.processes.slice(0, 6).map((p) => p.code).join(', ')}
                                        {s.processes.length > 6 ? '…' : ''}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>

            <section className="mt-6 rounded-lg border border-gray-200 bg-white p-5">
                <h2 className="text-sm font-semibold text-gray-900">The estate</h2>
                <p className="mt-1 text-xs text-gray-500">
                    {graph?.nodes?.length ?? 0} node(s), {graph?.edges?.length ?? 0} edge(s). Click a dependency to
                    see what stops if it fails.
                </p>

                <div className="mt-4 flex flex-wrap gap-2">
                    {dependencyNodes.map((n) => (
                        <Link
                            key={n.id}
                            href={tryRoute('bcms.dependencies.impact-of', [n.type, n.id.split(':')[1]])}
                            className={`rounded-full border px-3 py-1 text-xs ${n.spof ? 'border-red-300 bg-red-50 text-red-900' : 'border-gray-200 bg-gray-50 text-gray-700'}`}
                            title={`${n.type_label} · relied on by ${n.dependent_count} process(es)`}
                        >
                            {n.label}
                            <span className="ml-1 font-mono text-[11px] opacity-70">{n.dependent_count}</span>
                        </Link>
                    ))}
                    {dependencyNodes.length === 0 && (
                        <p className="text-sm text-gray-500">No dependencies match these filters.</p>
                    )}
                </div>
            </section>
        </AppLayout>
    );
}
