import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';

/**
 * Exit readiness — the stage every competitor skips.
 *
 * IT LEADS WITH THE GAP. A dashboard of traffic lights over the plans that
 * exist flatters a programme that has written three plans and needs thirty;
 * the first number on the page is how many engagements require a plan and have
 * none.
 *
 * A PLAN NEVER TESTED IS RED, the same as no plan. The bank's actual ability
 * to leave is identical in both cases, and an amber saying "we wrote
 * something" would let a programme report progress it has not made.
 */
export default function Index({ engagements = [], summary = {}, can = {} }) {
    const gap = summary.required_without_plan ?? 0;

    return (
        <AppLayout title="Exit readiness">
            <Head title="Exit readiness" />

            <PageHeader
                title="Exit readiness"
                subtitle="Whether this institution could actually leave each provider, and how recently it checked."
            />

            <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <Tile
                    label="Require a plan and have none"
                    value={gap}
                    tone={gap > 0 ? 'critical' : null}
                    hint={`of ${summary.total_required ?? 0} that require one`}
                />
                <Tile
                    label="Have a plan, never tested"
                    value={summary.never_tested ?? 0}
                    tone={summary.never_tested ? 'critical' : null}
                    hint="a document, not a capability"
                />
                <Tile
                    label="Past their test interval"
                    value={summary.stale ?? 0}
                    tone={summary.stale ? 'warn' : null}
                    hint="each carries a residual uplift"
                />
                <Tile label="Tested and current" value={summary.current ?? 0} hint="green" />
            </div>

            <div className="card overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200 text-sm">
                    <caption className="px-4 py-2 text-left text-xs text-gray-500">
                        Red means the institution cannot demonstrate it could leave — whether because there is no
                        plan, or because the plan has never been exercised.
                    </caption>
                    <thead className="bg-gray-50">
                        <tr>
                            <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600" />
                            <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Engagement</th>
                            <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Provider</th>
                            <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Tier</th>
                            <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Plan</th>
                            <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Last tested</th>
                            <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Next due</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {engagements.map((row) => (
                            <tr key={row.uuid}>
                                <td className="px-4 py-2"><Light value={row.light} /></td>
                                <th scope="row" className="px-4 py-2 text-left font-medium text-gray-900">
                                    <Link
                                        href={route('tprm.engagements.show', row.uuid)}
                                        className="text-blue-700 hover:underline"
                                    >
                                        {row.name}
                                    </Link>
                                    {row.required && !row.has_plan && (
                                        <span className="block text-xs font-normal text-red-700">
                                            {row.requirement_basis}
                                        </span>
                                    )}
                                </th>
                                <td className="px-4 py-2">{row.third_party}</td>
                                <td className="px-4 py-2 capitalize">{row.tier}</td>
                                <td className="px-4 py-2 text-xs">
                                    {row.has_plan ? row.credibility : <span className="text-red-700">none</span>}
                                </td>
                                <td className="px-4 py-2 text-xs">{row.last_tested_at ?? '—'}</td>
                                <td className="px-4 py-2 text-xs">{row.next_test_due ?? '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}

function Tile({ label, value, hint, tone }) {
    return (
        <div className="card p-4">
            <p className="text-xs font-medium uppercase tracking-wide text-gray-500">{label}</p>
            <p className={`mt-1 text-2xl font-semibold ${
                tone === 'critical' ? 'text-red-700' : tone === 'warn' ? 'text-amber-700' : 'text-gray-900'
            }`}>{value}</p>
            <p className="mt-0.5 text-xs text-gray-500">{hint}</p>
        </div>
    );
}

function Light({ value }) {
    const colour = {
        green: 'bg-green-500',
        amber: 'bg-amber-500',
        red: 'bg-red-600',
    }[value] ?? 'bg-gray-300';

    return (
        <span className="flex items-center gap-1.5">
            <span className={`inline-block h-2.5 w-2.5 rounded-full ${colour}`} />
            <span className="sr-only">{value}</span>
        </span>
    );
}
