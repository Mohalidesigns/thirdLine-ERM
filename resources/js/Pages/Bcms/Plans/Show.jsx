import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import BoundSection from '@/Components/Bcms/BoundSection';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * The plan builder and the plan viewer — one screen, because they are one
 * document seen two ways.
 *
 * A DRAFT IS EDITABLE AND RENDERS LIVE. An APPROVED VERSION IS NEITHER: it
 * prints the snapshot frozen when it was approved, for ever, and the only thing
 * it offers is "here is what this section would say today" beside it. That
 * comparison is how somebody decides whether a v2 is needed, and it is the only
 * place in the product where the two are put next to each other.
 *
 * THE SECTION NAVIGATOR CARRIES THE FLAGS. Drifted, overridden, AI-drafted,
 * never verified — all four are visible in the list, so a plan with one bad
 * section out of sixteen is found without scrolling through fifteen good ones.
 */
export default function Show({
    plan = {}, sections = [], live_sections = null, version_chain = [], sources = [],
    acknowledgement = null, has_acknowledged = false, activation = {}, ai = {}, can = {},
}) {
    const [active, setActive] = useState(sections[0]?.key ?? null);
    const [editing, setEditing] = useState(null);
    const [showApprove, setShowApprove] = useState(false);
    const [showSupersede, setShowSupersede] = useState(false);

    const section = sections.find((s) => s.key === active);
    const liveCounterpart = live_sections?.find((s) => s.key === active) ?? null;

    const edit = useForm({ title: '', body: '', source_binding: null });
    // No `?? 12`. A plan whose owner never declared a review cycle has not
    // got a twelve-month one, and pre-filling the approval form with a figure
    // nobody chose is how an invented cycle becomes a date on a board pack.
    const approve = useForm({ effective_from: '', review_frequency_months: plan.review_frequency_months ?? '' });
    const supersede = useForm({ version: '' });
    const activate = useForm({ reason: '', is_exercise: false });

    const startEditing = (s) => {
        edit.setData({ title: s.title, body: s.body ?? '', source_binding: s.binding ?? null });
        setEditing(s.key);
    };

    const saveSection = (e) => {
        e.preventDefault();
        edit.patch(tryRoute('bcms.plans.sections.update', [plan.uuid, section.id]), {
            preserveScroll: true,
            onSuccess: () => setEditing(null),
        });
    };

    const post = (name, args = plan.uuid, data = {}) => router.post(tryRoute(name, args), data, { preserveScroll: true });

    const driftedCount = sections.filter((s) => s.needs_review).length;

    return (
        <AppLayout title={plan.title}>
            <Head title={plan.title} />

            <PageHeader
                title={plan.title}
                subtitle={`${plan.plan_type_label} · version ${plan.version} · ${plan.status}`}
                actions={(
                    <div className="flex flex-wrap gap-2">
                        <Link href={tryRoute('bcms.plans.index')} className="btn-secondary text-sm">Library</Link>
                        <a href={tryRoute('bcms.plans.pdf', plan.uuid)} target="_blank" rel="noreferrer" className="btn-secondary text-sm">
                            PDF
                        </a>
                        {can.manage && (
                            <button type="button" className="btn-secondary text-sm" onClick={() => post('bcms.plans.assemble')}>
                                Re-assemble
                            </button>
                        )}
                        {can.manage && plan.status === 'draft' && (
                            <button type="button" className="btn-secondary text-sm" onClick={() => post('bcms.plans.submit-review')}>
                                Send for review
                            </button>
                        )}
                        {can.approve && (plan.status === 'draft' || plan.status === 'review') && (
                            <button type="button" className="btn-primary text-sm" onClick={() => setShowApprove((v) => !v)}>
                                Approve
                            </button>
                        )}
                        {can.manage && plan.status === 'approved' && (
                            <button type="button" className="btn-primary text-sm" onClick={() => setShowSupersede((v) => !v)}>
                                New version
                            </button>
                        )}
                    </div>
                )}
            />

            {/* ---- Banners ------------------------------------------------ */}

            {plan.immutable && (
                <p className="mb-4 rounded border border-gray-300 bg-gray-50 p-3 text-sm text-gray-700">
                    Version {plan.version} is {plan.status} and cannot be edited. What it shows below is what was frozen
                    when it was approved — that is what it will print for ever. To change it, create the next version;
                    this one stays retrievable and printable.
                </p>
            )}

            {plan.is_stale && (
                <p className="mb-4 rounded border border-red-300 bg-red-50 p-3 text-sm text-red-800">
                    This plan was due for review on {plan.next_review_date}.
                </p>
            )}

            {driftedCount > 0 && (
                <p className="mb-4 rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800">
                    {driftedCount} sections are bound to data that has changed since they were last verified. They are
                    marked in the list below.
                    {plan.immutable && ' An approved version is not edited — the correction is a new version.'}
                </p>
            )}

            {showApprove && (
                <form
                    onSubmit={(e) => { e.preventDefault(); approve.post(tryRoute('bcms.plans.approve', plan.uuid), { onSuccess: () => setShowApprove(false) }); }}
                    className="mb-6 space-y-3 rounded-lg border border-gray-200 bg-white p-6"
                >
                    <p className="text-sm text-gray-700">
                        Approving freezes what this plan renders today. It cannot be edited afterwards, and it will
                        print exactly this for ever.
                    </p>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <label className="text-sm">
                            <span className="text-gray-700">Effective from</span>
                            <input
                                type="date" className="mt-1 w-full rounded border-gray-300 text-sm"
                                value={approve.data.effective_from}
                                onChange={(e) => approve.setData('effective_from', e.target.value)}
                            />
                        </label>
                        <label className="text-sm">
                            <span className="text-gray-700">Review every (months)</span>
                            <input
                                type="number" min="1" max="120" className="mt-1 w-full rounded border-gray-300 text-sm"
                                value={approve.data.review_frequency_months ?? ''}
                                onChange={(e) => approve.setData('review_frequency_months', e.target.value)}
                            />
                        </label>
                    </div>
                    <button type="submit" className="btn-primary text-sm" disabled={approve.processing}>
                        Approve version {plan.version}
                    </button>
                </form>
            )}

            {showSupersede && (
                <form
                    onSubmit={(e) => { e.preventDefault(); supersede.post(tryRoute('bcms.plans.supersede', plan.uuid)); }}
                    className="mb-6 flex flex-wrap items-end gap-3 rounded-lg border border-gray-200 bg-white p-6"
                >
                    <label className="text-sm">
                        <span className="text-gray-700">New version number</span>
                        <input
                            type="text" required placeholder="2.0"
                            className="mt-1 w-40 rounded border-gray-300 text-sm"
                            value={supersede.data.version}
                            onChange={(e) => supersede.setData('version', e.target.value)}
                        />
                    </label>
                    <button type="submit" className="btn-primary text-sm" disabled={supersede.processing}>
                        Draft it
                    </button>
                    <p className="w-full text-xs text-gray-500">
                        The new version starts as a copy of this one, including every section and binding. This version
                        is archived and stays printable.
                    </p>
                </form>
            )}

            <div className="grid gap-6 lg:grid-cols-[280px_1fr]">

                {/* ---- Section navigator ---------------------------------- */}

                <div className="space-y-4">
                    <div className="rounded-lg border border-gray-200 bg-white">
                        <p className="border-b border-gray-100 px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500">
                            Sections
                        </p>
                        <ul className="divide-y divide-gray-100">
                            {sections.map((s) => (
                                <li key={s.key}>
                                    <button
                                        type="button"
                                        onClick={() => { setActive(s.key); setEditing(null); }}
                                        className={`w-full px-4 py-2.5 text-left text-sm ${active === s.key ? 'bg-gray-100 font-medium' : 'hover:bg-gray-50'}`}
                                    >
                                        <span className="block">{s.title}</span>
                                        <span className="mt-0.5 flex flex-wrap gap-1 text-[10px]">
                                            {s.is_bound && <span className="rounded bg-blue-100 px-1.5 text-blue-800">bound</span>}
                                            {s.needs_review && <span className="rounded bg-amber-100 px-1.5 text-amber-800">drifted</span>}
                                            {s.is_overridden && <span className="rounded bg-gray-200 px-1.5 text-gray-700">edited</span>}
                                            {s.ai_generated && <span className="rounded bg-purple-100 px-1.5 text-purple-800">AI</span>}
                                            {s.is_bound && !s.last_verified_at && (
                                                <span className="rounded bg-red-100 px-1.5 text-red-800">unverified</span>
                                            )}
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                        {sections.length === 0 && (
                            <p className="p-4 text-xs text-gray-600">
                                This plan has no sections. Apply a template from the library, or add sections by hand.
                            </p>
                        )}
                    </div>

                    {/* ---- Document control ------------------------------- */}

                    <div className="rounded-lg border border-gray-200 bg-white p-4 text-xs">
                        <p className="mb-2 font-semibold uppercase tracking-wide text-gray-500">Document control</p>
                        <dl className="space-y-1.5">
                            <div className="flex justify-between gap-2"><dt className="text-gray-500">Owner</dt><dd>{plan.owner ?? '—'}</dd></div>
                            <div className="flex justify-between gap-2"><dt className="text-gray-500">Approved by</dt><dd>{plan.approver ?? '—'}</dd></div>
                            <div className="flex justify-between gap-2"><dt className="text-gray-500">Effective</dt><dd>{plan.effective_from ?? '—'}</dd></div>
                            <div className="flex justify-between gap-2"><dt className="text-gray-500">Next review</dt><dd>{plan.next_review_date ?? 'Not set'}</dd></div>
                            <div className="flex justify-between gap-2"><dt className="text-gray-500">ISO clause</dt><dd>{plan.iso_clause_ref ?? '—'}</dd></div>
                        </dl>

                        {version_chain.length > 1 && (
                            <>
                                <p className="mb-1 mt-4 font-semibold uppercase tracking-wide text-gray-500">Versions</p>
                                <ul className="space-y-1">
                                    {version_chain.map((v) => (
                                        <li key={v.id}>
                                            {v.is_current ? (
                                                <span className="font-medium">v{v.version} — {v.status} (this one)</span>
                                            ) : (
                                                <Link href={tryRoute('bcms.plans.show', v.uuid)} className="text-blue-700 hover:underline">
                                                    v{v.version} — {v.status}
                                                </Link>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            </>
                        )}
                    </div>

                    {/* ---- Distribution ---------------------------------- */}

                    {plan.status === 'approved' && (
                        <div className="rounded-lg border border-gray-200 bg-white p-4 text-xs">
                            <p className="mb-2 font-semibold uppercase tracking-wide text-gray-500">Distribution</p>

                            {acknowledgement?.note && <p className="mb-2 text-amber-700">{acknowledgement.note}</p>}

                            <p className="mb-2">
                                {acknowledgement?.percentage == null
                                    ? `${acknowledgement?.acknowledged_count ?? 0} acknowledgements recorded.`
                                    : `${acknowledgement.percentage}% acknowledged (${acknowledgement.distribution_count - acknowledgement.outstanding.length} of ${acknowledgement.distribution_count}).`}
                            </p>

                            {!has_acknowledged && (
                                <button
                                    type="button"
                                    className="btn-primary w-full text-xs"
                                    onClick={() => post('bcms.plans.acknowledge')}
                                >
                                    I have read this plan
                                </button>
                            )}
                            {has_acknowledged && <p className="text-green-700">You have acknowledged this plan.</p>}

                            {can.bundle && (
                                <button
                                    type="button"
                                    className="btn-secondary mt-2 w-full text-xs"
                                    onClick={() => post('bcms.plans.bundle.generate')}
                                >
                                    {plan.offline_bundle_generated_at ? 'Rebuild offline bundle' : 'Generate offline bundle'}
                                </button>
                            )}
                            {plan.offline_bundle_generated_at && (
                                <p className="mt-1 text-[11px] text-gray-500">
                                    Bundle built {new Date(plan.offline_bundle_generated_at).toLocaleString()}.
                                </p>
                            )}

                            {acknowledgement?.outstanding?.length > 0 && (
                                <details className="mt-3">
                                    <summary className="cursor-pointer text-gray-600">
                                        {acknowledgement.outstanding.length} have not acknowledged
                                    </summary>
                                    <ul className="mt-1 space-y-0.5 text-gray-600">
                                        {acknowledgement.outstanding.map((p) => <li key={p.user_id}>{p.name}</li>)}
                                    </ul>
                                </details>
                            )}
                        </div>
                    )}

                    {/* ---- Activation ------------------------------------ */}

                    {plan.status === 'approved' && can.activate && (
                        <div className="rounded-lg border border-gray-200 bg-white p-4 text-xs">
                            <p className="mb-2 font-semibold uppercase tracking-wide text-gray-500">Activation</p>
                            <p className="mb-2 text-gray-600">
                                {activation.live_count ?? 0} live activations, {activation.exercise_count ?? 0} exercises.
                                {activation.last_live_at && ` Last used ${new Date(activation.last_live_at).toLocaleDateString()}.`}
                            </p>
                            {activation.is_active ? (
                                <p className="rounded bg-red-50 p-2 text-red-800">This plan is currently active.</p>
                            ) : (
                                <form
                                    onSubmit={(e) => { e.preventDefault(); activate.post(tryRoute('bcms.plans.activate', plan.uuid), { preserveScroll: true, onSuccess: () => activate.reset() }); }}
                                    className="space-y-2"
                                >
                                    <input
                                        type="text" required placeholder="Why is this being activated?"
                                        className="w-full rounded border-gray-300 text-xs"
                                        value={activate.data.reason}
                                        onChange={(e) => activate.setData('reason', e.target.value)}
                                    />
                                    <label className="flex items-center gap-2">
                                        <input
                                            type="checkbox"
                                            checked={activate.data.is_exercise}
                                            onChange={(e) => activate.setData('is_exercise', e.target.checked)}
                                        />
                                        <span className="text-gray-600">This is an exercise, not a real event</span>
                                    </label>
                                    <button type="submit" className="btn-secondary w-full text-xs">Activate</button>
                                </form>
                            )}
                        </div>
                    )}
                </div>

                {/* ---- The section itself --------------------------------- */}

                <div className="space-y-4">
                    {ai.available && can.manage && (
                        <button
                            type="button"
                            className="btn-secondary text-sm"
                            onClick={() => post('bcms.plans.ai-draft')}
                        >
                            Draft the written sections with AI
                        </button>
                    )}
                    {!ai.available && ai.reason && can.manage && (
                        <p className="rounded border border-gray-200 bg-gray-50 p-3 text-xs text-gray-600">{ai.reason}</p>
                    )}

                    {!section && (
                        <div className="rounded-lg border border-dashed border-gray-300 bg-white p-8 text-center text-sm text-gray-600">
                            Select a section.
                        </div>
                    )}

                    {section && (
                        <div className="rounded-lg border border-gray-200 bg-white p-6">
                            <div className="mb-3 flex items-start justify-between gap-4">
                                <h2 className="text-base font-semibold text-gray-900">{section.title}</h2>
                                {can.manage && editing !== section.key && (
                                    <button type="button" className="text-xs text-blue-700 hover:underline" onClick={() => startEditing(section)}>
                                        Edit
                                    </button>
                                )}
                            </div>

                            {editing === section.key ? (
                                <form onSubmit={saveSection} className="space-y-3">
                                    <input
                                        type="text"
                                        className="w-full rounded border-gray-300 text-sm"
                                        value={edit.data.title}
                                        onChange={(e) => edit.setData('title', e.target.value)}
                                    />
                                    <textarea
                                        rows={14}
                                        className="w-full rounded border-gray-300 font-mono text-sm"
                                        value={edit.data.body}
                                        onChange={(e) => edit.setData('body', e.target.value)}
                                    />
                                    <label className="block text-xs">
                                        <span className="text-gray-600">Bind to live data</span>
                                        <select
                                            className="mt-1 w-full rounded border-gray-300 text-sm"
                                            value={edit.data.source_binding?.source ?? ''}
                                            onChange={(e) => edit.setData('source_binding', e.target.value ? { source: e.target.value } : null)}
                                        >
                                            <option value="">Not bound — free text</option>
                                            {sources.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
                                        </select>
                                        {edit.data.source_binding?.source && (
                                            <span className="mt-1 block text-gray-500">
                                                {sources.find((s) => s.value === edit.data.source_binding.source)?.renders}
                                            </span>
                                        )}
                                    </label>
                                    {section.is_bound && (
                                        <p className="text-xs text-amber-700">
                                            Editing the text of a bound section marks it as yours. Re-assembling will keep
                                            checking whether the source has moved, but will leave your words alone.
                                        </p>
                                    )}
                                    <div className="flex gap-2">
                                        <button type="submit" className="btn-primary text-sm" disabled={edit.processing}>Save</button>
                                        <button type="button" className="btn-secondary text-sm" onClick={() => setEditing(null)}>Cancel</button>
                                    </div>
                                </form>
                            ) : (
                                <>
                                    {section.body
                                        ? <div className="prose prose-sm max-w-none whitespace-pre-wrap text-gray-800">{section.body}</div>
                                        : !section.is_bound && <p className="text-sm italic text-gray-500">This section has not been written.</p>}

                                    <BoundSection live={section.live} section={section} />
                                </>
                            )}
                        </div>
                    )}

                    {/* The frozen version beside what it would say today. */}
                    {liveCounterpart?.live && section?.is_bound && (
                        <div className="rounded-lg border border-dashed border-amber-300 bg-amber-50/40 p-6">
                            <h3 className="text-sm font-semibold text-amber-900">What this section would say today</h3>
                            <p className="mb-2 text-xs text-amber-800">
                                Version {plan.version} prints what was frozen when it was approved, above. This is the
                                current position. If they differ, the plan needs a new version.
                            </p>
                            <BoundSection live={liveCounterpart.live} section={liveCounterpart} />
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
