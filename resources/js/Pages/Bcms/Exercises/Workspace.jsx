import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import FormField from '@thirdline/ui/Components/FormField';
import EvidenceCapture from '@/Components/Bcms/EvidenceCapture';

/**
 * The exercise execution workspace (execution-workspace spec).
 *
 * THIS SCREEN ALWAYS SHOWS "THIS IS AN EXERCISE." Nothing that reaches this
 * route is a live continuity activation — a real one runs through
 * `Plans/Show.jsx`'s plan-activation flow instead. This is stated to the
 * frontend engineer as a rule, not a per-record flag to compute (spec §2.1),
 * which is why the banner below does not gate on anything from the server
 * beyond the fact that this page rendered at all.
 *
 * THE POLL FETCHES ONE SMALL JSON DOCUMENT, NEVER A FULL PAGE, and stops the
 * moment the exercise is over — matching `CallTrees/Live.jsx` and
 * `Emns/Alert.jsx` exactly, for the same 100 kbps reason (spec §6).
 */
const ENTRY_TYPES = {
    system: { label: 'System', cls: 'bg-slate-100 text-slate-700' },
    manual: { label: 'Manual', cls: 'bg-blue-100 text-blue-800' },
    inject: { label: 'Inject', cls: 'bg-violet-100 text-violet-800' },
    decision: { label: 'Decision', cls: 'bg-slate-200 text-slate-800' },
    milestone: { label: 'Milestone', cls: 'bg-amber-100 text-amber-900' },
};

export default function Workspace({
    occurrence = {}, is_simulation: isSimulation = true, ladder_warnings: ladderWarnings = [],
    readiness_overrides: readinessOverrides = [], channels_are_mocked: channelsAreMocked = false,
    metrics = {}, timeline = [], injects = [], attendance = {}, evidence = [],
    options = {}, can = {}, urls = {},
}) {
    const { flash } = usePage().props;
    const [state, setState] = useState({ occurrence, metrics, attendance, timeline });
    const [lastUpdated, setLastUpdated] = useState(0);
    const [pollFailing, setPollFailing] = useState(false);
    const [confirmingEnd, setConfirmingEnd] = useState(false);

    // GAP 2 — inject authoring. `authoring` is either `null`, the string
    // `'new'`, or the inject object being edited; `injectForm` is reused for
    // both because the fields are identical (create/edit are the same shape,
    // per `StoreExerciseInjectRequest`/`UpdateExerciseInjectRequest`).
    const [authoring, setAuthoring] = useState(null);
    const injectForm = useForm({ title: '', content: '', release_offset_minutes: '', delivery_channel: '' });
    const channelOptions = options.inject_delivery_channels ?? [];

    const startNewInject = () => {
        injectForm.reset();
        injectForm.clearErrors();
        setAuthoring('new');
    };

    const startEditInject = (inject) => {
        injectForm.setData({
            title: inject.title ?? '',
            content: inject.content ?? '',
            release_offset_minutes: inject.release_offset_minutes ?? '',
            delivery_channel: inject.delivery_channel ?? '',
        });
        injectForm.clearErrors();
        setAuthoring(inject);
    };

    const closeInjectForm = () => {
        setAuthoring(null);
        injectForm.reset();
        injectForm.clearErrors();
    };

    // The two-submit-button trap does not apply here (one submit action per
    // form instance), but the payload still needs shaping before it goes on
    // the wire — `transform()` right before `post()`/`patch()`, not `setData`
    // followed by a submit in the same handler, matching `Rcsa/Worksheet.jsx`.
    const submitInject = (e) => {
        e.preventDefault();

        injectForm.transform((data) => ({
            title: data.title,
            content: data.content === '' ? null : data.content,
            release_offset_minutes: data.release_offset_minutes === '' ? null : Number(data.release_offset_minutes),
            delivery_channel: data.delivery_channel === '' ? null : data.delivery_channel,
        }));

        const visitOptions = { preserveScroll: true, onSuccess: () => closeInjectForm() };

        if (authoring === 'new') {
            injectForm.post(urls.injects_store, visitOptions);
        } else {
            injectForm.patch(authoring.update_url, visitOptions);
        }
    };

    const deleteInject = (inject) => {
        if (window.confirm(`Remove the inject "${inject.title}"? This cannot be undone.`)) {
            router.delete(inject.delete_url, { preserveScroll: true });
        }
    };

    const moveInject = (index, direction) => {
        const ids = injects.map((i) => i.id);
        const target = index + direction;
        if (target < 0 || target >= ids.length) return;
        [ids[index], ids[target]] = [ids[target], ids[index]];
        router.post(urls.injects_reorder, { inject_ids: ids }, { preserveScroll: true });
    };

    const channelLabel = (value) => channelOptions.find((c) => c.value === value)?.label ?? value;

    const completed = state.occurrence.actual_end != null;

    // The 5-second poll (spec §6) — one small JSON document, stops on completion.
    useEffect(() => {
        if (completed || !urls.live_metrics) return undefined;

        const id = setInterval(() => {
            fetch(urls.live_metrics, { headers: { Accept: 'application/json' } })
                .then((r) => (r.ok ? r.json() : Promise.reject()))
                .then((data) => {
                    setPollFailing(false);
                    setLastUpdated(0);
                    setState((prev) => ({
                        occurrence: { ...prev.occurrence, status: data.status, actual_start: data.actual_start, actual_end: data.actual_end },
                        metrics: { ...prev.metrics, decisions_logged: data.decisions_logged },
                        attendance: { ...prev.attendance, expected: data.attendance.expected, checked_in: data.attendance.checked_in },
                        timeline: data.timeline,
                    }));
                })
                .catch(() => setPollFailing(true));
        }, 5000);

        return () => clearInterval(id);
    }, [completed, urls.live_metrics]);

    // Elapsed-time-since-last-poll ticker, purely cosmetic (spec §6's "last
    // updated {n}s ago" note) — not a second data source.
    useEffect(() => {
        if (completed) return undefined;
        const id = setInterval(() => setLastUpdated((n) => n + 1), 1000);
        return () => clearInterval(id);
    }, [completed]);

    // Frozen on its final value once the exercise ends (spec §3's "the
    // metrics bar freezes") — the end timestamp, not `Date.now()`, once
    // `actual_end` is set.
    const elapsedSeconds = state.occurrence.actual_start
        ? Math.max(0, Math.floor((
            (completed ? new Date(state.occurrence.actual_end).getTime() : Date.now())
            - new Date(state.occurrence.actual_start).getTime()
        ) / 1000))
        : null;

    const composer = useForm({ entry_type: 'manual', content: '' });

    const submitEntry = (e) => {
        e.preventDefault();
        composer.post(urls.timeline_store, {
            preserveScroll: true,
            onSuccess: () => composer.reset('content'),
        });
    };

    const releaseInject = (inject) => {
        const dueMinutes = inject.release_offset_minutes;
        const elapsedMinutes = elapsedSeconds != null ? Math.floor(elapsedSeconds / 60) : null;
        const early = dueMinutes != null && elapsedMinutes != null && elapsedMinutes < dueMinutes;

        if (early && !window.confirm(`Release now, ${dueMinutes - elapsedMinutes} minutes early?`)) {
            return;
        }

        router.post(inject.release_url, {}, { preserveScroll: true });
    };

    const manualCheckIn = (participantId) => {
        router.post(urls.check_in, { method: 'manual', participant_id: participantId }, { preserveScroll: true });
    };

    // Facilitator-only per-participant check-in code (contract: `short_code`
    // and `check_in_url` are present on an attendance row only when
    // `can.facilitate` is true — absent for anyone else). Copy is entirely
    // client-side: no network call, nothing to poll, matching the screen's
    // 100 kbps discipline.
    const [copyState, setCopyState] = useState({});
    const copyTimers = useRef({});
    const copyRaceTimers = useRef({});

    // `navigator.clipboard.writeText()` has no timeout of its own — seen
    // hanging indefinitely in a live pass (most likely a pending permission
    // prompt), which left the facilitator with neither "Link copied." nor
    // the fallback link: nothing at all. Race it against ~1.5s: whichever
    // outcome is shown FIRST wins and stays; a write that resolves after the
    // fallback was already shown does not flip the state back to "copied" —
    // the facilitator has already read (or started reading) the fallback
    // link aloud, and swapping the message under them would be worse than
    // leaving it be.
    const CLIPBOARD_RACE_MS = 1500;

    const showCopyState = (id, state) => {
        setCopyState((prev) => ({ ...prev, [id]: state }));
        clearTimeout(copyTimers.current[id]);
        copyTimers.current[id] = setTimeout(() => {
            setCopyState((prev) => ({ ...prev, [id]: undefined }));
        }, 5000);
    };

    const copyCheckInLink = (participant) => {
        const { id, check_in_url: url } = participant;
        clearTimeout(copyTimers.current[id]);
        clearTimeout(copyRaceTimers.current[id]);

        if (!navigator.clipboard || !navigator.clipboard.writeText) {
            showCopyState(id, 'unavailable');
            return;
        }

        let shown = false;
        copyRaceTimers.current[id] = setTimeout(() => {
            shown = true;
            showCopyState(id, 'unavailable');
        }, CLIPBOARD_RACE_MS);

        navigator.clipboard.writeText(url).then(() => {
            clearTimeout(copyRaceTimers.current[id]);
            if (!shown) {
                shown = true;
                showCopyState(id, 'copied');
            }
        }).catch(() => {
            clearTimeout(copyRaceTimers.current[id]);
            if (!shown) {
                shown = true;
                showCopyState(id, 'unavailable');
            }
        });
    };

    useEffect(() => () => {
        Object.values(copyTimers.current).forEach(clearTimeout);
        Object.values(copyRaceTimers.current).forEach(clearTimeout);
    }, []);

    // The outcome is collected HERE because this is the only moment it can
    // ever be recorded: `CompleteOccurrenceRequest` is the sole endpoint that
    // accepts it, and the AAR builder deliberately treats it as read-only
    // (spec's own "editing the exercise definition or occurrence's own
    // record" out-of-scope note) — condition 2 of the finalisation gate
    // would otherwise have no screen that could ever satisfy it.
    const [outcome, setOutcome] = useState('');

    const endExercise = () => {
        router.post(urls.complete, { outcome: outcome || undefined }, { preserveScroll: true, onSuccess: () => setConfirmingEnd(false) });
    };

    const abort = () => {
        const reason = window.prompt('Why is this exercise being aborted?');
        if (reason) {
            router.post(urls.complete, { aborted: true, reason }, { preserveScroll: true });
        }
    };

    const checkedInPct = state.attendance.expected > 0
        ? Math.round((state.attendance.checked_in / state.attendance.expected) * 100)
        : null;

    return (
        <AppLayout title={occurrence.definition_name}>
            <Head title={occurrence.definition_name ?? 'Exercise workspace'} />

            {/* Not focusable, first in DOM, non-live — announced once on load only (spec §5). */}
            <div className="mb-4 rounded border-2 border-violet-300 bg-violet-50 p-3 text-sm font-semibold text-violet-900">
                THIS IS AN EXERCISE — {occurrence.definition_name} · {occurrence.exercise_type_label}
            </div>

            <PageHeader
                title={`${occurrence.definition_name ?? ''} — ${occurrence.exercise_type_label ?? ''}`}
                subtitle={`${occurrence.ladder_level_label ?? ''} · ${occurrence.site ?? occurrence.location ?? 'no site recorded'} · started ${formatLocal(state.occurrence.actual_start)}`}
                actions={(
                    <div className="flex flex-wrap gap-2">
                        <Link href={urls.readiness} className="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">
                            Readiness
                        </Link>
                        {can.facilitate && !completed && (
                            <>
                                <button type="button" onClick={() => setConfirmingEnd(true)}
                                    className="rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">
                                    End exercise
                                </button>
                                <button type="button" onClick={abort}
                                    className="rounded border border-rose-300 px-3 py-1.5 text-sm text-rose-700 hover:bg-rose-50">
                                    Abort
                                </button>
                            </>
                        )}
                    </div>
                )}
            />

            {flash?.success && (
                <div role="status" className="mb-4 rounded border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900">
                    {flash.success}
                </div>
            )}
            {flash?.error && (
                <div role="alert" className="mb-4 rounded border border-rose-200 bg-rose-50 p-3 text-sm text-rose-900">
                    {flash.error}
                </div>
            )}

            {ladderWarnings.length > 0 && (
                <div className="mb-4 rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                    {ladderWarnings.map((w, i) => <p key={i}>{w.message ?? w}</p>)}
                </div>
            )}

            {readinessOverrides.length > 0 && (
                <div className="mb-4 rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                    <p className="font-medium">
                        This exercise started with {readinessOverrides.length} blocking item(s) overridden.
                        Every override is listed below and will appear in the after-action report.
                    </p>
                    <ul className="mt-2 list-disc pl-5">
                        {readinessOverrides.map((o, i) => (
                            <li key={i}>{o.task}: {o.reason}</li>
                        ))}
                    </ul>
                </div>
            )}

            {channelsAreMocked && (
                <div className="mb-4 rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                    Notification channels are still recording mocks. Nothing has left the building.
                </div>
            )}

            {/* Floating metrics bar */}
            <div className="sticky top-0 z-10 mb-6 grid grid-cols-2 gap-3 bg-slate-50/95 py-2 backdrop-blur sm:grid-cols-4">
                <Stat label="Elapsed" value={formatDuration(elapsedSeconds)} />
                <Stat label="Headcount" value={state.attendance.checked_in ?? 0} of={state.attendance.expected ?? 0} />
                {['FIREDRILL', 'EVAC'].includes(occurrence.type_code) ? (
                    <Stat label="Time to assembly target" value={metrics.time_to_assembly_target_seconds != null
                        ? formatDuration(metrics.time_to_assembly_target_seconds) : 'not recorded'} />
                ) : (
                    <Stat label="Decisions logged" value={state.metrics.decisions_logged ?? 0} />
                )}
                {['DRFAILOVER', 'DRFAILBACK', 'DRTEST', 'CALLTREE'].includes(occurrence.type_code) && (
                    <Stat label={occurrence.type_code === 'CALLTREE' ? 'Cascade detail' : 'RTO clock'}
                        value={occurrence.type_code === 'CALLTREE'
                            ? 'see the call tree’s live view' : 'recorded in the after-action report'} small />
                )}
            </div>

            {!completed && (
                <p className="mb-4 text-xs text-slate-400">
                    {pollFailing
                        ? `Last updated ${lastUpdated}s ago — connection is slow or down.`
                        : `Updated ${lastUpdated}s ago.`}
                </p>
            )}

            {completed && (
                <div className="mb-6 rounded border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-900">
                    <p className="font-medium">
                        This exercise ended at {formatLocal(state.occurrence.actual_end)}. Continue to the after-action report.
                    </p>
                    {urls.aar && (
                        <Link href={urls.aar} className="mt-2 inline-block rounded bg-emerald-700 px-3 py-1.5 text-white hover:bg-emerald-600">
                            Open the after-action report
                        </Link>
                    )}
                </div>
            )}

            <div className="grid gap-6 lg:grid-cols-[1fr_360px]">
                {/* Left: timeline */}
                <section className="rounded border border-slate-200 bg-white">
                    {!completed && can.facilitate && (
                        <form onSubmit={submitEntry} className="space-y-2 border-b border-slate-100 p-4">
                            <div className="flex flex-wrap items-end gap-2">
                                <div>
                                    <label htmlFor="entry_type" className="block text-xs font-medium text-slate-600">Entry type</label>
                                    <select id="entry_type" className="form-select" value={composer.data.entry_type}
                                        onChange={(e) => composer.setData('entry_type', e.target.value)}>
                                        <option value="manual">Manual</option>
                                        <option value="decision">Decision</option>
                                        <option value="milestone">Milestone</option>
                                    </select>
                                </div>
                                <div className="min-w-56 flex-1">
                                    <label htmlFor="entry_content" className="block text-xs font-medium text-slate-600">Log an entry</label>
                                    <textarea id="entry_content" rows={2} className="form-textarea w-full"
                                        value={composer.data.content}
                                        onChange={(e) => composer.setData('content', e.target.value)} />
                                </div>
                                <button type="submit" disabled={composer.processing}
                                    className="rounded bg-slate-800 px-3 py-2 text-sm text-white hover:bg-slate-700">
                                    Log
                                </button>
                            </div>
                            {composer.errors.content && <p role="alert" className="text-xs text-rose-700">{composer.errors.content}</p>}
                        </form>
                    )}

                    <ul className="divide-y divide-slate-100">
                        {state.timeline.length === 0 && (
                            <li className="p-6 text-sm text-slate-500">
                                Nothing logged yet. The clock is running — the first entry is usually the assembly
                                point confirming they are in position.
                            </li>
                        )}
                        {state.timeline.map((t) => {
                            const chip = ENTRY_TYPES[t.entry_type] ?? ENTRY_TYPES.manual;
                            return (
                                <li key={t.id} className="flex gap-3 p-3 text-sm">
                                    <span className="w-14 shrink-0 font-mono text-xs text-slate-500">{formatTime(t.logged_at)}</span>
                                    <span className={`h-fit shrink-0 rounded px-1.5 py-0.5 text-[11px] ${chip.cls}`}>{chip.label}</span>
                                    <span className="min-w-0 flex-1 text-slate-800">{t.content}</span>
                                    <span className="shrink-0 text-xs text-slate-400">{t.logged_by ?? '—'}</span>
                                </li>
                            );
                        })}
                    </ul>
                </section>

                {/* Right: injects, attendance, evidence */}
                <div className="space-y-4">
                    <section className="rounded border border-slate-200 bg-white p-4">
                        <div className="mb-2 flex items-center justify-between gap-2">
                            <h2 className="text-sm font-semibold text-slate-700">Injects</h2>
                            {can.facilitate && !completed && urls.injects_store && (
                                <button type="button" onClick={startNewInject}
                                    className="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-50">
                                    + Add inject
                                </button>
                            )}
                        </div>

                        {injects.length === 0 ? (
                            <p className="text-sm text-slate-500">This exercise has no scripted injects.</p>
                        ) : (
                            <ul className="divide-y divide-slate-100 text-sm">
                                {injects.map((i, index) => (
                                    <li key={i.id} className={`py-2 ${i.released_at ? 'opacity-60' : ''}`}>
                                        <div className="flex items-start justify-between gap-2">
                                            <div className="min-w-0 flex-1">
                                                <p className="text-slate-800">
                                                    {i.title}
                                                    {i.ai_generated && (
                                                        <span className="ml-1 inline-block rounded bg-sky-50 px-1.5 py-0.5 text-[11px] text-sky-800">AI draft</span>
                                                    )}
                                                </p>
                                                {i.content && <p className="mt-0.5 text-xs text-slate-600">{i.content}</p>}
                                                <p className="text-xs text-slate-500">
                                                    due at T+{i.release_offset_minutes}
                                                    {i.delivery_channel && ` · ${channelLabel(i.delivery_channel)}`}
                                                </p>
                                            </div>

                                            <div className="flex shrink-0 flex-col items-end gap-1">
                                                {!i.released_at && can.facilitate && !completed ? (
                                                    <button type="button" aria-label={`Release: ${i.title}`}
                                                        onClick={() => releaseInject(i)}
                                                        className="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-50">
                                                        Release now
                                                    </button>
                                                ) : i.released_at ? (
                                                    <span className="text-xs text-slate-500">
                                                        released {formatTime(i.released_at)} by {i.released_by ?? '—'}
                                                    </span>
                                                ) : null}

                                                {/* GAP 2 — edit/delete/reorder. `update_url`/`delete_url` are
                                                   absent or null once released or for a non-facilitator (the
                                                   same "hidden either way" contract `attendance.not_checked_in`
                                                   already uses) — both are falsy in JS, so a released inject is
                                                   read-only here with nothing more to check. */}
                                                {(i.update_url || i.delete_url || (can.facilitate && !completed)) && (
                                                    <div className="flex items-center gap-1 text-xs text-slate-500">
                                                        {/* A-ii: a released inject keeps its rank — no arrows for it
                                                           at all — and an unreleased inject can't swap with a
                                                           released neighbour, which the server refuses anyway, so
                                                           that direction's arrow is hidden rather than disabled. */}
                                                        {can.facilitate && !completed && !i.released_at && (
                                                            <>
                                                                {index > 0 && !injects[index - 1].released_at && (
                                                                    <button type="button" aria-label={`Move up: ${i.title}`}
                                                                        onClick={() => moveInject(index, -1)}
                                                                        className="rounded px-1 hover:bg-slate-100">
                                                                        ↑
                                                                    </button>
                                                                )}
                                                                {index < injects.length - 1 && !injects[index + 1].released_at && (
                                                                    <button type="button" aria-label={`Move down: ${i.title}`}
                                                                        onClick={() => moveInject(index, 1)}
                                                                        className="rounded px-1 hover:bg-slate-100">
                                                                        ↓
                                                                    </button>
                                                                )}
                                                            </>
                                                        )}
                                                        {i.update_url && (
                                                            <button type="button" aria-label={`Edit: ${i.title}`}
                                                                onClick={() => startEditInject(i)}
                                                                className="text-blue-700 hover:underline">
                                                                Edit
                                                            </button>
                                                        )}
                                                        {i.delete_url && (
                                                            <button type="button" aria-label={`Delete: ${i.title}`}
                                                                onClick={() => deleteInject(i)}
                                                                className="text-rose-700 hover:underline">
                                                                Delete
                                                            </button>
                                                        )}
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}

                        {authoring && (
                            <form onSubmit={submitInject} className="mt-3 space-y-3 rounded border border-slate-200 bg-slate-50 p-3">
                                <h3 className="text-xs font-semibold text-slate-700">
                                    {authoring === 'new' ? 'New inject' : `Edit inject: ${authoring.title}`}
                                </h3>
                                <FormField label="Title" htmlFor="inject-title" required error={injectForm.errors.title}>
                                    <input id="inject-title" type="text" className="form-input text-sm"
                                        value={injectForm.data.title}
                                        onChange={(e) => injectForm.setData('title', e.target.value)} />
                                </FormField>
                                <FormField label="Content" htmlFor="inject-content" error={injectForm.errors.content}>
                                    <textarea id="inject-content" rows={3} className="form-textarea text-sm"
                                        value={injectForm.data.content}
                                        onChange={(e) => injectForm.setData('content', e.target.value)} />
                                </FormField>
                                <div className="grid grid-cols-2 gap-3">
                                    <FormField label="Release at T+ (minutes)" htmlFor="inject-offset" error={injectForm.errors.release_offset_minutes}>
                                        <input id="inject-offset" type="number" min="0" className="form-input text-sm"
                                            value={injectForm.data.release_offset_minutes}
                                            onChange={(e) => injectForm.setData('release_offset_minutes', e.target.value)} />
                                    </FormField>
                                    <FormField label="Delivery channel" htmlFor="inject-channel" error={injectForm.errors.delivery_channel}>
                                        <select id="inject-channel" className="form-select text-sm"
                                            value={injectForm.data.delivery_channel}
                                            onChange={(e) => injectForm.setData('delivery_channel', e.target.value)}>
                                            <option value="">Not set</option>
                                            {channelOptions.map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
                                        </select>
                                    </FormField>
                                </div>
                                <div className="form-actions">
                                    <button type="button" className="btn-secondary text-sm" onClick={closeInjectForm}>Cancel</button>
                                    <button type="submit" disabled={injectForm.processing} className="btn-primary text-sm">
                                        {authoring === 'new' ? 'Add inject' : 'Save changes'}
                                    </button>
                                </div>
                            </form>
                        )}
                    </section>

                    <section className="rounded border border-slate-200 bg-white p-4">
                        <h2 className="mb-2 text-sm font-semibold text-slate-700">Attendance</h2>
                        <p className="text-sm text-slate-700">
                            {state.attendance.checked_in ?? 0} of {state.attendance.expected ?? 0}
                            {checkedInPct !== null ? ` (${checkedInPct}%)` : ''}
                        </p>
                        {can.facilitate && (
                            <Link href={urls.check_in_poster} className="mt-1 inline-block text-xs text-blue-700 hover:underline">
                                Open the check-in poster
                            </Link>
                        )}
                        {(attendance.not_checked_in ?? []).length > 0 && (
                            <details className="mt-2 text-xs text-slate-600">
                                <summary className="cursor-pointer">{attendance.not_checked_in.length} not checked in</summary>
                                <ul className="mt-1 space-y-1">
                                    {attendance.not_checked_in.map((p) => {
                                        const name = p.name ?? `Participant #${p.id}`;
                                        return (
                                            <li key={p.id} className="space-y-1">
                                                <div className="flex items-center justify-between gap-2">
                                                    <span>{name}</span>
                                                    {can.facilitate && !completed && (
                                                        <button type="button" className="text-blue-700 hover:underline"
                                                            aria-label={`Check in: ${name}`}
                                                            onClick={() => manualCheckIn(p.id)}>
                                                            Check in
                                                        </button>
                                                    )}
                                                </div>
                                                {/* Facilitator-only: `short_code`/`check_in_url` are absent
                                                   from this row for anyone without `can.facilitate` — never
                                                   rendered when the keys are missing. */}
                                                {p.short_code && p.check_in_url && (
                                                    <div className="flex flex-wrap items-center gap-2 rounded bg-slate-50 p-1.5">
                                                        <span className="text-[11px] font-medium text-slate-500">Check-in code</span>
                                                        <code className="font-mono text-sm font-semibold tracking-widest text-slate-900">
                                                            {p.short_code}
                                                        </code>
                                                        <button type="button"
                                                            onClick={() => copyCheckInLink(p)}
                                                            aria-label={`Copy check-in link: ${name}`}
                                                            className="rounded border border-slate-300 px-2 py-0.5 text-[11px] text-blue-700 hover:bg-slate-50">
                                                            Copy link
                                                        </button>
                                                        <span role="status" aria-live="polite" className="w-full text-[11px] text-slate-500">
                                                            {copyState[p.id] === 'copied' && 'Link copied.'}
                                                            {copyState[p.id] === 'unavailable' && (
                                                                <>Could not copy automatically — read the code above aloud, or copy this link: <span className="select-all font-mono">{p.check_in_url}</span></>
                                                            )}
                                                        </span>
                                                    </div>
                                                )}
                                            </li>
                                        );
                                    })}
                                </ul>
                            </details>
                        )}
                    </section>

                    <section className="rounded border border-slate-200 bg-white p-4">
                        <h2 className="mb-2 text-sm font-semibold text-slate-700">Evidence</h2>
                        {evidence.length === 0 && <p className="mb-2 text-xs text-slate-500">Nothing uploaded yet.</p>}
                        <ul className="mb-3 space-y-1 text-xs">
                            {evidence.map((e) => (
                                <li key={e.uuid} className="flex items-center justify-between gap-2 rounded bg-slate-50 p-2">
                                    <span className="min-w-0 truncate">
                                        {e.file_name}{e.caption ? ` — ${e.caption}` : ''}
                                        <span className="block text-[10px] text-slate-400">{e.uploader} · {formatLocal(e.captured_at)}{e.locked ? ' · locked' : ''}</span>
                                    </span>
                                    <span className="flex shrink-0 gap-2">
                                        <a href={e.download_url} aria-label={`Download: ${e.file_name}`} className="text-blue-700 hover:underline">download</a>
                                        {e.delete_url && (
                                            <button type="button" className="text-rose-700 hover:underline"
                                                aria-label={`Remove: ${e.file_name}`}
                                                onClick={() => { if (window.confirm('Remove this evidence file?')) router.delete(e.delete_url, { preserveScroll: true }); }}>
                                                remove
                                            </button>
                                        )}
                                    </span>
                                </li>
                            ))}
                        </ul>
                        {/* No onUploaded reload needed below: EvidenceCapture
                           posts through router.post's default full Inertia
                           visit, which already re-renders this page with
                           the fresh `evidence` prop. */}
                        {(can.facilitate || can.evaluate) && !completed && (
                            <EvidenceCapture
                                uploadUrl={urls.evidence_upload}
                                ownerType="occurrence"
                                ownerKey={occurrence.id}
                            />
                        )}
                    </section>

                    {can.evaluate && urls.score && (
                        <Link href={urls.score} className="block rounded border border-slate-300 bg-white p-4 text-center text-sm text-blue-700 hover:bg-slate-50">
                            Score this exercise&rsquo;s objectives
                        </Link>
                    )}
                </div>
            </div>

            {/* Fallback End-exercise CTA for a keyboard user tabbing through a long page (spec §5). */}
            {can.facilitate && !completed && (
                <div className="mt-6">
                    <button type="button" onClick={() => setConfirmingEnd(true)}
                        className="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">
                        End exercise
                    </button>
                </div>
            )}

            {confirmingEnd && (
                <div role="dialog" aria-modal="true" aria-label="End this exercise"
                    className="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
                    <div className="w-full max-w-md space-y-4 rounded-lg bg-white p-6 shadow-xl">
                        <h3 className="text-base font-semibold text-slate-900">End this exercise?</h3>
                        <p className="text-sm text-slate-700">
                            The timeline, injects and attendance close for editing. You can still amend facts
                            through the after-action report.
                        </p>
                        <div>
                            <label htmlFor="end-outcome" className="block text-xs font-medium text-slate-600">
                                Outcome (optional — can be left for the after-action report to record)
                            </label>
                            <select id="end-outcome" className="form-select mt-1 w-full text-sm"
                                value={outcome} onChange={(e) => setOutcome(e.target.value)}>
                                <option value="">Not yet decided</option>
                                <option value="pass">Pass</option>
                                <option value="pass_with_findings">Pass, with findings</option>
                                <option value="fail">Fail</option>
                                <option value="inconclusive">Inconclusive</option>
                            </select>
                        </div>
                        <div className="flex justify-end gap-2">
                            <button type="button" className="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50"
                                onClick={() => setConfirmingEnd(false)}>
                                Cancel
                            </button>
                            <button type="button" className="rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700"
                                onClick={endExercise}>
                                End exercise
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}

/** The same visual grammar as `CallTrees/Live.jsx`'s `Stat` tile — reused, not reinvented. */
function Stat({ label, value, of, small = false }) {
    return (
        <div className="rounded border border-slate-200 bg-white p-3">
            <div className={small ? 'text-sm font-medium text-slate-700' : 'text-2xl font-semibold text-slate-800'}>
                {value}{of != null ? <span className="text-base text-slate-400"> / {of}</span> : null}
            </div>
            <div className="text-xs text-slate-500">{label}</div>
        </div>
    );
}

function formatDuration(seconds) {
    if (seconds == null) return '—';
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = Math.floor(seconds % 60);
    return h > 0 ? `${h}h ${m}m` : `${m}m ${s}s`;
}

function formatTime(iso) {
    if (!iso) return '—';
    return iso.slice(11, 16);
}

function formatLocal(iso) {
    if (!iso) return 'not yet';
    return iso.slice(0, 16).replace('T', ' ');
}
