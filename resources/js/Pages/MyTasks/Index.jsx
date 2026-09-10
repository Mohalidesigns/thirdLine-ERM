import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import Pagination from '@thirdline/ui/Components/Pagination';

// Written out rather than composed: Tailwind scans source for literal class names.
export const BADGE = {
    green: 'bg-green-100 text-green-800',
    amber: 'bg-amber-100 text-amber-800',
    red: 'bg-red-100 text-red-800',
    blue: 'bg-blue-100 text-blue-800',
};
export const badgeClass = (color) => BADGE[color] || 'bg-slate-100 text-slate-700';

const url = (query) => {
    const params = new URLSearchParams(query);
    const s = params.toString();
    return `/risk/my-tasks${s ? `?${s}` : ''}`;
};

/** Every decision waiting on a person, from every module (migration Phase 3.7: risk/my-tasks/index.blade.php). */
export default function Index({ tasks, counts }) {
    const tiles = [
        { label: 'Open', value: counts.open, tone: 'text-gray-900', query: {} },
        { label: 'Overdue', value: counts.overdue, tone: 'text-red-600', query: { overdue: 1 } },
        { label: 'Due today', value: counts.due_today, tone: 'text-amber-600', query: {} },
        { label: 'Escalated to me', value: counts.escalated, tone: 'text-orange-600', query: { status: 'escalated' } },
        { label: 'Delegated to me', value: counts.delegated_to_me, tone: 'text-blue-600', query: { status: 'delegated' } },
    ];

    return (
        <AuthenticatedLayout title="My work">
            <Head title="My tasks" />
            <PageHeader title="My tasks" subtitle="Every decision waiting on you, from every module, in one queue. Deciding here does exactly what deciding on the record's own screen does." />

            <div className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-5">
                {tiles.map((t) => (
                    <Link key={t.label} href={url(t.query)} className="rounded-xl border border-gray-200 bg-white p-4 hover:border-[var(--color-primary)]">
                        <p className="text-[11px] font-medium uppercase tracking-wide text-gray-500">{t.label}</p>
                        <p className={`mt-1 text-2xl font-bold ${t.tone}`}>{t.value}</p>
                    </Link>
                ))}
            </div>

            <div className="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <table className="min-w-full divide-y divide-gray-200 text-sm">
                    <thead className="bg-gray-50 text-[11px] uppercase tracking-wide text-gray-500">
                        <tr><th className="px-4 py-2 text-left font-medium">What</th><th className="px-4 py-2 text-left font-medium">Record</th><th className="px-4 py-2 text-left font-medium">Process</th><th className="px-4 py-2 text-left font-medium">Due</th><th className="px-4 py-2 text-left font-medium">Status</th><th className="px-4 py-2" /></tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {tasks.data.length === 0 && <tr><td colSpan={6} className="px-4 py-10 text-center text-sm text-gray-500">Nothing is waiting on you.</td></tr>}
                        {tasks.data.map((t) => (
                            <tr key={t.id} className={t.overdue ? 'bg-red-50/50' : ''}>
                                <td className="px-4 py-3">
                                    <p className="font-medium text-gray-900">{t.name}</p>
                                    {t.delegated_by && <p className="text-[11px] text-blue-600">Delegated to you by {t.delegated_by}</p>}
                                </td>
                                <td className="px-4 py-3 text-gray-700">
                                    {t.subject.reference}
                                    {t.subject.title && <span className="block text-[11px] text-gray-400">{t.subject.title}</span>}
                                </td>
                                <td className="px-4 py-3 text-gray-600">{t.process ?? '—'}</td>
                                <td className="px-4 py-3">
                                    {t.due_display ? (
                                        <>
                                            <span className={t.overdue ? 'font-semibold text-red-600' : 'text-gray-700'}>{t.due_display}</span>
                                            {t.overdue && <span className="block text-[11px] text-red-500">{t.hours_overdue}h over</span>}
                                        </>
                                    ) : <span className="text-gray-400">—</span>}
                                </td>
                                <td className="px-4 py-3"><span className={`rounded-full px-2 py-0.5 text-[11px] font-medium ${badgeClass(t.status.color)}`}>{t.status.label}</span></td>
                                <td className="px-4 py-3 text-right"><Link href={t.url} className="rounded-lg bg-[var(--color-primary)] px-3 py-1.5 text-xs font-medium text-white hover:opacity-90">Open</Link></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                <Pagination links={tasks.links} meta={tasks.meta} />
            </div>
        </AuthenticatedLayout>
    );
}
