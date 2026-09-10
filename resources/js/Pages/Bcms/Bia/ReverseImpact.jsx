import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * "If this fails, what stops?"
 *
 * THE AGGREGATE EXPOSURE IS THE SHORTEST RTO, not the sum and not the longest.
 * It is the time the organisation has before its first commitment is breached —
 * the number that actually bounds the outage. Summing recovery times would be
 * arithmetic on unrelated clocks; taking the longest would report the most
 * relaxed process as the constraint.
 */
export default function ReverseImpact({ impact }) {
    return (
        <AppLayout title={`Impact of ${impact.target.name}`}>
            <Head title={`Impact of ${impact.target.name}`} />

            <PageHeader
                title={impact.target.name}
                subtitle={`${impact.target.type_label} · what stops if this fails`}
                actions={<Link href={tryRoute('bcms.dependencies.index')} className="btn-secondary text-sm">Back to dependencies</Link>}
            />

            <div className="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
                {[
                    ['Processes affected', impact.process_count],
                    ['Of which stop entirely', impact.halting_count],
                    ['Tier 1', impact.tier1_count],
                    ['Critical services', impact.critical_service_count],
                ].map(([label, value]) => (
                    <div key={label} className="rounded-lg border border-gray-200 bg-white p-4">
                        <p className="text-xs uppercase tracking-wide text-gray-500">{label}</p>
                        <p className="mt-1 font-mono text-2xl text-gray-900">{value}</p>
                    </div>
                ))}
            </div>

            <div className="mb-6 rounded-lg border border-gray-200 bg-white p-5">
                <p className="text-xs uppercase tracking-wide text-gray-500">Aggregate exposure</p>
                {impact.aggregate_rto_hours === null ? (
                    <p className="mt-1 text-sm text-gray-500">
                        None of the processes that stop has an approved BIA, so there is no stated recovery time to
                        measure the exposure against.
                    </p>
                ) : (
                    <>
                        <p className="mt-1 font-mono text-3xl text-gray-900">{impact.aggregate_rto_hours} hours</p>
                        <p className="mt-1 text-sm text-gray-600">
                            The shortest recovery time among the processes that stop — the time before the first
                            commitment is breached.
                        </p>
                    </>
                )}
            </div>

            <div className="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                <table className="w-full text-sm">
                    <thead className="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th className="px-4 py-3">Process</th>
                            <th className="px-4 py-3">Tier</th>
                            <th className="px-4 py-3">Relation</th>
                            <th className="px-4 py-3">RTO</th>
                            <th className="px-4 py-3">Notes</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {impact.processes.length === 0 && (
                            <tr><td colSpan={5} className="px-4 py-8 text-center text-gray-500">
                                Nothing depends on this yet.
                            </td></tr>
                        )}
                        {impact.processes.map((p) => (
                            <tr key={p.process_id} className={p.halts ? '' : 'text-gray-500'}>
                                <td className="px-4 py-3">
                                    <span className="text-gray-900">{p.name}</span>
                                    <span className="block font-mono text-xs text-gray-500">{p.code}</span>
                                </td>
                                <td className="px-4 py-3">{p.tier ? `Tier ${p.tier}` : '—'}</td>
                                <td className="px-4 py-3">{p.halts ? 'stops' : 'degrades'}</td>
                                <td className="px-4 py-3 font-mono">
                                    {p.rto_hours === null ? <span className="text-gray-400">no approved BIA</span> : `${p.rto_hours} h`}
                                </td>
                                <td className="px-4 py-3 text-xs">
                                    {p.single_point_of_failure && <span className="rounded bg-red-50 px-1.5 py-0.5 text-red-800">single point of failure</span>}
                                    {p.alternative_available && <span className="ml-1 rounded bg-emerald-50 px-1.5 py-0.5 text-emerald-800">alternative exists</span>}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
