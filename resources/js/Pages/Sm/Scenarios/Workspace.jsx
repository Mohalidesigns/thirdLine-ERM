import { useEffect, useMemo, useRef, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import ConfirmDialog from '@thirdline/ui/Components/ConfirmDialog';
import Icon from '@thirdline/ui/Components/Icon';
import Modal from '@thirdline/ui/Components/Modal';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import FailureBanner from '@/Components/Sm/FailureBanner';
import ScenarioStatusChip from '@/Components/Sm/ScenarioStatusChip';
import SignoffModal from '@/Components/Sm/SignoffModal';
import { formatDay, formatLagosClock, lagosDay } from '@/Components/Sm/format';
import useVisitFailures from '@/hooks/useVisitFailures';
import OverviewTab from './OverviewTab';
import QualitativeTab from './QualitativeTab';
import ScorecardTab from './ScorecardTab';
import FrequencyTab from './FrequencyTab';
import ImpactTab from './ImpactTab';
import InsuranceTab from './InsuranceTab';
import VariantsTab from './VariantsTab';
import ActionsTab from './ActionsTab';

/**
 * The scenario workspace shell (spec 01).
 *
 * THE CLIENT HOLDS NO STATE MACHINE. `transitions` was filtered by permission AND
 * by status on the server and is rendered VERBATIM, IN THE ORDER SENT. `can`
 * decides whether an EDITING affordance renders and never which lifecycle buttons
 * appear: a client that re-derived the machine from `can` would eventually offer a
 * transition the controller refuses (spec 01 §3, §5.2). A label the server does
 * not send does not render — the shell has no fallback vocabulary.
 *
 * EVERY TRANSITION POSTS ITS OWN REQUEST with the target passed AS AN ARGUMENT to
 * that call. They do not share a form and never `setData('to', …)` before posting:
 * `setData` is asynchronous and `post(url, { data })` is no rescue because Inertia
 * assigns `data` after spreading `options`. Standard §9 records the exact failure
 * this prevents — Phase 4.5's review panels would have APPROVED a submission when
 * the reviewer clicked Return for rework — and with fourteen edges sharing one
 * route and one `to` parameter, that bug would archive a scenario the user meant
 * to reopen.
 *
 * DEFERRED TABS ARE ABSENT FROM THE STRIP, not disabled (spec 01 §5.1), with their
 * existence disclosed once in prose via `roadmapNote`. Nine tabs of which two work
 * is worse than two tabs and a sentence: seven disabled tabs are seven dead tab
 * stops on every visit for every keyboard and screen-reader user, absent tabs
 * cannot be deep-linked so no `?tab=frequency` URL exists to render empty, and
 * standard §5's discipline applies to CAPABILITY as well as to numbers — a strip of
 * greyed-out tabs implies a quantitative module the product does not have.
 *
 * TABS ARE LINKS, NOT BUTTONS, so a validator can send a colleague "the
 * qualitative tab of SM-2026-014", a reload keeps the section, and a tab switch is
 * a navigation the unsaved-changes guard intercepts for free.
 */
const VARIANT_CLASSES = {
    primary: 'btn-primary',
    secondary: 'btn-secondary',
    warning: 'btn-warning',
    danger: 'btn-danger',
};

export default function Workspace(props) {
    const {
        scenario,
        review,
        gate,
        transitions,
        tab,
        tabs,
        roadmapNote,
        can,
        signoffWarnings,
        qualitativeSummary,
        suggestedReviewDueOn,
        signoffs,
        signoffSources,
        signoffDecisions,
        notes,
        notesMeta,
        noteKinds,
        urls,
        values,
        qualitative,
        scorecard,
        frequency,
        impact,
        insurance,
        variants,
        actions,
    } = props;

    const page = usePage();
    const errors = page.props.errors || {};

    // ── Modal and confirmation state ────────────────────────────────────
    const [signoffOpen, setSignoffOpen] = useState(false);
    const [reasonFor, setReasonFor] = useState(null);
    const [confirmFor, setConfirmFor] = useState(null);
    const [gateOpen, setGateOpen] = useState(false);
    const [leaveOpen, setLeaveOpen] = useState(false);
    const [reason, setReason] = useState('');
    const [posting, setPosting] = useState(false);
    /**
     * `{tone, text}` where tone is `success` (the move happened) or `refusal`
     * (it did not). BOTH RENDER IN THE SAME `role="status"` REGION.
     *
     * A lifecycle refusal is a 302 with a flash error, which Inertia reports
     * through `onSuccess` exactly like a committed transition — so the tone
     * cannot come from which callback fired. It comes from the truth in the
     * RELOADED props (§6.4) and from the server's own sentence, never from a
     * client-side humanising of `to`.
     */
    const [announcement, setAnnouncement] = useState(null);

    /**
     * Spec 01 §0.2 and §6.4. One listener, owned by the shell: the two tabs are
     * children of this page and their own saves are `PUT`s they announce
     * themselves, so the shell renders its banner only for a write IT issued
     * (`lastWrite`) — the `failure && lastAction` pairing `Kri/MyReturn.jsx`
     * established. Inertia's raw debug modal never renders on this screen.
     */
    const { failure, clear: clearFailure } = useVisitFailures();
    const [lastWrite, setLastWrite] = useState(null);
    /**
     * A LOST RESPONSE on an append-only `POST` (spec 01 §6.4): the request may
     * have committed, so the shell stops offering a retry and offers "Check what
     * happened" — a reload of the affected props — instead. `POST status` re-fires
     * a transition the map may now refuse; `POST signoffs` appends a SECOND set of
     * rows because §A1.6 has no unique key on (scenario, source).
     */
    const [lostResponse, setLostResponse] = useState(null);

    const pendingVisit = useRef(null);
    const allowLeave = useRef(false);
    const statusHeading = useRef(null);
    const saveRef = useRef(null);
    const reasonInputRef = useRef(null);

    /**
     * Dirty is held TWICE, on purpose. The ref is what `router.on('before')`
     * reads synchronously — that listener is registered once per `posting`, so a
     * state value captured in its closure would be stale. The state mirror exists
     * so the `beforeunload` effect below has something to depend on: an effect
     * with no dependency array tears down and re-registers its listener after
     * every render, which is what this component used to do.
     */
    const dirtyRef = useRef(false);
    const [dirty, setDirty] = useState(false);

    const markDirty = (value) => {
        dirtyRef.current = value;
        setDirty(value);
    };

    const transitionList = transitions || [];
    const editable = Boolean(can?.update);

    // ── Unsaved-changes guard (spec 01 §6.3) ──────────────────────────────
    // A tab switch is a navigation, so the same guard covers the tab strip, the
    // breadcrumbs, the nav, Back and refresh. The only navigation that bypasses it
    // is the post-save redirect, which sets `allowLeave` first.
    //
    // ONLY `get` VISITS ARE GUARDED. `router.on('before')` fires for every visit
    // including the workspace's own `PUT`/`POST` writes, so guarding those would
    // intercept a Save with the "Leave without saving?" dialog — the save would
    // never reach the server and the user would be asked whether they meant to
    // abandon the very work they just asked to keep. Spec 01 §6.3 scopes the
    // guard to navigation: switching tabs, navigating away, Back, refresh, close.
    useEffect(() => {
        const cancel = router.on('before', (event) => {
            const method = String(event.detail?.visit?.method || 'get').toLowerCase();

            if (method !== 'get' || allowLeave.current || !dirtyRef.current || posting) {
                return;
            }

            event.preventDefault();
            pendingVisit.current = event.detail.visit;
            setLeaveOpen(true);
        });

        return cancel;
    }, [posting]);

    useEffect(() => {
        if (!dirty) {
            return undefined;
        }

        const handler = (event) => {
            event.preventDefault();
            event.returnValue = '';
        };

        window.addEventListener('beforeunload', handler);

        return () => window.removeEventListener('beforeunload', handler);
    }, [dirty]);

    // After any TRANSITION, focus moves to the status banner heading, never to
    // `body` (spec 01 §7) — on a success AND on a refusal, because a refusal is
    // the answer to the button the user just pressed and it must not be missed.
    // Only a transition moves focus: §6.1 keeps it on Save after a save, and
    // §7.1 returns it to the note textarea, so those announcements set no
    // `focus` and rely on the region's `role="status"` to be heard.
    useEffect(() => {
        if (announcement?.focus) {
            statusHeading.current?.focus();
        }
    }, [announcement]);

    /**
     * A LOST RESPONSE, learned from the global `exception` event that
     * `useVisitFailures` listens to.
     *
     * THERE IS NO `onException` VISIT CALLBACK IN @inertiajs 2.3.27. `VisitCallbacks`
     * (node_modules/@inertiajs/core/types/types.d.ts:283-296) lists onBefore,
     * onStart, onProgress, onFinish, onCancel, onSuccess, onError, onFlash and the
     * two prefetch hooks — and nothing in either package ever reads an
     * `onException` key, so passing one is silently discarded. The only
     * observation point for "the request never completed" is the global event,
     * which the hook normalises to `failure.kind === 'network'`. (An earlier
     * revision of this screen relied on `onException` and therefore never ran its
     * reload at all.)
     *
     * Spec 01 §6.4: reload the truth BEFORE offering anything, because both of
     * this shell's writes are append-only — `POST status` re-fires a transition
     * the map may now refuse, and `POST notes` has no unique key to stop a
     * duplicate.
     */
    useEffect(() => {
        if (failure?.kind !== 'network' || !lastWrite || lostResponse) {
            return;
        }

        setLostResponse({ kind: lastWrite.kind });

        router.reload({
            only:
                lastWrite.kind === 'notes'
                    ? ['notes', 'notesMeta', 'signoffWarnings', 'scenario']
                    : ['scenario', 'transitions', 'gate', 'signoffs'],
            preserveScroll: true,
            preserveState: true,
        });
        // Single-shot on the `lostResponse` guard above; `failure`'s identity is
        // what fires this, and the reload's own failure must not loop.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [failure]);

    const leaveAnyway = () => {
        setLeaveOpen(false);
        allowLeave.current = true;
        const visit = pendingVisit.current;
        pendingVisit.current = null;

        if (visit) {
            router.visit(visit.url.href, { method: visit.method, data: visit.data, replace: visit.replace });
        }
    };

    /**
     * One route, fourteen edges. The target is an ARGUMENT to this call and never
     * shared form state — see the file docblock on standard §9.
     *
     * NEVER RETRY BLIND on a lost response (spec 01 §6.4): before any retry the
     * shell reloads `only: ['scenario','transitions','gate','signoffs']` to learn
     * whether the first request landed. A blind retry of `POST status` re-fires a
     * transition the map may now refuse.
     */
    const postStatus = (to, withReason) => {
        setPosting(true);
        clearFailure();
        setLostResponse(null);
        setLastWrite({
            kind: 'status',
            copy: withReason && reason.trim() !== '' ? `Reason: ${reason.trim()}` : null,
            // A 419 is the ONE failure §6.4 lets re-send, because the auth
            // middleware rejects before the write path — so nothing committed
            // and the same call is safe to repeat verbatim.
            resend: () => postStatus(to, withReason),
        });

        router.post(
            urls.status,
            withReason ? { to, reason } : { to },
            {
                preserveScroll: true,
                onFinish: () => {
                    setPosting(false);
                    setReason('');
                    setReasonFor(null);
                    setConfirmFor(null);
                    setGateOpen(false);
                },
                onSuccess: (page) => {
                    allowLeave.current = true;

                    const next = page.props;

                    // A REFUSED TRANSITION ANSWERS WITH A 302 AND A FLASH ERROR
                    // (`ScenarioGovernanceController` does `back()->with('error',
                    // $e->flash())`), which Inertia reports through `onSuccess`
                    // exactly like a committed move. So the tone is read from the
                    // TRUTH IN THE RELOADED PROPS and the sentence from the
                    // server's own flash — never from which callback fired, and
                    // never by humanising `to` on the client (spec 01 §0.2
                    // "Lifecycle refusal", §6.4).
                    const refusal = next.flash?.error || null;
                    const moved = next.scenario?.status === to;

                    if (moved && !refusal) {
                        // `ScenarioLifecycle::successMessage()` is the ONE place
                        // this edge's wording lives. The client composes no
                        // sentence of its own, so a non-JS response and this one
                        // cannot disagree about what just happened.
                        setAnnouncement({
                            tone: 'success',
                            text: next.flash?.success || 'Status updated.',
                            focus: true,
                        });
                        setLastWrite(null);

                        return;
                    }

                    setLastWrite(null);
                    setAnnouncement({
                        tone: 'refusal',
                        text:
                            refusal ||
                            `${scenario.reference} is still ${next.scenario?.status_label}. The status did not change.`,
                        focus: true,
                    });
                },
                // A 422 is an ordinary Inertia response and never reaches
                // `useVisitFailures`; the field errors render where they belong.
                // A LOST RESPONSE is handled by the `failure` effect below —
                // there is no `onException` visit callback in this Inertia
                // version, so nothing here can see one.
                onError: () => setLastWrite(null),
            },
        );
    };

    /**
     * Spec 01 §6.4's "Check what happened": reload the truth, never re-post.
     * Only the props the lost write could have moved are asked for (§0.4 — a
     * screen on 100 kbps does not re-download the workspace to answer one
     * question).
     */
    const checkWhatHappened = (kind = lostResponse?.kind || lastWrite?.kind) => {
        router.reload({
            only:
                kind === 'notes'
                    ? ['notes', 'notesMeta', 'signoffWarnings', 'scenario']
                    : ['scenario', 'transitions', 'gate', 'signoffs'],
        });
    };

    /**
     * Spec 01 §6.3's last resort, inside the session-expired and 403 states:
     * copy what this screen was about to write, because it cannot be saved.
     */
    const copyEntries = async () => {
        const text = lastWrite?.copy;

        if (!text) {
            return;
        }

        try {
            await navigator.clipboard.writeText(text);
            setAnnouncement({ tone: 'success', text: 'Your entries were copied.' });
        } catch {
            setAnnouncement({
                tone: 'refusal',
                text: 'Could not copy automatically. Select the text on the page and copy it.',
            });
        }
    };

    /** Re-send the write a 419 interrupted — the only retry §6.4 permits. */
    const resendLastWrite = () => {
        clearFailure();
        lastWrite?.resend?.();
    };

    /**
     * The notes log's own write path (`POST urls.notes`, spec 02 §7.1). Hoisted
     * out of the JSX so a 419 can re-send the identical request.
     *
     * `after` resolves the NotesLog draft EXACTLY ONCE and carries the outcome:
     * the paths disagree about what happened, so the first one to know wins. The
     * draft clears on GENUINE SUCCESS ONLY — `onFinish` is not a success signal,
     * it fires on a 422, a 403 and an exception too, and clearing there destroyed
     * a failed dissent (spec 01 §6.2: "Your entries are still on this page").
     */
    const postNote = (payload, after) => {
        allowLeave.current = true;
        clearFailure();
        setLostResponse(null);
        setLastWrite({
            kind: 'notes',
            copy: `Kind: ${payload?.kind}\n\n${payload?.body}`,
            resend: () => postNote(payload, after),
        });

        const before = (notes || []).length;
        let settled = false;

        const settle = (result) => {
            if (settled) {
                return;
            }

            settled = true;
            after?.(result);
        };

        router.post(urls.notes, payload, {
            // Only the log reloads: the rest of the workspace is untouched by a
            // note (spec 02 §7.1).
            preserveScroll: true,
            preserveState: true,
            only: ['notes', 'notesMeta', 'signoffWarnings'],
            onSuccess: (page) => {
                const next = page.props;
                const refusal = next.flash?.error || null;

                setLastWrite(null);

                // An archived scenario answers `POST notes` with a 302 and a
                // flash error, which Inertia reports right here in `onSuccess` —
                // so "the log grew" is part of the test, not a nicety.
                if (!refusal && (next.notes || []).length > before) {
                    settle({ ok: true });
                    setAnnouncement({ tone: 'success', text: next.flash?.success || 'Note added.' });

                    return;
                }

                settle({ ok: false });
                setAnnouncement({ tone: 'refusal', text: refusal || 'The note was not added.' });
            },
            onError: () => {
                // A 422: the field errors render in the panel and the typed
                // note stays exactly where the user left it.
                setLastWrite(null);
                settle({ ok: false });
            },
            // No `onException` — this Inertia version has no such callback, so a
            // lost response reaches the `failure` effect above, which sets
            // `lostResponse` and reloads the log. `onFinish` still resolves the
            // draft, and §6.4 keeps it: append-only with no unique key means a
            // blind re-post is a duplicate note.
            onFinish: () => {
                // The safety net: whatever happened, the form stops showing
                // "Adding…". A path that already resolved is not overridden, so
                // a success never has its draft put back.
                settle({ ok: false });
                allowLeave.current = false;
            },
        });
    };

    const onTransition = (transition) => {
        // `opens_modal` is the SERVER'S decision, not the client's: the shell has
        // no state machine and does not infer which edges need a dialog.
        if (transition.opens_modal === 'sign_off') {
            setSignoffOpen(true);
            return;
        }

        if (transition.opens_modal === 'reason') {
            setReason('');
            setReasonFor(transition);

            // Focus the textarea once the modal has rendered it.
            window.setTimeout(() => reasonInputRef.current?.focus(), 0);
            return;
        }

        // The `in_design → designed` edge opens the GATE PRE-FLIGHT modal rather
        // than posting: spec 01 §5.4 puts the readiness check in front of the
        // transition, and the server's own 422 is the backstop for a stale page.
        if (transition.to === 'designed' && gate) {
            setGateOpen(true);
            return;
        }

        if (transition.confirm) {
            setConfirmFor(transition);
            return;
        }

        postStatus(transition.to, false);
    };

    const subtitle = useMemo(
        () =>
            [scenario.nature_label, scenario.op_event_type?.name, scenario.business_unit?.name]
                .filter(Boolean)
                .join(' · '),
        [scenario.nature_label, scenario.op_event_type, scenario.business_unit],
    );

    const gateMissing = gate?.missing || [];
    const lastChanged = formatLagosClock(scenario.updated_at);
    const previousSignoffDay = lagosDay(scenario.previous_signoff?.signed_at);

    return (
        <AppLayout title={scenario.name}>
            <Head title={`${scenario.reference} · ${scenario.name}`} />

            <PageHeader
                title={scenario.name}
                subtitle={subtitle}
                breadcrumbs={[{ label: 'Scenario modelling', href: urls.index }, { label: scenario.reference }]}
                actions={
                    // A screen-reader user can jump to the lifecycle controls as one
                    // unit (spec 01 §7).
                    <div role="group" aria-label="Lifecycle actions" className="flex flex-wrap items-center gap-2">
                        {urls.deck && (
                            <Link
                                href={urls.deck}
                                className="btn-secondary text-sm inline-flex items-center gap-1.5"
                                title="Open Executive Presentation Deck"
                            >
                                <Icon name="presentation" className="h-4 w-4" />
                                <span>Presentation Deck</span>
                            </Link>
                        )}
                        {transitionList.map((transition) => {
                            const blockedByGate = transition.to === 'designed' && gate && !gate.ready;

                            return (
                                <button
                                    key={transition.to}
                                    type="button"
                                    onClick={() => onTransition(transition)}
                                    disabled={posting}
                                    // `aria-disabled`, NOT `disabled`, so a keyboard
                                    // user is not left facing a dead control with no
                                    // reason: activating it opens the gate modal,
                                    // which explains and links to the missing fields
                                    // (spec 01 §5.4, the `Kri/MyReturn` submit rule).
                                    aria-disabled={blockedByGate || undefined}
                                    className={`${VARIANT_CLASSES[transition.variant] || 'btn-secondary'} text-sm`}
                                >
                                    {transition.label}
                                </button>
                            );
                        })}
                    </div>
                }
            />

            <div className="space-y-3">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <ScenarioStatusChip status={scenario.status} label={scenario.status_label} />

                        {/* The "previously signed off" marker is the ONLY place the
                            earlier sign-off survives on screen, and it must
                            survive: a validator re-reading a reopened scenario
                            needs to know it was once approved (spec 01 §2). */}
                        {scenario.previous_signoff && (
                            <p className="mt-1 text-xs text-gray-600">
                                Signed off
                                {/* `signed_at` is an instant, so its LAGOS day is
                                    resolved before `formatDay`: slicing the ISO
                                    string takes the UTC day, which is a day
                                    early for a sign-off after 23:00 Lagos. */}
                                {previousSignoffDay ? ` ${formatDay(previousSignoffDay)}` : ''}{' '}
                                by {scenario.previous_signoff.sources.join(', ')}; reopened for revalidation.
                            </p>
                        )}

                        {/* Last-write-wins is accepted for Phase 1 (spec 01 §9.2).
                            This line does not prevent a collision, it makes one
                            visible afterwards — both values are already in the
                            payload. */}
                        {scenario.updated_by && lastChanged && (
                            <p className="mt-1 text-xs text-gray-600">
                                Last changed by {scenario.updated_by.name} at {lastChanged} Lagos time
                            </p>
                        )}
                    </div>

                    <a href="#scenario-panel" className="text-sm text-[var(--color-primary)] underline">
                        Skip to {tabs?.find((t) => t.active)?.label || 'Overview & Governance'}
                    </a>
                </div>

                {/* The overdue callout is `role="note"`, NOT `alert`: it is page
                    content, not a live event (spec 01 §4.3). `days_overdue` is
                    SERVER-COMPUTED in Africa/Lagos against the day boundary — the
                    browser never works out whether a date is past, because
                    `new Date().toISOString()` is yesterday for the first hour of a
                    Lagos day. */}
                {review?.days_overdue > 0 && (
                    <div
                        role="note"
                        className="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"
                    >
                        <Icon name="warning" className="mt-0.5 h-4 w-4 shrink-0" />
                        <span>
                            This scenario was due for review on {formatDay(review.due_on)} — {review.days_overdue}{' '}
                            {review.days_overdue === 1 ? 'day' : 'days'} ago.{' '}
                            {scenario.frozen
                                ? 'A signed-off scenario stays in force until it is reviewed.'
                                : 'Review it and update the next review date.'}
                        </span>
                    </div>
                )}

                {/* A live region for transition results, and the heading focus
                    lands on after any status change (spec 01 §7).

                    `role="status"` ALREADY IMPLIES `aria-live="polite"`, and the
                    old `aria-live={announcement ? 'polite' : 'off'}` toggle was
                    worse than redundant: flipping `off → polite` in the same
                    commit that inserts the text is exactly the mutation a live
                    region has to have happened BEFORE, so some screen readers
                    never announced the sentence at all. The region is permanent
                    and its content is what changes.

                    A REFUSED TRANSITION RENDER HERE TOO, in amber rather than
                    green. It is the answer to the button the user just pressed,
                    it is not transient, and §0.2 puts a lifecycle refusal in a
                    `role="status"` banner — a refusal that only ever reached a
                    five-second toast left the persistent region asserting the
                    opposite of the truth. */}
                <div ref={statusHeading} tabIndex={-1} role="status" className="focus:outline-none">
                    {announcement && (
                        <p
                            className={`rounded-lg border px-4 py-3 text-sm ${
                                announcement.tone === 'success'
                                    ? 'border-green-200 bg-green-50 text-green-900'
                                    : 'border-amber-200 bg-amber-50 text-amber-900'
                            }`}
                        >
                            {announcement.text}
                        </p>
                    )}
                </div>

                {/* Spec 01 §6.4: a lost response on `POST status` gets "Check
                    what happened", never a blind retry. The reload has already
                    run, so the status chip and the header buttons above ARE the
                    answer — this banner says to read them rather than offering
                    to fire the same transition again. */}
                {lostResponse && (
                    <div role="alert" className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                        <p>
                            {lostResponse.kind === 'notes'
                                ? "We lost the server's response, so we don't know whether the note was added. The log has been refreshed — check it before adding the note again."
                                : "We lost the server's response, so we don't know whether the status moved. The current status above has been refreshed — check it before doing anything else."}
                        </p>
                        <div className="mt-2 flex flex-wrap items-center gap-3">
                            <button type="button" onClick={() => checkWhatHappened()} className="btn-secondary text-sm">
                                Check what happened
                            </button>
                            <button
                                type="button"
                                onClick={() => {
                                    setLostResponse(null);
                                    setLastWrite(null);
                                }}
                                className="text-sm font-medium text-red-800 underline"
                            >
                                Dismiss
                            </button>
                        </div>
                    </div>
                )}

                {/* Spec 01 §0.2's five shared failure rows, through the repo's
                    sanctioned hook. Rendered only for a write THIS shell issued,
                    so a tab's own save failure is announced by the tab and never
                    twice. The control named is the control rendered. */}
                {/* Suppressed while `lostResponse` is set: a lost response has its
                    own banner above with the §6.4 reload-first control, and two
                    red banners for one failure would say two different things. */}
                {failure && lastWrite && !lostResponse && (
                    <FailureBanner
                        failure={
                            lastWrite.kind === 'notes' && failure.kind === 'forbidden'
                                ? {
                                      ...failure,
                                      message:
                                          'You no longer have permission to add notes to this scenario. Your note is still on this page.',
                                  }
                                : failure.kind === 'forbidden'
                                  ? {
                                        ...failure,
                                        message:
                                            'You no longer have permission to change this scenario. Your entries are still on this page.',
                                    }
                                  : failure
                        }
                        // A `POST` is append-only here, so the affordance is
                        // reload-first rather than "Try again" (§6.4). A 419 is
                        // the one exception §6.4 allows to re-send: the auth
                        // middleware rejects before the write path.
                        onRetry={
                            failure.kind === 'session'
                                ? () => resendLastWrite()
                                : failure.kind === 'network' || failure.kind === 'server'
                                  ? () => checkWhatHappened()
                                  : undefined
                        }
                        // `undefined` leaves the banner's own label, "Retry" —
                        // the only case where re-sending is the truth.
                        retryLabel={failure.kind === 'session' ? undefined : 'Check what happened'}
                        onDismiss={() => {
                            clearFailure();
                            setLastWrite(null);
                        }}
                    >
                        {(failure.kind === 'session' || failure.kind === 'forbidden') && lastWrite.copy && (
                            <button
                                type="button"
                                onClick={copyEntries}
                                className="text-sm font-medium text-[#1A365D] underline"
                            >
                                Copy my entries
                            </button>
                        )}
                    </FailureBanner>
                )}

                {scenario.frozen && <FrozenBanner scenario={scenario} signoffs={signoffs} onTransition={onTransition} transitionList={transitionList} />}

                {!scenario.frozen && ['in_validation', 'validated'].includes(scenario.status) && (
                    <p
                        role="note"
                        className="rounded-lg border border-yellow-200 bg-yellow-50 px-4 py-3 text-sm text-yellow-900"
                    >
                        {scenario.status === 'in_validation'
                            ? "In validation. You can't change this scenario until it is validated or returned for rework."
                            : "Validated. You can't change this scenario until it is signed off or returned for rework."}
                    </p>
                )}

                {/* The readiness strip is NOT a completeness score. Four
                    requirements, each met or not: a percentage over four fields
                    implies they carry equal weight, which the frozen guard denies
                    (spec 01 §5.4). */}
                {gate && !gate.ready && (
                    <div role="note" className="rounded-lg border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-gray-700">
                        {/* ONE SENTENCE, the strip's own. "Ready to submit — ask
                            a risk manager to move it forward" belonged to the
                            READY-but-no-permission case below and contradicted
                            this one in the same `<p>`: a designer three fields
                            short was told the scenario was both not ready and
                            ready to submit (spec 01 §5.4, §3). */}
                        <p>
                            Not ready to mark designed — {gateMissing.length} required{' '}
                            {gateMissing.length === 1 ? 'field' : 'fields'} still missing.
                        </p>
                        <details className="mt-1">
                            <summary className="cursor-pointer font-semibold text-[var(--color-primary)]">
                                What is missing
                            </summary>
                            <ul className="mt-1 list-inside list-disc">
                                {gateMissing.map((item) => (
                                    <li key={`${item.tab}-${item.field}`}>
                                        <a
                                            href={tabUrl(tabs, item.tab, `field-${item.field}`)}
                                            className="underline"
                                            onClick={(event) => navigateToField(event, tabs, item.tab, item.field, tab)}
                                        >
                                            {item.label} ({tabLabel(tabs, item.tab)})
                                        </a>
                                    </li>
                                ))}
                            </ul>
                        </details>
                    </div>
                )}

                {/* A designer holding `scenario.update` alone can fill in every
                    field and CANNOT advance the scenario out of `draft` — every
                    lifecycle move needs `scenario.validate` (§A1.10). Silence here
                    would read as a broken button, so the strip says so in words. */}
                {gate?.ready && !can?.validate && editable && (
                    <p role="note" className="rounded-lg border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-gray-700">
                        Ready to submit — ask a risk manager to move it forward.
                    </p>
                )}

                {roadmapNote && (
                    <div role="note" className="rounded-lg border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-gray-700">
                        {roadmapNote}
                    </div>
                )}

                {/* Links with `aria-current="page"`, NOT `role="tab"`: a tablist
                    promises that activating a tab does not navigate, and these do
                    — the URL is the point (spec 01 §7). */}
                <nav aria-label="Scenario sections" className="border-b border-gray-200">
                    <ul className="-mb-px flex flex-wrap gap-1">
                        {(tabs || []).map((entry) => {
                            const current = entry.key === tab;

                            return (
                                <li key={entry.key}>
                                    <Link
                                        href={entry.url}
                                        aria-current={current ? 'page' : undefined}
                                        className={`inline-block rounded-t-lg px-4 py-2 text-sm font-semibold transition-colors ${
                                            current
                                                ? 'border-b-2 border-[var(--color-primary)] text-[var(--color-primary)]'
                                                : 'text-gray-600 hover:text-gray-900'
                                        }`}
                                    >
                                        {entry.label}
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                </nav>

                {/* A `div`, NOT a second `<main>`: `AuthenticatedLayout` already
                    renders the page's one `main` landmark, and two of them leave a
                    screen-reader user with two "main content" entries to choose
                    between (spec 01 §7: "`<main>` from the layout"). */}
                <div id="scenario-panel" className="scroll-mt-24">
                    {tab === 'qualitative' ? (
                        <QualitativeTab
                            scenario={scenario}
                            qualitative={qualitative}
                            qualitativeSummary={qualitativeSummary}
                            errors={errors}
                            can={can}
                            urls={urls}
                            notes={notes}
                            notesMeta={notesMeta}
                            saveRef={saveRef}
                            onDirtyChange={markDirty}
                        />
                    ) : tab === 'scorecard' ? (
                        <ScorecardTab
                            scenario={scenario}
                            scorecard={scorecard}
                            can={can}
                            urls={urls}
                            onDirtyChange={markDirty}
                        />
                    ) : tab === 'frequency' ? (
                        <FrequencyTab
                            scenario={scenario}
                            frequency={frequency}
                            can={can}
                            urls={urls}
                            onDirtyChange={markDirty}
                        />
                    ) : tab === 'impact' ? (
                        <ImpactTab
                            scenario={scenario}
                            impact={impact}
                            can={can}
                            urls={urls}
                            onDirtyChange={markDirty}
                        />
                    ) : tab === 'insurance' ? (
                        <InsuranceTab
                            scenario={scenario}
                            insurance={insurance}
                            can={can}
                            urls={urls}
                            onDirtyChange={markDirty}
                        />
                    ) : tab === 'variants' ? (
                        <VariantsTab
                            scenario={scenario}
                            variants={variants}
                            can={can}
                            urls={urls}
                            onDirtyChange={markDirty}
                        />
                    ) : tab === 'actions' ? (
                        <ActionsTab
                            scenario={scenario}
                            actions={actions}
                            can={can}
                            urls={urls}
                            onDirtyChange={markDirty}
                        />
                    ) : (
                        <OverviewTab
                            scenario={scenario}
                            values={values}
                            errors={errors}
                            can={can}
                            gate={gate}
                            transitions={transitionList}
                            urls={urls}
                            baseCurrency={props.baseCurrency}
                            businessUnits={props.businessUnits}
                            opEventTypes={props.opEventTypes}
                            natures={props.natures}
                            weightings={props.weightings}
                            currencies={props.currencies}
                            fxRates={props.fxRates}
                            signoffs={signoffs}
                            signoffSources={signoffSources}
                            notes={notes}
                            notesMeta={notesMeta}
                            noteKinds={noteKinds}
                            saveRef={saveRef}
                            onDirtyChange={markDirty}
                            onSaved={() => {
                                markDirty(false);
                                allowLeave.current = true;
                                // `focus: false` — spec 01 §6.1 keeps focus on
                                // Save after a successful save.
                                setAnnouncement({ tone: 'success', text: 'Saved.' });
                                window.setTimeout(() => {
                                    allowLeave.current = false;
                                }, 0);
                            }}
                            onNotesPosted={postNote}
                            onLoadEarlierNotes={(earlier) => {
                                allowLeave.current = true;

                                router.get(earlier, {}, {
                                    preserveScroll: true,
                                    preserveState: true,
                                    only: ['notes', 'notesMeta', 'urls'],
                                    onFinish: () => {
                                        allowLeave.current = false;
                                    },
                                });
                            }}
                        />
                    )}
                </div>
            </div>

            {/* ── The reason modal (spec 01 §5.3) ───────────────────────────── */}
            <Modal show={Boolean(reasonFor)} maxWidth="lg" onClose={() => !posting && setReasonFor(null)}>
                <div className="p-6">
                    <h2 className="text-lg font-semibold text-gray-900">
                        Return {scenario.reference} for rework?
                    </h2>
                    <div className="mt-3">
                        <label htmlFor="return-reason" className="form-label">
                            What has to change?
                            <span className="ml-0.5 text-red-600" aria-hidden="true">
                                *
                            </span>
                            <span className="sr-only"> (required)</span>
                        </label>
                        <textarea
                            id="return-reason"
                            ref={reasonInputRef}
                            rows={4}
                            minLength={10}
                            maxLength={500}
                            className="form-input text-sm"
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                            aria-describedby="return-reason-hint"
                            aria-invalid={errors.reason ? 'true' : undefined}
                        />
                        <p id="return-reason-hint" className="mt-1 text-xs text-gray-600">
                            The designer sees this as the reason the scenario came back, and it is recorded against the
                            scenario permanently.
                        </p>
                        {errors.reason && <p className="mt-1 text-sm text-red-700">{errors.reason}</p>}
                        {!errors.reason && reason.trim() !== '' && reason.trim().length < 10 && (
                            <p className="mt-1 text-sm text-red-700">
                                Say what has to change. A return with no reason gives the designer nothing to work from.
                            </p>
                        )}
                    </div>
                    <div className="mt-5 flex justify-end gap-3">
                        <button type="button" className="btn-secondary text-sm" disabled={posting} onClick={() => setReasonFor(null)}>
                            Cancel
                        </button>
                        <button
                            type="button"
                            className="btn-warning text-sm"
                            disabled={posting || reason.trim().length < 10}
                            aria-busy={posting || undefined}
                            onClick={() => reasonFor && postStatus(reasonFor.to, true)}
                        >
                            {posting ? 'Returning…' : 'Return for rework'}
                        </button>
                    </div>
                </div>
            </Modal>

            {/* ── The gate pre-flight (spec 01 §5.4) ────────────────────────── */}
            <Modal show={gateOpen} maxWidth="lg" onClose={() => !posting && setGateOpen(false)}>
                <div className="p-6">
                    <h2 className="text-lg font-semibold text-gray-900">Mark {scenario.reference} as designed?</h2>

                    {gate?.ready ? (
                        <p className="mt-2 text-sm text-gray-700">
                            A validator will check it before it can be submitted for validation.
                        </p>
                    ) : (
                        <>
                            <p className="mt-2 text-sm text-gray-700">
                                {gateMissing.length} required {gateMissing.length === 1 ? 'field is' : 'fields are'}{' '}
                                still missing:
                            </p>
                            <ul className="mt-2 list-inside list-disc text-sm text-gray-700">
                                {gateMissing.map((item) => (
                                    <li key={`${item.tab}-${item.field}`}>
                                        <a
                                            href={tabUrl(tabs, item.tab, `field-${item.field}`)}
                                            className="underline"
                                            onClick={(event) => {
                                                setGateOpen(false);
                                                navigateToField(event, tabs, item.tab, item.field, tab);
                                            }}
                                        >
                                            {item.label} ({tabLabel(tabs, item.tab)})
                                        </a>
                                    </li>
                                ))}
                            </ul>
                        </>
                    )}

                    <div className="mt-5 flex justify-end gap-3">
                        <button type="button" className="btn-secondary text-sm" disabled={posting} onClick={() => setGateOpen(false)}>
                            Cancel
                        </button>
                        <button
                            type="button"
                            className="btn-primary text-sm"
                            disabled={posting}
                            // `aria-disabled` and STILL FOCUSABLE, never
                            // `disabled`: activating it moves focus to the first
                            // missing field, navigating tabs if needed, and says
                            // why (spec 01 §5.4).
                            aria-disabled={gate && !gate.ready ? 'true' : undefined}
                            aria-busy={posting || undefined}
                            onClick={() => {
                                if (gate && !gate.ready) {
                                    const first = gateMissing[0];
                                    setGateOpen(false);

                                    if (first) {
                                        // `refusal`, not `success`: nothing moved,
                                        // and green would say otherwise (spec 01
                                        // §5.4's `Kri/MyReturn` submit rule).
                                        setAnnouncement({
                                            tone: 'refusal',
                                            text: `${first.label} is required before this scenario can be marked designed.`,
                                        });
                                        // `focusField` (inside) keeps re-asserting
                                        // focus until it HOLDS with no dialog in
                                        // the DOM, because closing this Modal
                                        // unmounts Headless UI's `Dialog`, which
                                        // restores focus to this very button
                                        // during React's commit — after the
                                        // synchronous `.focus()` the old code
                                        // relied on.
                                        goToField(tabs, first.tab, first.field, tab);
                                    }

                                    return;
                                }

                                postStatus('designed', false);
                            }}
                        >
                            {posting ? 'Working…' : 'Design complete'}
                        </button>
                    </div>
                </div>
            </Modal>

            {/* ── Plain confirmations ───────────────────────────────────────── */}
            <ConfirmDialog
                show={Boolean(confirmFor)}
                title={`${confirmFor?.label} ${scenario.reference}?`}
                message={confirmFor?.confirm || ''}
                confirmLabel={confirmFor?.label}
                cancelLabel="Cancel"
                variant={confirmFor?.variant === 'danger' ? 'danger' : confirmFor?.variant === 'warning' ? 'warning' : 'primary'}
                processing={posting}
                onConfirm={() => confirmFor && postStatus(confirmFor.to, false)}
                onCancel={() => setConfirmFor(null)}
            />

            {/* ── The two-step sign-off modal ───────────────────────────────── */}
            <SignoffModal
                show={signoffOpen}
                scenario={scenario}
                qualitativeSummary={qualitativeSummary}
                signoffWarnings={signoffWarnings}
                signoffs={signoffs}
                signoffSources={signoffSources}
                signoffDecisions={signoffDecisions}
                suggestedReviewDueOn={suggestedReviewDueOn}
                urls={urls}
                onClose={(moved, message) => {
                    setSignoffOpen(false);

                    if (moved) {
                        allowLeave.current = true;
                        // The modal hands back the SERVER'S sentence, read from
                        // the reloaded props. Deriving it here from
                        // `scenario.status` instead read the shell's PRE-MOVE
                        // prop — this closure is created before the status write
                        // resolves — so it always fell through to the generic
                        // branch and announced "Status updated." for a sign-off
                        // that had just frozen the scenario (spec 01 §5.6,
                        // `ScenarioLifecycle::successMessage()`).
                        setAnnouncement({
                            tone: 'success',
                            text: message || 'Status updated.',
                            focus: true,
                        });
                    }
                }}
            />

            {/* ── The unsaved-changes guard ─────────────────────────────────── */}
            <ConfirmDialog
                show={leaveOpen}
                title="Leave without saving?"
                message="You have unsaved changes. Leave without saving?"
                confirmLabel="Leave"
                cancelLabel="Stay"
                variant="warning"
                onConfirm={leaveAnyway}
                onCancel={() => {
                    setLeaveOpen(false);
                    pendingVisit.current = null;
                }}
            />
        </AppLayout>
    );
}

/**
 * The frozen banner (spec 01 §5.5).
 *
 * Names in the sentence are `signatory_name` SNAPSHOTS, not joined user records,
 * so the banner still reads correctly after a signatory leaves the bank — and only
 * `is_current` rows are named. The "figures do not recompute" sentence is the
 * user-facing half of ADR 0027 D1: Phase 1 has no computed figures on screen, but
 * the sentence is written now because Phase 2 renders into the same banner and the
 * promise must not change.
 */
function FrozenBanner({ scenario, signoffs, onTransition, transitionList }) {
    const current = (signoffs || []).filter((row) => row.is_current);
    const names = current
        .map(
            (row) =>
                `${row.signatory_name} (${row.source_label}, ${row.role}${
                    row.is_independent_challenge ? ' — independent challenge' : ''
                })`,
        )
        .join(', ');

    // The sign-off date comes from the CURRENT rows' own `signed_at` snapshots,
    // never from `validated_on`: validation and sign-off are different acts by
    // different people, and dating one from the other would state a fact the
    // record does not carry (standard §5). The instant is rendered in Lagos and
    // its date part taken, so no client-side timezone shift can move the day.
    const signedAt = current
        .map((row) => row.signed_at)
        .filter(Boolean)
        .sort()
        .pop();
    // Spec 01 §5.5's sentence reads "Signed off on Mon 9 Nov 2026" — `formatDay`'s
    // shape, not `formatLagosInstant`'s "09 Nov 2026". `signed_at` is an INSTANT,
    // so its Lagos calendar day is resolved first: `formatDay` refuses anything
    // that is not `Y-m-d`, and slicing the ISO string would take the UTC day,
    // which is the previous day for a sign-off after 23:00 Lagos.
    const signedDayValue = lagosDay(signedAt);
    const signedDay = signedDayValue ? formatDay(signedDayValue) : null;

    return (
        <div role="note" className="rounded-lg border border-[#1A365D]/20 bg-[#1A365D]/5 px-4 py-3">
            <h2 className="flex items-center gap-1.5 text-sm font-semibold text-[#1A365D]">
                <Icon name="lock" className="h-4 w-4" />
                Signed off — this scenario is frozen.
            </h2>
            {names && (
                <p className="mt-1 text-sm text-gray-700">
                    {signedDay ? `Signed off on ${signedDay} by ` : 'Signed off by '}
                    {names}.
                </p>
            )}
            <p className="mt-1 text-sm text-gray-700">
                Every field on this workspace is read-only, and the figures it carries are the ones recorded at sign-off
                — they do not recompute. To change anything, reopen it for revalidation; it then travels design →
                validation → sign-off again. It is not eligible for capital quantification while it is open.
            </p>
            {/* The two ways out of `signed_off` are DIFFERENT ACTS and the banner
                offers both: one makes the scenario editable, the other keeps it
                read-only and puts it in front of a validator. Each renders only
                when `transitions` carries it, because the client holds no state
                machine. */}
            <div className="mt-3 flex flex-wrap gap-2">
                {transitionList
                    .filter((transition) => ['returned_for_rework', 'in_validation'].includes(transition.to))
                    .map((transition) => (
                        <button
                            key={transition.to}
                            type="button"
                            onClick={() => onTransition(transition)}
                            className={`${VARIANT_CLASSES[transition.variant] || 'btn-secondary'} text-sm`}
                        >
                            {transition.label}
                        </button>
                    ))}
            </div>
        </div>
    );
}

/** The `?tab=…#field-…` URL for a gate item that lives on the other tab. */
function tabUrl(tabs, key, anchor) {
    const entry = (tabs || []).find((candidate) => candidate.key === key);

    if (!entry) {
        return `#field-${anchor.replace('field-', '')}`;
    }

    return `${entry.url}#${anchor}`;
}

function tabLabel(tabs, key) {
    return (tabs || []).find((candidate) => candidate.key === key)?.label || key;
}

/** Intercept a gate link so the focus move happens after the tab has rendered. */
function navigateToField(event, tabs, tabKey, field, currentTab) {
    // ALWAYS an in-page move. `tabUrl` deliberately returns an absolute
    // `?tab=…#field-…` URL so the href is real for a middle-click, "open in new
    // tab" and a no-JS browser — which means the old `startsWith('#')` test NEVER
    // matched, so a link to a field on the tab the user was already on fell
    // through to a full page navigation: the browser scrolled to the anchor,
    // `goToField` never ran, and focus landed on `<body>` (spec 01 §5.4 requires
    // the link to "move focus to the field", §7 that focus never falls to body).
    event.preventDefault();
    goToField(tabs, tabKey, field, currentTab);
}

/**
 * Move focus to `#field-{code}`, switching tabs first when the field lives on the
 * other one. The `gate.missing[].field` values are the frozen column names and
 * panel anchors, so the shell builds `#field-{field}` from them and cannot resolve
 * anything else (spec 01 §5.4).
 *
 * `currentTab` is the shell's own `tab` PROP. The previous version inferred
 * "am I already on that tab?" from `window.location.search`, which is a guess
 * about the presenter's URL shape; the prop is the fact.
 */
function goToField(tabs, tabKey, field, currentTab) {
    const entry = (tabs || []).find((candidate) => candidate.key === tabKey);

    if (entry && currentTab && tabKey !== currentTab) {
        // Cross-tab ONLY is a real visit. Focus is driven from `onFinish`, and
        // `focusField` then waits for the new tab's DOM instead of trusting a
        // `setTimeout(…, 0)` to land after React has committed it.
        router.get(entry.url, {}, { preserveScroll: true, onFinish: () => focusField(field) });

        return;
    }

    focusField(field);
}

/**
 * Focus `#field-{code}` AND KEEP FOCUS THERE.
 *
 * A single synchronous `.focus()` loses on this screen, for two separate reasons:
 *
 * 1. Headless UI's `Dialog` restores focus to its trigger when it UNMOUNTS. The
 *    repo's `Modal` (`packages/ui/src/Components/Modal.jsx`) returns `null` once
 *    `show` is false and wraps no `<Transition>` — deliberately, its docblock
 *    records that the transition never reaches its end state on Headless UI 2.2 +
 *    Tailwind 4 — so there is no `afterLeave` hook to wait for, and the Modal is
 *    shared and outside this module, so it is not ours to change. The restore
 *    happens during React's commit for the `setGateOpen(false)` render, i.e.
 *    AFTER the click handler that already called `.focus()`.
 * 2. On a cross-tab visit the target element does not exist yet when `onFinish`
 *    fires.
 *
 * So this re-asserts focus across animation frames and stops only once the field
 * has HELD focus for a frame with no dialog left in the DOM (`Modal` renders
 * `id="modal"`). That is a convergence check reading `document.activeElement`,
 * not a guessed delay, and it is hard-capped so a field code the server invents
 * cannot loop.
 */
function focusField(field, maxFrames = 24) {
    let scrolled = false;
    let held = 0;
    let frames = 0;

    const step = () => {
        frames += 1;

        const element = document.getElementById(`field-${field}`);

        if (element) {
            if (!scrolled) {
                element.scrollIntoView({ block: 'center' });
                scrolled = true;
            }

            if (!element.contains(document.activeElement)) {
                const control = element.querySelector('input, select, textarea, [tabindex]') || element;
                control.focus({ preventScroll: true });
            }

            // "We focused it once" is not enough — the Dialog's restore fires
            // after us. Wait until it has held, with no dialog still mounted.
            if (element.contains(document.activeElement) && !document.getElementById('modal')) {
                held += 1;

                if (held >= 2) {
                    return;
                }
            } else {
                held = 0;
            }
        }

        if (frames < maxFrames) {
            window.requestAnimationFrame(step);
        }
    };

    step();
}
