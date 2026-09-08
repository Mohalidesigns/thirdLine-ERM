import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * The BIA workspace (Blueprint §15, screen 9).
 *
 * THE DERIVED MTPD IS SHOWN WITH ITS REASONING AND AS A SUGGESTION. The button
 * says "use this", not "apply" — the grid proposes and the assessor decides, and
 * where they differ the difference is kept and surfaced at review. A screen that
 * silently wrote the derived value would be a screen making the judgement.
 *
 * BLOCKING ISSUES AND WARNINGS ARE VISUALLY DIFFERENT AND SO ARE THEIR
 * CONSEQUENCES. Blocking disables Submit; a warning never does. Merging them
 * teaches people that neither matters.
 *
 * THE AI BUTTON EXPLAINS ITSELF WHEN IT IS OFF. A greyed-out control with no
 * reason gets raised as a bug; one that says which switch is off gets fixed by
 * whoever can fix it.
 */
const SEVERITY_CLASS = {
    1: 'bg-emerald-50 text-emerald-900',
    2: 'bg-lime-50 text-lime-900',
    3: 'bg-amber-50 text-amber-900',
    4: 'bg-orange-100 text-orange-900',
    5: 'bg-red-100 text-red-900',
};

export default function Workspace({
    assessment, process, grid, derived_mtpd: derivedMtpd, validation,
    dependencies = [], dependency_options: options, ai, can = {},
}) {
    const [cell, setCell] = useState(null);

    const objectives = useForm({
        mtpd_hours: assessment.mtpd_hours ?? '',
        rto_hours: assessment.rto_hours ?? '',
        rpo_minutes: assessment.rpo_minutes ?? '',
        mbco_description: assessment.mbco_description ?? '',
        min_staff_required: assessment.min_staff_required ?? '',
        workaround_available: assessment.workaround_available,
        workaround_max_duration_hours: assessment.workaround_max_duration_hours ?? '',
    });

    const blocking = validation?.blocking ?? [];
    const warnings = validation?.warnings ?? [];
    const editable = assessment.editable && can.edit;

    const post = (name, arg, data = {}) => router.post(tryRoute(name, arg), data, { preserveScroll: true });

    return (
        <AppLayout title={`BIA — ${process.name ?? ''}`}>
            <Head title={`BIA — ${process.name ?? ''}`} />

            <PageHeader
                title={process.name}
                subtitle={`${process.code}${process.unit ? ` · ${process.unit}` : ''} · ${assessment.status_label}`}
                actions={
                    <div className="flex flex-wrap gap-2">
                        {editable && (
                            <button type="button" className="btn-secondary text-sm"
                                title={ai.available ? undefined : ai.reason}
                                disabled={!ai.available}
                                onClick={() => post('bcms.bia.ai-draft', assessment.id)}>
                                Draft with AI
                            </button>
                        )}
                        {editable && (
                            <button type="button" className="btn-primary text-sm" disabled={blocking.length > 0}
                                onClick={() => post('bcms.bia.submit', assessment.id)}>
                                Submit for review
                            </button>
                        )}
                        {assessment.status === 'submitted' && can.approve_this && (
                            <button type="button" className="btn-primary text-sm"
                                onClick={() => post('bcms.bia.approve', assessment.id)}>
                                Approve
                            </button>
                        )}
                    </div>
                }
            />

            {process.is_critical_service && (
                <div className="mb-4 rounded-lg border border-purple-200 bg-purple-50 p-3 text-sm text-purple-900">
                    <span className="font-semibold">Designated a critical service.</span>{' '}
                    {process.critical_service_justification}
                </div>
            )}

            {assessment.ai_generated && (
                <div className="mb-4 rounded-lg border border-sky-300 bg-sky-50 p-4 text-sm text-sky-900">
                    <p className="font-semibold">Parts of this assessment were drafted by a model on {assessment.ai_drafted_at}.</p>
                    <p className="mt-1">
                        Nothing here is approved by drafting it. Read what it proposed and why, change what is wrong,
                        and submit it yourself.
                    </p>
                    {Array.isArray(assessment.ai_reasoning?.challenge_questions) && assessment.ai_reasoning.challenge_questions.length > 0 && (
                        <ul className="mt-2 list-disc space-y-1 pl-5">
                            {assessment.ai_reasoning.challenge_questions.map((q, i) => <li key={i}>{q}</li>)}
                        </ul>
                    )}
                </div>
            )}

            {!ai.available && ai.reason && editable && (
                <p className="mb-4 text-xs text-gray-500">AI drafting is unavailable: {ai.reason}</p>
            )}

            {blocking.length > 0 && (
                <div className="mb-4 rounded-lg border border-red-300 bg-red-50 p-4">
                    <p className="text-sm font-semibold text-red-900">
                        This cannot be submitted until {blocking.length === 1 ? 'this is' : 'these are'} resolved
                    </p>
                    <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-red-900">
                        {blocking.map((issue, i) => <li key={i}>{issue.message}</li>)}
                    </ul>
                </div>
            )}

            {warnings.length > 0 && (
                <div className="mb-6 rounded-lg border border-amber-300 bg-amber-50 p-4">
                    <p className="text-sm font-semibold text-amber-900">Worth a second look — these do not block submission</p>
                    <ul className="mt-2 space-y-2 text-sm text-amber-900">
                        {warnings.map((issue, i) => (
                            <li key={i}>
                                {issue.message}
                                {issue.citation && <span className="block text-xs italic">{issue.citation}</span>}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            <div className="grid grid-cols-1 gap-6 xl:grid-cols-3">
                <section className="space-y-6 xl:col-span-2">
                    <div className="overflow-x-auto rounded-lg border border-gray-200 bg-white p-5">
                        <h2 className="text-sm font-semibold text-gray-900">Impact over time</h2>
                        <p className="mt-1 text-xs text-gray-500">
                            Score how bad the disruption is at each point. The MTPD is proposed from the first
                            horizon at which any category becomes intolerable.
                        </p>

                        <table className="mt-4 w-full text-sm">
                            <thead>
                                <tr className="text-left text-xs uppercase tracking-wide text-gray-500">
                                    <th className="pb-2 pr-3">Category</th>
                                    {grid.horizons.map((h) => <th key={h.value} className="pb-2 px-2 text-center">{h.value}</th>)}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {grid.categories.map((c) => (
                                    <tr key={c.value}>
                                        <td className="py-2 pr-3 text-gray-800">
                                            {c.label}
                                            {c.monetary && <span className="block text-[11px] text-gray-400">has a naira figure</span>}
                                        </td>
                                        {grid.horizons.map((h) => {
                                            const value = grid.cells?.[c.value]?.[h.value];
                                            const severity = value?.severity ?? null;

                                            return (
                                                <td key={h.value} className="px-1 py-1 text-center">
                                                    <button
                                                        type="button"
                                                        disabled={!editable}
                                                        onClick={() => setCell({ category: c.value, horizon: h.value, monetary: c.monetary, ...value })}
                                                        title={value?.narrative ?? undefined}
                                                        className={`h-9 w-full rounded text-sm ${severity ? SEVERITY_CLASS[severity] : 'bg-gray-50 text-gray-400'}`}
                                                    >
                                                        {severity ?? '·'}
                                                    </button>
                                                </td>
                                            );
                                        })}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="rounded-lg border border-gray-200 bg-white p-5">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <h2 className="text-sm font-semibold text-gray-900">Dependencies</h2>
                                <p className="mt-1 text-xs text-gray-500">
                                    What this process cannot run without. Vendors come from the third-party register
                                    and people from the platform — nothing is duplicated here.
                                </p>
                            </div>
                        </div>

                        <ul className="mt-3 divide-y divide-gray-100 text-sm">
                            {dependencies.length === 0 && (
                                <li className="py-3 text-gray-500">
                                    None recorded. A BIA with no dependencies has not found what the process actually
                                    needs — which is the half of clause 8.2.2 that produces the recovery plan.
                                </li>
                            )}
                            {dependencies.map((d) => (
                                <li key={d.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                                    <div>
                                        <span className="text-gray-900">{d.name}</span>
                                        <span className="ml-2 text-xs text-gray-500">{d.type_label} · {d.criticality}</span>
                                        <span className="block text-xs text-gray-500">{d.relation_label}</span>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        {d.single_point_of_failure && (
                                            <span className="rounded bg-red-50 px-2 py-0.5 text-xs text-red-800">single point of failure</span>
                                        )}
                                        {editable && (
                                            <button type="button" className="text-xs text-gray-500 underline"
                                                onClick={() => router.delete(tryRoute('bcms.bia.dependencies.destroy', [assessment.id, d.id]), { preserveScroll: true })}>
                                                remove
                                            </button>
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ul>

                        {editable && <AddDependency assessmentId={assessment.id} options={options} />}
                    </div>
                </section>

                <aside className="space-y-6">
                    <div className="rounded-lg border border-gray-200 bg-white p-5">
                        <h2 className="text-sm font-semibold text-gray-900">Proposed MTPD</h2>
                        {derivedMtpd?.hours === null ? (
                            <p className="mt-2 text-sm text-gray-500">{derivedMtpd.rationale}</p>
                        ) : (
                            <>
                                <p className="mt-2 font-mono text-2xl text-gray-900">{derivedMtpd.hours} hours</p>
                                <p className="mt-1 text-xs text-gray-600">{derivedMtpd.rationale}</p>
                                {editable && (
                                    <button type="button" className="btn-secondary mt-3 text-xs"
                                        onClick={() => post('bcms.bia.accept-mtpd', assessment.id)}>
                                        Use this as the MTPD
                                    </button>
                                )}
                            </>
                        )}
                    </div>

                    <form
                        onSubmit={(e) => { e.preventDefault(); objectives.put(tryRoute('bcms.bia.update', assessment.id), { preserveScroll: true }); }}
                        className="space-y-4 rounded-lg border border-gray-200 bg-white p-5"
                    >
                        <h2 className="text-sm font-semibold text-gray-900">Recovery objectives</h2>

                        {[
                            ['mtpd_hours', 'Maximum tolerable period of disruption (hours)'],
                            ['rto_hours', 'Recovery time objective (hours)'],
                            ['rpo_minutes', 'Recovery point objective (minutes)'],
                            ['min_staff_required', 'Minimum staff required'],
                        ].map(([field, label]) => (
                            <label key={field} className="block text-sm">
                                <span className="text-gray-700">{label}</span>
                                <input type="number" step="any" disabled={!editable}
                                    className="mt-1 w-full rounded border-gray-300 text-sm"
                                    value={objectives.data[field]}
                                    onChange={(e) => objectives.setData(field, e.target.value)} />
                                {objectives.errors[field] && <span className="text-xs text-red-600">{objectives.errors[field]}</span>}
                                {assessment.ai_reasoning?.[field] && (
                                    <span className="mt-1 block text-xs italic text-sky-700">AI: {assessment.ai_reasoning[field]}</span>
                                )}
                            </label>
                        ))}

                        <label className="block text-sm">
                            <span className="text-gray-700">Minimum business continuity objective</span>
                            <textarea rows={3} disabled={!editable} className="mt-1 w-full rounded border-gray-300 text-sm"
                                value={objectives.data.mbco_description}
                                onChange={(e) => objectives.setData('mbco_description', e.target.value)} />
                            <span className="mt-1 block text-xs text-gray-500">In words, not numbers — what must keep running.</span>
                        </label>

                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" disabled={!editable} checked={objectives.data.workaround_available}
                                onChange={(e) => objectives.setData('workaround_available', e.target.checked)} />
                            <span className="text-gray-700">A manual workaround exists</span>
                        </label>

                        {objectives.data.workaround_available && (
                            <label className="block text-sm">
                                <span className="text-gray-700">How long it can be sustained (hours)</span>
                                <input type="number" step="any" disabled={!editable}
                                    className="mt-1 w-full rounded border-gray-300 text-sm"
                                    value={objectives.data.workaround_max_duration_hours}
                                    onChange={(e) => objectives.setData('workaround_max_duration_hours', e.target.value)} />
                            </label>
                        )}

                        {editable && (
                            <button type="submit" className="btn-primary text-sm" disabled={objectives.processing}>Save</button>
                        )}
                    </form>
                </aside>
            </div>

            {cell && (
                <ImpactCellEditor
                    assessmentId={assessment.id}
                    cell={cell}
                    onClose={() => setCell(null)}
                />
            )}
        </AppLayout>
    );
}

function ImpactCellEditor({ assessmentId, cell, onClose }) {
    const form = useForm({
        impact_category: cell.category,
        horizon: cell.horizon,
        severity_score: cell.severity ?? '',
        financial_amount_minor: cell.financial_amount_minor ?? '',
        narrative: cell.narrative ?? '',
    });

    return (
        <div className="fixed inset-0 z-40 flex items-center justify-center bg-black/30 p-4">
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(tryRoute('bcms.bia.impacts.store', assessmentId), { preserveScroll: true, onSuccess: onClose });
                }}
                className="w-full max-w-lg space-y-4 rounded-lg bg-white p-6 shadow-lg"
            >
                <h2 className="text-sm font-semibold text-gray-900">
                    {cell.category} impact at {cell.horizon}
                </h2>

                <label className="block text-sm">
                    <span className="text-gray-700">Severity (1 tolerable — 5 intolerable)</span>
                    <select className="mt-1 w-full rounded border-gray-300 text-sm" value={form.data.severity_score}
                        onChange={(e) => form.setData('severity_score', e.target.value)}>
                        <option value="">Not scored</option>
                        {[1, 2, 3, 4, 5].map((n) => <option key={n} value={n}>{n}</option>)}
                    </select>
                </label>

                {cell.monetary && (
                    <label className="block text-sm">
                        <span className="text-gray-700">Financial impact (kobo)</span>
                        <input type="number" className="mt-1 w-full rounded border-gray-300 text-sm"
                            value={form.data.financial_amount_minor}
                            onChange={(e) => form.setData('financial_amount_minor', e.target.value)} />
                        <span className="mt-1 block text-xs text-gray-500">Minor units, so nothing is lost to rounding.</span>
                    </label>
                )}

                <label className="block text-sm">
                    <span className="text-gray-700">What the impact looks like</span>
                    <textarea rows={3} className="mt-1 w-full rounded border-gray-300 text-sm" value={form.data.narrative}
                        onChange={(e) => form.setData('narrative', e.target.value)} />
                </label>

                {form.errors.financial_amount_minor && (
                    <p className="text-xs text-red-600">{form.errors.financial_amount_minor}</p>
                )}

                <div className="flex gap-2">
                    <button type="submit" className="btn-primary text-sm" disabled={form.processing}>Save</button>
                    <button type="button" className="btn-secondary text-sm" onClick={onClose}>Cancel</button>
                </div>
            </form>
        </div>
    );
}

function AddDependency({ assessmentId, options }) {
    const form = useForm({
        dependable_type: options.types[0]?.value ?? '',
        dependable_id: '',
        dependency_type: 'upstream',
        criticality: 'medium',
        single_point_of_failure: false,
        alternative_available: false,
        recovery_notes: '',
    });

    const targets = options.targets?.[form.data.dependable_type] ?? [];

    return (
        <form
            onSubmit={(e) => { e.preventDefault(); form.post(tryRoute('bcms.bia.dependencies.store', assessmentId), { preserveScroll: true, onSuccess: () => form.reset('dependable_id', 'recovery_notes') }); }}
            className="mt-4 space-y-3 rounded border border-dashed border-gray-300 p-3"
        >
            <div className="flex flex-wrap gap-2">
                <select className="rounded border-gray-300 text-sm" value={form.data.dependable_type}
                    onChange={(e) => { form.setData('dependable_type', e.target.value); form.setData('dependable_id', ''); }}>
                    {options.types.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                </select>

                <select className="min-w-56 flex-1 rounded border-gray-300 text-sm" value={form.data.dependable_id}
                    onChange={(e) => form.setData('dependable_id', e.target.value)}>
                    <option value="">Choose…</option>
                    {targets.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                </select>

                <select className="rounded border-gray-300 text-sm" value={form.data.dependency_type}
                    onChange={(e) => form.setData('dependency_type', e.target.value)}>
                    {options.relations.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
                </select>

                <select className="rounded border-gray-300 text-sm" value={form.data.criticality}
                    onChange={(e) => form.setData('criticality', e.target.value)}>
                    {options.criticalities.map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
                </select>
            </div>

            <div className="flex flex-wrap items-center gap-4 text-sm">
                <label className="flex items-center gap-2">
                    <input type="checkbox" checked={form.data.single_point_of_failure}
                        onChange={(e) => form.setData('single_point_of_failure', e.target.checked)} />
                    <span className="text-gray-700">Single point of failure</span>
                </label>
                <label className="flex items-center gap-2">
                    <input type="checkbox" checked={form.data.alternative_available}
                        onChange={(e) => form.setData('alternative_available', e.target.checked)} />
                    <span className="text-gray-700">An alternative exists</span>
                </label>
                <button type="submit" className="btn-secondary text-sm" disabled={form.processing}>Add</button>
            </div>

            {form.errors.single_point_of_failure && (
                <p className="text-xs text-red-600">{form.errors.single_point_of_failure}</p>
            )}
        </form>
    );
}
