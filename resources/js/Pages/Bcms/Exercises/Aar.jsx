import { Head, Link, router, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import FormField from '@thirdline/ui/Components/FormField';

/**
 * The after-action report builder (aar-builder spec).
 *
 * THE GATE IS A LIVE STATUS, NOT A SUBMIT-TIME SURPRISE. `conditions` arrives
 * computed on every load from `AarService::conditions()` — the same method
 * `finalise()` itself calls — and this screen never re-derives it. A
 * client-side re-implementation of an eleven-condition gate is exactly where
 * it would drift from the server's own logic (spec §7).
 *
 * TWO-BUTTON RACE: Finalise/Reopen/Distribute/AI-draft each hit their OWN
 * fixed route with a fixed payload — none of them share a `useForm` whose
 * `data.action` a second button could race, so the `useRef`-for-intent
 * pattern `Rcsa/Worksheet.jsx` needs does not apply here. Where the SAME
 * form could be submitted with two different intents, that pattern is the
 * one to reach for; this screen never puts itself in that shape.
 */
const METRIC_LABELS = {
    headcount_expected: 'Headcount expected', headcount_actual: 'Headcount actual',
    time_to_assembly_seconds: 'Time to assembly (seconds)', target_seconds: 'Target (seconds)',
    unaccounted_resolved: 'Unaccounted resolved', roll_call_responses: 'Roll-call responses',
    nodes_total: 'Nodes total', nodes_reached: 'Nodes reached', completion_rate: 'Completion rate',
    first_attempt_rate: 'First-attempt rate', deputy_activation_rate: 'Deputy activation rate',
    total_cascade_minutes: 'Total cascade minutes', rto_target_minutes: 'RTO target (minutes)',
    rto_actual_minutes: 'RTO actual (minutes)', rpo_target_minutes: 'RPO target (minutes)',
    rpo_actual_minutes: 'RPO actual (minutes)', met_objectives: 'Objectives met',
    downtime_minutes: 'Downtime (minutes)', threshold_breached: 'Threshold breached',
    failback_performed: 'Failback performed', data_integrity_verified: 'Data integrity verified',
    time_to_convene_minutes: 'Time to convene (minutes)', decisions_logged: 'Decisions logged',
    holding_statement_minutes: 'Holding statement issued (minutes)',
    escalation_decision_at: 'Escalation decision at', regulatory_window_minutes: 'Regulatory window (minutes)',
    regulatory_window_met: 'Regulatory window met', activation_criteria_applied: 'Activation criteria applied',
    plan_steps_tested: 'Plan steps tested', plan_steps_failed: 'Plan steps failed',
    vendor_id: 'Vendor', workaround_executable: 'Workaround executable', mbco_delivered: 'MBCO delivered',
};

/**
 * The per-exercise-type-family required metric KEYS ONLY — a read-only
 * mirror of `AarService::REQUIRED_METRICS`, used exclusively to decide which
 * fields this section renders as editable. This is a rendering decision, not
 * the gate: condition 11's met/unmet verdict always comes from the server's
 * `conditions[]`, never recomputed here.
 */
const REQUIRED_METRICS = {
    FIREDRILL: ['headcount_expected', 'headcount_actual', 'time_to_assembly_seconds', 'target_seconds', 'unaccounted_resolved'],
    EVAC: ['headcount_expected', 'headcount_actual', 'time_to_assembly_seconds', 'target_seconds', 'unaccounted_resolved', 'roll_call_responses'],
    CALLTREE: ['nodes_total', 'nodes_reached', 'completion_rate', 'first_attempt_rate', 'deputy_activation_rate', 'total_cascade_minutes'],
    DRFAILOVER: ['rto_target_minutes', 'rto_actual_minutes', 'rpo_target_minutes', 'rpo_actual_minutes', 'met_objectives', 'downtime_minutes', 'threshold_breached'],
    DRFAILBACK: ['rto_target_minutes', 'rto_actual_minutes', 'rpo_target_minutes', 'rpo_actual_minutes', 'met_objectives', 'failback_performed'],
    DRTEST: ['rto_target_minutes', 'rto_actual_minutes', 'rpo_target_minutes', 'rpo_actual_minutes', 'met_objectives', 'data_integrity_verified'],
    BACKUP: ['rpo_target_minutes', 'rpo_actual_minutes', 'met_objectives', 'data_integrity_verified'],
    CRISISSIM: ['time_to_convene_minutes', 'decisions_logged', 'holding_statement_minutes'],
    CYBER: ['time_to_convene_minutes', 'decisions_logged', 'escalation_decision_at', 'regulatory_window_minutes', 'regulatory_window_met'],
    FUNCTIONAL: ['time_to_convene_minutes', 'decisions_logged'],
    TABLETOP: ['decisions_logged', 'activation_criteria_applied'],
    PANDEMIC: ['decisions_logged', 'activation_criteria_applied'],
    WALKTHRU: ['plan_steps_tested', 'plan_steps_failed'],
    ORIENT: ['plan_steps_tested', 'plan_steps_failed'],
    SUPPLIER: ['plan_steps_tested', 'plan_steps_failed', 'vendor_id', 'workaround_executable'],
    FULLSCALE: ['time_to_convene_minutes', 'decisions_logged', 'mbco_delivered'],
};

export default function Aar({
    aar = {}, occurrence = null, conditions = [], all_conditions_met: allConditionsMet = false,
    approval = { allowed: true, reason: null, blocking: false }, timeline = { entries: [], total: 0 },
    findings = [], ai = {}, ai_suggested_findings: aiSuggestions = [], options = {}, can = {}, urls = {},
}) {
    const qr = aar.quantitative_results ?? {};
    const isFinal = aar.status === 'final';
    const editable = can.manage && !isFinal;

    const [summary, setSummary] = useState(aar.summary ?? '');
    const [whatWorked, setWhatWorked] = useState(aar.what_worked ?? '');
    const [whatFailed, setWhatFailed] = useState(aar.what_failed ?? '');
    const [objectiveEdits, setObjectiveEdits] = useState({});
    const [metricEdits, setMetricEdits] = useState({});
    const [notMeasuredEdits, setNotMeasuredEdits] = useState({});
    const [carriedEdits, setCarriedEdits] = useState({});
    const [saving, setSaving] = useState(false);
    const [saveErrors, setSaveErrors] = useState({});
    const [confirmingFinalise, setConfirmingFinalise] = useState(false);
    const [confirmingDistribute, setConfirmingDistribute] = useState(false);
    const [reopening, setReopening] = useState(false);
    const [aiDrafting, setAiDrafting] = useState(false);
    // null = the form is closed; otherwise {objectiveText, description} prefill.
    const [raising, setRaising] = useState(null);

    const objectives = qr.objectives ?? [];
    const carried = qr.carried_actions ?? [];
    const metrics = qr.metrics ?? {};
    const notMeasured = qr.not_measured ?? [];
    const requiredKeys = REQUIRED_METRICS[qr.scope?.exercise_type_code] ?? [];
    const typeCode = qr.scope?.exercise_type_code;

    const objectiveField = (text, key, fallback) => objectiveEdits[text]?.[key] ?? fallback;
    const setObjectiveField = (text, key, value) => setObjectiveEdits((prev) => ({ ...prev, [text]: { ...prev[text], [key]: value } }));

    const metricValue = (key) => (key in metricEdits ? metricEdits[key] : metrics[key]);
    const setMetric = (key, value) => setMetricEdits((prev) => ({ ...prev, [key]: value }));
    const notMeasuredReason = (key) => notMeasuredEdits[key] ?? (notMeasured.find((n) => n.metric === key)?.reason ?? '');
    const setNotMeasuredReason = (key, value) => setNotMeasuredEdits((prev) => ({ ...prev, [key]: value }));

    const carriedField = (ref, key, fallback) => carriedEdits[ref]?.[key] ?? fallback;
    const setCarriedField = (ref, key, value) => setCarriedEdits((prev) => ({ ...prev, [ref]: { ...prev[ref], [key]: value } }));

    const saveDraft = () => {
        setSaving(true);
        setSaveErrors({});

        const payload = {
            summary, what_worked: whatWorked, what_failed: whatFailed,
            quantitative_results: {
                objectives: objectives.map((o) => ({
                    objective_text: o.objective_text,
                    target: objectiveField(o.objective_text, 'target', o.target),
                    actual: objectiveField(o.objective_text, 'actual', o.actual),
                    met: objectiveField(o.objective_text, 'met', o.met),
                    mean_score: o.mean_score,
                    scores_count: o.scores_count,
                    finding_reference: o.finding_reference,
                    disposition_note: objectiveField(o.objective_text, 'disposition_note', o.disposition_note),
                })),
                metrics: { ...metrics, ...metricEdits },
                not_measured: requiredKeys
                    .filter((k) => notMeasuredReason(k).trim() !== '')
                    .map((k) => ({ metric: k, reason: notMeasuredReason(k) })),
            },
            objective_disposition: objectives
                .filter((o) => objectiveEdits[o.objective_text]?.disposition_note !== undefined)
                .map((o) => ({ objective_text: o.objective_text, note: objectiveField(o.objective_text, 'disposition_note', o.disposition_note) })),
            carried_action_disposition: carried.map((c) => ({
                reference: c.reference,
                disposition: carriedField(c.reference, 'disposition', c.disposition) || 'still_open',
                note: carriedField(c.reference, 'note', c.note),
            })),
        };

        router.patch(urls.update, payload, {
            preserveScroll: true,
            onError: (errors) => setSaveErrors(errors),
            onFinish: () => setSaving(false),
        });
    };

    const requestAiDraft = () => {
        setAiDrafting(true);
        router.post(urls.ai_draft, {}, { preserveScroll: true, onFinish: () => setAiDrafting(false) });
    };

    const finalise = () => {
        router.post(urls.finalise, {}, { preserveScroll: true, onSuccess: () => setConfirmingFinalise(false) });
    };

    const distribute = () => {
        router.post(urls.distribute, {}, { preserveScroll: true, onSuccess: () => setConfirmingDistribute(false) });
    };

    return (
        <AppLayout title={`After-action report — ${occurrence?.definition_name ?? ''}`}>
            <Head title={`After-action report — ${occurrence?.definition_name ?? ''}`} />

            <PageHeader
                title={`After-action report — ${occurrence?.definition_name ?? ''}`}
                subtitle={`${occurrence?.scheduled_date ?? ''} · ${occurrence?.exercise_type ?? ''} · ${aar.status}`}
            />

            {/* Status banner — one of three mutually exclusive states (spec §2). */}
            {isFinal ? (
                <div className="mb-4 rounded border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-900">
                    <p className="font-medium">
                        Finalised by {aar.approved_by ?? '—'} on {formatLocal(aar.approved_at)}.
                        Distributed {aar.distributed_at ? formatLocal(aar.distributed_at) : 'not yet'}.
                    </p>
                    {can.approve && (
                        <button type="button" className="mt-2 text-xs text-emerald-800 underline" onClick={() => setReopening(true)}>
                            Reopen
                        </button>
                    )}
                </div>
            ) : allConditionsMet ? (
                <div className="mb-4 rounded border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                    Every condition is met. Finalising will lock the timeline, scores, injects and attendance for
                    this occurrence.
                </div>
            ) : (
                <div className="mb-4 rounded border border-red-300 bg-red-50 p-4 text-sm text-red-900">
                    <p className="font-semibold">
                        This cannot be finalised until {conditions.filter((c) => !c.met).length} issue(s) are resolved.
                    </p>
                </div>
            )}

            {aar.ai_generated && (
                <div className="mb-4 rounded border border-sky-300 bg-sky-50 p-4 text-sm text-sky-900">
                    <p className="font-semibold">Parts of this report were drafted by a model on {formatLocal(aar.ai_draft_generated_at)}.</p>
                    <p className="mt-1">Nothing here is approved by drafting it. Read what it proposed, change what is wrong, and submit it yourself.</p>
                </div>
            )}

            {/* The live gate checklist — a real list, both icon and text per item (spec §5). */}
            <section className="mb-6 rounded border border-slate-200 bg-white p-4">
                <h2 className="mb-2 text-sm font-semibold text-slate-700">Finalisation checklist</h2>
                <ol className="space-y-1 text-sm">
                    {conditions.map((c) => (
                        <li key={c.key} className={c.met ? 'text-emerald-800' : 'text-red-800'}>
                            {c.met ? '✓ Met' : '✗ Not met'} — {c.label}{!c.met && c.message ? `: ${c.message}` : ''}
                        </li>
                    ))}
                </ol>
            </section>

            {!isFinal && approval.blocking && (
                <div className="mb-4 rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                    {approval.reason}
                </div>
            )}

            {/* 1. Exercise identity and design */}
            <SectionCard number={1} title="Exercise identity and design" clause="ISO 22301 8.5">
                <dl className="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                    <Field label="Exercise" value={occurrence?.definition_name} />
                    <Field label="Type" value={occurrence?.exercise_type} />
                    <Field label="Ladder level" value={occurrence?.ladder_level} />
                    <Field label="Scheduled date" value={occurrence?.scheduled_date} />
                    <Field label="Facilitator" value={occurrence?.facilitator} />
                    <Field label="Participants expected" value={qr.attendance?.expected} />
                </dl>
                {(qr.readiness?.overrides ?? []).length > 0 && (
                    <div className="mt-3 rounded bg-amber-50 p-3 text-xs text-amber-900">
                        <p className="font-medium">Readiness overrides on this occurrence:</p>
                        <ul className="mt-1 list-disc pl-4">
                            {qr.readiness.overrides.map((o, i) => <li key={i}>{o.task}: {o.reason}</li>)}
                        </ul>
                    </div>
                )}
            </SectionCard>

            {/* 2. Objectives vs outcomes */}
            <SectionCard number={2} title="Objectives vs outcomes" clause="ISO 22398">
                {objectives.length === 0 ? (
                    <p className="text-sm text-slate-500">No objectives were defined for this exercise.</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="data-table text-xs">
                            <thead>
                                <tr>
                                    <th>Objective</th>
                                    <th>Target</th>
                                    <th>Actual</th>
                                    <th>Met</th>
                                    <th>Mean score</th>
                                    <th>Disposition</th>
                                </tr>
                            </thead>
                            <tbody>
                                {objectives.map((o) => {
                                    const low = o.mean_score != null && o.mean_score <= 2;
                                    return (
                                        <tr key={o.objective_text} className={low ? 'bg-red-50' : ''}>
                                            <td className="align-top">{o.objective_text}</td>
                                            <td className="align-top">
                                                {editable ? (
                                                    <input className="form-input w-24 text-xs"
                                                        aria-label={`Target for objective: ${o.objective_text}`}
                                                        value={objectiveField(o.objective_text, 'target', o.target) ?? ''}
                                                        onChange={(e) => setObjectiveField(o.objective_text, 'target', e.target.value)} />
                                                ) : (o.target ?? '—')}
                                            </td>
                                            <td className="align-top">
                                                {editable ? (
                                                    <input className="form-input w-24 text-xs"
                                                        aria-label={`Actual for objective: ${o.objective_text}`}
                                                        value={objectiveField(o.objective_text, 'actual', o.actual) ?? ''}
                                                        onChange={(e) => setObjectiveField(o.objective_text, 'actual', e.target.value)} />
                                                ) : (o.actual ?? '—')}
                                            </td>
                                            <td className="align-top">
                                                {editable ? (
                                                    <select className="form-select text-xs"
                                                        aria-label={`Met, for objective: ${o.objective_text}`}
                                                        value={objectiveField(o.objective_text, 'met', o.met) ?? ''}
                                                        onChange={(e) => setObjectiveField(o.objective_text, 'met', e.target.value === '' ? null : e.target.value === 'true')}>
                                                        <option value="">Unknown</option>
                                                        <option value="true">Met</option>
                                                        <option value="false">Not met</option>
                                                    </select>
                                                ) : (
                                                    <span className={o.met === true ? 'text-emerald-700' : o.met === false ? 'text-red-700' : 'text-slate-400'}>
                                                        {o.met === true ? 'Met' : o.met === false ? 'Not met' : 'Unknown'}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="align-top">
                                                {o.mean_score != null ? Number(o.mean_score).toFixed(1) : '—'}
                                                <span className="block text-[10px] text-slate-400">{o.scores_count} score(s)</span>
                                            </td>
                                            <td className="align-top">
                                                {o.finding_reference ? (
                                                    <span className="text-xs text-slate-700">Linked to finding {o.finding_reference}</span>
                                                ) : low ? (
                                                    <div className="space-y-1">
                                                        {editable ? (
                                                            <>
                                                                <textarea rows={2} className="form-textarea w-48 text-xs"
                                                                    aria-label={`Disposition note for objective: ${o.objective_text}`}
                                                                    placeholder="Why, if not raising a finding"
                                                                    value={objectiveField(o.objective_text, 'disposition_note', o.disposition_note) ?? ''}
                                                                    onChange={(e) => setObjectiveField(o.objective_text, 'disposition_note', e.target.value)} />
                                                                {can.finding_manage && (
                                                                    <button type="button" className="block text-xs text-blue-700 hover:underline"
                                                                        aria-label={`Raise a finding for objective: ${o.objective_text}`}
                                                                        onClick={() => setRaising({ objectiveText: o.objective_text, description: '' })}>
                                                                        Raise a finding for this objective
                                                                    </button>
                                                                )}
                                                            </>
                                                        ) : (o.disposition_note ?? <span className="text-red-700">Not dispositioned</span>)}
                                                    </div>
                                                ) : '—'}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </SectionCard>

            {/* 3. Timeline of key events */}
            <SectionCard number={3} title="Timeline of key events" clause="ISO 22301 8.5">
                {timeline.entries.length === 0 ? (
                    <p className="text-sm text-slate-500">No timeline entries recorded.</p>
                ) : (
                    <ul className="divide-y divide-slate-100 text-sm">
                        {timeline.entries.map((t) => (
                            <li key={t.id} className="flex gap-3 py-1.5">
                                <span className="w-14 shrink-0 font-mono text-xs text-slate-500">{t.logged_at?.slice(11, 16)}</span>
                                <span className="shrink-0 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-700">{t.entry_type}</span>
                                <span className="flex-1">{t.content}</span>
                            </li>
                        ))}
                    </ul>
                )}
                {timeline.total > timeline.entries.length && (
                    <p className="mt-2 text-xs text-slate-500">Showing the last {timeline.entries.length} of {timeline.total}. The export carries the full timeline.</p>
                )}
            </SectionCard>

            {/* 4. Quantitative results */}
            <SectionCard number={4} title="Quantitative results" clause="bcms.aar.quantitative.v1">
                {requiredKeys.length === 0 ? (
                    <p className="text-sm text-slate-500">This exercise type has no required metrics.</p>
                ) : (
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {requiredKeys.map((key) => {
                            const value = metricValue(key);
                            const absent = value === undefined || value === null;
                            const computed = key === 'threshold_breached';

                            return (
                                <div key={key} className="rounded border border-slate-200 p-3">
                                    <label htmlFor={`metric-${key}`} className="block text-xs font-medium text-slate-600">
                                        {METRIC_LABELS[key] ?? key}
                                    </label>
                                    {computed ? (
                                        <p className="mt-1 text-sm text-slate-800">
                                            {value === true ? 'Breached' : value === false ? 'Not breached' : 'Not yet computed'}
                                            {metrics.downtime_minutes != null && metrics.rto_target_minutes != null && (
                                                <span className="block text-[11px] text-slate-500">
                                                    {metrics.downtime_minutes} min actual vs {metrics.rto_target_minutes} min threshold
                                                </span>
                                            )}
                                        </p>
                                    ) : editable ? (
                                        <input id={`metric-${key}`} className="form-input mt-1 w-full text-sm"
                                            value={value ?? ''} onChange={(e) => setMetric(key, e.target.value)} />
                                    ) : (
                                        <p className="mt-1 text-sm text-slate-800">{value ?? 'Not recorded'}</p>
                                    )}

                                    {!computed && absent && (
                                        editable ? (
                                            <div className="mt-2">
                                                <label htmlFor={`nm-${key}`} className="block text-[11px] text-slate-500">Not measured — state why</label>
                                                <input id={`nm-${key}`} className="form-input mt-0.5 w-full text-xs"
                                                    value={notMeasuredReason(key)} onChange={(e) => setNotMeasuredReason(key, e.target.value)} />
                                            </div>
                                        ) : notMeasuredReason(key) ? (
                                            <p className="mt-1 text-[11px] text-amber-800">Not measured: {notMeasuredReason(key)}</p>
                                        ) : null
                                    )}
                                </div>
                            );
                        })}
                    </div>
                )}
            </SectionCard>

            {/* 5. What worked / what did not */}
            <SectionCard number={5} title="What worked / what did not" clause="ISO 22398">
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <FormField label="What worked" htmlFor="aar-what-worked" error={saveErrors.what_worked}>
                        <textarea id="aar-what-worked" rows={5} disabled={!editable} className="form-textarea w-full"
                            placeholder="Nothing recorded yet — what worked well during this exercise?"
                            value={whatWorked} onChange={(e) => setWhatWorked(e.target.value)} />
                    </FormField>
                    <FormField label="What did not work" htmlFor="aar-what-failed" error={saveErrors.what_failed}>
                        <textarea id="aar-what-failed" rows={5} disabled={!editable} className="form-textarea w-full"
                            placeholder="Nothing recorded yet — what did not go to plan?"
                            value={whatFailed} onChange={(e) => setWhatFailed(e.target.value)} />
                    </FormField>
                </div>
                <FormField label="Summary" htmlFor="aar-summary" className="mt-4" error={saveErrors.summary}>
                    <textarea id="aar-summary" rows={4} disabled={!editable} className="form-textarea w-full"
                        value={summary} onChange={(e) => setSummary(e.target.value)} />
                </FormField>
            </SectionCard>

            {/* 6. Participant feedback */}
            <SectionCard number={6} title="Participant feedback" clause="ISO 22398">
                <ParticipantFeedback feedback={aar.participant_feedback} />
            </SectionCard>

            {/* 7 & 8. Findings and corrective actions */}
            <SectionCard number={7} title="Findings and corrective actions" clause="ISO 22301 10.1">
                {findings.length === 0 && <p className="mb-3 text-sm text-slate-500">No findings raised against this report yet.</p>}
                <div className="space-y-3">
                    {findings.map((f) => (
                        <article key={f.uuid} className="rounded border border-slate-200 p-3 text-sm">
                            <p className="font-mono text-xs text-slate-500">{f.reference} · {f.classification} · {f.severity ?? 'unrated'} · {f.iso_clause_ref}</p>
                            <p className="mt-1">{f.description}</p>
                            <div className="mt-2 space-y-1">
                                {f.actions.map((a) => (
                                    <div key={a.uuid} className="flex justify-between rounded bg-slate-50 p-2 text-xs">
                                        <span>{a.reference} — {a.title}</span>
                                        <span>{a.owner ?? 'unassigned'} · due {a.due_date ?? 'not set'} · {a.status}</span>
                                    </div>
                                ))}
                            </div>
                            {can.finding_manage && !isFinal && (
                                <AddAction storeActionUrl={f.store_action_url} users={options.users ?? []} />
                            )}
                        </article>
                    ))}
                </div>

                {can.finding_manage && !isFinal && (
                    <RaiseFinding
                        key={raising ? `${raising.objectiveText ?? ''}::${raising.description}` : '__closed__'}
                        aarKey={aar.id} raiseUrl={urls.raise_finding} context={raising} onClose={() => setRaising(null)}
                        open={raising !== null} onOpen={() => setRaising({ objectiveText: null, description: '' })}
                    />
                )}

                {aiSuggestions.length > 0 && (
                    <div className="mt-4 rounded border border-sky-200 bg-sky-50 p-3 text-xs text-sky-900">
                        <p className="mb-1 font-semibold">AI-suggested findings — nothing here is raised until you do it:</p>
                        <ul className="space-y-1">
                            {aiSuggestions.map((s, i) => (
                                <li key={i} className="flex items-center justify-between gap-2">
                                    <span>{s.description}</span>
                                    {can.finding_manage && (
                                        <button type="button" className="shrink-0 text-blue-700 hover:underline"
                                            aria-label={`Raise this finding: ${s.description}`}
                                            onClick={() => setRaising({ objectiveText: null, description: s.description })}>
                                            Raise this
                                        </button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </SectionCard>

            {/* 9. Carried-forward chain */}
            <SectionCard number={9} title="Carried-forward chain" clause="ISO 22301 10.1">
                {carried.length === 0 ? (
                    <p className="text-sm text-slate-500">Nothing carried forward to this occurrence.</p>
                ) : (
                    <table className="data-table text-xs">
                        <thead>
                            <tr><th>Reference</th><th>Disposition</th><th>Note</th></tr>
                        </thead>
                        <tbody>
                            {carried.map((c) => (
                                <tr key={c.reference} className={!c.disposition ? 'bg-red-50' : ''}>
                                    <td>{c.reference}</td>
                                    <td>
                                        {editable ? (
                                            <select className="form-select text-xs"
                                                aria-label={`Disposition for carried action ${c.reference}`}
                                                value={carriedField(c.reference, 'disposition', c.disposition) ?? ''}
                                                onChange={(e) => setCarriedField(c.reference, 'disposition', e.target.value)}>
                                                <option value="">Choose…</option>
                                                <option value="validated">Validated</option>
                                                <option value="still_open">Still open</option>
                                                <option value="superseded">Superseded</option>
                                            </select>
                                        ) : (c.disposition ?? <span className="text-red-700">Not dispositioned</span>)}
                                    </td>
                                    <td>
                                        {editable ? (
                                            <input className="form-input w-full text-xs"
                                                aria-label={`Note for carried action ${c.reference}`}
                                                value={carriedField(c.reference, 'note', c.note) ?? ''}
                                                onChange={(e) => setCarriedField(c.reference, 'note', e.target.value)} />
                                        ) : (c.note ?? '—')}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </SectionCard>

            {/* Footer action bar */}
            <div className="sticky bottom-0 mt-6 flex flex-wrap gap-2 border-t border-slate-200 bg-white/95 p-3 backdrop-blur">
                {editable && (
                    <button type="button" disabled={saving} onClick={saveDraft}
                        className="rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700 disabled:opacity-60">
                        {saving ? 'Saving…' : 'Save draft'}
                    </button>
                )}
                {editable && ai.available && (
                    <button type="button" disabled={aiDrafting} onClick={requestAiDraft}
                        className="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50 disabled:opacity-60">
                        {aiDrafting ? 'Drafting…' : 'Draft with AI'}
                    </button>
                )}
                {editable && !ai.available && ai.unavailable_reason && (
                    <p className="self-center text-xs text-slate-500">AI drafting is unavailable: {ai.unavailable_reason}</p>
                )}
                {can.approve && !isFinal && (
                    <button type="button" disabled={!allConditionsMet || approval.blocking}
                        title={!allConditionsMet ? conditions.find((c) => !c.met)?.message : approval.blocking ? approval.reason : undefined}
                        onClick={() => setConfirmingFinalise(true)}
                        className="rounded bg-emerald-700 px-3 py-1.5 text-sm text-white hover:bg-emerald-600 disabled:cursor-not-allowed disabled:opacity-50">
                        Finalise
                    </button>
                )}
                {can.approve && isFinal && (
                    <button type="button" onClick={() => setConfirmingDistribute(true)}
                        className="rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">
                        Distribute
                    </button>
                )}
                {can.export && occurrence && urls.export && (
                    <a href={urls.export} className="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">
                        Export
                    </a>
                )}
                <Link href={urls.workspace} className="ml-auto self-center text-sm text-blue-700 hover:underline">
                    Back to workspace
                </Link>
            </div>

            {confirmingFinalise && (
                <Modal title="Finalise this report?" onClose={() => setConfirmingFinalise(false)}>
                    <p className="text-sm text-slate-700">
                        The timeline, scores, injects and attendance for this occurrence become locked.
                        Corrective actions will be raised from the findings above.
                    </p>
                    <ModalActions onCancel={() => setConfirmingFinalise(false)} onConfirm={finalise} confirmLabel="Finalise" />
                </Modal>
            )}

            {confirmingDistribute && (
                <Modal title="Distribute this report?" onClose={() => setConfirmingDistribute(false)}>
                    <p className="text-sm text-slate-700">This sends the finalised report to its distribution list. This cannot be undone.</p>
                    <ModalActions onCancel={() => setConfirmingDistribute(false)} onConfirm={distribute} confirmLabel="Distribute" />
                </Modal>
            )}

            {reopening && (
                <ReopenModal reopenUrl={urls.reopen} onClose={() => setReopening(false)} />
            )}
        </AppLayout>
    );
}

function SectionCard({ number, title, clause, children }) {
    return (
        <section className="mb-6 rounded border border-slate-200 bg-white p-4">
            <div className="mb-3 flex items-baseline justify-between gap-2">
                <h2 className="text-sm font-semibold text-slate-800">{number}. {title}</h2>
                <span className="text-[11px] text-slate-400">{clause}</span>
            </div>
            {children}
        </section>
    );
}

function Field({ label, value }) {
    return (
        <div>
            <dt className="text-xs text-slate-500">{label}</dt>
            <dd className="text-slate-800">{value ?? '—'}</dd>
        </div>
    );
}

function ParticipantFeedback({ feedback }) {
    const invited = feedback?.invited;
    const responded = feedback?.responded;
    const comments = Array.isArray(feedback?.comments) ? feedback.comments : [];

    if (!feedback || (invited == null && comments.length === 0)) {
        return <p className="text-sm text-slate-500">No participant feedback recorded.</p>;
    }

    return (
        <div className="space-y-3 text-sm">
            {invited != null && (
                <p className="text-slate-700">{responded ?? 0} of {invited} responded.</p>
            )}
            {comments.length > 0 && (
                <ul className="space-y-2">
                    {comments.map((c, i) => (
                        <li key={i} className="rounded bg-slate-50 p-2 text-xs">
                            <span className="text-slate-500">{[c.role, c.business_unit].filter(Boolean).join(' · ') || 'Anonymous'}</span>
                            <p className="mt-1 text-slate-800">{c.text}</p>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function RaiseFinding({ aarKey, raiseUrl, context, open, onOpen, onClose }) {
    const form = useForm({
        source: 'aar', classification: 'observation', description: context?.description || '',
        severity: 'medium', iso_clause_ref: '', aar_id: aarKey, objective_text: context?.objectiveText || '',
    });

    if (!open) {
        return (
            <button type="button" className="mt-3 text-xs text-blue-700 hover:underline" onClick={onOpen}>
                Raise a finding
            </button>
        );
    }

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.post(raiseUrl, { preserveScroll: true, onSuccess: () => { form.reset(); onClose(); } });
            }}
            className="mt-3 space-y-2 rounded border border-dashed border-slate-300 p-3"
        >
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
                <select className="form-select text-xs" aria-label="Classification" value={form.data.classification}
                    onChange={(e) => form.setData('classification', e.target.value)}>
                    <option value="observation">Observation</option>
                    <option value="improvement">Improvement</option>
                    <option value="nonconformity">Nonconformity</option>
                </select>
                <select className="form-select text-xs" aria-label="Severity" value={form.data.severity}
                    onChange={(e) => form.setData('severity', e.target.value)}>
                    {['low', 'medium', 'high', 'critical'].map((s) => <option key={s} value={s}>{s}</option>)}
                </select>
                <input className="form-input text-xs" aria-label="ISO clause (optional)" placeholder="ISO clause (optional)"
                    value={form.data.iso_clause_ref} onChange={(e) => form.setData('iso_clause_ref', e.target.value)} />
            </div>
            <textarea rows={2} className="form-textarea w-full text-xs" aria-label="What was found" placeholder="What was found"
                value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
            {form.data.objective_text && (
                <p className="text-[11px] text-slate-500">Against objective: {form.data.objective_text}</p>
            )}
            <div className="flex gap-2">
                <button type="submit" disabled={form.processing} className="rounded bg-slate-800 px-3 py-1 text-xs text-white hover:bg-slate-700">
                    Raise
                </button>
                <button type="button" className="text-xs text-slate-500 hover:underline" onClick={onClose}>Cancel</button>
            </div>
        </form>
    );
}

function AddAction({ storeActionUrl, users }) {
    const form = useForm({ title: '', owner_id: '', due_date: '', priority: 'medium' });

    return (
        <form
            onSubmit={(e) => { e.preventDefault(); form.post(storeActionUrl, { preserveScroll: true, onSuccess: () => form.reset() }); }}
            className="mt-2 flex flex-wrap items-end gap-2 rounded border border-dashed border-slate-300 p-2"
        >
            <input className="form-input min-w-48 flex-1 text-xs" placeholder="Corrective action" aria-label="Corrective action"
                value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} />
            <select className="form-select text-xs" aria-label="Owner" value={form.data.owner_id}
                onChange={(e) => form.setData('owner_id', e.target.value)}>
                <option value="">Owner</option>
                {users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
            </select>
            <input type="date" className="form-input text-xs" aria-label="Due date" value={form.data.due_date}
                onChange={(e) => form.setData('due_date', e.target.value)} />
            <button type="submit" className="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-50" disabled={form.processing}>Add</button>
        </form>
    );
}

function ReopenModal({ reopenUrl, onClose }) {
    const form = useForm({ reason: '' });

    return (
        <Modal title="Reopen this report" onClose={onClose}>
            <form onSubmit={(e) => { e.preventDefault(); form.post(reopenUrl, { preserveScroll: true, onSuccess: onClose }); }} className="space-y-3">
                <p className="text-sm text-slate-700">
                    Reopening requires re-approval and re-distribution before this can be final again.
                </p>
                <FormField label="Why is this being reopened?" htmlFor="aar-reopen-reason" required error={form.errors.reason}>
                    <textarea id="aar-reopen-reason" rows={3} required minLength={10} className="form-textarea w-full"
                        value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                </FormField>
                <div className="flex justify-end gap-2">
                    <button type="button" className="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50" onClick={onClose}>Cancel</button>
                    <button type="submit" disabled={form.processing} className="rounded bg-amber-700 px-3 py-1.5 text-sm text-white hover:bg-amber-600">Reopen</button>
                </div>
            </form>
        </Modal>
    );
}

function Modal({ title, onClose, children }) {
    const ref = useRef(null);
    return (
        <div role="dialog" aria-modal="true" aria-label={title} ref={ref}
            className="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
            <div className="w-full max-w-md space-y-4 rounded-lg bg-white p-6 shadow-xl">
                <h3 className="text-base font-semibold text-slate-900">{title}</h3>
                {children}
            </div>
        </div>
    );
}

function ModalActions({ onCancel, onConfirm, confirmLabel }) {
    return (
        <div className="mt-4 flex justify-end gap-2">
            <button type="button" className="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50" onClick={onCancel}>Cancel</button>
            <button type="button" className="rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700" onClick={onConfirm}>{confirmLabel}</button>
        </div>
    );
}

function formatLocal(iso) {
    if (!iso) return '—';
    return iso.slice(0, 16).replace('T', ' ');
}
