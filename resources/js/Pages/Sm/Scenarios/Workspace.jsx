import { useEffect, useMemo, useRef, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import ConfirmDialog from '@thirdline/ui/Components/ConfirmDialog';
import Icon from '@thirdline/ui/Components/Icon';
import Modal from '@thirdline/ui/Components/Modal';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import ScenarioStatusChip from '@/Components/Sm/ScenarioStatusChip';
import SignoffModal from '@/Components/Sm/SignoffModal';
import { formatDay, formatLagosClock, formatLagosInstant } from '@/Components/Sm/format';
import OverviewTab from './OverviewTab';
import QualitativeTab from './QualitativeTab';

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
        notes,
        notesMeta,
        noteKinds,
        urls,
        values,
        qualitative,
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
    const [announcement, setAnnouncement] = useState(null);

    const pendingVisit = useRef(null);
    const allowLeave = useRef(false);
    const statusHeading = useRef(null);
    const saveRef = useRef(null);
    const dirtyRef = useRef(false);
    const reasonInputRef = useRef(null);

    const transitionList = transitions || [];
    const editable = Boolean(can?.update);

    // ── Unsaved-changes guard (spec 01 §6.3) ──────────────────────────────
    // A tab switch is a navigation, so the same guard covers the tab strip, the
    // breadcrumbs, the nav, Back and refresh. The only navigation that bypasses it
    // is the post-save redirect, which sets `allowLeave` first.
    useEffect(() => {
        const cancel = router.on('before', (event) => {
            if (allowLeave.current || !dirtyRef.current || posting) {
                return;
            }

            event.preventDefault();
            pendingVisit.current = event.detail.visit;
            setLeaveOpen(true);
        });

        return cancel;
    }, [posting]);

    useEffect(() => {
        if (!dirtyRef.current) {
            return undefined;
        }

        const handler = (event) => {
            event.preventDefault();
            event.returnValue = '';
        };

        window.addEventListener('beforeunload', handler);

        return () => window.removeEventListener('beforeunload', handler);
    });

    // After any transition, focus moves to the status banner heading, never to
    // `body` (spec 01 §7).
    useEffect(() => {
        if (announcement) {
            statusHeading.current?.focus();
        }
    }, [announcement]);

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
                onSuccess: () => {
                    allowLeave.current = true;
                    setAnnouncement(`Status updated. ${scenario.reference} is now ${to.replace(/_/g, ' ')}.`);
                },
                onException: () => {
                    // Learn the truth before offering a retry.
                    router.reload({ only: ['scenario', 'transitions', 'gate', 'signoffs'] });
                },
            },
        );
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
                    <div role="group" aria-label="Lifecycle actions" className="flex flex-wrap gap-2">
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
                                {scenario.previous_signoff.signed_at
                                    ? ` ${formatDay(String(scenario.previous_signoff.signed_at).slice(0, 10))}`
                                    : ''}{' '}
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
                        Skip to {tab === 'qualitative' ? 'Qualitative' : 'Overview & Governance'}
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
                    lands on after any status change (spec 01 §7). */}
                <div
                    ref={statusHeading}
                    tabIndex={-1}
                    role="status"
                    className="focus:outline-none"
                    aria-live={announcement ? 'polite' : 'off'}
                >
                    {announcement && (
                        <p className="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-900">
                            {announcement}
                        </p>
                    )}
                </div>

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
                        <p>
                            Not ready to mark designed — {gateMissing.length} required{' '}
                            {gateMissing.length === 1 ? 'field' : 'fields'} still missing.
                            {!can?.validate && ' Ready to submit — ask a risk manager to move it forward.'}
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
                                            onClick={(event) => navigateToField(event, tabs, item.tab, item.field)}
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

                <main id="scenario-panel" className="scroll-mt-24">
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
                            onDirtyChange={(dirty) => {
                                dirtyRef.current = dirty;
                            }}
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
                            notes={notes}
                            notesMeta={notesMeta}
                            noteKinds={noteKinds}
                            saveRef={saveRef}
                            onDirtyChange={(dirty) => {
                                dirtyRef.current = dirty;
                            }}
                            onSaved={() => {
                                dirtyRef.current = false;
                                allowLeave.current = true;
                                setAnnouncement('Saved.');
                                window.setTimeout(() => {
                                    allowLeave.current = false;
                                }, 0);
                            }}
                            onNotesPosted={(payload, after) => {
                                allowLeave.current = true;

                                router.post(urls.notes, payload, {
                                    // Only the log reloads: the rest of the
                                    // workspace is untouched by a note (spec 02 §7.1).
                                    preserveScroll: true,
                                    preserveState: true,
                                    only: ['notes', 'notesMeta', 'signoffWarnings'],
                                    onFinish: () => {
                                        after?.();
                                        allowLeave.current = false;
                                    },
                                });
                            }}
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
                </main>
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
                                                navigateToField(event, tabs, item.tab, item.field);
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
                                        setAnnouncement(`${first.label} is required before this scenario can be marked designed.`);
                                        goToField(tabs, first.tab, first.field);
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
                suggestedReviewDueOn={suggestedReviewDueOn}
                urls={urls}
                onClose={(moved) => {
                    setSignoffOpen(false);

                    if (moved) {
                        allowLeave.current = true;
                        setAnnouncement(
                            scenario.status === 'signed_off'
                                ? 'Signed off. This scenario is now frozen.'
                                : 'Status updated.',
                        );
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

    return (
        <div role="note" className="rounded-lg border border-[#1A365D]/20 bg-[#1A365D]/5 px-4 py-3">
            <h2 className="flex items-center gap-1.5 text-sm font-semibold text-[#1A365D]">
                <Icon name="lock" className="h-4 w-4" />
                Signed off — this scenario is frozen.
            </h2>
            {names && (
                <p className="mt-1 text-sm text-gray-700">
                    {scenario.validated_on ? `Signed off on ${formatDay(scenario.validated_on)} by ` : 'Signed off by '}
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
function navigateToField(event, tabs, tabKey, field) {
    if (tabUrl(tabs, tabKey, `field-${field}`).startsWith('#')) {
        // Already on this tab: let the browser handle the anchor, then focus.
        event.preventDefault();
        goToField(tabs, tabKey, field);
    }
}

/**
 * Move focus to `#field-{code}`, switching tabs first when the field lives on the
 * other one. The `gate.missing[].field` values are the frozen column names and
 * panel anchors, so the shell builds `#field-{field}` from them and cannot resolve
 * anything else (spec 01 §5.4).
 */
function goToField(tabs, tabKey, field) {
    const focus = () => {
        const element = document.getElementById(`field-${field}`);

        if (element) {
            element.scrollIntoView({ block: 'center' });
            const control = element.querySelector('input, select, textarea, [tabindex]') || element;
            control.focus({ preventScroll: true });
        }
    };

    const entry = (tabs || []).find((candidate) => candidate.key === tabKey);
    const onTab = window.location.search.includes(`tab=${tabKey}`) || (tabKey === 'overview' && !window.location.search.includes('tab='));

    if (onTab || !entry) {
        focus();
        return;
    }

    router.get(entry.url, {}, { preserveScroll: true, onFinish: () => window.setTimeout(focus, 0) });
}
