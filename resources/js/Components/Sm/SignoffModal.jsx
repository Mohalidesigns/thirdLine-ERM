import { useEffect, useMemo, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import Modal from '@thirdline/ui/Components/Modal';
import Icon from '@thirdline/ui/Components/Icon';
import useVisitFailures from '@/hooks/useVisitFailures';
import { formatDay, lagosDay } from './format';
import { resolveDecisions, resolveSources } from './signoffSources';

/**
 * The two-step sign-off modal (spec 01 §5.6).
 *
 * TWO SEQUENTIAL WRITES, EACH VISIBLE. §A1.8's guard on `validated → signed_off`
 * requires ≥1 CURRENT sign-off with `decision = approved`, so the rows must exist
 * BEFORE the status can move — and they are two routes under two permissions
 * (`POST signoffs` under `scenario.sign_off`, then `POST status` under
 * `scenario.validate`). They are never one silently chained pair: on a degraded
 * link a lost response after the first write must not leave the user guessing
 * whether the scenario is signed off.
 *
 * RECORDING A SIGN-OFF AND MOVING THE STATUS ARE GENUINELY SEPARATE ACTS, and a
 * signer who is not the person that moves the status is a normal case, not an
 * error (spec 01 §5.6.8). So Close is always available and says what closing
 * means, and a step-2 FAILURE reports "your sign-offs are recorded" rather than
 * rolling back behind the user's back or pretending nothing landed.
 *
 * NEVER RETRY A POST BLIND (spec 01 §6.4). Both writes here are append-only and
 * §A1.6 puts no unique key on (scenario, source), so a blind retry of `POST
 * signoffs` appends a SECOND set of rows for the same sources, and a blind retry
 * of `POST status` re-fires a transition the map may now refuse. Step 2's Retry
 * re-posts only `urls.status`, and only after the first write is known to have
 * landed.
 *
 * NAMES AND ROLES ARE COLLECTED AS TEXT (spec 01 §4.7). `signatory_name` and
 * `role` are NOT NULL snapshots and `role` is a FREE-TEXT capacity — never a
 * `select` of Spatie platform roles, because renaming one "would silently falsify
 * every historical sign-off storing it".
 */
export default function SignoffModal({ show, scenario, qualitativeSummary, signoffWarnings, signoffs, signoffSources, signoffDecisions, suggestedReviewDueOn, urls, onClose }) {
    // The vocabulary is the server's (spec 03 §2: the front end hard-codes none of
    // it). `resolveSources`/`resolveDecisions` fall back to their mirrors only when
    // the props are absent — see ./signoffSources.
    const sources = useMemo(() => resolveSources(signoffSources ?? null, signoffs), [signoffSources, signoffs]);
    const decisions = useMemo(() => resolveDecisions(signoffDecisions ?? null, signoffs), [signoffDecisions, signoffs]);
    const current = (signoffs || []).filter((row) => row.is_current);

    const [step, setStep] = useState(1);
    const [rows, setRows] = useState(() => seedRows(sources, current));
    const [reviewDueOn, setReviewDueOn] = useState(scenario?.review_due_on || suggestedReviewDueOn || '');
    const [comment, setComment] = useState('');
    const [posting, setPosting] = useState(false);
    const [step2, setStep2] = useState(null);
    const [reason, setReason] = useState('');
    /**
     * Spec 01 §6.4: a LOST RESPONSE on one of the two append-only `POST`s.
     * `'signoffs'` because §A1.6 puts no unique key on (scenario, source), so a
     * blind retry appends a SECOND set of rows for the same sources; `'status'`
     * because a blind retry re-fires a transition the map may now refuse.
     *
     * While it is set the write's control stays disarmed and the modal offers
     * "Check what happened" — a reload of the truth — instead. Re-arming the
     * button before the outcome is known is how a tenant acquires duplicate
     * governance rows that nothing can tell apart afterwards.
     */
    const [lostResponse, setLostResponse] = useState(null);
    /**
     * The server's own refusal of `POST signoffs` — C2.2's status guard on a
     * frozen record answers with a flash error, which Inertia reports through
     * `onSuccess`. Kept out of `step2` because it leaves the modal on step 1: the
     * rows were never written, so there is no "resulting position" to show.
     */
    const [recordRefused, setRecordRefused] = useState(null);
    const summaryRef = useRef(null);
    const cancelRef = useRef(null);
    const errors = useErrors();
    const errorKeys = Object.keys(errors);

    /**
     * The write currently in flight — and the only way this modal can learn that
     * a response was LOST.
     *
     * `onException` IS NOT A VISIT CALLBACK in @inertiajs 2.3.27: `VisitCallbacks`
     * (node_modules/@inertiajs/core/types/types.d.ts:283-296) lists onBefore,
     * onStart, onProgress, onFinish, onCancel, onSuccess, onError, onFlash and the
     * two prefetch hooks, and nothing in either package reads an `onException`
     * key — passing one is silently discarded. The only observation point for
     * "the request never completed" is the global `exception` event, which
     * `useVisitFailures` normalises to `failure.kind === 'network'`.
     *
     * The shell mounts the same hook and gates its banner on a write IT issued, so
     * a lost sign-off response is claimed here and only here.
     */
    const { failure } = useVisitFailures();
    const pendingWrite = useRef(null);
    const lostHandled = useRef(false);

    useEffect(() => {
        if (!show) {
            return;
        }

        setStep(1);
        setStep2(null);
        setLostResponse(null);
        setRecordRefused(null);
        setRows(seedRows(sources, current));
        setReviewDueOn(scenario?.review_due_on || suggestedReviewDueOn || '');
        setComment('');
        setReason('');
        pendingWrite.current = null;
        lostHandled.current = false;
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [show]);

    useEffect(() => {
        if (show && step === 1) {
            cancelRef.current?.focus();
        }
    }, [show, step]);

    useEffect(() => {
        if (errorKeys.length > 0) {
            summaryRef.current?.focus();
        }
    }, [errorKeys.join('|')]);

    /**
     * Spec 01 §6.4, and the reason neither write here may be retried blind. Both
     * are append-only and §A1.6 puts no unique key on (scenario, source), so a
     * second `POST signoffs` appends a SECOND set of rows for the same sources,
     * and a second `POST status` re-fires a transition the map may now refuse.
     *
     * So on a lost response: mark it, then RELOAD the truth — and if the write did
     * in fact land, render the success state instead of a warning about a request
     * that succeeded.
     */
    useEffect(() => {
        const write = pendingWrite.current;

        if (failure?.kind !== 'network' || !write || lostHandled.current) {
            return;
        }

        lostHandled.current = true;
        pendingWrite.current = null;

        if (write.kind === 'signoffs') {
            setLostResponse('signoffs');

            router.reload({
                only: ['scenario', 'signoffs', 'signoffWarnings', 'transitions', 'gate'],
                preserveScroll: true,
                preserveState: true,
                onSuccess: (page) => {
                    const rows = Array.isArray(page.props.signoffs) ? page.props.signoffs : [];

                    // A landed write APPENDED a row this modal had not seen: the
                    // per-source count cannot answer this, because a re-signing
                    // round already has a current row for the same source (§4.6).
                    const landed = write.postedSources.filter((source) =>
                        rows.some(
                            (row) =>
                                row.source === source &&
                                row.is_current &&
                                !write.priorIds.has(row.id),
                        ),
                    );

                    if (write.postedSources.length > 0 && landed.length === write.postedSources.length) {
                        setLostResponse(null);
                        buildStep2(page.props, write.postedSources);
                    }
                },
            });

            return;
        }

        setLostResponse('status');
        setStep2((prev) => (prev ? { ...prev, failed: 'unknown' } : prev));

        router.reload({
            only: ['scenario', 'transitions', 'gate', 'signoffs'],
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                if (page.props.scenario?.status === write.to) {
                    setLostResponse(null);
                    onClose?.(true, page.props.flash?.success || 'Status updated.');
                }
            },
        });
        // Single-shot on the `lostHandled` guard; `failure`'s identity fires it
        // and the reload's own failure must not loop.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [failure]);

    /** Rows the user actually filled: an entirely empty row is not posted. */
    const filled = rows.filter(
        (row) => row.signatory_name.trim() !== '' || row.role.trim() !== '' || row.comment.trim() !== '',
    );

    // The 422 keys are indexed by the POSTED array (`signoffs.{i}.role`), which
    // skips empty rows — so the summary anchors need the posted position of each
    // source, not its position in the rendered list.
    const postedIndexBySource = filled.reduce((acc, row, index) => {
        acc[row.source] = index;
        return acc;
    }, {});

    const anyReturned = filled.some((row) => row.decision === 'returned_for_rework');
    const anyIndependent = filled.some((row) => row.is_independent_challenge);
    const anyApproved = filled.some((row) => row.decision === 'approved');
    // Spec 01 §5.6.3: at least one checked row is required to SIGN OFF. The
    // control is not the control — the server refuses too — but the modal says so
    // before the user discovers it as a 422.
    const independenceMissing = !anyReturned && !anyIndependent;

    /**
     * Build step 2 from a props payload — the response to the write, or the
     * reload that followed a lost one, which is why this is a function and not
     * inline.
     *
     * STEP 2 READS THE SERVER'S ROWS, NOT THE CLIENT'S CHECKBOXES. Two reasons,
     * and both are the server overruling what this component remembers:
     *
     * 1. C1.5 — an author signing their own scenario has `is_independent_challenge`
     *    forced FALSE with a flash, because the sign-off is legitimate and only
     *    the claim of independence is refused. Deriving `canSignOff` from
     *    `anyIndependent` would then offer "Sign off" over a guard §A1.8 is about
     *    to fail, and the signer would learn that from a 422.
     * 2. §4.6 — `signoffs` is latest-row-per-source, so which rows are CURRENT is
     *    the server's call. A source that signed in an earlier round and was not
     *    re-posted still counts toward the guard, and this component cannot see
     *    that from `filled`.
     *
     * The client's own answers are the FALLBACK only, for a payload that carries
     * no `signoffs` array at all.
     */
    const buildStep2 = (props, postedSources) => {
        const reloaded = Array.isArray(props?.signoffs) ? props.signoffs : null;
        const allCurrent = reloaded ? reloaded.filter((row) => row.is_current) : null;

        const returned = allCurrent
            ? allCurrent.some((row) => row.decision === 'returned_for_rework')
            : anyReturned;
        const approved = allCurrent ? allCurrent.some((row) => row.decision === 'approved') : anyApproved;
        const independent = allCurrent
            ? allCurrent.some((row) => row.is_independent_challenge)
            : anyIndependent;
        const recorded = allCurrent
            ? allCurrent.filter((row) => postedSources.includes(row.source)).length
            : filled.length;

        setStep2({
            // The Sign off control is ABSENT, not disabled, when a source returned
            // the scenario: §A1.8 has no `validated → signed_off` edge a returned
            // sign-off could satisfy, and the server would refuse it anyway
            // (spec 01 §5.6.8).
            returned,
            count: recorded || filled.length,
            canSignOff: approved && independent && !returned,
            independent,
            // C1.5's flash, and the `warning` the controller joins from
            // `recordSignoffs`. Surfaced because it is the server telling the
            // signer something this form cannot know.
            warning: props?.flash?.warning || null,
        });
        setStep(2);
    };

    const recordSignoffs = () => {
        setPosting(true);
        setLostResponse(null);
        setRecordRefused(null);

        const postedSources = filled.map((row) => row.source);

        // Armed BEFORE the request: the `failure` effect above is what disarms
        // this write and reloads the truth if the response never arrives.
        //
        // `priorIds` is what makes the reload's "did it land?" answer trustworthy.
        // Counting current rows per source is NOT enough: a re-signing round has a
        // current row for that source ALREADY (§4.6, and the sentence above the
        // rows says so), so the count matches whether or not this write landed.
        // `recordSignoffs` APPENDS, so a landed write is a row id that was not
        // there before — and that is the only comparison that cannot lie.
        lostHandled.current = false;
        pendingWrite.current = {
            kind: 'signoffs',
            postedSources,
            priorIds: new Set((signoffs || []).map((row) => row.id)),
        };

        router.post(
            urls.signoffs,
            {
                signoffs: filled.map((row) => ({
                    source: row.source,
                    signatory_name: row.signatory_name.trim(),
                    role: row.role.trim(),
                    decision: row.decision,
                    is_independent_challenge: row.is_independent_challenge,
                    comment: row.comment.trim() === '' ? null : row.comment.trim(),
                })),
                review_due_on: reviewDueOn,
                comment: comment.trim() === '' ? null : comment.trim(),
            },
            {
                preserveScroll: true,
                onFinish: () => setPosting(false),
                onSuccess: (page) => {
                    pendingWrite.current = null;

                    const refusal = page.props.flash?.error || null;

                    // `storeSignoffs` refuses a frozen record with a 302 and a
                    // flash error (C2.2's status guard), which Inertia reports
                    // HERE, in `onSuccess`. Advancing to step 2 on that would
                    // offer "Sign off" over a write that never landed — the same
                    // trap as the shell's transition banner.
                    if (refusal || !Array.isArray(page.props.signoffs)) {
                        setRecordRefused(refusal || 'The sign-offs were not recorded.');

                        return;
                    }

                    buildStep2(page.props, postedSources);
                },
                // A 422 keeps the modal on step 1 with the entries intact; the
                // error summary above owns the message (spec 01 §5.6).
                onError: () => {
                    pendingWrite.current = null;
                },
                // NO `onException` HERE: this Inertia version has no such visit
                // callback, so a lost response is invisible to this options bag.
                // The `failure` effect above owns it — and §6.4 is why it must not
                // simply re-arm "Record sign-offs": §A1.6 has no unique key on
                // (scenario, source), so a second click appends a SECOND set of
                // rows for the same sources and nothing can tell them apart.
            },
        );
    };

    const moveStatus = (to, withReason) => {
        setPosting(true);
        setLostResponse(null);

        lostHandled.current = false;
        pendingWrite.current = { kind: 'status', to };

        router.post(
            urls.status,
            withReason ? { to, reason } : { to },
            {
                preserveScroll: true,
                onFinish: () => setPosting(false),
                onSuccess: (page) => {
                    pendingWrite.current = null;

                    const next = page.props;
                    const refusal = next.flash?.error || null;
                    const moved = next.scenario?.status === to;

                    if (moved && !refusal) {
                        // Hand the SERVER'S sentence back to the shell. Deriving it
                        // there from the shell's own `scenario.status` reads the
                        // PRE-MOVE prop and always fell through to a generic
                        // "Status updated." (spec 01 §5.6).
                        onClose?.(true, next.flash?.success || 'Status updated.');

                        return;
                    }

                    // The server ANSWERED, so nothing landed and re-posting this
                    // one route is safe (§5.6.9). The sentence is the server's.
                    setStep2((prev) =>
                        prev
                            ? {
                                  ...prev,
                                  failed: 'refused',
                                  failureText:
                                      refusal ||
                                      `${scenario?.reference} is still ${next.scenario?.status_label}. The status did not change.`,
                              }
                            : prev,
                    );
                },
                // A 422 (a too-short return reason, say) or a lifecycle refusal
                // the flash did not carry: the server ANSWERED, so nothing landed.
                // The field error renders under the textarea.
                onError: () => {
                    pendingWrite.current = null;

                    setStep2((prev) =>
                        prev
                            ? { ...prev, failed: 'refused', failureText: 'The status change was rejected. Nothing was written.' }
                            : prev,
                    );
                },
                // NO `onException` HERE: this Inertia version has no such visit
                // callback. A LOST RESPONSE is claimed by the `failure` effect
                // above, which marks step 2 `unknown` — never "didn't go through",
                // because nobody knows that — and reloads the truth instead of
                // re-firing a transition the map may now refuse (spec 01 §6.4).
            },
        );
    };

    /** Spec 01 §6.4's "Check what happened": reload the truth, never re-post. */
    const checkWhatHappened = () => {
        router.reload({
            only:
                lostResponse === 'signoffs'
                    ? ['scenario', 'signoffs', 'signoffWarnings', 'transitions', 'gate']
                    : ['scenario', 'transitions', 'gate', 'signoffs'],
            preserveScroll: true,
            preserveState: true,
        });
    };

    return (
        <Modal show={show} maxWidth="xl" onClose={() => onClose?.(false)}>
            <div className="p-6">
                <h2 className="text-lg font-semibold text-gray-900">
                    {step === 1 ? `Sign off ${scenario?.reference}` : `Sign-offs recorded for ${scenario?.reference}`}
                </h2>

                {/* The server's refusal of the sign-off write. `role="alert"`
                    because it follows the signer's action, and NOT transient: a
                    refusal that vanished on a timer would leave "Record sign-offs"
                    looking like a button that does nothing. */}
                {step === 1 && recordRefused && (
                    <p role="alert" className="mt-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                        {recordRefused} Nothing was recorded.
                    </p>
                )}

                {/* Spec 01 §6.4, step 1. The control offered is "Check what
                    happened" — a reload — and NOT a retry: §A1.6 has no unique key
                    on (scenario, source), so re-posting appends a second set of
                    rows for the same sources. `Record sign-offs` stays disarmed
                    until the truth is known. */}
                {step === 1 && lostResponse === 'signoffs' && (
                    <div role="alert" className="mt-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                        <p>
                            We lost the server's response, so we don't know whether your sign-offs were recorded.
                            Recording them again without checking could add a second set of rows for the same sources.
                        </p>
                        <button
                            type="button"
                            onClick={checkWhatHappened}
                            className="btn-secondary mt-2 text-sm"
                            disabled={posting}
                            aria-busy={posting || undefined}
                        >
                            Check what happened
                        </button>
                    </div>
                )}

                {errorKeys.length > 0 && (
                    <div className="mt-3 rounded-lg border border-red-200 bg-red-50 p-3">
                        <h3 ref={summaryRef} tabIndex={-1} className="text-sm font-semibold text-red-800 focus:outline-none">
                            Fix {errorKeys.length} {errorKeys.length === 1 ? 'item' : 'items'} before recording
                        </h3>
                        <ul className="mt-1 list-inside list-disc text-sm text-red-700">
                            {errorKeys.map((key) => (
                                <li key={key}>
                                    <a href={`#${modalAnchor(key, filled)}`} className="underline">
                                        {errors[key]}
                                    </a>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {step === 1 ? (
                    <StepOne
                        scenario={scenario}
                        summary={qualitativeSummary}
                        sources={sources}
                        decisions={decisions}
                        rows={rows}
                        setRows={setRows}
                        postedIndexBySource={postedIndexBySource}
                        current={current}
                        reviewDueOn={reviewDueOn}
                        setReviewDueOn={setReviewDueOn}
                        comment={comment}
                        setComment={setComment}
                        warnings={signoffWarnings}
                        errors={errors}
                        posting={posting}
                        cancelRef={cancelRef}
                        onRecord={recordSignoffs}
                        onClose={onClose}
                        anyReturned={anyReturned}
                        independenceMissing={independenceMissing}
                        // §6.4: never re-arm an append-only POST until the truth
                        // is known.
                        disarmed={lostResponse === 'signoffs'}
                    />
                ) : (
                    <StepTwo
                        scenario={scenario}
                        step2={step2}
                        reason={reason}
                        setReason={setReason}
                        reasonError={errors.reason}
                        posting={posting}
                        onMove={moveStatus}
                        onClose={onClose}
                        lostResponse={lostResponse}
                        onCheck={checkWhatHappened}
                    />
                )}
            </div>
        </Modal>
    );
}

/* ------------------------------------------------------------------ */
/*  Step one                                                           */
/* ------------------------------------------------------------------ */

function StepOne({
    scenario,
    summary,
    sources,
    decisions,
    rows,
    setRows,
    postedIndexBySource,
    current,
    reviewDueOn,
    setReviewDueOn,
    comment,
    setComment,
    warnings,
    errors,
    posting,
    cancelRef,
    onRecord,
    onClose,
    anyReturned,
    independenceMissing,
    disarmed,
}) {
    const set = (source, key, value) =>
        setRows((prev) => prev.map((row) => (row.source === source ? { ...row, [key]: value } : row)));

    return (
        <div className="mt-4 space-y-5">
            {/* 1 — A READ-ONLY RECAP of what is being approved. Every value is a
                prop and every label is the SERVER'S WORDING; nothing is
                recomputed or re-derived in the browser, so the recap renders
                identically whichever tab the signer opened the modal from
                (spec 01 §5.6.1). */}
            <section aria-labelledby="signoff-recap-heading" className="rounded-lg bg-gray-50 p-4">
                <h3 id="signoff-recap-heading" className="text-sm font-semibold text-gray-900">
                    What you are approving
                </h3>
                <dl className="mt-2 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                    <RecapRow label="Reference" value={scenario?.reference} mono />
                    <RecapRow label="Scenario" value={scenario?.name} />
                    <RecapRow label="Business unit" value={scenario?.business_unit?.name} />
                    <RecapRow label="Nature" value={scenario?.nature_label} />
                    <RecapRow label="OP event type" value={scenario?.op_event_type?.name} />
                </dl>
                <dl className="mt-3 space-y-1 border-t border-gray-200 pt-3 text-sm">
                    <RecapRow
                        label="Causes"
                        value={`${summary?.causes_selected ?? 0} ${summary?.causes_selected === 1 ? 'cause' : 'causes'} selected`}
                    />
                    <RecapRow
                        label="Allocation"
                        value={
                            summary?.allocation_total === null || summary?.allocation_total === undefined
                                ? // "Not allocated", NEVER "0%" — a rate over
                                  // nothing is undefined, not zero (spec 01 §0.5).
                                  'Not allocated'
                                : `${summary.allocation_lines} ${summary.allocation_lines === 1 ? 'business line' : 'business lines'}, allocation ${summary.allocation_total}%`
                        }
                    />
                    <RecapRow label="Reputational impact" value={summary?.reputational_label} />
                    <RecapRow label="Legal and regulatory" value={summary?.legal_label} />
                    <RecapRow label="Insurance risk" value={summary?.insurance_risk_label} />
                    <RecapRow label="Financial / market risk" value={summary?.financial_risk_label} />
                    <RecapRow label="Eligible to transfer" value={summary?.eligible_to_transfer_label} />
                </dl>
            </section>

            {/* 2 — Sign-off sources. Up to three rows; at least Source 1 is
                required, and a row left entirely empty is not posted. */}
            <section aria-labelledby="signoff-sources-heading" className="space-y-4">
                <h3 id="signoff-sources-heading" className="text-sm font-semibold text-gray-900">
                    Sign-off sources
                </h3>

                {current.length > 0 && (
                    // Because §A1.6 has no unique key on (scenario, source), a
                    // second round APPENDS rows. Without this sentence a signer
                    // will assume they are editing history; with it the
                    // append-only model is legible from the screen.
                    <p className="text-xs text-gray-600">
                        {/* `signed_at` is an ISO INSTANT and `formatDay` only
                            accepts `Y-m-d`, so passing it straight in rendered
                            "Source 1 last signed on —" for every re-signing —
                            the one sentence §5.6 needs in order to make the
                            append-only model legible. Its Lagos calendar day is
                            resolved first; slicing the ISO string instead would
                            take the UTC day, which is a day early for a sign-off
                            after 23:00 Lagos. */}
                        {current
                            .map((row) => {
                                const day = lagosDay(row.signed_at);

                                return `${row.source_label} last signed${day ? ` on ${formatDay(day)}` : ''}.`;
                            })
                            .join(' ')}{' '}
                        Recording a new sign-off supersedes it; the earlier one stays on the audit trail.
                    </p>
                )}

                {sources.map((source) => {
                    const row = rows.find((candidate) => candidate.source === source.value);

                    if (!row) {
                        return null;
                    }

                    // The server's error keys index the POSTED array, which omits
                    // rows the user left empty — so the lookup goes through the
                    // posted position, not the rendered one.
                    const posted = postedIndexBySource[source.value];
                    const prefix = posted === undefined ? null : `signoffs.${posted}`;
                    const nameError = prefix ? errors[`${prefix}.signatory_name`] : null;
                    const roleError = prefix ? errors[`${prefix}.role`] : null;
                    const decisionError = prefix ? errors[`${prefix}.decision`] : null;

                    return (
                        <fieldset
                            key={source.value}
                            id={`signoff-row-${source.value}`}
                            className="scroll-mt-4 rounded-lg border border-gray-200 p-3"
                        >
                            <legend className="px-1 text-sm font-semibold text-[#1A365D]">{source.label}</legend>

                            <div className="space-y-3">
                                <div>
                                    <label htmlFor={`signatory-${source.value}`} className="form-label">
                                        Signatory name
                                        <RequiredMark />
                                    </label>
                                    <input
                                        id={`signatory-${source.value}`}
                                        type="text"
                                        maxLength={200}
                                        className="form-input text-sm"
                                        value={row.signatory_name}
                                        onChange={(event) => set(source.value, 'signatory_name', event.target.value)}
                                        aria-invalid={nameError ? 'true' : undefined}
                                        aria-describedby={nameError ? `signatory-${source.value}-error` : undefined}
                                    />
                                    {nameError && (
                                        <p id={`signatory-${source.value}-error`} className="mt-1 text-sm text-red-700">
                                            {nameError}
                                        </p>
                                    )}
                                </div>

                                <div>
                                    {/* FREE TEXT, never a select of platform roles
                                        (spec 01 §4.7): "Head of Operational Risk",
                                        "Second Line — Independent Challenge". */}
                                    <label htmlFor={`role-${source.value}`} className="form-label">
                                        Role / capacity
                                        <RequiredMark />
                                    </label>
                                    <input
                                        id={`role-${source.value}`}
                                        type="text"
                                        maxLength={100}
                                        className="form-input text-sm"
                                        value={row.role}
                                        onChange={(event) => set(source.value, 'role', event.target.value)}
                                        aria-invalid={roleError ? 'true' : undefined}
                                        aria-describedby={roleError ? `role-${source.value}-error` : `role-${source.value}-hint`}
                                    />
                                    {roleError ? (
                                        <p id={`role-${source.value}-error`} className="mt-1 text-sm text-red-700">
                                            {roleError}
                                        </p>
                                    ) : (
                                        <p id={`role-${source.value}-hint`} className="mt-1 text-xs text-gray-600">
                                            The capacity this person signed in — for example "Head of Operational Risk".
                                        </p>
                                    )}
                                </div>

                                <fieldset>
                                    <legend className="form-label mb-0">Decision</legend>
                                    <div className="mt-1 flex flex-wrap gap-4">
                                        {decisions.map((decision) => (
                                            <div key={decision.value} className="flex items-start gap-2">
                                                <input
                                                    id={`decision-${source.value}-${decision.value}`}
                                                    name={`decision-${source.value}`}
                                                    type="radio"
                                                    className="form-radio mt-0.5"
                                                    value={decision.value}
                                                    checked={row.decision === decision.value}
                                                    onChange={() => set(source.value, 'decision', decision.value)}
                                                />
                                                <label
                                                    htmlFor={`decision-${source.value}-${decision.value}`}
                                                    className="text-sm text-gray-800"
                                                >
                                                    {/* The RESOLVED LABEL, not a
                                                        client-side ternary on the
                                                        value. `decisions` comes
                                                        from `signoffDecisions`
                                                        (config `sm.decision_labels`)
                                                        via `resolveDecisions`, so
                                                        hard-coding two strings here
                                                        made this modal a second
                                                        authority on the vocabulary
                                                        the moment a tenant renamed
                                                        one — the exact thing the
                                                        file docblock forbids. */}
                                                    {decision.label}
                                                </label>
                                            </div>
                                        ))}
                                    </div>
                                    {decisionError && <p className="mt-1 text-sm text-red-700">{decisionError}</p>}
                                    {/* Where "approved with conditions" went:
                                        §A1.6 refused a third decision value, and
                                        the modal says so where a user would look
                                        for it. */}
                                    <p className="mt-1 text-xs text-gray-600">
                                        Conditions and caveats go in the notes log as an assumption, so they are recorded
                                        rather than implied.
                                    </p>
                                </fieldset>

                                <div className="flex items-start gap-2">
                                    <input
                                        id={`independent-${source.value}`}
                                        type="checkbox"
                                        className="form-checkbox mt-1"
                                        checked={row.is_independent_challenge}
                                        onChange={(event) => set(source.value, 'is_independent_challenge', event.target.checked)}
                                    />
                                    <label htmlFor={`independent-${source.value}`} className="text-sm text-gray-800">
                                        This signer is independent of the team that designed this scenario.
                                    </label>
                                </div>

                                <div>
                                    <label htmlFor={`comment-${source.value}`} className="form-label">
                                        Comment
                                    </label>
                                    <textarea
                                        id={`comment-${source.value}`}
                                        rows={2}
                                        maxLength={2000}
                                        className="form-input text-sm"
                                        value={row.comment}
                                        onChange={(event) => set(source.value, 'comment', event.target.value)}
                                    />
                                </div>
                            </div>
                        </fieldset>
                    );
                })}

                {independenceMissing && (
                    <p className="text-sm text-red-700">
                        At least one signer must be independent of the team that designed this scenario. Tick the box
                        beside that signer.
                    </p>
                )}
            </section>

            {/* 4 — Next review due. REQUIRED IN THIS MODAL: a signed-off scenario
                with no review date never comes back for its annual refresh.
                `suggestedReviewDueOn` is SERVER-COMPUTED — there is no
                `+12 months` in client code, because a client-side date constant is
                a fabricated figure and would drift from the tenant's setting
                (spec 01 §5.6.4). */}
            <div>
                <label htmlFor="review_due_on" className="form-label">
                    Next review due
                    <RequiredMark />
                </label>
                <input
                    id="review_due_on"
                    type="date"
                    className="form-input sm:max-w-xs"
                    value={reviewDueOn}
                    onChange={(event) => setReviewDueOn(event.target.value)}
                    aria-invalid={errors.review_due_on ? 'true' : undefined}
                    aria-describedby={errors.review_due_on ? 'review_due_on-error' : undefined}
                />
                {errors.review_due_on && (
                    <p id="review_due_on-error" className="mt-1 text-sm text-red-700">
                        {errors.review_due_on}
                    </p>
                )}
            </div>

            {/* 5 — WARNINGS, NOT BLOCKS. The wording is the server's, from
                `config('sm.signoff.warnings_note')` and
                `signoff.warning_messages`; the codes are what get snapshotted onto
                the sign-off row. */}
            {warnings?.messages?.length > 0 && (
                <div role="note" className="rounded-lg border border-amber-200 bg-amber-50 p-3">
                    <h3 className="flex items-center gap-1.5 text-sm font-semibold text-amber-900">
                        <Icon name="warning" className="h-4 w-4" />
                        Outstanding before you sign
                    </h3>
                    <ul className="mt-1 list-inside list-disc text-sm text-amber-900">
                        {warnings.messages.map((message) => (
                            <li key={message}>{message}</li>
                        ))}
                    </ul>
                    {warnings.note && <p className="mt-2 text-xs text-amber-900">{warnings.note}</p>}
                </div>
            )}

            <div>
                <label htmlFor="signoff-comment" className="form-label">
                    Comment
                </label>
                <textarea
                    id="signoff-comment"
                    rows={3}
                    maxLength={2000}
                    className="form-input text-sm"
                    value={comment}
                    onChange={(event) => setComment(event.target.value)}
                    aria-describedby="signoff-comment-hint"
                    aria-invalid={errors.comment ? 'true' : undefined}
                />
                <p id="signoff-comment-hint" className="mt-1 text-xs text-gray-600">
                    Anything the next reader should know.
                </p>
                {errors.comment && <p className="mt-1 text-sm text-red-700">{errors.comment}</p>}
            </div>

            <div className="flex justify-end gap-3 border-t border-gray-100 pt-4">
                {/* Cancel is FOCUSED FIRST, so a signer who opened the modal by
                    accident does not confirm it by pressing Enter. */}
                <button ref={cancelRef} type="button" onClick={() => onClose?.(false)} className="btn-secondary text-sm" disabled={posting}>
                    Cancel
                </button>
                <button
                    type="button"
                    onClick={onRecord}
                    className="btn-primary text-sm"
                    // `disarmed` is a LOST RESPONSE (§6.4): the first request may
                    // have committed, and §A1.6 has no unique key to stop a second
                    // one appending duplicate rows for the same sources. The
                    // button stays down until "Check what happened" has answered.
                    disabled={posting || disarmed}
                    aria-busy={posting || undefined}
                    aria-disabled={disarmed || undefined}
                >
                    {posting ? 'Recording…' : 'Record sign-offs'}
                </button>
            </div>

            {anyReturned && (
                <p className="text-xs text-gray-600">
                    A source has returned this scenario for rework, so the next step will ask you to move it to
                    Returned for rework rather than to Signed off.
                </p>
            )}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Step two                                                           */
/* ------------------------------------------------------------------ */

function StepTwo({ scenario, step2, reason, setReason, reasonError, posting, onMove, onClose, lostResponse, onCheck }) {
    // The edge this step is offering, used by the banner's own control so that
    // "Retry" re-posts the SAME transition rather than a guessed one.
    const to = step2?.returned ? 'returned_for_rework' : 'signed_off';
    const disarmed = lostResponse === 'status';

    return (
        <div className="mt-4 space-y-4">
            {/* Spec 01 §5.6.9: the modal does NOT roll back and does not pretend
                to. The two failure kinds are kept apart because only one of them
                is KNOWN — asserting "the status change didn't go through" over a
                lost response states a fact nobody has, and naming a Retry control
                that does not render is a banner that lies twice. */}
            {step2?.failed === 'refused' && (
                <div role="alert" className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                    <p>
                        Your sign-offs are recorded. The status change didn't go through
                        {step2.failureText ? ` — ${step2.failureText}` : ''} Press Retry to move it, or close and do it
                        from the header.
                    </p>
                    {/* The control the sentence names, rendered. Re-posting only
                        `urls.status` is safe here: the server ANSWERED, so the
                        write demonstrably did not land — and the append-only
                        sign-off write is never repeated. */}
                    <button
                        type="button"
                        onClick={() => onMove(to, Boolean(step2?.returned))}
                        className="btn-secondary mt-2 text-sm"
                        disabled={posting}
                        aria-busy={posting || undefined}
                    >
                        {posting ? 'Working…' : 'Retry'}
                    </button>
                </div>
            )}

            {step2?.failed === 'unknown' && (
                <div role="alert" className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                    <p>
                        Your sign-offs are recorded. We lost the server's response, so we don't know whether the status
                        moved — check the current status above before doing anything else.
                    </p>
                    {/* NOT a retry (spec 01 §6.4): a blind re-post of
                        `POST status` re-fires a transition the map may now
                        refuse. The reload answers the question, and if the move
                        did land it closes this modal on the success state. */}
                    <button type="button" onClick={onCheck} className="btn-secondary mt-2 text-sm">
                        Check what happened
                    </button>
                </div>
            )}

            {/* C1.5's flash: an author signing their own scenario has
                `is_independent_challenge` forced false, and the server says so.
                Without this the signer sees "Sign off" simply absent and no
                reason why. */}
            {step2?.warning && (
                <p role="status" className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                    {step2.warning}
                </p>
            )}

            {step2?.returned ? (
                <>
                    <p className="text-sm text-gray-800">
                        A source returned this scenario for rework. Move it to <strong>Returned for rework</strong>?
                    </p>
                    {/* `TransitionScenarioRequest` requires `reason` (min 10) when
                        `to` is `returned_for_rework`, and `ScenarioLifecycle`
                        writes the `challenge` note in the SAME TRANSACTION as the
                        status change. The shell's reason modal (spec 01 §5.3)
                        collects it; this step must too, or the post would 422. */}
                    <div>
                        <label htmlFor="return-reason" className="form-label">
                            What has to change?
                            <RequiredMark />
                        </label>
                        <textarea
                            id="return-reason"
                            rows={3}
                            maxLength={500}
                            className="form-input text-sm"
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                            aria-describedby="return-reason-hint"
                            aria-invalid={reasonError ? 'true' : undefined}
                        />
                        <p id="return-reason-hint" className="mt-1 text-xs text-gray-600">
                            The designer sees this as the reason the scenario came back, and it is recorded against the
                            scenario permanently.
                        </p>
                        {reasonError && <p className="mt-1 text-sm text-red-700">{reasonError}</p>}
                    </div>
                </>
            ) : (
                <p className="text-sm text-gray-800">
                    Sign-offs recorded for {step2?.count} {step2?.count === 1 ? 'source' : 'sources'}. Move{' '}
                    {scenario?.reference} to <strong>Signed off</strong>? It will be frozen.
                </p>
            )}

            <div className="flex flex-wrap justify-end gap-3 border-t border-gray-100 pt-4">
                {/* Close is ALWAYS available, and the modal says what closing
                    means: the two acts are separate and a signer who is not the
                    person that moves the status is a normal case (spec 01 §5.6.8). */}
                <button type="button" onClick={() => onClose?.(false)} className="btn-secondary text-sm" disabled={posting}>
                    Close
                </button>
                {step2?.returned ? (
                    <button
                        type="button"
                        onClick={() => onMove('returned_for_rework', true)}
                        className="btn-warning text-sm"
                        // Held down while a lost response is unresolved (§6.4).
                        disabled={posting || disarmed}
                        aria-busy={posting || undefined}
                    >
                        {posting ? 'Moving…' : 'Return for rework'}
                    </button>
                ) : (
                    step2?.canSignOff && (
                        <button
                            type="button"
                            onClick={() => onMove('signed_off', false)}
                            className="btn-primary text-sm"
                            disabled={posting || disarmed}
                            aria-busy={posting || undefined}
                        >
                            {posting ? 'Signing off…' : 'Sign off'}
                        </button>
                    )
                )}
            </div>

            <p className="text-xs text-gray-600">
                Your sign-offs are recorded. The status is still {scenario?.status_label} — move it later from the
                header.
            </p>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Pieces                                                             */
/* ------------------------------------------------------------------ */

function seedRows(sources, current) {
    return sources.map((source) => {
        // Pre-fill from the CURRENT rows (§4.6's `is_current`), never by sorting
        // or picking "latest" in the browser.
        const existing = current.find((row) => row.source === source.value);

        return {
            source: source.value,
            signatory_name: existing?.signatory_name ?? '',
            role: existing?.role ?? '',
            decision: existing?.decision ?? 'approved',
            is_independent_challenge: Boolean(existing?.is_independent_challenge),
            comment: existing?.comment ?? '',
        };
    });
}

function RequiredMark() {
    return (
        <>
            <span className="ml-0.5 text-red-600" aria-hidden="true">
                *
            </span>
            <span className="sr-only"> (required)</span>
        </>
    );
}

function RecapRow({ label, value, mono = false }) {
    return (
        <div>
            <dt className="text-xs font-medium uppercase tracking-wide text-gray-500">{label}</dt>
            <dd className={`text-sm text-gray-900 ${mono ? 'font-mono' : ''}`}>
                {value === null || value === undefined || value === '' ? <span className="text-gray-600">—</span> : value}
            </dd>
        </div>
    );
}

/**
 * 422 keys arrive as `signoffs.{i}.signatory_name`, `signoffs.{i}.role`,
 * `signoffs.{i}.user_id`, `review_due_on` and `comment` (spec 01 §5.6), indexed by
 * the POSTED array — so the summary anchors map back to the source row, not to a
 * position the user cannot see.
 */
function modalAnchor(key, filled) {
    const match = key.match(/^signoffs\.(\d+)\./);

    if (!match) {
        return key;
    }

    // `filled` IS the posted array, so its position i is the `signoffs.{i}` the
    // server's error key names — and its `source` is the row to focus.
    const row = filled?.[Number(match[1])];

    return row ? `signoff-row-${row.source}` : key;
}

/**
 * The modal's own error bag.
 *
 * Read straight from the shared Inertia page props rather than a second store:
 * both writes are ordinary Inertia visits, so their 422s land in `props.errors`
 * like every other form's, and keeping one source means the summary and the field
 * can never disagree about what failed.
 */
function useErrors() {
    return usePage().props.errors || {};
}
