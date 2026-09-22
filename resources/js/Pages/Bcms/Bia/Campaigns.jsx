import { Head, router, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import FormField from '@thirdline/ui/Components/FormField';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * The campaign dashboard.
 *
 * THE OVERDUE LIST IS THE SCREEN, not the funnel. A response percentage is a
 * picture; the named people who have not replied, with how many times they have
 * been chased and whether their manager has been told, is what a coordinator
 * works down on a Monday morning.
 *
 * THE RESPONSE RATE IS NULL BEFORE DISTRIBUTION, NOT ZERO. A rate over no
 * assessments is undefined, and 0% reads as "nobody replied".
 */
export default function Campaigns({ campaigns = [], selected, progress, process_count: processCount, can = {} }) {
    const create = useForm({ name: '', cycle: 'annual', closes_at: '' });
    const campaign = campaigns.find((c) => c.id === selected) ?? null;

    const postTo = (url) => router.post(url, {}, { preserveScroll: true });

    return (
        <AppLayout title="BIA campaigns">
            <Head title="BIA campaigns" />

            <PageHeader
                title="BIA campaigns"
                subtitle="Distributing the analysis, and chasing the people who have not done it."
            />

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <aside className="space-y-4">
                    <ul className="divide-y divide-gray-200 rounded-lg border border-gray-200 bg-white">
                        {campaigns.length === 0 && <li className="p-4 text-sm text-gray-500">No campaigns yet.</li>}
                        {campaigns.map((c) => (
                            <li key={c.id}>
                                <button type="button"
                                    className={`w-full px-4 py-3 text-left text-sm ${c.id === selected ? 'bg-gray-50' : ''}`}
                                    onClick={() => router.get(tryRoute('bcms.bia-campaigns.index'), { campaign: c.id }, { preserveScroll: true })}>
                                    <span className="font-medium text-gray-900">{c.name}</span>
                                    <span className="block text-xs text-gray-500">
                                        {c.status} · closes {c.closes_at ?? 'not set'}
                                        {c.response_rate === null ? '' : ` · ${c.response_rate}%`}
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>

                    {can.manage && (
                        <form
                            onSubmit={(e) => { e.preventDefault(); create.post(tryRoute('bcms.bia-campaigns.store')); }}
                            className="card"
                        >
                            <div className="card-header"><h2 className="form-section-title">New campaign</h2></div>
                            <div className="card-body space-y-4">
                                <FormField label="Name" required error={create.errors.name}>
                                    <input className="form-input" placeholder="Annual BIA"
                                        value={create.data.name} onChange={(e) => create.setData('name', e.target.value)} />
                                </FormField>
                                <FormField label="Cycle">
                                    <select className="form-select" value={create.data.cycle}
                                        onChange={(e) => create.setData('cycle', e.target.value)}>
                                        <option value="annual">Annual</option>
                                        <option value="semi_annual">Every six months</option>
                                        <option value="adhoc">One-off</option>
                                    </select>
                                </FormField>
                                <FormField label="Deadline" error={create.errors.closes_at}>
                                    <input type="date" className="form-input"
                                        value={create.data.closes_at} onChange={(e) => create.setData('closes_at', e.target.value)} />
                                </FormField>
                                <button type="submit" className="btn-primary text-sm" disabled={create.processing}>Create</button>
                            </div>
                        </form>
                    )}
                </aside>

                <section className="lg:col-span-2">
                    {!campaign ? (
                        <p className="rounded-lg border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500">
                            Choose a campaign, or create one. Distributing it creates an assessment for each of the
                            {' '}{processCount} active process(es).
                        </p>
                    ) : (
                        <div className="space-y-6">
                            <div className="rounded-lg border border-gray-200 bg-white p-5">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h2 className="text-sm font-semibold text-gray-900">{campaign.name}</h2>
                                        <p className="text-xs text-gray-500">{campaign.status} · closes {campaign.closes_at ?? 'not set'}</p>
                                    </div>
                                    {can.manage && (
                                        <div className="flex gap-2">
                                            {campaign.status !== 'closed' && (
                                                <button type="button" className="btn-secondary text-sm"
                                                    onClick={() => postTo(campaign.distribute_url)}>Distribute</button>
                                            )}
                                            {campaign.status === 'open' && (
                                                <>
                                                    <button type="button" className="btn-secondary text-sm"
                                                        onClick={() => postTo(campaign.chase_url)}>Chase now</button>
                                                    <button type="button" className="btn-primary text-sm"
                                                        onClick={() => postTo(campaign.close_url)}>Close</button>
                                                </>
                                            )}
                                        </div>
                                    )}
                                </div>

                                <div className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-5">
                                    {[
                                        ['Not started', progress.counts.not_started],
                                        ['In progress', progress.counts.in_progress],
                                        ['Submitted', progress.counts.submitted],
                                        ['Approved', progress.counts.approved],
                                        ['Returned', progress.counts.returned],
                                    ].map(([label, value]) => (
                                        <div key={label}>
                                            <p className="text-xs uppercase tracking-wide text-gray-500">{label}</p>
                                            <p className="font-mono text-xl text-gray-900">{value}</p>
                                        </div>
                                    ))}
                                </div>

                                <p className="mt-4 text-sm text-gray-700">
                                    {progress.response_rate === null
                                        ? 'Not distributed yet, so there is no response rate.'
                                        : `${progress.response_rate}% of ${progress.counts.total} have responded.`}
                                    {progress.counts.unassigned > 0 && (
                                        <span className="ml-1 text-amber-800">
                                            {progress.counts.unassigned} have no assessor and nobody will be asked.
                                        </span>
                                    )}
                                </p>
                            </div>

                            <div className="card">
                                <div className="card-header"><h2 className="form-section-title">By department</h2></div>
                                <div className="overflow-x-auto">
                                    <table className="data-table">
                                        <thead>
                                            <tr><th>Unit</th><th>Outstanding</th><th>Responded</th></tr>
                                        </thead>
                                        <tbody>
                                            {progress.by_unit.map((u) => (
                                                <tr key={u.unit}>
                                                    <td className="cell-muted">{u.unit}</td>
                                                    <td className="font-mono text-gray-900">{u.outstanding}</td>
                                                    <td className="font-mono text-gray-500">{u.responded} / {u.total}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div className="rounded-lg border border-gray-200 bg-white p-5">
                                <h2 className="text-sm font-semibold text-gray-900">Overdue</h2>
                                {progress.overdue.length === 0 ? (
                                    <p className="mt-2 text-sm text-gray-500">Nothing is past the deadline.</p>
                                ) : (
                                    <ul className="mt-3 divide-y divide-gray-100 text-sm">
                                        {progress.overdue.map((o) => (
                                            <li key={o.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                                                <div>
                                                    <span className="text-gray-900">{o.process}</span>
                                                    <span className="block text-xs text-gray-500">
                                                        {o.assessor ?? 'no assessor'} · chased {o.chase_count} time(s)
                                                        {o.chased_at ? `, last on ${o.chased_at}` : ''}
                                                    </span>
                                                </div>
                                                {o.escalated_at && (
                                                    <span className="rounded bg-amber-50 px-2 py-0.5 text-xs text-amber-800">
                                                        escalated {o.escalated_at}
                                                    </span>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        </div>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
