import { Head, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import Pagination from '@thirdline/ui/Components/Pagination';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * The override register — FR-TIER-04's "overrides are reported separately to
 * the risk committee".
 *
 * Every exception in the module lands in one table: a tier override, a
 * blocking-clause waiver, a due-diligence waiver, an access exception. Four
 * separate reports would be four reports a committee does not read.
 *
 * Sorted by expiry, soonest first, because the column a committee acts on is
 * "what lapses next" — an exception nobody revisits is the failure this
 * register exists to prevent.
 */
export default function Index({ waivers, summary = {}, includeLapsed = false }) {
    const rows = waivers?.data ?? [];

    return (
        <AppLayout title="Override register">
            <Head title="Override register" />

            <PageHeader
                title="Override register"
                subtitle="Every exception in force across the third-party programme, soonest to lapse first."
                actions={
                    <button
                        type="button"
                        onClick={() => router.get(tryRoute('tprm.overrides.index'), { include_lapsed: !includeLapsed }, { preserveScroll: true })}
                        className="btn-secondary text-sm"
                    >
                        {includeLapsed ? 'Hide lapsed' : 'Include lapsed'}
                    </button>
                }
            />

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <Tile label="In force" value={summary.in_force ?? 0} hint="approved and not expired" />
                <Tile label="Expiring in 30 days" value={summary.expiring_30 ?? 0} hint="need a decision" tone={summary.expiring_30 ? 'warn' : null} />
                <Tile label="Lapsed" value={summary.lapsed ?? 0} hint="expired, tier restored" />
            </div>

            <div className="card overflow-hidden">
                {rows.length ? (
                    <table className="w-full text-sm">
                        <thead className="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th className="px-4 py-3 font-medium">Type</th>
                                <th className="px-4 py-3 font-medium">Engagement</th>
                                <th className="px-4 py-3 font-medium">Rationale</th>
                                <th className="px-4 py-3 font-medium">Approved by</th>
                                <th className="px-4 py-3 font-medium">Expires</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {rows.map((row) => (
                                <tr key={row.id} className={row.in_force ? '' : 'bg-gray-50 text-gray-500'}>
                                    <td className="px-4 py-3">
                                        <span className="rounded bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-700">
                                            {row.type_label}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3">
                                        {row.url ? (
                                            <a href={row.url} className="font-mono text-xs text-blue-700 hover:underline">
                                                {row.engagement}
                                            </a>
                                        ) : <span className="text-xs">—</span>}
                                        <p className="text-xs text-gray-500">{row.third_party}</p>
                                    </td>
                                    <td className="px-4 py-3 max-w-md">
                                        <p className="line-clamp-2 text-xs text-gray-700">{row.rationale}</p>
                                    </td>
                                    <td className="px-4 py-3 text-xs">
                                        {row.approver ?? '—'}
                                        {row.approver_role && <p className="text-gray-500">{row.approver_role}</p>}
                                    </td>
                                    <td className="px-4 py-3 text-xs">
                                        {row.expires_at ?? 'No expiry'}
                                        {row.days_remaining !== null && row.in_force && (
                                            <p className={row.days_remaining <= 30 ? 'font-medium text-amber-700' : 'text-gray-500'}>
                                                {row.days_remaining < 0
                                                    ? `${Math.abs(row.days_remaining)} days ago`
                                                    : `${row.days_remaining} days left`}
                                            </p>
                                        )}
                                        {! row.in_force && <p className="text-gray-400">{row.status}</p>}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                ) : (
                    <div className="p-8 text-center text-sm text-gray-500">
                        No overrides are in force. Every engagement is on the tier the model computed.
                    </div>
                )}
            </div>

            {waivers?.links && <Pagination links={waivers.links} className="mt-4" />}
        </AppLayout>
    );
}

function Tile({ label, value, hint, tone }) {
    return (
        <div className="card p-4">
            <p className="text-xs font-medium uppercase tracking-wide text-gray-500">{label}</p>
            <p className={`mt-1 text-2xl font-semibold ${tone === 'warn' ? 'text-amber-700' : 'text-gray-900'}`}>{value}</p>
            <p className="mt-0.5 text-xs text-gray-500">{hint}</p>
        </div>
    );
}
