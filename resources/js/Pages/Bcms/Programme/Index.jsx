import { Head, Link, router, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * Programme overview (Blueprint §15, screen 1).
 *
 * THE MATURITY PANEL SHOWS A DASH WHERE A CLAUSE GROUP HAS NO EVIDENCE, not a
 * one. A group the organisation has not started has no maturity level; printing
 * 1/5 asserts a judgement the data does not support, and the rationale beside
 * each row says which it is. Development standard §5.
 *
 * THE RACI GAP REPORT IS ABOVE THE FOLD because it is the one thing on this
 * screen somebody has to act on. "Fourteen processes have no accountable owner"
 * is a sentence a risk committee moves on; a matrix nobody audits is a
 * spreadsheet in a different shape.
 */
export default function Index({
    programme, objectives = [], scope = [], obligations = [], policy, maturity,
    reviews = [], raci_gaps: gaps, can = {}, business_units: units = [], processes = [],
}) {
    const create = useForm({ name: '', year: new Date().getFullYear(), scope_statement: '', out_of_scope_statement: '' });

    if (!programme) {
        return (
            <AppLayout title="Programme governance">
                <Head title="Programme governance" />
                <PageHeader title="Programme governance" subtitle="No business continuity programme exists yet." />

                <form
                    onSubmit={(e) => { e.preventDefault(); create.post(tryRoute('bcms.programme.store')); }}
                    className="max-w-2xl space-y-4 rounded-lg border border-gray-200 bg-white p-6"
                >
                    <p className="text-sm text-gray-600">
                        A programme is the scope of the BCMS for a year — what it covers, what it deliberately does
                        not, and who owns it. ISO 22301 clause 4.3 asks for the boundary and for any exclusion to be
                        justified.
                    </p>
                    <label className="block text-sm">
                        <span className="text-gray-700">Name</span>
                        <input className="mt-1 w-full rounded border-gray-300 text-sm" value={create.data.name}
                            onChange={(e) => create.setData('name', e.target.value)} />
                        {create.errors.name && <span className="text-xs text-red-600">{create.errors.name}</span>}
                    </label>
                    <label className="block text-sm">
                        <span className="text-gray-700">Year</span>
                        <input type="number" className="mt-1 w-full rounded border-gray-300 text-sm" value={create.data.year}
                            onChange={(e) => create.setData('year', e.target.value)} />
                    </label>
                    <label className="block text-sm">
                        <span className="text-gray-700">Scope statement</span>
                        <textarea rows={4} className="mt-1 w-full rounded border-gray-300 text-sm" value={create.data.scope_statement}
                            onChange={(e) => create.setData('scope_statement', e.target.value)} />
                        {create.errors.scope_statement && <span className="text-xs text-red-600">{create.errors.scope_statement}</span>}
                    </label>
                    <label className="block text-sm">
                        <span className="text-gray-700">What is deliberately out of scope</span>
                        <textarea rows={3} className="mt-1 w-full rounded border-gray-300 text-sm" value={create.data.out_of_scope_statement}
                            onChange={(e) => create.setData('out_of_scope_statement', e.target.value)} />
                    </label>
                    <button type="submit" className="btn-primary text-sm" disabled={!can.manage || create.processing}>
                        Create programme
                    </button>
                </form>
            </AppLayout>
        );
    }

    const post = (name, arg) => router.post(tryRoute(name, arg), {}, { preserveScroll: true });

    return (
        <AppLayout title="Programme governance">
            <Head title="Programme governance" />

            <PageHeader
                title={programme.name}
                subtitle={`${programme.year} · ${programme.status}${programme.owner ? ` · owned by ${programme.owner}` : ''}`}
                actions={
                    <div className="flex gap-2">
                        {can.approve && programme.status === 'draft' && (
                            <button type="button" className="btn-primary text-sm"
                                onClick={() => post('bcms.programme.approve', programme.id)}>Approve</button>
                        )}
                        {can.approve && programme.status === 'approved' && (
                            <button type="button" className="btn-primary text-sm"
                                onClick={() => post('bcms.programme.activate', programme.id)}>Activate</button>
                        )}
                    </div>
                }
            />

            {gaps?.without_accountable?.length > 0 && (
                <div className="mb-6 rounded-lg border border-amber-300 bg-amber-50 p-4">
                    <p className="text-sm font-semibold text-amber-900">
                        {gaps.without_accountable.length} of {gaps.total} process(es) have nobody accountable.
                    </p>
                    <p className="mt-1 text-sm text-amber-900">
                        Most critical first: {gaps.without_accountable.slice(0, 5).map((p) => p.code).join(', ')}
                        {gaps.without_accountable.length > 5 ? '…' : ''}
                    </p>
                    <Link href={tryRoute('bcms.processes.index', { gap: 'no_accountable' })}
                        className="mt-2 inline-block text-sm font-medium text-amber-900 underline">
                        Fix in the process catalogue
                    </Link>
                </div>
            )}

            {gaps?.inactive_accountable?.length > 0 && (
                <div className="mb-6 rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-900">
                    <p className="font-semibold">
                        {gaps.inactive_accountable.length} process(es) are accountable to somebody who has left.
                    </p>
                    <p className="mt-1">The matrix still looks complete, which is why this is listed separately.</p>
                </div>
            )}

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <section className="space-y-6 lg:col-span-2">
                    <div className="rounded-lg border border-gray-200 bg-white p-6">
                        <h2 className="text-sm font-semibold text-gray-900">Scope</h2>
                        <p className="mt-2 whitespace-pre-line text-sm text-gray-700">{programme.scope_statement}</p>
                        {programme.out_of_scope_statement && (
                            <>
                                <h3 className="mt-4 text-xs font-semibold uppercase tracking-wide text-gray-500">Out of scope</h3>
                                <p className="mt-1 whitespace-pre-line text-sm text-gray-700">{programme.out_of_scope_statement}</p>
                            </>
                        )}
                        {scope.length > 0 && (
                            <ul className="mt-4 divide-y divide-gray-100 text-sm">
                                {scope.map((item) => (
                                    <li key={item.id} className="flex items-start justify-between gap-4 py-2">
                                        <span className="text-gray-800">{item.label}</span>
                                        <span className={item.in_scope ? 'text-emerald-700' : 'text-gray-500'}>
                                            {item.in_scope ? 'in scope' : `excluded — ${item.rationale}`}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    <div className="rounded-lg border border-gray-200 bg-white p-6">
                        <h2 className="text-sm font-semibold text-gray-900">Objectives</h2>
                        {objectives.length === 0 ? (
                            <p className="mt-2 text-sm text-gray-500">
                                No objectives yet. Clause 6.2 asks for measurable ones — a target with no baseline
                                cannot show movement.
                            </p>
                        ) : (
                            <table className="mt-3 w-full text-sm">
                                <thead className="text-left text-xs uppercase tracking-wide text-gray-500">
                                    <tr><th className="pb-2">Objective</th><th className="pb-2">Baseline</th><th className="pb-2">Target</th><th className="pb-2">KRI</th></tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {objectives.map((o) => (
                                        <tr key={o.id}>
                                            <td className="py-2 text-gray-800">{o.title}</td>
                                            <td className="py-2 font-mono text-gray-700">
                                                {o.baselined ? `${o.baseline} ${o.unit ?? ''}` : <span className="text-gray-400">no baseline</span>}
                                            </td>
                                            <td className="py-2 font-mono text-gray-700">
                                                {o.measurable ? `${o.target} ${o.unit ?? ''}` : <span className="text-gray-400">not measurable</span>}
                                            </td>
                                            <td className="py-2 text-gray-600">{o.kri ?? <span className="text-gray-400">not linked</span>}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </div>

                    <div className="rounded-lg border border-gray-200 bg-white p-6">
                        <div className="flex items-center justify-between">
                            <h2 className="text-sm font-semibold text-gray-900">Regulatory obligations</h2>
                            {can.manage && (
                                <button type="button" className="btn-secondary text-sm"
                                    onClick={() => post('bcms.programme.obligations.seed', programme.id)}>
                                    {obligations.length === 0 ? 'Load the register' : 'Check for new obligations'}
                                </button>
                            )}
                        </div>
                        {obligations.length === 0 ? (
                            <p className="mt-2 text-sm text-gray-500">
                                The register is empty. Loading it brings in the CBN, BOFIA and NDPA obligations that
                                may bind this institution; each is then marked applicable or not, with a reason.
                            </p>
                        ) : (
                            <ul className="mt-3 divide-y divide-gray-100 text-sm">
                                {obligations.map((o) => (
                                    <li key={o.id} className="py-2">
                                        <div className="flex items-start justify-between gap-4">
                                            <div>
                                                <p className="text-gray-900">{o.title}</p>
                                                <p className="text-xs text-gray-500">{o.standard} · {o.clause_ref}</p>
                                            </div>
                                            <span className={o.applies ? 'text-xs text-emerald-700' : 'text-xs text-gray-500'}>
                                                {o.applies ? (o.cadence ?? 'applies') : 'not applicable'}
                                            </span>
                                        </div>
                                        {!o.applies && o.applicability_note && (
                                            <p className="mt-1 text-xs text-gray-500">{o.applicability_note}</p>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </section>

                <aside className="space-y-6">
                    <div className="rounded-lg border border-gray-200 bg-white p-6">
                        <div className="flex items-center justify-between">
                            <h2 className="text-sm font-semibold text-gray-900">Maturity</h2>
                            <button type="button" className="text-xs text-gray-600 underline"
                                onClick={() => post('bcms.maturity.assess')}>Re-assess</button>
                        </div>
                        {!maturity ? (
                            <p className="mt-2 text-sm text-gray-500">
                                The maturity engine has not run yet. It scores artefact presence and currency, not
                                self-declaration, so the score moves the moment an artefact is added.
                            </p>
                        ) : (
                            <>
                                <p className="mt-2 font-mono text-3xl text-gray-900">
                                    {maturity.overall_score ?? '—'}
                                    <span className="ml-1 text-sm text-gray-400">/ 5</span>
                                </p>
                                <p className="text-xs text-gray-500">
                                    Assessed {maturity.assessed_at} · method {maturity.method_version}
                                </p>
                                <ul className="mt-4 space-y-2 text-sm">
                                    {maturity.scores.map((s) => (
                                        <li key={s.clause_group} title={s.rationale}>
                                            <div className="flex items-center justify-between">
                                                <span className="text-gray-700">{s.label}</span>
                                                <span className="font-mono text-gray-900">{s.score ?? '—'}</span>
                                            </div>
                                            <p className="text-xs text-gray-500">{s.rationale}</p>
                                        </li>
                                    ))}
                                </ul>
                            </>
                        )}
                    </div>

                    <div className="rounded-lg border border-gray-200 bg-white p-6">
                        <h2 className="text-sm font-semibold text-gray-900">BC policy</h2>
                        {!policy ? (
                            <p className="mt-2 text-sm text-gray-500">No approved policy version.</p>
                        ) : (
                            <div className="mt-2 text-sm">
                                <p className="text-gray-900">{policy.title}</p>
                                <p className="text-xs text-gray-500">
                                    v{policy.version} · approved {policy.approved_at}
                                </p>
                                <p className={`mt-2 text-xs ${policy.attested_this_year ? 'text-emerald-700' : 'text-amber-700'}`}>
                                    {policy.attested_this_year
                                        ? 'Board-attested this year.'
                                        : 'Not board-attested this year.'}
                                </p>
                            </div>
                        )}
                        <Link href={tryRoute('bcms.policy.index')} className="mt-3 inline-block text-sm underline">
                            Open the policy
                        </Link>
                    </div>

                    <div className="rounded-lg border border-gray-200 bg-white p-6">
                        <h2 className="text-sm font-semibold text-gray-900">Management review</h2>
                        {reviews.length === 0 ? (
                            <p className="mt-2 text-sm text-gray-500">
                                No management review recorded. Clause 9.3 makes its results a mandatory record.
                            </p>
                        ) : (
                            <ul className="mt-2 space-y-2 text-sm">
                                {reviews.map((r) => (
                                    <li key={r.id} className="flex items-center justify-between">
                                        <span className="text-gray-800">{r.title}</span>
                                        <span className="text-xs text-gray-500">{r.held_on} · {r.status}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </aside>
            </div>
        </AppLayout>
    );
}
