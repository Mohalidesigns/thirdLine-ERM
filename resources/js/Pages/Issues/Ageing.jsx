import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import KpiCard from '@/Components/KpiCard';
import PageHeader from '@/Components/PageHeader';
import TrendChart from '@/Components/TrendChart';
import { BAND_COLORS, priorityTone, statusTone, titleCase } from './format';

/**
 * The ageing report (migration Phase 4.4: risk/issues/ageing.blade.php).
 *
 * Figures come from App\Services\Issues\IssueAgeingService. The priority × band
 * matrix is rendered as a table rather than a stacked Chart.js bar: it is
 * sixteen numbers, and a table shows all sixteen at once where the chart showed
 * four stacks you had to hover to read.
 */
export default function Ageing({ bands = [], matrix = [], trend = [], oldest = [], total = 0 }) {
    const worst = Math.max(1, ...matrix.flatMap((row) => row.bands));

    return (
        <AuthenticatedLayout title="Issue Ageing">
            <Head title="Issue Ageing" />

            <PageHeader
                title="Issue Ageing"
                subtitle={`${total} open ${total === 1 ? 'issue' : 'issues'}, by how long they have been open`}
                breadcrumbs={[{ label: 'Issues', href: route('risk.issues.index') }, { label: 'Ageing' }]}
            />

            <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                {bands.map((band, index) => (
                    <KpiCard
                        key={band.label}
                        title={band.label}
                        value={band.value}
                        icon="schedule"
                        color={index >= 3 ? 'danger' : index === 2 ? 'warning' : index === 1 ? 'info' : 'success'}
                    />
                ))}
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <div className="px-5 py-4 border-b border-gray-100">
                        <h3 className="text-sm font-semibold text-[#1A365D]">Ageing by Priority</h3>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="data-table">
                            <thead>
                                <tr>
                                    <th>Priority</th>
                                    {bands.map((band) => <th key={band.label} className="text-center">{band.label}</th>)}
                                    <th className="text-center">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                {matrix.map((row) => (
                                    <tr key={row.priority}>
                                        <td><span className={`badge ${priorityTone(row.priority)}`}>{titleCase(row.priority)}</span></td>
                                        {row.bands.map((count, index) => (
                                            <td key={index} className="text-center">
                                                {count > 0 ? (
                                                    <span
                                                        className="inline-flex items-center justify-center min-w-[2rem] px-2 py-0.5 rounded text-xs font-semibold text-white"
                                                        style={{
                                                            backgroundColor: BAND_COLORS[index],
                                                            opacity: 0.35 + (count / worst) * 0.65,
                                                        }}
                                                    >
                                                        {count}
                                                    </span>
                                                ) : (
                                                    <span className="text-gray-300">—</span>
                                                )}
                                            </td>
                                        ))}
                                        <td className="text-center text-xs font-semibold">{row.total}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

                <div className="bg-white rounded-xl border border-gray-200 p-5">
                    <h3 className="text-sm font-semibold text-[#1A365D] mb-1">Opened and Closed</h3>
                    <p className="text-xs text-gray-400 mb-4">Trailing six months</p>
                    <TrendChart
                        data={trend}
                        series={[
                            { key: 'opened', label: 'Opened', color: '#C53030' },
                            { key: 'closed', label: 'Closed', color: '#2D7D46' },
                        ]}
                    />
                </div>
            </div>

            <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <div className="px-5 py-4 border-b border-gray-100">
                    <h3 className="text-sm font-semibold text-[#1A365D]">Oldest Open Issues</h3>
                </div>
                <div className="overflow-x-auto">
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Issue</th>
                                <th>Owner</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Due</th>
                                <th>Age</th>
                            </tr>
                        </thead>
                        <tbody>
                            {oldest.length === 0 && (
                                <tr><td colSpan={7} className="text-center py-10 text-gray-400">No open issues.</td></tr>
                            )}
                            {oldest.map((issue) => (
                                <tr key={issue.id}>
                                    <td className="text-xs font-mono">
                                        <Link href={issue.url} className="text-[#1A365D] hover:underline">{issue.reference}</Link>
                                    </td>
                                    <td className="text-sm font-medium max-w-xs truncate" title={issue.title}>{issue.title}</td>
                                    <td className="text-xs">{issue.owner ?? '—'}</td>
                                    <td><span className={`badge ${priorityTone(issue.priority)}`}>{titleCase(issue.priority)}</span></td>
                                    <td><span className={`badge ${statusTone(issue.status)}`}>{titleCase(issue.status)}</span></td>
                                    <td className="text-xs text-gray-500">{issue.dueDate ?? '—'}</td>
                                    <td className="text-xs font-semibold">{issue.ageDays} days</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
