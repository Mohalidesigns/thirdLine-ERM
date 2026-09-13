import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * The gap analysis — the screen that gets budget approved.
 *
 * RANKED BY EXPOSURE, NOT BY PROCESS CODE. Biggest shortfall first, then the
 * processes with no strategy at all, then everything that is fine. A gap table
 * in alphabetical order is one nobody works down.
 *
 * A PROCESS WITH NO STRATEGY IS IN THE TABLE, with a reason instead of a
 * number. It is the largest gap there is, and it is exactly the row a report
 * built by joining strategies to processes silently drops.
 */
export default function Gap({ analysis = {}, max_tier, can = {} }) {
    const rows = analysis.rows ?? [];
    const summary = analysis.summary ?? {};

    const tierFilter = (tier) => router.get(
        tryRoute('bcms.strategy.gap'),
        { tier: tier || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );

    return (
        <AppLayout title="Strategy gap analysis">
            <Head title="Strategy gap analysis" />

            <PageHeader
                title="Gap analysis"
                subtitle="Where the organisation cannot recover as fast as it has decided it must."
                actions={(
                    <div className="flex gap-2">
                        <Link href={tryRoute('bcms.strategy.index')} className="btn-secondary text-sm">
                            Strategy register
                        </Link>
                        {can.export && (
                            <a
                                href={`${tryRoute('bcms.strategy.gap.export')}${max_tier ? `?tier=${max_tier}` : ''}`}
                                className="btn-primary text-sm"
                            >
                                Export CSV
                            </a>
                        )}
                    </div>
                )}
            />

            <div className="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
                {[
                    { label: 'Processes in scope', value: summary.processes },
                    { label: 'With a shortfall', value: summary.with_gap, alarm: true },
                    { label: 'No strategy at all', value: summary.unprotected, alarm: true },
                    {
                        label: 'Total shortfall',
                        value: summary.total_shortfall_hours == null ? '—' : `${summary.total_shortfall_hours}h`,
                    },
                ].map((tile) => (
                    <div
                        key={tile.label}
                        className={`rounded-lg border p-4 ${tile.alarm && tile.value > 0 ? 'border-red-300 bg-red-50' : 'border-gray-200 bg-white'}`}
                    >
                        <p className="text-xs font-medium uppercase tracking-wide text-gray-500">{tile.label}</p>
                        <p className="mt-1 font-mono text-2xl text-gray-900">{tile.value ?? 0}</p>
                    </div>
                ))}
            </div>

            <div className="mb-4 flex items-center gap-2 text-xs">
                <span className="text-gray-500">Criticality tier:</span>
                {[1, 2, 3, null].map((t) => (
                    <button
                        key={String(t)}
                        type="button"
                        onClick={() => tierFilter(t)}
                        className={`rounded px-2 py-1 ${(t === null && max_tier == null) || String(max_tier) === String(t)
                            ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-700'}`}
                    >
                        {t === null ? 'All' : `Tier ${t} and above`}
                    </button>
                ))}
            </div>

            {summary.stale_assessments > 0 && (
                <p className="mb-4 rounded border border-amber-300 bg-amber-50 p-3 text-xs text-amber-800">
                    {summary.stale_assessments} strategies were assessed against a BIA that has since been superseded.
                    The shortfall column is computed against today&rsquo;s approved BIA; the &ldquo;at assessment&rdquo;
                    column is what the approved strategy paper said. Where they differ, the strategy needs reassessing.
                </p>
            )}

            <div className="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                <table className="min-w-full divide-y divide-gray-200 text-sm">
                    <thead className="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th className="px-4 py-3 text-left">Process</th>
                            <th className="px-4 py-3 text-left">Business unit</th>
                            <th className="px-4 py-3 text-right">Required</th>
                            <th className="px-4 py-3 text-right">Achievable</th>
                            <th className="px-4 py-3 text-right">Shortfall</th>
                            <th className="px-4 py-3 text-right">At assessment</th>
                            <th className="px-4 py-3 text-left">Strategy</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {rows.length === 0 && (
                            <tr>
                                <td colSpan={7} className="px-4 py-8 text-center text-gray-600">
                                    No processes are in scope at this tier.
                                </td>
                            </tr>
                        )}

                        {rows.map((row) => (
                            <tr key={row.process_id} className={row.shortfall_hours != null ? 'bg-red-50' : undefined}>
                                <td className="px-4 py-3">
                                    <Link href={tryRoute('bcms.strategy.show', row.process_uuid)} className="font-medium text-gray-900 hover:underline">
                                        {row.code}
                                    </Link>
                                    <span className="block text-xs text-gray-600">{row.name}</span>
                                    {row.reason && <span className="block text-xs italic text-amber-700">{row.reason}</span>}
                                </td>
                                <td className="px-4 py-3 text-xs text-gray-600">{row.business_unit ?? '—'}</td>
                                <td className="px-4 py-3 text-right font-mono">
                                    {row.rto_required_hours != null ? `${row.rto_required_hours}h` : '—'}
                                </td>
                                <td className="px-4 py-3 text-right font-mono">
                                    {row.rto_achievable_hours != null ? `${row.rto_achievable_hours}h` : '—'}
                                </td>
                                <td className="px-4 py-3 text-right font-mono font-semibold text-red-700">
                                    {row.shortfall_hours != null ? `${row.shortfall_hours}h` : ''}
                                </td>
                                <td className="px-4 py-3 text-right font-mono text-xs text-gray-500">
                                    {row.gap_at_assessment_hours == null
                                        ? '—'
                                        : `${row.gap_at_assessment_hours > 0 ? '+' : ''}${row.gap_at_assessment_hours}h`}
                                    {row.assessment_is_stale && <span className="ml-1 text-amber-700">stale</span>}
                                </td>
                                <td className="px-4 py-3 text-xs">
                                    {row.strategy_label ?? <span className="text-red-700">None selected</span>}
                                    {row.approval_status && row.approval_status !== 'approved' && (
                                        <span className="ml-1 text-amber-700">({row.approval_status})</span>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
