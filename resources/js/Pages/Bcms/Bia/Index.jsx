import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import Pagination from '@thirdline/ui/Components/Pagination';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * Every business impact assessment, in the order somebody works them.
 *
 * SORTED BY WHAT NEEDS DOING, NOT BY CODE. Returned first — those have already
 * failed review once and are a different conversation from the thirty-nine that
 * have simply not been started.
 */
export default function Index({ assessments, filters = {}, statuses = [], can = {} }) {
    const rows = assessments?.data ?? [];

    const filter = (key, value) => router.get(
        tryRoute('bcms.bia.index'),
        { ...filters, [key]: value || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );

    return (
        <AppLayout title="Business impact analysis">
            <Head title="Business impact analysis" />

            <PageHeader
                title="Business impact analysis"
                subtitle="What each process can survive, how quickly it must come back, and what it cannot run without."
                actions={can.manage_campaigns && (
                    <Link href={tryRoute('bcms.bia-campaigns.index')} className="btn-secondary text-sm">Campaigns</Link>
                )}
            />

            <div className="mb-4 flex flex-wrap gap-3">
                <input className="w-64 rounded border-gray-300 text-sm" placeholder="Search process name or code"
                    defaultValue={filters.search ?? ''}
                    onKeyDown={(e) => { if (e.key === 'Enter') filter('search', e.target.value); }} />
                <select className="rounded border-gray-300 text-sm" value={filters.status ?? ''}
                    onChange={(e) => filter('status', e.target.value)}>
                    <option value="">Any status</option>
                    {statuses.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
                </select>
            </div>

            <div className="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                <table className="w-full text-sm">
                    <thead className="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th className="px-4 py-3">Process</th>
                            <th className="px-4 py-3">Unit</th>
                            <th className="px-4 py-3">Tier</th>
                            <th className="px-4 py-3">MTPD</th>
                            <th className="px-4 py-3">RTO</th>
                            <th className="px-4 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {rows.length === 0 && (
                            <tr><td colSpan={6} className="px-4 py-8 text-center text-gray-500">
                                No assessments match. A campaign creates one per process in scope.
                            </td></tr>
                        )}
                        {rows.map((a) => (
                            <tr key={a.id}>
                                <td className="px-4 py-3">
                                    <Link href={tryRoute('bcms.bia.show', a.id)} className="text-gray-900 underline">{a.process}</Link>
                                    <span className="block font-mono text-xs text-gray-500">{a.code}</span>
                                    {a.is_critical_service && (
                                        <span className="mt-1 inline-block rounded bg-purple-50 px-1.5 py-0.5 text-[11px] text-purple-800">critical service</span>
                                    )}
                                    {a.ai_generated && (
                                        <span className="ml-1 inline-block rounded bg-sky-50 px-1.5 py-0.5 text-[11px] text-sky-800">AI draft</span>
                                    )}
                                </td>
                                <td className="px-4 py-3 text-gray-600">{a.unit ?? '—'}</td>
                                <td className="px-4 py-3 text-gray-600">{a.tier ? `Tier ${a.tier}` : <span className="text-gray-400">not tiered</span>}</td>
                                <td className="px-4 py-3 font-mono text-gray-700">{a.mtpd_hours === null ? <span className="text-gray-400">—</span> : `${a.mtpd_hours} h`}</td>
                                <td className="px-4 py-3 font-mono text-gray-700">{a.rto_hours === null ? <span className="text-gray-400">—</span> : `${a.rto_hours} h`}</td>
                                <td className="px-4 py-3">
                                    <span className={`rounded px-2 py-0.5 text-xs ${a.status === 'returned' ? 'bg-red-50 text-red-800' : a.status === 'approved' ? 'bg-emerald-50 text-emerald-800' : 'bg-gray-100 text-gray-700'}`}>
                                        {a.status_label}
                                    </span>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {assessments?.links && <Pagination links={assessments.links} className="mt-4" />}
        </AppLayout>
    );
}
