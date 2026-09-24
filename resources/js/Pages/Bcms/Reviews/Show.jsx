import { useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import FormField from '@thirdline/ui/Components/FormField';
import ConfirmDialog from '@thirdline/ui/Components/ConfirmDialog';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * The management-review record and its extended clause 9.3.2 inputs snapshot
 * — `docs/bcms/screens/management-review-inputs.md`.
 *
 * A SNAPSHOT, NOT A LIVE QUERY. Every figure below `inputs_captured_at`
 * renders from the stored `inputs` JSON, never recomputed on read — a
 * re-visit in December must show what October's meeting actually saw.
 *
 * SECTION 4 (INTERNAL AUDIT) IS THE ONE FORM ON THE PAGE, because no
 * audit-programme table exists to compute it from (ADR 0021 §1). Leaving it
 * blank and approving is a valid path — the compliance matrix then renders
 * 9.2 red, honestly, not blocked here.
 */
export default function Show({ review, can = {} }) {
    const notCaptured = review.inputs_captured_at === null;
    const approved = review.status === 'approved';

    const capture = useForm({
        internal_audit: {
            report_reference: review.inputs?.internal_audit?.report_reference ?? '',
            date: review.inputs?.internal_audit?.date ?? '',
            auditor: review.inputs?.internal_audit?.auditor ?? '',
            independence_statement: review.inputs?.internal_audit?.independence_statement ?? '',
            conclusion: review.inputs?.internal_audit?.conclusion ?? '',
        },
        interested_party_feedback: review.inputs?.interested_party_feedback ?? '',
        context_changes: review.inputs?.context_changes?.note ?? '',
    });

    const [confirmingApprove, setConfirmingApprove] = useState(false);
    const [approving, setApproving] = useState(false);

    const recapture = (e) => {
        e.preventDefault();
        capture.post(tryRoute('bcms.reviews.capture', review.uuid), { preserveScroll: true });
    };

    const approve = () => {
        setApproving(true);
        router.post(tryRoute('bcms.reviews.approve', review.uuid), {}, {
            preserveScroll: true,
            onFinish: () => { setApproving(false); setConfirmingApprove(false); },
        });
    };

    const inputs = review.inputs ?? {};

    return (
        <AppLayout title={`Management review — ${review.title}`}>
            <Head title={`Management review — ${review.title}`} />

            <PageHeader title={`Management review — ${review.title}`} subtitle={`${review.held_on} · ${review.status}`} />

            {approved ? (
                <div className="mb-6 rounded-lg border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-900">
                    Approved by {review.approved_by} on {review.approved_at}. This review's inputs and decisions are frozen.
                </div>
            ) : notCaptured ? (
                <div className="mb-6 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                    Inputs have not been captured for this review yet.
                </div>
            ) : (
                <div className="mb-6 rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm text-gray-900">
                    Inputs captured {review.inputs_captured_at}. Awaiting approval.
                </div>
            )}

            <div className="space-y-6">
                <AgendaSection n={1} title="Status of actions from previous reviews" clauseRef="iso22301.9.3.inputs">
                    {notCaptured ? <Placeholder /> : (
                        inputs.previous_review_actions?.previous_review === null ? (
                            <p className="text-sm text-gray-500">No prior approved review exists.</p>
                        ) : (
                            <p className="text-sm text-gray-700">
                                {inputs.previous_review_actions?.closed ?? 0} of {inputs.previous_review_actions?.total ?? 0} actions from the previous review are closed.
                            </p>
                        )
                    )}
                </AgendaSection>

                <AgendaSection n={2} title="Changes in external/internal issues relevant to the BCMS" clauseRef="iso22301.9.3.inputs">
                    {approved ? (
                        <p className="text-sm text-gray-700">{inputs.context_changes?.note || 'None recorded.'}</p>
                    ) : (
                        <FormField label="Context changes">
                            <textarea rows={3} className="form-textarea" value={capture.data.context_changes}
                                onChange={(e) => capture.setData('context_changes', e.target.value)} />
                        </FormField>
                    )}
                </AgendaSection>

                <AgendaSection n={3} title="Performance information: maturity, exercises, plan currency" clauseRef="iso22301.9.3.inputs">
                    {notCaptured ? <Placeholder /> : (
                        <ul className="space-y-1 text-sm text-gray-700">
                            <li>Maturity: {inputs.maturity ? `${inputs.maturity.overall_score ?? '—'}/5, assessed ${inputs.maturity.assessed_at}` : 'No maturity assessment on file.'}</li>
                            <li>Exercises this year: {inputs.exercises?.completed ?? 0} completed of {inputs.exercises?.planned ?? 0} planned, {inputs.exercises?.missed ?? 0} missed.</li>
                            <li>Plans: {inputs.plans?.approved ?? 0} approved, {inputs.plans?.review_overdue ?? 0} overdue for review.</li>
                            <li>Open findings: {inputs.findings?.open ?? 0} ({inputs.findings?.nonconformities_open ?? 0} nonconformities).</li>
                            <li>Corrective actions: {inputs.corrective_actions?.open ?? 0} open, {inputs.corrective_actions?.overdue ?? 0} overdue, {inputs.corrective_actions?.verified ?? 0} verified.</li>
                        </ul>
                    )}
                </AgendaSection>

                <AgendaSection n={4} title="Internal audit results" clauseRef="iso22301.9.2.results / .programme, iso22301.9.3.inputs">
                    {approved ? (
                        inputs.internal_audit?.report_reference || inputs.internal_audit?.conclusion ? (
                            <dl className="grid grid-cols-1 gap-2 text-sm text-gray-700 sm:grid-cols-2">
                                <div><dt className="text-xs text-gray-500">Report reference</dt><dd>{inputs.internal_audit.report_reference}</dd></div>
                                <div><dt className="text-xs text-gray-500">Date</dt><dd>{inputs.internal_audit.date}</dd></div>
                                <div><dt className="text-xs text-gray-500">Auditor</dt><dd>{inputs.internal_audit.auditor}</dd></div>
                                <div><dt className="text-xs text-gray-500">Independence statement</dt><dd>{inputs.internal_audit.independence_statement}</dd></div>
                                <div className="sm:col-span-2"><dt className="text-xs text-gray-500">Conclusion</dt><dd>{inputs.internal_audit.conclusion}</dd></div>
                            </dl>
                        ) : (
                            <p className="text-sm text-gray-500">No internal audit was on file when this review was approved.</p>
                        )
                    ) : (
                        <>
                            <p className="mb-3 text-xs text-gray-500">
                                If an internal audit covered any part of the BCMS since the last review, record it here
                                — report reference, date, auditor and their independence statement, and the
                                conclusion. This is the only place that fact is captured; there is no internal-audit
                                register in this system (ADR 0021 §1).
                            </p>
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <FormField label="Report reference">
                                    <input className="form-input" value={capture.data.internal_audit.report_reference}
                                        onChange={(e) => capture.setData('internal_audit', { ...capture.data.internal_audit, report_reference: e.target.value })} />
                                </FormField>
                                <FormField label="Date">
                                    <input type="date" className="form-input" value={capture.data.internal_audit.date}
                                        onChange={(e) => capture.setData('internal_audit', { ...capture.data.internal_audit, date: e.target.value })} />
                                </FormField>
                                <FormField label="Auditor">
                                    <input className="form-input" value={capture.data.internal_audit.auditor}
                                        onChange={(e) => capture.setData('internal_audit', { ...capture.data.internal_audit, auditor: e.target.value })} />
                                </FormField>
                                <FormField label="Independence statement">
                                    <input className="form-input" value={capture.data.internal_audit.independence_statement}
                                        onChange={(e) => capture.setData('internal_audit', { ...capture.data.internal_audit, independence_statement: e.target.value })} />
                                </FormField>
                                <FormField label="Conclusion" >
                                    <textarea rows={2} className="form-textarea sm:col-span-2" value={capture.data.internal_audit.conclusion}
                                        onChange={(e) => capture.setData('internal_audit', { ...capture.data.internal_audit, conclusion: e.target.value })} />
                                </FormField>
                            </div>
                        </>
                    )}
                </AgendaSection>

                <AgendaSection n={5} title="Incidents" clauseRef="iso22301.9.3.inputs">
                    {notCaptured ? <Placeholder /> : (
                        <p className="text-sm text-gray-700">
                            {inputs.incidents?.count ?? 0} incident(s), {inputs.incidents?.notified_within_due ?? 0} notified within the due window,
                            {' '}{inputs.incidents?.notified_late_or_missing ?? 0} late or missing.
                        </p>
                    )}
                </AgendaSection>

                <AgendaSection n={6} title="Exercise evaluation outputs" clauseRef="iso22301.9.3.inputs">
                    {notCaptured ? <Placeholder /> : (
                        <p className="text-sm text-gray-700">
                            {inputs.exercise_evaluation_outputs?.aars_final ?? 0} finalised AAR(s) this year,
                            {' '}{inputs.exercise_evaluation_outputs?.quantitative_misses ?? 0} quantitative measure(s) scored below target.
                        </p>
                    )}
                </AgendaSection>

                <AgendaSection n={7} title="Call tree and EMNS performance" clauseRef="iso22301.9.3.inputs">
                    {notCaptured ? <Placeholder /> : (
                        <pre className="whitespace-pre-wrap text-xs text-gray-700">{JSON.stringify(inputs.call_tree_and_emns_performance ?? {}, null, 2)}</pre>
                    )}
                </AgendaSection>

                <AgendaSection n={8} title="DR achievement" clauseRef="iso22301.9.3.inputs">
                    {notCaptured ? <Placeholder /> : (
                        <p className="text-sm text-gray-700">
                            {inputs.dr_achievement?.met_objectives ?? 0} of {inputs.dr_achievement?.tests_in_period ?? 0} DR tests this period met their objectives.
                        </p>
                    )}
                </AgendaSection>

                <AgendaSection n={9} title="Supplier continuity" clauseRef="iso22301.9.3.inputs">
                    {notCaptured ? <Placeholder /> : (
                        <p className="text-sm text-gray-700">{inputs.supplier_continuity?.chase_list_count ?? 0} vendor attestation(s) overdue or missing.</p>
                    )}
                </AgendaSection>

                <AgendaSection n={10} title="Interested-party feedback" clauseRef="iso22301.9.3.inputs">
                    <p className="mb-2 text-xs text-gray-500">No feedback register exists in this build — recorded as free text.</p>
                    {approved ? (
                        <p className="text-sm text-gray-700">{inputs.interested_party_feedback || 'None recorded.'}</p>
                    ) : (
                        <FormField label="Feedback">
                            <textarea rows={3} className="form-textarea" value={capture.data.interested_party_feedback}
                                onChange={(e) => capture.setData('interested_party_feedback', e.target.value)} />
                        </FormField>
                    )}
                </AgendaSection>

                <AgendaSection n={11} title="BIA and risk changes" clauseRef="iso22301.9.3.inputs">
                    {notCaptured ? <Placeholder /> : (
                        <p className="text-sm text-gray-700">{inputs.bia_and_risk_changes?.bias_approved_this_year ?? 0} BIA(s) approved this year.</p>
                    )}
                </AgendaSection>

                <AgendaSection n={12} title="Improvement opportunities" clauseRef="iso22301.9.3.inputs">
                    {notCaptured ? <Placeholder /> : (
                        <p className="text-sm text-gray-700">{inputs.improvement_opportunities?.open ?? 0} open improvement opportunity(ies).</p>
                    )}
                </AgendaSection>
            </div>

            {!approved && can.manage && (
                <div className="mt-6 flex flex-wrap items-center gap-3 border-t border-gray-200 pt-4">
                    <button type="button" className="btn-secondary text-sm" onClick={recapture} disabled={capture.processing}>
                        {notCaptured ? 'Capture inputs' : 'Re-capture inputs'}
                    </button>
                    {can.approve && (
                        <button
                            type="button"
                            className="btn-primary text-sm"
                            disabled={notCaptured}
                            title={notCaptured ? 'Inputs must be captured first' : undefined}
                            onClick={() => setConfirmingApprove(true)}
                        >
                            Approve
                        </button>
                    )}
                </div>
            )}

            <ConfirmDialog
                show={confirmingApprove}
                title="Approve this review?"
                message="Its inputs and decisions become frozen. A later position is recorded as a new review, not an edit to this one."
                confirmLabel="Approve"
                variant="primary"
                processing={approving}
                onConfirm={approve}
                onCancel={() => setConfirmingApprove(false)}
            />
        </AppLayout>
    );
}

function AgendaSection({ n, title, clauseRef, children }) {
    return (
        <section className="rounded-lg border border-gray-200 bg-white p-6">
            <h2 className="text-sm font-semibold text-gray-900">{n}. {title}</h2>
            <p className="text-[10px] uppercase tracking-wide text-gray-400">{clauseRef}</p>
            <div className="mt-3">{children}</div>
        </section>
    );
}

function Placeholder() {
    return <p className="text-sm text-gray-500">Not yet captured for this review.</p>;
}
