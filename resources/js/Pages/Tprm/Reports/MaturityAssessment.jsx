import { useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';

/**
 * One maturity assessment — FR-RPT-06.
 *
 * THE GAP PLAN IS ABOVE THE SCORES, because the scores are the input and the
 * plan is the output. A page that opened with eighteen dropdowns would be a
 * data-entry form; this one opens with what the programme has committed to
 * doing about the categories it is short on.
 *
 * A GAP WITH NO ACTION RECORDED IS MARKED. That is the gap plan's own gap, and
 * a plan listing six shortfalls and three actions should say so on its face.
 *
 * "NOT ASSESSED" IS A SELECTABLE VALUE, not an empty state. Clearing a score
 * is a legitimate correction — somebody who realises they scored a category on
 * the wrong evidence should be able to take the number back out rather than
 * leave one they no longer stand behind.
 */
export default function MaturityAssessmentPage({
    assessment = {},
    scores = [],
    gapPlan = {},
    levels = {},
    owners = [],
    can = {},
}) {
    const [editing, setEditing] = useState(null);

    const byFramework = (framework) => scores.filter((score) => score.framework === framework);

    const approve = () => {
        router.post(route('tprm.reports.maturity.approve', assessment.uuid), {}, { preserveScroll: true });
    };

    return (
        <AppLayout title={`Maturity — ${assessment.period_label}`}>
            <Head title={`Maturity — ${assessment.period_label}`} />

            <PageHeader
                title={`Programme maturity — ${assessment.period_label}`}
                subtitle={`Position as at ${assessment.as_at}. Rubric ${assessment.framework_version}.`}
            />

            {!assessment.editable && (
                <div className="mb-6 rounded border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
                    <strong>Approved by {assessment.approved_by}</strong>
                    {assessment.approved_at ? ` on ${assessment.approved_at}` : ''}. These scores are frozen and
                    sit on the trend. Open a new period to record a later position.
                </div>
            )}

            {assessment.editable && can.assess && (
                <div className="mb-6 flex items-center gap-3">
                    <button type="button" className="btn-primary" onClick={approve}>
                        Approve and freeze
                    </button>
                    <span className="text-xs text-gray-500">
                        Approving puts this period on the trend. At least one category must carry a current
                        level — an assessment of nothing is not a baseline.
                    </span>
                </div>
            )}

            {/* ---------------------------------------------------- gap plan */}
            <section className="mb-6">
                <h2 className="mb-2 text-sm font-semibold text-gray-800">Gap plan</h2>

                <div className="card overflow-x-auto">
                    <table className="min-w-full divide-y divide-gray-200 text-sm">
                        <caption className="px-4 py-2 text-left text-xs text-gray-500">
                            Categories short of their own target, worst first. {gapPlan.no_target ?? 0} scored
                            categories have no target set and are not in this plan — a gap measured against a
                            target nobody agreed is not a gap. {gapPlan.unscored ?? 0} categories are unscored.
                        </caption>
                        <thead className="bg-gray-50">
                            <tr>
                                <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Category</th>
                                <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Current</th>
                                <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Target</th>
                                <th scope="col" className="px-4 py-2 text-right font-medium text-gray-600">Gap</th>
                                <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Actions</th>
                                <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Owner</th>
                                <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">By</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {(gapPlan.items ?? []).map((item) => (
                                <tr key={`${item.framework}-${item.category_code}`}>
                                    <td className="px-4 py-2">
                                        <span className="font-medium">{item.category_code}</span> {item.category_name}
                                    </td>
                                    <td className="px-4 py-2">{item.current_label}</td>
                                    <td className="px-4 py-2">{item.target_label}</td>
                                    <td className="px-4 py-2 text-right tabular-nums">{item.gap}</td>
                                    <td className="px-4 py-2">
                                        {item.has_plan ? (
                                            item.actions
                                        ) : (
                                            <span className="rounded bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">
                                                No action recorded
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-2">{item.owner ?? '—'}</td>
                                    <td className="px-4 py-2">{item.target_date ?? '—'}</td>
                                </tr>
                            ))}
                            {(gapPlan.items ?? []).length === 0 && (
                                <tr>
                                    <td colSpan={7} className="px-4 py-8 text-center text-sm text-gray-500">
                                        No scored category is below its target. Read that against the coverage
                                        figures in the caption above.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </section>

            {/* ------------------------------------------------------ scores */}
            {[
                ['vrmmm', 'VRMMM categories', 'Category names as published; the level criteria below are this product’s own.'],
                ['nist_csf', 'NIST CSF 2.0 — GV.SC', 'Subcategory titles quoted from the framework.'],
            ].map(([framework, title, note]) => (
                <section key={framework} className="mb-6">
                    <h2 className="mb-1 text-sm font-semibold text-gray-800">{title}</h2>
                    <p className="mb-2 text-xs text-gray-500">{note}</p>

                    <div className="space-y-2">
                        {byFramework(framework).map((score) => (
                            <ScoreRow
                                key={score.id}
                                score={score}
                                levels={levels}
                                owners={owners}
                                editable={assessment.editable && can.assess}
                                open={editing === score.id}
                                onOpen={() => setEditing(editing === score.id ? null : score.id)}
                                onSaved={() => setEditing(null)}
                            />
                        ))}
                    </div>
                </section>
            ))}
        </AppLayout>
    );
}

function ScoreRow({ score, levels, owners, editable, open, onOpen, onSaved }) {
    const { data, setData, put, processing } = useForm({
        current_level: score.current_level ?? '',
        target_level: score.target_level ?? '',
        evidence: score.evidence ?? '',
        gap_actions: score.gap_actions ?? '',
        owner_id: score.owner_id ?? '',
        target_date: score.target_date ?? '',
    });

    const save = (event) => {
        event.preventDefault();
        put(route('tprm.reports.maturity.score', score.id), { preserveScroll: true, onSuccess: onSaved });
    };

    return (
        <div className="card p-4">
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div className="min-w-0 flex-1">
                    <div className="text-sm font-medium text-gray-800">
                        {score.category_code} — {score.category_name}
                    </div>
                    {score.criterion && <p className="mt-1 text-xs text-gray-500">{score.criterion}</p>}
                    {score.evidence && <p className="mt-2 text-xs text-gray-600">{score.evidence}</p>}
                </div>

                <div className="flex items-center gap-4 text-sm">
                    <div className="text-right">
                        <div className="text-xs uppercase tracking-wide text-gray-500">Current</div>
                        <div
                            className={`font-semibold ${
                                score.current_level === null ? 'text-gray-400' : 'text-gray-900'
                            }`}
                        >
                            {score.current_label}
                        </div>
                    </div>
                    <div className="text-right">
                        <div className="text-xs uppercase tracking-wide text-gray-500">Target</div>
                        <div className="font-semibold text-gray-700">{score.target_label}</div>
                    </div>
                    {editable && (
                        <button type="button" className="btn-secondary" onClick={onOpen}>
                            {open ? 'Close' : 'Score'}
                        </button>
                    )}
                </div>
            </div>

            {open && (
                <form onSubmit={save} className="mt-4 grid gap-4 border-t border-gray-100 pt-4 md:grid-cols-2">
                    <label className="text-sm">
                        <span className="mb-1 block font-medium text-gray-700">Current level</span>
                        <select
                            className="w-full rounded border-gray-300 text-sm"
                            value={data.current_level}
                            onChange={(event) => setData('current_level', event.target.value)}
                        >
                            <option value="">Not assessed</option>
                            {Object.entries(levels).map(([level, label]) => (
                                <option key={level} value={level}>{level} — {label}</option>
                            ))}
                        </select>
                    </label>

                    <label className="text-sm">
                        <span className="mb-1 block font-medium text-gray-700">Target level</span>
                        <select
                            className="w-full rounded border-gray-300 text-sm"
                            value={data.target_level}
                            onChange={(event) => setData('target_level', event.target.value)}
                        >
                            <option value="">No target set</option>
                            {Object.entries(levels).map(([level, label]) => (
                                <option key={level} value={level}>{level} — {label}</option>
                            ))}
                        </select>
                    </label>

                    <label className="text-sm md:col-span-2">
                        <span className="mb-1 block font-medium text-gray-700">Evidence for the current level</span>
                        <textarea
                            rows={2}
                            className="w-full rounded border-gray-300 text-sm"
                            value={data.evidence}
                            onChange={(event) => setData('evidence', event.target.value)}
                        />
                    </label>

                    <label className="text-sm md:col-span-2">
                        <span className="mb-1 block font-medium text-gray-700">What closes the gap</span>
                        <textarea
                            rows={2}
                            className="w-full rounded border-gray-300 text-sm"
                            value={data.gap_actions}
                            onChange={(event) => setData('gap_actions', event.target.value)}
                        />
                    </label>

                    <label className="text-sm">
                        <span className="mb-1 block font-medium text-gray-700">Owner</span>
                        <select
                            className="w-full rounded border-gray-300 text-sm"
                            value={data.owner_id}
                            onChange={(event) => setData('owner_id', event.target.value)}
                        >
                            <option value="">Unassigned</option>
                            {owners.map((owner) => (
                                <option key={owner.value} value={owner.value}>{owner.label}</option>
                            ))}
                        </select>
                    </label>

                    <label className="text-sm">
                        <span className="mb-1 block font-medium text-gray-700">Target date</span>
                        <input
                            type="date"
                            className="w-full rounded border-gray-300 text-sm"
                            value={data.target_date}
                            onChange={(event) => setData('target_date', event.target.value)}
                        />
                    </label>

                    <div className="md:col-span-2">
                        <button type="submit" className="btn-primary" disabled={processing}>
                            Save
                        </button>
                    </div>
                </form>
            )}
        </div>
    );
}
