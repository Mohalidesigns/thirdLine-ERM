import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import Modal from '@thirdline/ui/Components/Modal';
import { formatIncidentDateTime } from '@/Components/Bcms/dateDisplay';

/**
 * `docs/bcms/screens/pir-post-incident-review.md` — the post-incident review,
 * a delta on `Exercises/Aar.jsx` (aar-builder spec) built as its own page.
 *
 * WHY THIS IS A SEPARATE FILE, NOT `bcms.aars.show` (the spec's own stated
 * preference, §1): `Exercises/Aar.jsx` and `AarController` are Phase 9 files
 * outside this session's edit boundary. `AarController::show()` gates on
 * `bcms.exercise.view` only — wrong for a PIR, which the spec's own
 * permission note (§1) requires to *also* check `bcms.incident.view` because
 * a real incident's review can carry personal data an exercise AAR cannot —
 * and `Aar.jsx` renders every field against an `occurrence` that is null for
 * a PIR. This page is gated correctly from its own route
 * (`bcms.incidents.review.show`) and reads the same `Aar` row through
 * `IncidentPresenter::review()`. Writes reuse the exercise screen's own
 * routes unmodified (`bcms.aars.update`/`.reopen`/`.distribute`), because
 * `AarService::update()` is already subject-agnostic — only the screen
 * differs, not the write path. Flagged in this phase's handoff as a
 * deliberate deviation from the spec's "same URL" instruction.
 *
 * THE EIGHT-CONDITION GATE IS LIVE, NEVER RECOMPUTED HERE (mirrors
 * `Exercises/Aar.jsx`'s own rule) — `conditions[]` comes from
 * `PirService::conditions()`, the same method `finalise()` itself calls.
 *
 * THE TENTH SECTION IS AI-DRAFT-SHAPED BUT NOT YET WIRED — `PirAiDrafter`
 * does not exist this phase (`phase-10-notes.md`, "explicitly deferred"), so
 * `ai.available` is always false and this section states that rather than
 * offering a button that would call nothing. THIS SCREEN NEVER POSTS TO AN
 * AI-DRAFT ROUTE — the server has stopped shipping `urls.ai_draft`
 * (code-reviewer defect 6); criterion 10 stays "unavailable" until
 * `PirAiDrafter` exists, and this screen does not call a route that is not there.
 *
 * REALISED FINANCIAL LOSS IS STATED AT FINALISATION, NOT PREFILLED FROM THE
 * DECLARATION-TIME ESTIMATE (code-reviewer defect 7) — `finalise()` posts
 * `realised_loss_minor` (top-level), whole naira in the field and minor units on the
 * wire (the `Strategy/Index.jsx` convention), with an explicit "No realised
 * loss" option that submits `0` rather than leaving the figure unstated.
 *
 * SEPARATION OF DUTIES (advisory A4) — `can.approve` already reflects
 * `PirService::pirApproverAllowed()` (the declarer, and anyone who logged a
 * decision entry, cannot sign their own PIR); this screen never re-derives
 * that rule. `approver_barred_reason` is set only for someone who holds the
 * underlying permission but is barred this time, and the Finalise button
 * stays visible-but-disabled with that reason attached (`aria-describedby`)
 * rather than a silent gap where a control used to be — a genuine
 * permission-denied case (no reason) hides the control entirely, per the
 * module's usual convention.
 *
 * `flash.success`/`flash.warning`/`flash.error` ALL RENDER — the finalise
 * write can succeed while its ERM loss-register mirror fails silently
 * (`ErmBridge` is tolerant by design); `flash.warning` is what tells the
 * officer the mirror needs a manual follow-up rather than leaving them to
 * assume it reached the register.
 */
export default function Review({
    incident = {}, aar = {}, plan_sections: planSections = [], conditions = [], all_conditions_met: allConditionsMet = false,
    timeline = { entries: [], total: 0 }, findings = [], ai = {}, options = {}, can = {}, urls = {},
    approver_barred_reason: approverBarredReason = null,
}) {
    const { flash } = usePage().props;
    const final = aar.status === 'final';
    const draftForm = useForm({
        summary: aar.summary ?? '', what_worked: aar.what_worked ?? '', what_failed: aar.what_failed ?? '',
        quantitative_results: aar.quantitative_results ?? {},
    });
    const [sections, setSections] = useState(planSections);
    const [confirmingFinalise, setConfirmingFinalise] = useState(false);
    const [confirmingDistribute, setConfirmingDistribute] = useState(false);
    const [reopening, setReopening] = useState(false);

    const canEdit = can.manage && !final;

    // The confirmed REALISED loss, whole naira in the field, minor units on
    // the wire (the `Strategy/Index.jsx` convention) — `null` until the
    // officer states a figure or explicitly says there was none; the
    // declaration-time `incident.estimated_impact_minor` is shown as a hint
    // only, never prefilled, so finalising restates the actual rather than
    // rubber-stamping the guess (pir-post-incident-review.md §7).
    const finaliseForm = useForm({ realised_loss_minor: null });
    const realisedLossConfirmed = finaliseForm.data.realised_loss_minor !== null;
    const finalisedRealisedLossMinor = aar.realised_loss_minor ?? null;

    const saveNarrative = (e) => {
        e.preventDefault();
        draftForm.transform((data) => ({ ...data, quantitative_results: { ...data.quantitative_results, plan_sections: sections } }));
        draftForm.patch(urls.update, { preserveScroll: true });
    };

    const updateSection = (sectionId, patch) => {
        setSections((prev) => prev.map((s) => (s.section_id === sectionId ? { ...s, ...patch } : s)));
    };

    const savePlanSections = () => {
        router.patch(urls.update, {
            summary: draftForm.data.summary, what_worked: draftForm.data.what_worked, what_failed: draftForm.data.what_failed,
            quantitative_results: { ...draftForm.data.quantitative_results, plan_sections: sections },
        }, { preserveScroll: true });
    };

    const finalise = () => {
        finaliseForm.transform((data) => ({ realised_loss_minor: data.realised_loss_minor }));
        finaliseForm.post(urls.finalise, { preserveScroll: true, onSuccess: () => setConfirmingFinalise(false) });
    };

    const distribute = () => {
        router.post(urls.distribute, {}, { preserveScroll: true, onSuccess: () => setConfirmingDistribute(false) });
    };

    return (
        <AppLayout title={`Post-incident review — ${incident.reference}`}>
            <Head title={`Post-incident review — ${incident.reference}`} />

            <PageHeader
                title={`Post-incident review — ${incident.reference} · ${incident.title} · ${statusLabel(aar.status)}`}
                subtitle="A post-incident review, not an exercise report — stamped iso22320.incident_response, never iso22301.8.5.report."
                actions={(
                    <div className="flex gap-2">
                        <Link href={urls.crisis_room} className="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">
                            Crisis room
                        </Link>
                        {can.export && (
                            <a href={urls.export} className="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">Export</a>
                        )}
                    </div>
                )}
            />

            {flash?.success && (
                <div role="status" className="mb-4 rounded border border-emerald-300 bg-emerald-50 p-3 text-sm text-emerald-900">
                    {flash.success}
                </div>
            )}
            {/*
                The finalise write never fails because the ERM side is
                unreachable (`ErmBridge` is tolerant by design) — but a
                silent gap there would leave an officer believing a
                confirmed loss reached the loss register when it did not.
                `flash.warning` surfaces exactly that: finalised, but the
                mirror failed.
            */}
            {flash?.warning && (
                <div role="alert" className="mb-4 rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                    {flash.warning}
                </div>
            )}
            {flash?.error && (
                <div role="alert" className="mb-4 rounded border border-rose-300 bg-rose-50 p-3 text-sm text-rose-900">
                    {flash.error}
                </div>
            )}

            {aar.ai_generated && (
                <div className="mb-4 rounded border border-sky-300 bg-sky-50 p-3 text-sm text-sky-900">
                    Parts of this review were drafted by a model{aar.ai_draft_generated_at ? ` on ${formatLocal(aar.ai_draft_generated_at)}` : ''}.
                    Nothing here is approved by drafting it — review and edit before finalising.
                </div>
            )}

            {final && (
                <div className="mb-4 rounded border border-emerald-300 bg-emerald-50 p-3 text-sm text-emerald-900">
                    Finalised by {aar.approved_by ?? 'unknown'} on {formatLocal(aar.approved_at)}.
                    {aar.distributed_at ? ` Distributed ${formatLocal(aar.distributed_at)}.` : ' Not yet distributed.'}
                </div>
            )}

            {/* Section 1 — identity */}
            <SectionCard number={1} title="Incident identity" source="bcms_incidents">
                <dl className="grid gap-2 text-sm sm:grid-cols-3">
                    <Field label="Type" value={incident.incident_type ?? '—'} />
                    <Field label="Severity" value={incident.severity_label ?? '—'} />
                    <Field label="Activation level" value={incident.activation_level_label ?? '—'} />
                    <Field label="Business unit / site" value={`${incident.business_unit ?? '—'} / ${incident.site ?? '—'}`} />
                    <Field label="Detected" value={formatLocal(incident.detected_at)} />
                    <Field label="Declared" value={formatLocal(incident.declared_at)} />
                    <Field label="Closed" value={formatLocal(incident.closed_at)} />
                    <Field label="Declaring officer" value={incident.declared_by ?? '—'} />
                </dl>
                {/* Same convention `Findings/Index.jsx` uses for `erm_issue_id`
                    — a plain note, never a link, so a reader without ERM
                    loss-register access sees the fact without a broken
                    cross-module URL (clause map §4.3). */}
                {incident.erm_loss_event_id && (
                    <p className="mt-2 text-xs text-slate-500">Mirrored to the ERM loss register.</p>
                )}
            </SectionCard>

            {/* Section 2 — plan versus actual */}
            <SectionCard number={2} title="Plan versus actual" source="bcms_plan_sections, scoped to the plan(s) this incident activated">
                {sections.length === 0 ? (
                    <p className="text-sm text-slate-500">No plan section is linked to this incident's activation(s).</p>
                ) : (
                    <ul className="space-y-3">
                        {sections.map((s) => (
                            <li key={s.section_id} className="rounded border border-slate-200 p-3">
                                <p className="mb-2 text-sm font-medium text-slate-800">{s.title}</p>
                                <div className="flex flex-wrap items-center gap-2">
                                    <label htmlFor={`verdict-${s.section_id}`} className="form-label mb-0">Verdict</label>
                                    <select id={`verdict-${s.section_id}`} className="form-select text-sm" disabled={!canEdit}
                                        value={s.verdict ?? ''} onChange={(e) => updateSection(s.section_id, { verdict: e.target.value || null })}>
                                        <option value="">Not yet recorded</option>
                                        <option value="held">Held</option>
                                        <option value="did_not_hold">Did not hold</option>
                                    </select>
                                </div>
                                <textarea rows={2} placeholder="Note" aria-label={`Note for ${s.title}`} disabled={!canEdit}
                                    className="form-textarea mt-2 w-full text-sm" value={s.note ?? ''}
                                    onChange={(e) => updateSection(s.section_id, { note: e.target.value })} />
                                {s.verdict === 'did_not_hold' && (
                                    <div className="mt-2 grid gap-2 sm:grid-cols-2">
                                        <input type="text" placeholder="Linked finding reference" aria-label={`Linked finding for ${s.title}`}
                                            disabled={!canEdit} className="form-input text-sm" value={s.finding_reference ?? ''}
                                            onChange={(e) => updateSection(s.section_id, { finding_reference: e.target.value })} />
                                        <input type="text" placeholder="Or a disposition reason" aria-label={`Disposition reason for ${s.title}`}
                                            disabled={!canEdit} className="form-input text-sm" value={s.disposition_note ?? ''}
                                            onChange={(e) => updateSection(s.section_id, { disposition_note: e.target.value })} />
                                    </div>
                                )}
                            </li>
                        ))}
                        {canEdit && (
                            <button type="button" onClick={savePlanSections} className="rounded bg-slate-800 px-3 py-1.5 text-xs text-white">
                                Save plan-section verdicts
                            </button>
                        )}
                    </ul>
                )}
            </SectionCard>

            {/* Section 3 — timeline */}
            <SectionCard number={3} title="Timeline" source="bcms_incident_log — decision and escalation entries only">
                {timeline.entries.length === 0 ? (
                    <p className="text-sm text-slate-500">No decision or escalation entry recorded yet.</p>
                ) : (
                    <ul className="divide-y divide-slate-100 text-sm">
                        {timeline.entries.map((e) => (
                            <li key={e.id} className="flex gap-3 py-2">
                                <span className="w-32 shrink-0 font-mono text-xs text-slate-500">{formatLocal(e.logged_at)}</span>
                                <span className="w-20 shrink-0 rounded bg-slate-100 px-1.5 py-0.5 text-center text-[11px]">{e.entry_type}</span>
                                <span className="flex-1">{e.content}</span>
                                <span className="shrink-0 text-xs text-slate-400">{e.logged_by ?? '—'}</span>
                            </li>
                        ))}
                    </ul>
                )}
                {timeline.total > timeline.entries.length && (
                    <p className="mt-2 text-xs text-slate-500">Showing the last {timeline.entries.length} of {timeline.total}.</p>
                )}
            </SectionCard>

            {/* Sections 4-6 — narrative and quantitative */}
            <form onSubmit={saveNarrative}>
                <SectionCard number={4} title="Quantitative results" source="Incident metrics; regulator-notification timing compared against each open obligation separately">
                    <pre className="max-h-64 overflow-auto rounded bg-slate-50 p-2 text-xs">{JSON.stringify(draftForm.data.quantitative_results?.metrics ?? {}, null, 2)}</pre>
                </SectionCard>

                <SectionCard number={5} title="What worked / what did not">
                    <div className="grid gap-3 sm:grid-cols-3">
                        <Textarea id="pir-summary" label="Summary" disabled={!canEdit} value={draftForm.data.summary}
                            onChange={(v) => draftForm.setData('summary', v)} />
                        <Textarea id="pir-worked" label="What worked" disabled={!canEdit} value={draftForm.data.what_worked}
                            onChange={(v) => draftForm.setData('what_worked', v)} />
                        <Textarea id="pir-failed" label="What did not work" disabled={!canEdit} value={draftForm.data.what_failed}
                            onChange={(v) => draftForm.setData('what_failed', v)} />
                    </div>
                </SectionCard>

                <SectionCard number={6} title="Responder debrief" source="Role and unit only, never a name">
                    <pre className="max-h-48 overflow-auto rounded bg-slate-50 p-2 text-xs">{JSON.stringify(aar.participant_feedback ?? {}, null, 2)}</pre>
                </SectionCard>

                {canEdit && (
                    <button type="submit" disabled={draftForm.processing} className="mb-6 rounded bg-slate-800 px-3 py-1.5 text-sm text-white">
                        Save narrative
                    </button>
                )}
            </form>

            {/* Section 7 — findings */}
            <SectionCard number={7} title="Findings" source="bcms_findings where aar_id matches this review">
                {findings.length === 0 ? (
                    <p className="text-sm text-slate-500">No finding raised from this review yet.</p>
                ) : (
                    <ul className="space-y-3">
                        {findings.map((f) => (
                            <li key={f.uuid} className="rounded border border-slate-200 p-3 text-sm">
                                <p className="font-medium">{f.reference} — {f.description}</p>
                                <p className="text-xs text-slate-500">{f.classification} · {f.severity} · {f.status}</p>
                                {f.actions.length > 0 && (
                                    <ul className="mt-1 text-xs text-slate-600">
                                        {f.actions.map((a) => <li key={a.uuid}>{a.reference} — {a.title} ({a.owner ?? 'unassigned'})</li>)}
                                    </ul>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </SectionCard>

            {/* Section 9 — carried-forward (informational only) */}
            <SectionCard number={9} title="Carried forward">
                <p className="text-sm text-slate-600">
                    An action from this review may be validated at the next exercise that covers this scenario, once
                    that occurrence is scheduled — carried automatically by the exercise programme when it opens, not
                    by this review.
                </p>
            </SectionCard>

            {/* Section 10 — what the exercises predicted */}
            <SectionCard number={10} title="What the exercises predicted, and what reality exposed">
                {ai.available ? (
                    <p className="text-sm text-slate-600">Draft available.</p>
                ) : (
                    <p className="text-sm text-slate-500">{ai.unavailable_reason ?? 'Not available in this deployment.'}</p>
                )}
            </SectionCard>

            {/* Finalisation gate */}
            <section className="mt-6 rounded border border-slate-200 bg-white p-4">
                <h2 className="mb-2 text-sm font-semibold text-slate-700">
                    Ready to finalise — post-incident reviews are not scored against pre-set objectives, so the scoring and inject conditions do not apply here
                </h2>
                <ul className="mb-3 space-y-1 text-sm">
                    {conditions.map((c) => (
                        <li key={c.key} className={c.met ? 'text-emerald-700' : 'text-rose-700'}>
                            {c.met ? '✓' : '✗'} {c.label}{!c.met && c.message ? ` — ${c.message}` : ''}
                        </li>
                    ))}
                </ul>

                {/*
                    Realised financial loss — required by `finalise()`
                    (`realised_loss_minor`, top-level), separate from the eight-
                    condition gate above because it is this screen's own
                    completeness check, not a server-computed condition.
                    NOT prefilled from `incident.estimated_impact_minor`: the
                    officer states the actual figure, the declaration-time
                    guess is shown only as a hint alongside it (spec §7).
                */}
                {!final && can.approve && (
                    <div className="mb-3 rounded border border-slate-200 p-3 text-sm">
                        <label htmlFor="pir-realised-loss" className="block text-xs font-medium text-slate-600">
                            Realised financial loss (whole naira)
                        </label>
                        <p className="mb-1 text-xs text-slate-500">
                            Estimated at declaration: {money(incident.estimated_impact_minor) ?? 'not estimated'}. State the actual figure below.
                        </p>
                        <div className="flex flex-wrap items-center gap-2">
                            <input
                                id="pir-realised-loss" type="number" min="0" step="1"
                                className="form-input w-40"
                                disabled={finaliseForm.data.realised_loss_minor === 0}
                                value={finaliseForm.data.realised_loss_minor == null || finaliseForm.data.realised_loss_minor === 0
                                    ? '' : finaliseForm.data.realised_loss_minor / 100}
                                onChange={(e) => finaliseForm.setData('realised_loss_minor',
                                    e.target.value === '' ? null : Math.round(Number(e.target.value) * 100))}
                            />
                            <label className="flex items-center gap-1 text-xs text-slate-600">
                                <input
                                    type="checkbox" className="form-checkbox"
                                    checked={finaliseForm.data.realised_loss_minor === 0}
                                    onChange={(e) => finaliseForm.setData('realised_loss_minor', e.target.checked ? 0 : null)}
                                />
                                No realised loss
                            </label>
                        </div>
                        {finaliseForm.errors.realised_loss_minor && (
                            <p role="alert" className="mt-1 text-xs text-rose-700">{finaliseForm.errors.realised_loss_minor}</p>
                        )}
                    </div>
                )}

                {final && finalisedRealisedLossMinor != null && (
                    <p className="mb-3 text-sm text-slate-700">
                        Realised financial loss: <span className="font-medium">{money(finalisedRealisedLossMinor) ?? '₦0'}</span>
                    </p>
                )}

                {/*
                    Separation of duties (A4) — the incident's declarer and
                    anyone who logged a decision entry cannot sign this PIR
                    alone. `approver_barred_reason` is null both when the
                    officer CAN approve and when they simply lack the
                    underlying permission (`can.approve` false, no reason —
                    hidden entirely below, per "no apparent means to do the
                    thing you cannot do"); it is set only for someone who
                    holds the permission but is barred THIS TIME, so the
                    Finalise control stays visible-but-disabled with the
                    reason attached, rather than a silent gap where a button
                    used to be.
                */}
                {!final && !can.approve && approverBarredReason && (
                    <p id="pir-approver-barred-reason" role="alert" className="mb-3 text-sm text-amber-800">
                        {approverBarredReason}
                    </p>
                )}

                <div className="flex flex-wrap gap-2">
                    {!final && can.approve && (
                        <button type="button" disabled={!allConditionsMet || !realisedLossConfirmed} onClick={() => setConfirmingFinalise(true)}
                            title={!allConditionsMet
                                ? conditions.find((c) => !c.met)?.message
                                : (!realisedLossConfirmed ? 'State the realised financial loss, or confirm there was none.' : undefined)}
                            className="rounded bg-emerald-700 px-3 py-1.5 text-sm text-white disabled:cursor-not-allowed disabled:opacity-50">
                            Finalise
                        </button>
                    )}
                    {!final && !can.approve && approverBarredReason && (
                        <button type="button" disabled aria-describedby="pir-approver-barred-reason"
                            className="rounded bg-emerald-700 px-3 py-1.5 text-sm text-white opacity-50 cursor-not-allowed">
                            Finalise
                        </button>
                    )}
                    {final && can.approve && !aar.distributed_at && (
                        <button type="button" onClick={() => setConfirmingDistribute(true)} className="rounded bg-slate-800 px-3 py-1.5 text-sm text-white">
                            Distribute
                        </button>
                    )}
                    {final && can.manage && (
                        <button type="button" onClick={() => setReopening(true)} className="rounded border border-slate-300 px-3 py-1.5 text-sm">
                            Reopen
                        </button>
                    )}
                </div>
            </section>

            <Modal show={confirmingFinalise} onClose={() => setConfirmingFinalise(false)}>
                <div className="p-6">
                    <h3 className="mb-2 text-base font-semibold">Finalise this review?</h3>
                    <p className="mb-4 text-sm text-slate-700">The timeline, plan-section verdicts and findings are locked once finalised.</p>
                    <div className="flex justify-end gap-2">
                        <button type="button" className="rounded border border-slate-300 px-3 py-1.5 text-sm" onClick={() => setConfirmingFinalise(false)}>Cancel</button>
                        <button type="button" className="rounded bg-emerald-700 px-3 py-1.5 text-sm text-white" onClick={finalise}>Finalise</button>
                    </div>
                </div>
            </Modal>

            <Modal show={confirmingDistribute} onClose={() => setConfirmingDistribute(false)}>
                <div className="p-6">
                    <h3 className="mb-2 text-base font-semibold">Distribute this review?</h3>
                    <p className="mb-4 text-sm text-slate-700">This sends the finalised report to its distribution list. This cannot be undone.</p>
                    <div className="flex justify-end gap-2">
                        <button type="button" className="rounded border border-slate-300 px-3 py-1.5 text-sm" onClick={() => setConfirmingDistribute(false)}>Cancel</button>
                        <button type="button" className="rounded bg-slate-800 px-3 py-1.5 text-sm text-white" onClick={distribute}>Distribute</button>
                    </div>
                </div>
            </Modal>

            <Modal show={reopening} onClose={() => setReopening(false)}>
                <ReopenForm reopenUrl={urls.reopen} onDone={() => setReopening(false)} />
            </Modal>
        </AppLayout>
    );
}

function SectionCard({ number, title, source, children }) {
    return (
        <section className="mb-6 rounded border border-slate-200 bg-white p-4">
            <h2 className="text-sm font-semibold text-slate-800">{number}. {title}</h2>
            {source && <p className="mb-3 text-xs text-slate-400">Source: {source}</p>}
            <div className={source ? '' : 'mt-3'}>{children}</div>
        </section>
    );
}

function Field({ label, value }) {
    return (
        <div>
            <dt className="text-xs text-slate-500">{label}</dt>
            <dd className="text-slate-800">{value}</dd>
        </div>
    );
}

function Textarea({ id, label, value, onChange, disabled }) {
    return (
        <div>
            <label htmlFor={id} className="block text-xs font-medium text-slate-600">{label}</label>
            <textarea id={id} rows={4} disabled={disabled} className="form-textarea mt-1 w-full text-sm"
                value={value} onChange={(e) => onChange(e.target.value)} />
        </div>
    );
}

function ReopenForm({ reopenUrl, onDone }) {
    const form = useForm({ reason: '' });

    const submit = (e) => {
        e.preventDefault();
        form.post(reopenUrl, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className="space-y-3 p-6">
            <h3 className="text-base font-semibold">Reopen this review</h3>
            <label htmlFor="pir-reopen-reason" className="block text-sm font-medium text-slate-700">Why is this being reopened?</label>
            <textarea id="pir-reopen-reason" required minLength={10} rows={3} className="form-textarea w-full text-sm"
                value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
            {form.errors.reason && <p role="alert" className="text-xs text-rose-700">{form.errors.reason}</p>}
            <div className="flex justify-end gap-2">
                <button type="button" className="rounded border border-slate-300 px-3 py-1.5 text-sm" onClick={onDone}>Cancel</button>
                <button type="submit" disabled={form.processing} className="rounded bg-slate-800 px-3 py-1.5 text-sm text-white">Reopen</button>
            </div>
        </form>
    );
}

function statusLabel(status) {
    if (status === 'final') return 'Final';
    if (status === 'draft') return 'Draft';
    return status ?? '—';
}

// Whole naira, minor units on the wire — the `Strategy/Index.jsx` /
// `Strategy/Compare.jsx` convention, copied rather than reinvented. `null`
// stays `null` (nothing recorded), never `₦0` — that is a real figure a
// caller must state explicitly (the "No realised loss" checkbox above).
function money(minor) {
    if (minor == null) return null;
    return `₦${(minor / 100).toLocaleString(undefined, { maximumFractionDigits: 0 })}`;
}

function formatLocal(iso) {
    return formatIncidentDateTime(iso) ?? 'not yet';
}
