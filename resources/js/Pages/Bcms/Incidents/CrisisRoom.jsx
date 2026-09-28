import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import Modal from '@thirdline/ui/Components/Modal';
import SeverityMatrix from '@/Components/Bcms/SeverityMatrix';
import ReportabilityQuestion from '@/Components/Bcms/ReportabilityQuestion';
import { toLocalInput, localInputToIso } from '@/Components/Bcms/dateInput';
import { formatIncidentDateTime } from '@/Components/Bcms/dateDisplay';
import AwarenessField, { awarenessDefault, isAwarenessLate } from '@/Components/Bcms/AwarenessField';
import AudiencePicker from '@/Components/Bcms/AudiencePicker';

const ENTRY_TONE = {
    decision: 'bg-slate-200 text-slate-800',
    action: 'bg-blue-100 text-blue-800',
    communication: 'bg-violet-100 text-violet-800',
    situation_report: 'bg-amber-100 text-amber-900',
    escalation: 'bg-rose-100 text-rose-800',
};

/**
 * `docs/bcms/screens/crisis-room.md` — the one screen a crisis team looks at
 * together, real or nothing.
 *
 * TWO INDEPENDENT COUNTDOWN TILES, NEVER ONE "REGULATOR NOTIFIED" TICK (ADR
 * 0020) — `countdown_tiles` renders one card per open obligation, and an
 * absent tile means nobody has classified that obligation yet, not that it is
 * satisfied. The 5-second poll (below) resyncs each tile's `due_at`/
 * `submitted_at`/`state` independently, by regulator, and never merges them.
 *
 * THE POLL FETCHES ONE SMALL JSON DOCUMENT, NEVER THE FULL CRISIS-ROOM
 * PAYLOAD (spec §6) — `urls.live_metrics` returns `{ metrics,
 * countdown_tiles, counts, latest_entry_id, latest_entry_at, status }` and
 * nothing else. The metrics bar and the countdown tiles update in place from
 * that; `entries`/`tasks`/`plan_activations`/`incident` stay Inertia props
 * and only change when `router.reload({ only: [...] })` runs, which happens
 * exactly when the poll observes a fact those props can't get from a small
 * JSON body: a new `latest_entry_id`, a change in `counts`, or a change in
 * `status`. Two consecutive failed polls back the interval off to 30 seconds
 * and show a "Reconnecting…" note (`aria-live="polite"`); the next
 * successful poll resets both. Polling stops once the incident's status is
 * terminal (closed/cancelled — `isTerminalStatus` below).
 *
 * PLAN ACTIVATION AND ALERT COMPOSE SEND `incident_id` (`incident.uuid`) IN
 * THE POST BODY, against the existing server-built `plan.activate_url` /
 * `urls.alerts_store` routes — there is no separate incident-scoped route
 * for either action. `PlanDocumentController::activate()`
 * (`ActivateBcmsPlanRequest`) and `AlertController::store()`
 * (`StoreBcmsAlertRequest`) both accept `incident_id` as the incident's uuid
 * and resolve it through `Incident::visibleTo()` before storing the integer
 * FK — a uuid the poster cannot see 404s rather than silently attaching to
 * the wrong tenant's/unit's record.
 */
export default function CrisisRoom({
    incident = {}, can = {}, severity_bands: severityBands = [], activation_levels: activationLevels = [],
    entry_types: entryTypes = [], plans = [], roll_call: rollCall = null, review = {}, closed_by: closedBy = null,
    countdown_tiles: countdownTiles = [], reportability = {}, entries = [], tasks = [], plan_activations: planActivations = [],
    metrics = {}, urls = {},
    // ADR 0024 item B — the SitRep/stakeholder/roll-call composers' audience
    // picker, via the same `EmnsPresenter::audienceOptions()` the EMNS
    // console itself reads (`IncidentPresenter`), so this screen and the
    // console never disagree about what "Crisis Management Team" resolves
    // to. `occurrence_options` is carried for parity with that presenter but
    // has no picker of its own here — a crisis-room alert is already scoped
    // to this incident, not to a drill.
    audience_options: audienceOptions = {},
}) {
    // `entries`/`tasks`/`planActivations` are plain Inertia props — they only
    // change when the server re-renders (a normal visit, or the partial
    // `router.reload()` the poll below triggers on drift). `live` is the
    // part the 5-second poll owns outright, updated in place between reloads.
    const [live, setLive] = useState({ metrics, countdownTiles });
    const [lastUpdated, setLastUpdated] = useState(0);
    const [pollFailing, setPollFailing] = useState(false);
    const [reconnecting, setReconnecting] = useState(false);
    const [regrading, setRegrading] = useState(false);
    const [correcting, setCorrecting] = useState(null);
    const [showTaskForm, setShowTaskForm] = useState(false);
    const [showActivate, setShowActivate] = useState(false);
    const [showRollCallForm, setShowRollCallForm] = useState(false);
    const [showSitRepForm, setShowSitRepForm] = useState(false);
    const [showCommsForm, setShowCommsForm] = useState(false);

    const closed = isTerminalStatus(incident.status);

    // What the last poll (or the initial render) saw, purely to detect drift
    // — never rendered directly.
    const lastSeenRef = useRef({ entryId: entries[0]?.id ?? null, counts: null, status: incident.status });
    const consecutiveFailuresRef = useRef(0);

    useEffect(() => {
        if (closed || !urls.live_metrics) return undefined;

        let cancelled = false;
        let timeoutId;

        const schedule = (delay) => {
            timeoutId = setTimeout(tick, delay);
        };

        const tick = () => {
            fetch(urls.live_metrics, { headers: { Accept: 'application/json' } })
                .then((r) => (r.ok ? r.json() : Promise.reject()))
                .then((data) => {
                    if (cancelled) return;

                    consecutiveFailuresRef.current = 0;
                    setPollFailing(false);
                    setReconnecting(false);
                    setLastUpdated(0);
                    setLive({ metrics: data.metrics ?? {}, countdownTiles: data.countdown_tiles ?? [] });

                    const seen = lastSeenRef.current;
                    const drifted = data.latest_entry_id !== seen.entryId
                        || JSON.stringify(data.counts) !== JSON.stringify(seen.counts)
                        || data.status !== seen.status;
                    lastSeenRef.current = { entryId: data.latest_entry_id, counts: data.counts, status: data.status };

                    if (drifted) {
                        router.reload({ only: ['entries', 'tasks', 'plan_activations', 'incident'], preserveScroll: true });
                    }

                    if (!cancelled && !isTerminalStatus(data.status)) schedule(5000);
                })
                .catch(() => {
                    if (cancelled) return;
                    consecutiveFailuresRef.current += 1;
                    setPollFailing(true);
                    if (consecutiveFailuresRef.current >= 2) setReconnecting(true);
                    schedule(consecutiveFailuresRef.current >= 2 ? 30000 : 5000);
                });
        };

        schedule(5000);

        return () => { cancelled = true; clearTimeout(timeoutId); };
    }, [closed, urls.live_metrics]);

    useEffect(() => {
        if (closed) return undefined;
        const id = setInterval(() => setLastUpdated((n) => n + 1), 1000);
        return () => clearInterval(id);
    }, [closed]);

    const elapsedSeconds = incident.declared_at
        ? Math.max(0, Math.floor((Date.now() - new Date(incident.declared_at).getTime()) / 1000))
        : null;

    const composer = useForm({ entry_type: 'decision', content: '', options_considered: '', rationale: '', supersedes_entry_id: null });
    const taskForm = useForm({ title: '', owner_id: '', due_at: '' });
    const activateForm = useForm({ reason: '', is_exercise: false });
    const regradeForm = useForm({ severity: incident.severity, activation_level: incident.activation_level, reason: '' });

    // ADR 0020 Amendment 2 rules 5-7: BOTH clocks (CBN and NDPC/personal-data)
    // default `awareness_at` to the incident's `detected_at`, falling back to
    // `declared_at` — never to "now", which is exactly the direction that lets
    // a bank buy itself regulatory time by delaying. This one form is shared
    // by whichever question the officer answers `yes` to (`answerReportability`
    // below), matching `ClassifyBcmsIncidentRequest`, which validates
    // `awareness_at`/`awareness_reason` the same way regardless of `question`.
    const awarenessDefaultAt = awarenessDefault(incident);
    const awarenessForm = useForm({
        awareness_at: awarenessDefaultAt ? toLocalInput(new Date(awarenessDefaultAt)) : '',
        awareness_reason: '',
    });
    // Rule 6: moving awareness LATER than the default is the direction that
    // needs a stated reason; earlier needs none. Without a default to compare
    // against (both `detected_at`/`declared_at` missing — rule 7), nothing here
    // can tell, so the server's own refusal is what surfaces via `errors.awareness_at`.
    const awarenessIsLate = isAwarenessLate(awarenessForm.data.awareness_at, awarenessDefaultAt);

    const submitEntry = (e) => {
        e.preventDefault();
        composer.post(urls.log_store, {
            preserveScroll: true,
            onSuccess: () => { composer.reset('content', 'options_considered', 'rationale', 'supersedes_entry_id'); setCorrecting(null); },
        });
    };

    const submitTask = (e) => {
        e.preventDefault();
        taskForm.transform((data) => ({ ...data, due_at: data.due_at ? localInputToIso(data.due_at) : null }));
        taskForm.post(urls.tasks_store, { preserveScroll: true, onSuccess: () => { taskForm.reset(); setShowTaskForm(false); } });
    };

    const completeTask = (task) => router.post(task.complete_url, {}, { preserveScroll: true });

    const submitActivate = (e) => {
        e.preventDefault();
        const plan = plans.find((p) => String(p.id) === String(activateForm.data.plan_id));
        if (!plan) return;
        // `incident_id` (the incident's uuid) is sent so the activation is
        // attributable to this incident — `PlanDocumentController::activate()`
        // (`ActivateBcmsPlanRequest`) resolves it through `Incident::
        // visibleTo()` and stores the resulting integer FK, and the crisis
        // room's own `plan_activations` list (an Inertia prop, refreshed on
        // reload) then shows it.
        activateForm.transform((data) => ({
            reason: data.reason,
            is_exercise: incident.is_exercise ? true : false,
            incident_id: incident.uuid,
        }));
        activateForm.post(plan.activate_url, { preserveScroll: true, onSuccess: () => { activateForm.reset(); setShowActivate(false); } });
    };

    const submitRegrade = (e) => {
        e.preventDefault();
        regradeForm.post(urls.regrade, { preserveScroll: true, onSuccess: () => setRegrading(false) });
    };

    const answerReportability = (question, answer) => {
        if (answer === 'no') {
            const reason = window.prompt('Reason this is not, in fact, reportable:');
            if (!reason) return;
            router.post(urls.classify, { question, answer, reason }, { preserveScroll: true });
            return;
        }
        if (answer === 'yes') {
            // Shared by both questions (ADR 0020 §2 point 1, Amendment 2) —
            // `awarenessIsLate` is read fresh from this render's closure, so it
            // always reflects whichever value is currently in the field.
            awarenessForm.transform((data) => ({
                question,
                answer,
                awareness_at: data.awareness_at ? localInputToIso(data.awareness_at) : null,
                ...(awarenessIsLate ? { awareness_reason: data.awareness_reason } : {}),
            }));
            awarenessForm.post(urls.classify, { preserveScroll: true, onSuccess: () => awarenessForm.reset() });
            return;
        }
        router.post(urls.classify, { question, answer }, { preserveScroll: true });
    };

    // Nest superseding entries directly beneath what they supersede (spec §2) —
    // a plain reduce over the already reverse-chronological list.
    const nestedEntries = useMemo(() => nestBySupersession(entries), [entries]);

    const openTasks = tasks.filter((t) => !['complete', 'cancelled'].includes(t.status));
    const overdueTasks = openTasks.filter((t) => t.due_at && new Date(t.due_at) < new Date());
    const completeTasks = tasks.filter((t) => ['complete', 'cancelled'].includes(t.status));
    const [showComplete, setShowComplete] = useState(false);

    return (
        <AppLayout title={incident.reference}>
            <Head title={`${incident.reference} — ${incident.title}`} />

            <PageHeader
                title={`${incident.reference} — ${incident.title}`}
                subtitle={`${incident.severity_label ?? ''} · ${incident.activation_level_label ?? ''} · declared ${formatLocal(incident.declared_at)}`}
                actions={(
                    <div className="flex gap-2">
                        {can.view_notifications && (
                            <Link href={urls.notifications} className="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">
                                Notification log
                            </Link>
                        )}
                        {can.manage && !closed && (
                            <Link href={urls.stand_down} className="rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">
                                Stand down
                            </Link>
                        )}
                    </div>
                )}
            />

            {incident.is_exercise && (
                <div className="mb-4 rounded border-2 border-violet-300 bg-violet-50 p-3 text-sm font-semibold text-violet-900">
                    THIS IS AN EXERCISE
                </div>
            )}

            {/* Two independent countdown tiles */}
            {live.countdownTiles.length > 0 && (
                <div className="mb-4 grid gap-3 sm:grid-cols-2">
                    {live.countdownTiles.map((tile) => (
                        <CountdownTile key={tile.id} tile={tile} notificationsUrl={urls.notifications} />
                    ))}
                </div>
            )}

            {reportability.cbn === 'no' && reportability.personal_data === 'no' && live.countdownTiles.length === 0 && (
                <p className="mb-4 rounded bg-slate-50 p-3 text-sm text-slate-700">
                    Not currently classified as reportable to the CBN or the NDPC. This can be changed at
                    any time from the panel below.
                </p>
            )}

            {/*
                This banner's "No" only ever runs while the question is
                still `unknown` — no obligation row exists yet at that point,
                so `NotificationService::reassessNotReportable()` takes its
                decision-log-only branch, which stays on `bcms.incident.manage`
                (ADR 0020 Amendment 2). Reassessing an ALREADY-`yes` obligation
                would WITHDRAW a live row and needs `can.withdraw`
                (`bcms.incident.notify`) instead — there is no control for
                that on this screen today (neither `crisis-room.md` nor
                `incident-notification-log.md` specs one), so `can.withdraw`
                is shipped unused, the same way `can.notify`/`can.export`
                already are on this payload, for whichever screen adds one.
            */}
            {(reportability.cbn === 'unknown' || reportability.personal_data === 'unknown') && (
                <div className="mb-4 space-y-3 rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                    {reportability.cbn === 'unknown' && (
                        <ReportabilityInline label="Reportability to the CBN is still unresolved. Answer it now — the clock, once started, runs from when the incident was first detected, not from today."
                            onAnswer={(a) => answerReportability('cbn', a)} />
                    )}
                    {reportability.personal_data === 'unknown' && (
                        <ReportabilityInline label="Reportability to the NDPC is still unresolved. Answer it now — the clock, once started, runs from when the incident was first detected, not from today."
                            onAnswer={(a) => answerReportability('personal_data', a)} />
                    )}
                    {/*
                        Shared by whichever question above gets answered `Yes`
                        (ADR 0020 §2 point 1, Amendment 2 rules 5-7) — the same
                        `AwarenessField` `NotificationLog.jsx`'s `ClassifyForm`
                        uses, so the two screens cannot drift out of step again.
                    */}
                    <AwarenessField
                        value={awarenessForm.data.awareness_at}
                        onChange={(v) => awarenessForm.setData('awareness_at', v)}
                        reasonValue={awarenessForm.data.awareness_reason}
                        onReasonChange={(v) => awarenessForm.setData('awareness_reason', v)}
                        defaultAt={awarenessDefaultAt}
                        errors={awarenessForm.errors}
                        idPrefix="awareness"
                    />
                </div>
            )}

            {/* Sticky metrics bar */}
            <div className="sticky top-0 z-10 mb-6 grid grid-cols-2 gap-3 bg-slate-50/95 py-2 backdrop-blur sm:grid-cols-4">
                <Stat label="Elapsed" value={formatDuration(elapsedSeconds)} />
                <Stat label="Activation level" value={incident.activation_level_label ?? '—'} small />
                <Stat label="Open tasks" value={live.metrics.open_tasks ?? openTasks.length} />
                {rollCall && <Stat label="Unaccounted for" value={rollCall.unaccounted_for ?? 0} tone={(rollCall.unaccounted_for ?? 0) > 0 ? 'text-rose-700' : 'text-emerald-700'} />}
                <Stat label="Decisions logged" value={live.metrics.decisions_logged ?? 0} />
            </div>

            {!closed && (
                <p className="mb-4 text-xs text-slate-400" aria-live={reconnecting ? 'polite' : undefined}>
                    {reconnecting
                        ? `Reconnecting… last updated ${lastUpdated}s ago.`
                        : pollFailing
                            ? `Last updated ${lastUpdated}s ago — connection is slow or down.`
                            : `Updated ${lastUpdated}s ago.`}
                </p>
            )}

            {closed && (
                <div className="mb-6 rounded border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-900">
                    <p className="font-medium">
                        This incident was closed at {formatLocal(incident.closed_at)}{closedBy ? ` by ${closedBy}` : ''}.
                        Continue to the post-incident review.
                    </p>
                    {review.show_url ? (
                        <Link href={review.show_url} className="mt-2 inline-block rounded bg-emerald-700 px-3 py-1.5 text-white hover:bg-emerald-600">
                            {review.final ? 'View the post-incident review' : 'Continue the post-incident review'}
                        </Link>
                    ) : (
                        <button type="button" onClick={() => router.post(urls.review_start)}
                            className="mt-2 rounded bg-emerald-700 px-3 py-1.5 text-white hover:bg-emerald-600">
                            Start the post-incident review
                        </button>
                    )}
                </div>
            )}

            <div className="grid gap-6 lg:grid-cols-[1fr_360px]">
                {/* Left: decision log */}
                <section className="rounded border border-slate-200 bg-white">
                    {!closed && can.manage && (
                        <form onSubmit={submitEntry} className="space-y-2 border-b border-slate-100 p-4">
                            {correcting != null && (
                                <p className="rounded bg-slate-50 p-2 text-xs text-slate-700">
                                    This corrects entry #{correcting}. Both entries stay visible.
                                    <button type="button" className="ml-2 text-blue-700 underline" onClick={() => { setCorrecting(null); composer.setData('supersedes_entry_id', null); }}>
                                        Cancel
                                    </button>
                                </p>
                            )}
                            <div className="flex flex-wrap items-end gap-2">
                                <div>
                                    <label htmlFor="entry_type" className="block text-xs font-medium text-slate-600">Entry type</label>
                                    <select id="entry_type" className="form-select" value={composer.data.entry_type}
                                        onChange={(e) => composer.setData('entry_type', e.target.value)}>
                                        {entryTypes.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                                    </select>
                                </div>
                                <div className="min-w-56 flex-1">
                                    <label htmlFor="entry_content" className="block text-xs font-medium text-slate-600">Log an entry</label>
                                    <textarea id="entry_content" rows={2} className="form-textarea w-full"
                                        value={composer.data.content} onChange={(e) => composer.setData('content', e.target.value)} />
                                </div>
                                <button type="submit" disabled={composer.processing}
                                    className="rounded bg-slate-800 px-3 py-2 text-sm text-white hover:bg-slate-700">
                                    Log
                                </button>
                            </div>
                            {entryTypes.find((t) => t.value === composer.data.entry_type)?.requires_options_and_rationale && (
                                <div className="grid gap-2 sm:grid-cols-2">
                                    <div>
                                        <label htmlFor="options_considered" className="block text-xs font-medium text-slate-600" aria-required="true">
                                            Options considered
                                        </label>
                                        <input id="options_considered" className="form-input w-full text-sm" value={composer.data.options_considered}
                                            onChange={(e) => composer.setData('options_considered', e.target.value)} />
                                        {composer.errors.options_considered && <p role="alert" className="text-xs text-rose-700">{composer.errors.options_considered}</p>}
                                    </div>
                                    <div>
                                        <label htmlFor="rationale" className="block text-xs font-medium text-slate-600" aria-required="true">Rationale</label>
                                        <input id="rationale" className="form-input w-full text-sm" value={composer.data.rationale}
                                            onChange={(e) => composer.setData('rationale', e.target.value)} />
                                        {composer.errors.rationale && <p role="alert" className="text-xs text-rose-700">{composer.errors.rationale}</p>}
                                    </div>
                                </div>
                            )}
                            {composer.errors.content && <p role="alert" className="text-xs text-rose-700">{composer.errors.content}</p>}
                        </form>
                    )}

                    <ul className="divide-y divide-slate-100">
                        {nestedEntries.length === 0 && (
                            <li className="p-6 text-sm text-slate-500">Nothing logged yet.</li>
                        )}
                        {nestedEntries.map((e) => <EntryRow key={e.id} entry={e} depth={0} canManage={can.manage && !closed}
                            onCorrect={(id) => { setCorrecting(id); composer.setData('supersedes_entry_id', id); }} />)}
                    </ul>
                </section>

                {/* Right: four cards */}
                <div className="space-y-4">
                    <section className="rounded border border-slate-200 bg-white p-4">
                        <h2 className="mb-2 text-sm font-semibold text-slate-700">Severity & activation</h2>
                        <p className="text-sm text-slate-800">{incident.severity_label} · {incident.activation_level_label}</p>
                        {can.manage && !closed && (
                            <button type="button" onClick={() => setRegrading(true)}
                                className="mt-2 rounded border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50">
                                Re-grade
                            </button>
                        )}
                    </section>

                    <section className="rounded border border-slate-200 bg-white p-4">
                        <h2 className="mb-2 text-sm font-semibold text-slate-700">Plan activation</h2>
                        {planActivations.length === 0 && (
                            <p className="text-sm text-slate-500">No crisis-level activation required at this level.</p>
                        )}
                        <ul className="mb-2 space-y-1 text-sm">
                            {planActivations.map((a) => (
                                <li key={a.id} className="rounded bg-slate-50 p-2">
                                    <span className="font-medium">{a.plan}</span>
                                    <span className="block text-xs text-slate-500">
                                        {a.activated_by} · {formatLocal(a.activated_at)}
                                        {a.deactivated_at ? ` · deactivated ${formatLocal(a.deactivated_at)}` : ''}
                                    </span>
                                </li>
                            ))}
                        </ul>
                        {can.activate_plan && !closed && (plans.length > 0 ? (
                            <button type="button" onClick={() => setShowActivate((v) => !v)}
                                className="rounded border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50">
                                Activate a plan
                            </button>
                        ) : (
                            <p className="text-xs text-slate-500">No plan available to activate.</p>
                        ))}
                        {showActivate && (
                            <form onSubmit={submitActivate} className="mt-2 space-y-2 rounded border border-slate-200 p-2">
                                <label htmlFor="activate_plan_id" className="sr-only">Select a plan</label>
                                <select id="activate_plan_id" className="form-select w-full text-sm" value={activateForm.data.plan_id ?? ''}
                                    onChange={(e) => activateForm.setData('plan_id', e.target.value)}>
                                    <option value="">Select a plan</option>
                                    {plans.map((p) => <option key={p.id} value={p.id}>{p.title}</option>)}
                                </select>
                                <input type="text" required placeholder="Why is this being activated?"
                                    aria-label="Why is this being activated?" className="form-input w-full text-sm"
                                    value={activateForm.data.reason} onChange={(e) => activateForm.setData('reason', e.target.value)} />
                                <button type="submit" disabled={activateForm.processing} className="w-full rounded bg-slate-800 py-1.5 text-xs text-white">
                                    Activate
                                </button>
                            </form>
                        )}
                    </section>

                    <section className="rounded border border-slate-200 bg-white p-4">
                        <h2 className="mb-2 text-sm font-semibold text-slate-700">Tasks</h2>
                        {can.manage && !closed && (
                            <button type="button" onClick={() => setShowTaskForm((v) => !v)} className="mb-2 text-xs text-blue-700 underline">
                                Add task
                            </button>
                        )}
                        {showTaskForm && (
                            <form onSubmit={submitTask} className="mb-3 space-y-1 rounded border border-dashed border-slate-300 p-2">
                                <input className="form-input w-full text-sm" placeholder="Title" aria-label="Task title"
                                    value={taskForm.data.title} onChange={(e) => taskForm.setData('title', e.target.value)} />
                                <input type="datetime-local" className="form-input w-full text-sm" aria-label="Due at"
                                    value={taskForm.data.due_at} onChange={(e) => taskForm.setData('due_at', e.target.value)} />
                                <button type="submit" disabled={taskForm.processing} className="rounded bg-slate-800 px-2 py-1 text-xs text-white">Add</button>
                            </form>
                        )}
                        {['open', 'in_progress'].map((status) => {
                            const rows = openTasks.filter((t) => t.status === status);
                            if (rows.length === 0) return null;
                            return (
                                <div key={status} className="mb-2">
                                    <p className="text-[10px] font-semibold uppercase text-slate-400">{status.replace('_', ' ')}</p>
                                    <ul className="divide-y divide-slate-100 text-sm">
                                        {rows.map((t) => (
                                            <li key={t.id} className={`flex items-center justify-between py-1 ${overdueTasks.includes(t) ? 'text-rose-700' : ''}`}>
                                                <span>{t.title} <span className="text-xs text-slate-400">{t.owner}</span></span>
                                                {can.manage && !closed && (
                                                    <button type="button" onClick={() => completeTask(t)} className="text-xs text-blue-700 underline">Mark complete</button>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            );
                        })}
                        {completeTasks.length > 0 && (
                            <button type="button" onClick={() => setShowComplete((v) => !v)} className="text-xs text-slate-500 underline">
                                {showComplete ? 'Hide' : `Show ${completeTasks.length} complete`}
                            </button>
                        )}
                        {showComplete && (
                            <ul className="mt-1 text-xs text-slate-400">
                                {completeTasks.map((t) => <li key={t.id}>{t.title}</li>)}
                            </ul>
                        )}
                    </section>

                    <section className="rounded border border-slate-200 bg-white p-4">
                        <h2 className="mb-2 text-sm font-semibold text-slate-700">Comms & roll-call</h2>
                        <div className="space-y-2">
                            {can.life_safety && !closed && (
                                rollCall ? (
                                    <Link href={rollCall.alert_url} className="block rounded border border-rose-300 px-3 py-2 text-center text-xs text-rose-800 hover:bg-rose-50">
                                        View roll-call
                                    </Link>
                                ) : (
                                    <button type="button" onClick={() => setShowRollCallForm((v) => !v)}
                                        className="w-full rounded border border-rose-300 px-3 py-2 text-xs text-rose-800 hover:bg-rose-50">
                                        Dispatch a roll-call
                                    </button>
                                )
                            )}
                            {showRollCallForm && !rollCall && (
                                <AlertComposer
                                    storeUrl={urls.alerts_store}
                                    incidentUuid={incident.uuid}
                                    defaultSeverity="critical"
                                    confirmLabel="Dispatch a life-safety roll-call?"
                                    isSimulation={incident.is_exercise}
                                    audienceOptions={audienceOptions}
                                />
                            )}
                            {can.dispatch_alert && !closed && (
                                <button type="button" onClick={() => setShowSitRepForm((v) => !v)}
                                    className="w-full rounded border border-slate-300 px-3 py-2 text-xs hover:bg-slate-50">
                                    Compose a SitRep
                                </button>
                            )}
                            {showSitRepForm && (
                                <AlertComposer storeUrl={urls.alerts_store} incidentUuid={incident.uuid}
                                    defaultSeverity="advisory" confirmLabel="Send this SitRep?" isSimulation={incident.is_exercise}
                                    audienceOptions={audienceOptions} />
                            )}
                            {can.dispatch_alert && !closed && (
                                <button type="button" onClick={() => setShowCommsForm((v) => !v)}
                                    className="w-full rounded border border-slate-300 px-3 py-2 text-xs hover:bg-slate-50">
                                    Compose a stakeholder communication
                                </button>
                            )}
                            {showCommsForm && (
                                <AlertComposer storeUrl={urls.alerts_store} incidentUuid={incident.uuid}
                                    defaultSeverity="advisory" confirmLabel="Send this communication?" isSimulation={incident.is_exercise}
                                    audienceOptions={audienceOptions} audienceNote />
                            )}
                        </div>
                    </section>
                </div>
            </div>

            <Modal show={regrading} onClose={() => setRegrading(false)} maxWidth="2xl">
                <form onSubmit={submitRegrade} className="space-y-4 p-6">
                    <h3 id="regrade-legend" className="text-base font-semibold text-slate-900">Re-grade severity</h3>
                    <SeverityMatrix
                        bands={severityBands}
                        suggested={incident.severity}
                        value={regradeForm.data.severity}
                        onChange={(v) => regradeForm.setData('severity', v)}
                        reason={regradeForm.data.reason}
                        onReasonChange={(v) => regradeForm.setData('reason', v)}
                        reasonError={regradeForm.errors.reason}
                        legendId="regrade-legend"
                    />
                    {regradeForm.data.severity === incident.severity && (
                        <div>
                            <label htmlFor="regrade_reason" className="block text-xs font-medium text-slate-700">Reason for this re-grade</label>
                            <input id="regrade_reason" required minLength={10} className="form-input mt-1 w-full text-sm"
                                value={regradeForm.data.reason} onChange={(e) => regradeForm.setData('reason', e.target.value)} />
                        </div>
                    )}
                    <div>
                        <label htmlFor="regrade_activation" className="block text-xs font-medium text-slate-700">Activation level</label>
                        <select id="regrade_activation" className="form-select mt-1 w-full text-sm" value={regradeForm.data.activation_level ?? ''}
                            onChange={(e) => regradeForm.setData('activation_level', e.target.value)}>
                            {activationLevels.map((a) => <option key={a.value} value={a.value}>{a.label}</option>)}
                        </select>
                    </div>
                    <div className="flex justify-end gap-2">
                        <button type="button" className="rounded border border-slate-300 px-3 py-1.5 text-sm" onClick={() => setRegrading(false)}>Cancel</button>
                        <button type="submit" disabled={regradeForm.processing} className="rounded bg-slate-800 px-3 py-1.5 text-sm text-white">Save</button>
                    </div>
                </form>
            </Modal>
        </AppLayout>
    );
}

function CountdownTile({ tile, notificationsUrl }) {
    const [now, setNow] = useState(Date.now());
    const announcedOverdue = useRef(false);

    useEffect(() => {
        const id = setInterval(() => setNow(Date.now()), 1000);
        return () => clearInterval(id);
    }, []);

    const due = tile.due_at ? new Date(tile.due_at).getTime() : null;
    const remainingMs = due != null ? due - now : null;
    const overdue = remainingMs != null && remainingMs < 0;

    useEffect(() => {
        if (overdue) announcedOverdue.current = true;
    }, [overdue]);

    const label = `${tile.regulator_label} — ${tile.kind_label}`;
    const dueText = tile.due_at ? (formatIncidentDateTime(tile.due_at) ?? 'no deadline recorded') : 'no deadline recorded';
    const durationText = remainingMs == null ? '—' : formatCountdown(Math.abs(remainingMs));

    return (
        <Link
            href={notificationsUrl}
            role="timer"
            aria-label={`${label} due ${overdue ? 'overdue' : `in ${durationText}`}, at ${dueText}`}
            className={`block rounded border p-3 text-sm ${overdue ? 'border-rose-400 bg-rose-50 text-rose-900' : 'border-slate-300 bg-slate-50 text-slate-800'}`}
        >
            <p className="font-semibold">{label}</p>
            <p className="text-lg font-mono">{overdue ? `${durationText} overdue` : durationText}</p>
            <p className="text-xs">due {dueText}</p>
            {overdue && <p aria-live="assertive" className="sr-only">{tile.regulator_label} notification is now overdue</p>}
        </Link>
    );
}

function ReportabilityInline({ label, onAnswer }) {
    return (
        <div>
            <p>{label}</p>
            <div className="mt-1 flex gap-2">
                <button type="button" className="rounded border border-amber-400 px-2 py-1 text-xs" onClick={() => onAnswer('yes')}>Yes</button>
                <button type="button" className="rounded border border-amber-400 px-2 py-1 text-xs" onClick={() => onAnswer('no')}>No</button>
                <button type="button" className="rounded border border-amber-400 px-2 py-1 text-xs" onClick={() => onAnswer('unknown')}>Still unknown</button>
            </div>
        </div>
    );
}

function EntryRow({ entry, depth, canManage, onCorrect }) {
    return (
        <li style={{ marginLeft: depth * 16 }} className={depth > 0 ? 'border-l-2 border-slate-200 pl-3' : ''}>
            <div className="flex gap-3 p-3 text-sm">
                <span className="w-14 shrink-0 font-mono text-xs text-slate-500">{formatTime(entry.logged_at)}</span>
                <span className={`h-fit shrink-0 rounded px-1.5 py-0.5 text-[11px] ${ENTRY_TONE[entry.entry_type] ?? ENTRY_TONE.decision}`}>
                    {entry.entry_type.replace('_', ' ')}
                </span>
                <span className="min-w-0 flex-1 whitespace-pre-wrap text-slate-800">
                    {depth > 0 && <span className="mb-1 block text-xs font-medium text-slate-500">Supersedes the entry above</span>}
                    {entry.content}
                </span>
                <span className="shrink-0 text-xs text-slate-400">{entry.logged_by ?? '—'}</span>
                {canManage && (
                    <button type="button" className="shrink-0 text-xs text-blue-700 underline" onClick={() => onCorrect(entry.id)}>
                        Correct
                    </button>
                )}
            </div>
            {entry.children?.length > 0 && (
                <ul>
                    {entry.children.map((c) => <EntryRow key={c.id} entry={c} depth={depth + 1} canManage={canManage} onCorrect={onCorrect} />)}
                </ul>
            )}
        </li>
    );
}

/**
 * ADR 0024 item B: EVERY CRISIS-ROOM COMPOSER CARRIES AN AUDIENCE, THE SAME
 * PICKER THE EMNS CONSOLE OFFERS. Defaults to this tenant's crisis-team call
 * tree when one is found by name (`/crisis/i` over `audience_options.call_
 * trees` — a heuristic, not a configured field, because nothing on this
 * payload names one explicitly); otherwise the operator must choose one
 * before the button below will submit. A roll-call, SitRep or stakeholder
 * update with nobody targeted is not a smaller version of the real thing —
 * it is nothing sent at all, silently.
 */
function AlertComposer({ storeUrl, incidentUuid, defaultSeverity, confirmLabel, isSimulation, audienceNote, audienceOptions = {} }) {
    const defaultAudience = useMemo(() => {
        const crisisTeam = (audienceOptions.call_trees ?? []).find((t) => /crisis/i.test(t.name));
        return crisisTeam ? { type: 'call_tree', id: crisisTeam.id } : null;
    }, [audienceOptions]);

    const form = useForm({
        title: '', message: '', severity: defaultSeverity, incident_id: incidentUuid,
        audience_rule: defaultAudience,
    });

    const audienceChosen = !!form.data.audience_rule;

    const submit = (e) => {
        e.preventDefault();
        // `message` is `required` server-side (`StoreBcmsAlertRequest`,
        // `errors.message`) — this guard puts the identical refusal in
        // front of the operator immediately, without a round trip (same as
        // `Emns/Index.jsx`'s composer).
        if (!form.data.message.trim()) {
            form.setError('message', 'Enter a message.');
            return;
        }
        if (!audienceChosen) return;
        if (!window.confirm(confirmLabel)) return;
        form.post(storeUrl, { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="space-y-1 rounded border border-dashed border-slate-300 p-2">
            <input className="form-input w-full text-sm" placeholder="Title" aria-label="Title"
                value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} />
            {form.errors.title && <p role="alert" className="text-xs text-rose-700">{form.errors.title}</p>}
            <textarea className="form-textarea w-full text-sm" rows={2} placeholder="Message" aria-label="Message"
                value={form.data.message}
                onChange={(e) => { form.setData('message', e.target.value); if (form.errors.message) form.clearErrors('message'); }} />
            {form.errors.message && <p role="alert" className="text-xs text-rose-700">{form.errors.message}</p>}
            <AudiencePicker
                idPrefix={`crisis-audience-${incidentUuid}-${defaultSeverity}`}
                legend="Who does this reach?"
                required
                options={audienceOptions}
                value={form.data.audience_rule}
                onChange={(rule) => form.setData('audience_rule', rule)}
                error={form.errors.audience_rule}
            />
            {!audienceChosen && (
                <p className="text-[11px] text-amber-700">Choose who this reaches before it can be sent.</p>
            )}
            {audienceNote && (
                <p className="rounded bg-amber-50 p-1 text-[11px] text-amber-800">
                    Confirm the audience before sending — this is recorded exactly as sent.
                </p>
            )}
            {isSimulation && <p className="text-[11px] text-violet-700">This incident is flagged as an exercise; the alert stays a simulation.</p>}
            <button type="submit" disabled={form.processing || !audienceChosen}
                className="w-full rounded bg-slate-800 py-1.5 text-xs text-white disabled:cursor-not-allowed disabled:opacity-60">
                Continue to dispatch
            </button>
        </form>
    );
}

function Stat({ label, value, tone = '', small = false }) {
    return (
        <div className="rounded border border-slate-200 bg-white p-3">
            <div className={`${small ? 'text-sm font-medium' : 'text-2xl font-semibold'} ${tone || 'text-slate-800'}`}>{value}</div>
            <div className="text-xs text-slate-500">{label}</div>
        </div>
    );
}

function nestBySupersession(entries) {
    const byId = new Map(entries.map((e) => [e.id, { ...e, children: [] }]));
    const roots = [];
    for (const e of byId.values()) {
        if (e.supersedes_entry_id && byId.has(e.supersedes_entry_id)) {
            byId.get(e.supersedes_entry_id).children.push(e);
        } else if (!e.supersedes_entry_id) {
            roots.push(e);
        }
    }
    // An entry whose superseded target isn't in the current page (shouldn't
    // happen — scoped to this incident) still renders as a root rather than vanishing.
    for (const e of byId.values()) {
        if (e.supersedes_entry_id && !byId.has(e.supersedes_entry_id) && !roots.includes(e)) {
            roots.push(e);
        }
    }
    return roots;
}

// Mirrors `App\Enums\Bcms\IncidentStatus::isTerminal()` (closed/cancelled) —
// the poll stops, the composer/task-form/comms/re-grade controls disappear.
function isTerminalStatus(status) {
    return status === 'closed' || status === 'cancelled';
}

function formatDuration(seconds) {
    if (seconds == null) return '—';
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    return h > 0 ? `${h}h ${m}m` : `${m}m`;
}

function formatCountdown(ms) {
    const totalSeconds = Math.floor(ms / 1000);
    const days = Math.floor(totalSeconds / 86400);
    const hours = Math.floor((totalSeconds % 86400) / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;
    if (days > 0) return `${days}d ${hours}h`;
    const pad = (n) => String(n).padStart(2, '0');
    return `${pad(hours)}:${pad(minutes)}:${pad(seconds)}`;
}

// Compact (no zone name — this is a dense per-row column, `crisis-room.md`
// §2's `HH:mm` convention), but still converted to the VIEWER's own local
// zone through the one shared formatter, never a raw slice of the server's
// UTC digits.
function formatTime(iso) {
    return formatIncidentDateTime(iso, { compact: true }) ?? '—';
}

function formatLocal(iso) {
    return formatIncidentDateTime(iso) ?? 'not yet';
}

