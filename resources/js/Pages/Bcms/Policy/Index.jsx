import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * The BC policy — clause 5.2, with its version history and attestation trail.
 *
 * AN APPROVED VERSION IS READ-ONLY ON THIS SCREEN, and the button says
 * "Supersede" rather than "Edit". That is not UI politeness: the version an
 * auditor was shown has to still be the version they were shown, and a screen
 * offering Edit teaches a customer that history is negotiable.
 */
export default function Index({ current, versions = [], default_statement: defaultStatement, can = {} }) {
    const [selected, setSelected] = useState(versions[0]?.id ?? null);
    const version = versions.find((v) => v.id === selected) ?? versions[0] ?? null;

    const draft = useForm({ title: 'Business Continuity Policy', next_review_date: '' });
    const supersede = useForm({ version: '' });
    const attest = useForm({ statement: defaultStatement, attestation_type: 'board' });

    const submitDraft = (e) => { e.preventDefault(); draft.post(tryRoute('bcms.policy.store'), { preserveScroll: true }); };

    return (
        <AppLayout title="BC policy">
            <Head title="BC policy" />

            <PageHeader
                title="Business continuity policy"
                subtitle={current ? `Current version ${current.version}, effective ${current.effective_from ?? '—'}` : 'No approved policy version.'}
            />

            {versions.length === 0 ? (
                <form onSubmit={submitDraft} className="max-w-2xl space-y-4 rounded-lg border border-gray-200 bg-white p-6">
                    <p className="text-sm text-gray-600">
                        ISO 22301 clause 5.2 requires a policy that is documented, communicated and available. Once a
                        version is approved it can never be edited — only superseded by the next one, which is what
                        makes the version history worth anything to an auditor.
                    </p>
                    <label className="block text-sm">
                        <span className="text-gray-700">Title</span>
                        <input className="mt-1 w-full rounded border-gray-300 text-sm" value={draft.data.title}
                            onChange={(e) => draft.setData('title', e.target.value)} />
                    </label>
                    <label className="block text-sm">
                        <span className="text-gray-700">Next review date</span>
                        <input type="date" className="mt-1 w-full rounded border-gray-300 text-sm" value={draft.data.next_review_date}
                            onChange={(e) => draft.setData('next_review_date', e.target.value)} />
                    </label>
                    <button type="submit" className="btn-primary text-sm" disabled={!can.manage || draft.processing}>
                        Draft version 1.0
                    </button>
                </form>
            ) : (
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <nav className="lg:col-span-1">
                        <h2 className="mb-2 text-sm font-semibold text-gray-900">Version history</h2>
                        <ul className="divide-y divide-gray-200 rounded-lg border border-gray-200 bg-white">
                            {versions.map((v) => (
                                <li key={v.id}>
                                    <button type="button" onClick={() => setSelected(v.id)}
                                        className={`w-full px-4 py-3 text-left text-sm ${v.id === version?.id ? 'bg-gray-50' : ''}`}>
                                        <span className="font-medium text-gray-900">v{v.version}</span>
                                        <span className="ml-2 text-xs text-gray-500">{v.status}</span>
                                        <span className="block text-xs text-gray-500">
                                            {v.approved_at ? `approved ${v.approved_at}` : 'not approved'}
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </nav>

                    <section className="lg:col-span-2">
                        {version && (
                            <div className="space-y-6">
                                <div className="rounded-lg border border-gray-200 bg-white p-6">
                                    <div className="flex items-start justify-between gap-4">
                                        <div>
                                            <h2 className="text-sm font-semibold text-gray-900">{version.title}</h2>
                                            <p className="text-xs text-gray-500">
                                                v{version.version} · {version.status}
                                                {version.supersedes_plan_id ? ' · supersedes an earlier version' : ''}
                                            </p>
                                        </div>
                                        <div className="flex gap-2">
                                            {can.approve && version.status === 'draft' && (
                                                <button type="button" className="btn-primary text-sm"
                                                    onClick={() => router.post(tryRoute('bcms.policy.approve', version.id), {}, { preserveScroll: true })}>
                                                    Approve
                                                </button>
                                            )}
                                            {can.manage && version.status === 'approved' && (
                                                <form
                                                    onSubmit={(e) => { e.preventDefault(); supersede.post(tryRoute('bcms.policy.supersede', version.id), { preserveScroll: true }); }}
                                                    className="flex gap-2"
                                                >
                                                    <input placeholder="2.0" className="w-20 rounded border-gray-300 text-sm"
                                                        value={supersede.data.version}
                                                        onChange={(e) => supersede.setData('version', e.target.value)} />
                                                    <button type="submit" className="btn-secondary text-sm">Supersede</button>
                                                </form>
                                            )}
                                        </div>
                                    </div>

                                    {version.immutable && (
                                        <p className="mt-3 rounded bg-gray-50 p-3 text-xs text-gray-600">
                                            This version is approved and cannot be edited. Supersede it to make a
                                            change — the earlier version stays retrievable, which is what an examiner
                                            asking &ldquo;what did your policy say in 2026&rdquo; is asking for.
                                        </p>
                                    )}
                                </div>

                                <div className="rounded-lg border border-gray-200 bg-white p-6">
                                    <h2 className="text-sm font-semibold text-gray-900">Board attestation</h2>
                                    {version.attestations.length === 0 ? (
                                        <p className="mt-2 text-sm text-gray-500">Not attested.</p>
                                    ) : (
                                        <ul className="mt-2 divide-y divide-gray-100 text-sm">
                                            {version.attestations.map((a, i) => (
                                                <li key={i} className="py-2">
                                                    <p className="text-gray-900">
                                                        {a.by}{a.role ? `, ${a.role}` : ''} — {a.year}
                                                    </p>
                                                    <p className="text-xs text-gray-500">{a.at} · {a.type}</p>
                                                    <p className="mt-1 text-xs italic text-gray-600">&ldquo;{a.statement}&rdquo;</p>
                                                </li>
                                            ))}
                                        </ul>
                                    )}

                                    {can.attest && version.status === 'approved' && (
                                        <form
                                            onSubmit={(e) => { e.preventDefault(); attest.post(tryRoute('bcms.policy.attest', version.id), { preserveScroll: true }); }}
                                            className="mt-4 space-y-3 border-t border-gray-100 pt-4"
                                        >
                                            <label className="block text-sm">
                                                <span className="text-gray-700">Statement you are attesting to</span>
                                                <textarea rows={3} className="mt-1 w-full rounded border-gray-300 text-sm"
                                                    value={attest.data.statement}
                                                    onChange={(e) => attest.setData('statement', e.target.value)} />
                                                <span className="mt-1 block text-xs text-gray-500">
                                                    Stored with your name, role and the time. It cannot be edited afterwards.
                                                </span>
                                            </label>
                                            <button type="submit" className="btn-primary text-sm" disabled={attest.processing}>
                                                Attest
                                            </button>
                                        </form>
                                    )}
                                </div>
                            </div>
                        )}
                    </section>
                </div>
            )}
        </AppLayout>
    );
}
