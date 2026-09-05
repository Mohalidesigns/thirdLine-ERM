import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import KpiCard from '@/Components/KpiCard';
import SeriesChart from '@/Components/Quantification/SeriesChart';
import { number } from '@/Components/Quantification/figures';
import tryRoute from '@/lib/tryRoute';

const IMPACT_CLASSES = {
    critical: 'bg-red-100 text-red-700',
    high: 'bg-orange-100 text-orange-700',
    medium: 'bg-yellow-100 text-yellow-700',
    low: 'bg-gray-100 text-gray-700',
};

const humanise = (value) => (value ? String(value).replaceAll('_', ' ') : '—');

const shortDate = (value) =>
    value ? new Date(value).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';

/**
 * Regulatory Pulse (migration Phase 5.6).
 *
 * Every figure comes from RegulatoryPulseService, over the tenant's own
 * `regulatory_circulars` and `regulatory_deadlines`. The mapping is in
 * docs/ai-number-provenance.md and the prop names here are unchanged from the
 * Blade view's, so that document still holds without edits.
 *
 * The screen used to be a hardcoded array of six circulars with invented
 * reference numbers, completion percentages and action counts — identical for
 * every tenant. The blue band below is not decoration: it is the screen saying
 * what it does and does not do, and it stays.
 */
export default function RegulatoryPulse({ feed, upcomingDeadlines, impactMix, summary, window, asAt }) {
    const circularsUrl = tryRoute('risk.regulatory.circulars');

    return (
        <AuthenticatedLayout title="Regulatory Pulse">
            <Head title="Regulatory Pulse" />

            <div className="flex items-start justify-between mb-6">
                <div>
                    <h1 className="text-xl font-bold text-[#1A365D]">Regulatory Pulse</h1>
                    <p className="text-sm text-gray-500 mt-1">
                        Circulars and filing deadlines recorded against this organisation.
                    </p>
                </div>
                <div className="text-right text-xs text-gray-500">
                    <p>
                        As at{' '}
                        <span className="font-semibold text-[#1A365D]">
                            {new Date(asAt).toLocaleString('en-GB', {
                                day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
                            })}
                        </span>
                    </p>
                    {circularsUrl && (
                        <Link href={circularsUrl} className="text-[#1A365D] hover:underline mt-1 inline-block">
                            Manage circulars
                        </Link>
                    )}
                </div>
            </div>

            <div className="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-6 flex items-start gap-3">
                <span className="material-symbols-outlined text-blue-600 text-lg">info</span>
                <p className="text-xs text-blue-900 leading-relaxed">
                    This screen reads your own <span className="font-medium">regulatory circulars</span> and{' '}
                    <span className="font-medium">filing deadlines</span> tables. It does not scan external sources,
                    and it shows nothing your team has not recorded. Compliance percentages are the values entered
                    against each circular.
                </p>
            </div>

            <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <KpiCard
                    title="Circulars on file"
                    value={number(summary.circulars_total)}
                    icon="description"
                    color="primary"
                    subtitle={`${summary.circulars_recent} issued in last ${window.recent_window_days} days`}
                />
                <KpiCard title="High or critical impact" value={number(summary.high_impact)} icon="priority_high" color="danger" />
                <KpiCard
                    title="Past effective date, not compliant"
                    value={number(summary.past_effective_date_not_compliant)}
                    icon="event_busy"
                    color="warning"
                />
                <KpiCard
                    title="Filings overdue"
                    value={number(summary.deadlines_overdue)}
                    icon="assignment_late"
                    color="danger"
                    subtitle={`${summary.deadlines_due_30d} due in ${window.deadline_horizon_days} days`}
                />
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                <section className="bg-white rounded-xl border border-gray-200 p-5">
                    <h3 className="text-sm font-semibold text-[#1A365D] mb-4">Impact mix</h3>
                    {Object.keys(impactMix ?? {}).length === 0 ? (
                        <p className="text-sm text-gray-400 italic py-8 text-center">No circulars on file.</p>
                    ) : (
                        <SeriesChart
                            labels={Object.keys(impactMix).map(humanise)}
                            values={Object.values(impactMix)}
                            height={200}
                            ariaLabel="Circulars by impact level"
                            valueLabel={(v) => String(Math.round(v))}
                        />
                    )}
                </section>

                <section className="lg:col-span-2 bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <header className="px-5 py-4 border-b border-gray-100">
                        <h3 className="text-sm font-semibold text-[#1A365D]">Filings due</h3>
                        <p className="text-xs text-gray-500 mt-1">
                            Next {window.deadline_horizon_days} days, and anything already overdue
                        </p>
                    </header>
                    {upcomingDeadlines.length === 0 ? (
                        <p className="px-5 py-8 text-sm text-gray-500 text-center">Nothing due in the window.</p>
                    ) : (
                        <ul className="divide-y divide-gray-100">
                            {upcomingDeadlines.map((deadline, index) => (
                                <li key={index} className="px-5 py-3 flex items-center gap-3">
                                    <span className="badge bg-blue-50 text-blue-700 text-[10px]">{deadline.regulator}</span>
                                    <div className="flex-1 min-w-0">
                                        <p className="text-sm font-medium text-gray-800 truncate">{deadline.title}</p>
                                        <p className="text-xs text-gray-500">{deadline.report_type}</p>
                                    </div>
                                    <span className={`text-xs shrink-0 ${deadline.is_overdue ? 'text-red-600 font-semibold' : 'text-gray-500'}`}>
                                        {shortDate(deadline.due_at ?? deadline.deadline_date)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>

            <section className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <header className="px-5 py-4 border-b border-gray-100">
                    <h3 className="text-sm font-semibold text-[#1A365D]">Circular feed</h3>
                </header>

                {feed.length === 0 ? (
                    <p className="px-5 py-10 text-sm text-gray-500 text-center">
                        No circulars have been recorded. This feed shows what your team enters, not an external scan.
                    </p>
                ) : (
                    <div className="divide-y divide-gray-100">
                        {feed.map((item, index) => (
                            <article key={index} className={`px-5 py-4 ${item.is_overdue ? 'border-l-4 border-l-red-500' : ''}`}>
                                <div className="flex items-start justify-between gap-4">
                                    <div className="flex-1 min-w-0">
                                        <div className="flex items-center gap-2 flex-wrap">
                                            <span className="badge bg-[#1A365D] text-white text-[10px]">{item.regulator}</span>
                                            <span className="text-[10px] font-mono text-gray-500">{item.reference}</span>
                                            {item.is_overdue && (
                                                <span className="badge bg-red-100 text-red-700 text-[10px]">Past effective date</span>
                                            )}
                                        </div>
                                        <p className="text-sm font-semibold text-gray-800 mt-1.5">{item.title}</p>
                                        {item.summary && <p className="text-xs text-gray-600 mt-1">{item.summary}</p>}
                                    </div>
                                    <span className={`badge text-[10px] shrink-0 ${IMPACT_CLASSES[item.impact] ?? IMPACT_CLASSES.low}`}>
                                        {humanise(item.impact)} impact
                                    </span>
                                </div>

                                <dl className="grid grid-cols-2 md:grid-cols-4 gap-3 mt-3 text-xs">
                                    <div>
                                        <dt className="text-gray-500">Issued</dt>
                                        <dd className="font-medium text-gray-800 mt-0.5">{shortDate(item.issued_at)}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-gray-500">Effective</dt>
                                        <dd className="font-medium text-gray-800 mt-0.5">{shortDate(item.effective_at)}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-gray-500">Compliance</dt>
                                        <dd className="font-medium text-gray-800 mt-0.5">
                                            {humanise(item.compliance_status)}
                                            <span className="block text-gray-500 font-normal">
                                                {item.compliance_pct !== null ? `${Math.round(item.compliance_pct)}%` : 'Not recorded'}
                                            </span>
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-gray-500">Linked</dt>
                                        <dd className="font-medium text-gray-800 mt-0.5">
                                            {item.linked_risks} risk{item.linked_risks === 1 ? '' : 's'},{' '}
                                            {item.linked_controls} control{item.linked_controls === 1 ? '' : 's'}
                                        </dd>
                                    </div>
                                </dl>

                                {item.action_required && (
                                    <p className="text-xs text-gray-700 mt-3 bg-gray-50 rounded-lg p-3">
                                        <span className="font-semibold">Action required:</span> {item.action_required}
                                        {item.assigned_to && <span className="text-gray-500"> — {item.assigned_to}</span>}
                                    </p>
                                )}
                            </article>
                        ))}
                    </div>
                )}
            </section>
        </AuthenticatedLayout>
    );
}
