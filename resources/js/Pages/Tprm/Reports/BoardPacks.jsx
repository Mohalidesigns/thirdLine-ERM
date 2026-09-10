import { Head, Link, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';

/**
 * The board pack register — FR-RPT-05.
 *
 * A PACK IS A SNAPSHOT, AND THE LIST SAYS SO. Each row shows whether its
 * figures are still moving or frozen, because a committee member opening last
 * quarter's pack needs to know they are reading what was tabled and not a
 * fresh recomputation.
 *
 * THE TREND PLOTS SIGNED-OFF PACKS ONLY. A line that moved because somebody
 * re-ran a draft this morning is a line nobody can discuss in a meeting.
 */
export default function BoardPacks({ packs = [], trend = [], can = {} }) {
    const { data, setData, post, processing, errors } = useForm({
        period_label: '',
        as_at: new Date().toISOString().slice(0, 10),
    });

    const submit = (event) => {
        event.preventDefault();
        post(route('tprm.reports.board-packs.prepare'));
    };

    return (
        <AppLayout title="Board packs">
            <Head title="Board packs" />

            <PageHeader
                title="Board and Risk Committee packs"
                subtitle="Each pack freezes its figures when it is signed off, so the numbers the minutes cite are the numbers that reprint."
            />

            {can.prepare && (
                <form onSubmit={submit} className="card mb-6 flex flex-wrap items-end gap-4 p-4">
                    <label className="text-sm">
                        <span className="mb-1 block font-medium text-gray-700">Period</span>
                        <input
                            type="text"
                            className="w-40 rounded border-gray-300 text-sm"
                            placeholder="Q3 2026"
                            value={data.period_label}
                            onChange={(event) => setData('period_label', event.target.value)}
                        />
                        {errors.period_label && (
                            <span className="mt-1 block text-xs text-red-600">{errors.period_label}</span>
                        )}
                    </label>

                    <label className="text-sm">
                        <span className="mb-1 block font-medium text-gray-700">Position as at</span>
                        <input
                            type="date"
                            className="rounded border-gray-300 text-sm"
                            value={data.as_at}
                            onChange={(event) => setData('as_at', event.target.value)}
                        />
                        {errors.as_at && <span className="mt-1 block text-xs text-red-600">{errors.as_at}</span>}
                    </label>

                    <button type="submit" className="btn-primary" disabled={processing}>
                        Prepare pack
                    </button>

                    <p className="w-full text-xs text-gray-500">
                        Preparing an existing draft refreshes its figures. A pack that has been signed off is
                        frozen — prepare a new period rather than recomputing an approved one.
                    </p>
                </form>
            )}

            {trend.length > 1 && (
                <div className="card mb-6 overflow-x-auto p-4">
                    <h2 className="mb-2 text-sm font-semibold text-gray-800">Trend across signed-off packs</h2>
                    <table className="min-w-full text-sm">
                        <thead>
                            <tr>
                                <th scope="col" className="px-3 py-1 text-left font-medium text-gray-600">Period</th>
                                <th scope="col" className="px-3 py-1 text-right font-medium text-gray-600">Engagements</th>
                                <th scope="col" className="px-3 py-1 text-right font-medium text-gray-600">Mean residual</th>
                                <th scope="col" className="px-3 py-1 text-right font-medium text-gray-600">Open findings</th>
                                <th scope="col" className="px-3 py-1 text-right font-medium text-gray-600">HHI</th>
                                <th scope="col" className="px-3 py-1 text-right font-medium text-gray-600">Exit gaps</th>
                                <th scope="col" className="px-3 py-1 text-right font-medium text-gray-600">Incidents</th>
                            </tr>
                        </thead>
                        <tbody>
                            {trend.map((row) => (
                                <tr key={row.period_label}>
                                    <td className="px-3 py-1">{row.period_label}</td>
                                    <Cell value={row.engagements} />
                                    <Cell value={row.mean_residual} />
                                    <Cell value={row.open_findings} />
                                    <Cell value={row.hhi} />
                                    <Cell value={row.exit_plan_gaps} />
                                    <Cell value={row.incidents} />
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            <div className="card overflow-hidden">
                <table className="min-w-full divide-y divide-gray-200 text-sm">
                    <thead className="bg-gray-50">
                        <tr>
                            <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Period</th>
                            <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">As at</th>
                            <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Status</th>
                            <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Prepared by</th>
                            <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Signed off by</th>
                            <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Narrative</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {packs.map((pack) => (
                            <tr key={pack.uuid}>
                                <td className="px-4 py-2 font-medium">
                                    <Link className="text-indigo-600" href={pack.url}>{pack.period_label}</Link>
                                </td>
                                <td className="px-4 py-2">{pack.as_at}</td>
                                <td className="px-4 py-2">
                                    <span
                                        className={`rounded px-2 py-0.5 text-xs font-medium ${
                                            pack.status === 'signed_off'
                                                ? 'bg-emerald-50 text-emerald-700'
                                                : 'bg-gray-100 text-gray-700'
                                        }`}
                                    >
                                        {pack.status_label}
                                    </span>
                                </td>
                                <td className="px-4 py-2">{pack.prepared_by ?? '—'}</td>
                                <td className="px-4 py-2">{pack.signed_off_by ?? '—'}</td>
                                <td className="px-4 py-2 text-xs text-gray-600">{pack.narrative_provenance}</td>
                            </tr>
                        ))}
                        {packs.length === 0 && (
                            <tr>
                                <td colSpan={6} className="px-4 py-10 text-center text-sm text-gray-500">
                                    No pack has been prepared. The first one establishes the baseline the trend
                                    is measured against.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}

function Cell({ value }) {
    return (
        <td className="px-3 py-1 text-right tabular-nums">
            {value === null || value === undefined ? <span className="text-gray-400">—</span> : value}
        </td>
    );
}
