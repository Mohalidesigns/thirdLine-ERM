import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';

/**
 * One incident, and the clocks running on it — AC-07.
 *
 * THE COUNTDOWN IS THE ONLY THING ABOVE THE FOLD. Everything else about an
 * incident can wait; a twenty-four-hour window cannot, and a page that put the
 * description first would bury the one number that changes what somebody does
 * in the next hour.
 *
 * THE CLOCK TICKS IN THE BROWSER but is never AUTHORITATIVE there. The server
 * computed the deadline and the state; this only interpolates between renders
 * so the number does not sit still while somebody watches it. A page left open
 * overnight shows a stale state, which is why the state badge comes from the
 * server payload rather than from the local arithmetic.
 */
export default function Show({
    incident = {}, clocks = [], assessments = [], drafts = [], escalations = [], settings = {}, can = {},
}) {
    return (
        <AppLayout title={incident.reference}>
            <Head title={incident.reference} />

            <PageHeader
                title={incident.title}
                subtitle={`${incident.reference} · ${incident.third_party ?? ''} · reported ${incident.reported_to_us_at ?? 'not recorded'}`}
                actions={can.manage ? (
                    <div className="flex gap-2">
                        <button
                            type="button"
                            className="btn btn-secondary"
                            onClick={() => router.post(route('tprm.incidents.assess', incident.uuid))}
                        >
                            Reassess
                        </button>
                        <button
                            type="button"
                            className="btn btn-secondary"
                            onClick={() => router.post(route('tprm.incidents.drafts.build', incident.uuid))}
                        >
                            Prepare drafts
                        </button>
                    </div>
                ) : null}
            />

            {clocks.length > 0 ? (
                <div className="mb-6 grid gap-4 md:grid-cols-2">
                    {clocks.map((clock) => <Countdown key={clock.regulator} clock={clock} />)}
                </div>
            ) : (
                <div className="card mb-6 p-4 text-sm text-gray-600">
                    No regulatory clock is running on this incident.
                </div>
            )}

            {!settings.has_materiality_basis && (
                <div className="card mb-6 border-l-4 border-amber-400 p-4 text-sm text-gray-700">
                    {settings.note}
                </div>
            )}

            <div className="grid gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <section className="card p-4">
                        <h2 className="text-sm font-semibold text-gray-900">Reportability</h2>
                        <ul className="mt-3 space-y-3">
                            {assessments.map((assessment) => (
                                <li key={assessment.regulator}>
                                    <p className="text-sm font-medium text-gray-900">
                                        {assessment.regulator_label}
                                        {' — '}
                                        <span className={
                                            assessment.undetermined ? 'text-amber-700'
                                                : assessment.reportable ? 'text-red-700' : 'text-gray-600'
                                        }>
                                            {assessment.undetermined
                                                ? 'cannot be determined'
                                                : assessment.reportable ? 'reportable' : 'not reportable'}
                                        </span>
                                    </p>
                                    <p className="mt-0.5 text-xs text-gray-600">{assessment.rationale}</p>
                                    <p className="mt-0.5 text-xs text-gray-400">{assessment.citation}</p>
                                </li>
                            ))}
                        </ul>
                    </section>

                    <section className="card p-4">
                        <h2 className="text-sm font-semibold text-gray-900">What happened</h2>
                        <p className="mt-2 whitespace-pre-wrap text-sm text-gray-700">{incident.description}</p>
                        <dl className="mt-4 grid grid-cols-2 gap-3 text-xs">
                            <Fact label="Detected by the provider" value={incident.detected_at} />
                            <Fact label="Told to us" value={incident.reported_to_us_at} />
                            <Fact label="Personal data" value={incident.personal_data_involved ? 'Yes' : 'No'} />
                            <Fact label="Data subjects" value={incident.data_subjects_affected} />
                            <Fact label="Customers affected" value={incident.customers_affected} />
                            <Fact
                                label="Estimated loss"
                                value={incident.estimated_loss_minor
                                    ? `${incident.currency ?? ''} ${(incident.estimated_loss_minor / 100).toLocaleString()}`
                                    : null}
                            />
                        </dl>

                        {can.manage && incident.estimated_loss_minor > 0 && (
                            <button
                                type="button"
                                className="btn btn-sm btn-secondary mt-4"
                                onClick={() => router.post(route('tprm.incidents.loss-register', incident.uuid))}
                            >
                                {incident.erm_loss_event_id
                                    ? 'Update the loss register'
                                    : 'Post to the loss register'}
                            </button>
                        )}
                    </section>

                    {drafts.map((draft) => <Draft key={draft.id} draft={draft} can={can} />)}
                </div>

                <aside className="space-y-4">
                    <section className="card p-4">
                        <h2 className="text-sm font-semibold text-gray-900">Escalations</h2>
                        {escalations.length === 0 ? (
                            <p className="mt-2 text-xs text-gray-500">None yet.</p>
                        ) : (
                            <ul className="mt-2 space-y-2">
                                {escalations.map((escalation, index) => (
                                    <li key={index} className="text-xs text-gray-600">
                                        {escalation.regulator} at {escalation.threshold_pct}% —{' '}
                                        {escalation.fired_at}, {escalation.recipients} notified
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </aside>
            </div>
        </AppLayout>
    );
}

function Countdown({ clock }) {
    const [remaining, setRemaining] = useState(clock.hours_remaining);

    useEffect(() => {
        // Interpolates between server renders so the number does not sit still
        // while somebody watches it. The STATE badge below still comes from
        // the server — a page left open overnight must not decide for itself
        // that a deadline has passed.
        const deadline = new Date(clock.deadline_at).getTime();
        const tick = () => setRemaining((deadline - Date.now()) / 3600000);

        tick();
        const id = setInterval(tick, 30000);

        return () => clearInterval(id);
    }, [clock.deadline_at]);

    const tone = {
        reported: 'border-green-500 text-green-800',
        breached: 'border-red-600 text-red-800',
        critical: 'border-red-500 text-red-800',
        warning: 'border-amber-500 text-amber-800',
        running: 'border-blue-500 text-blue-800',
    }[clock.state] ?? 'border-gray-300 text-gray-700';

    return (
        <div className={`card border-l-4 p-4 ${tone}`}>
            <div className="flex items-baseline justify-between">
                <h2 className="text-sm font-semibold">{clock.label}</h2>
                <span className="text-xs uppercase tracking-wide">{clock.state}</span>
            </div>

            {clock.state === 'reported' ? (
                <p className="mt-2 text-2xl font-semibold">Reported</p>
            ) : (
                <p className="mt-2 text-2xl font-semibold tabular-nums">
                    {remaining >= 0
                        ? `${Math.floor(remaining)}h ${Math.floor((remaining % 1) * 60)}m left`
                        : `${Math.abs(Math.floor(remaining))}h overdue`}
                </p>
            )}

            <div className="mt-2 h-1.5 w-full overflow-hidden rounded bg-gray-100">
                <div
                    className={`h-full ${clock.elapsed_pct >= 80 ? 'bg-red-600' : clock.elapsed_pct >= 50 ? 'bg-amber-500' : 'bg-blue-600'}`}
                    style={{ width: `${clock.elapsed_pct}%` }}
                />
            </div>

            <p className="mt-1 text-xs opacity-80">
                Due {new Date(clock.deadline_at).toLocaleString()} · {clock.citation}
            </p>
        </div>
    );
}

/** TRD §12.7. Nothing is ever sent from here. */
function Draft({ draft, can }) {
    const [recording, setRecording] = useState(false);

    return (
        <section className="card p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <h2 className="text-sm font-semibold text-gray-900">{draft.title}</h2>
                <span className="text-xs text-gray-500">{draft.status}</span>
            </div>

            {draft.gaps.length > 0 && (
                <div className="mt-2 rounded bg-amber-50 p-2">
                    <p className="text-xs font-medium text-amber-900">
                        A regulator will ask for these and we cannot yet answer them
                    </p>
                    <ul className="mt-1 list-inside list-disc text-xs text-amber-900">
                        {draft.gaps.map((gap, index) => <li key={index}>{gap}</li>)}
                    </ul>
                </div>
            )}

            <pre className="mt-3 max-h-64 overflow-y-auto whitespace-pre-wrap rounded bg-gray-50 p-3 text-xs text-gray-800">
                {draft.body}
            </pre>

            {draft.submitted_at ? (
                <p className="mt-3 text-xs text-green-800">
                    Recorded as submitted {draft.submitted_at}, reference {draft.submission_reference}.
                </p>
            ) : can.notify ? (
                <div className="mt-3 flex flex-wrap gap-2">
                    {!draft.approved_at && (
                        <button
                            type="button"
                            className="btn btn-sm btn-primary"
                            onClick={() => router.post(route('tprm.incidents.drafts.approve', draft.id))}
                        >
                            Approve the wording
                        </button>
                    )}
                    {draft.can_submit && (
                        <button
                            type="button"
                            className="btn btn-sm btn-secondary"
                            onClick={() => setRecording(true)}
                        >
                            Record that it was sent
                        </button>
                    )}
                </div>
            ) : (
                <p className="mt-3 text-xs text-gray-500">
                    Approving and recording a submission needs the notification permission.
                </p>
            )}

            {recording && <SubmissionDialog draft={draft} onClose={() => setRecording(false)} />}
        </section>
    );
}

function SubmissionDialog({ draft, onClose }) {
    const form = useForm({ reference: '', submitted_at: '' });

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4">
            <form
                className="w-full max-w-md rounded-lg bg-white p-5 shadow-xl"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(route('tprm.incidents.drafts.submit', draft.id), { onSuccess: onClose });
                }}
            >
                <h2 className="text-base font-semibold text-gray-900">Record the submission</h2>
                <p className="mt-1 text-xs text-gray-600">
                    Nothing was sent from this system. Record what you sent and the reference the regulator
                    gave you — that is what stops the clock.
                </p>

                <label className="mt-3 block text-sm font-medium text-gray-700">Their reference</label>
                <input
                    className="w-full rounded border border-gray-200 p-2 text-sm"
                    value={form.data.reference}
                    onChange={(event) => form.setData('reference', event.target.value)}
                />
                {form.errors.reference && <p className="mt-1 text-xs text-red-700">{form.errors.reference}</p>}

                <label className="mt-3 block text-sm font-medium text-gray-700">When you sent it</label>
                <input
                    type="datetime-local"
                    className="w-full rounded border border-gray-200 p-2 text-sm"
                    value={form.data.submitted_at}
                    onChange={(event) => form.setData('submitted_at', event.target.value)}
                />
                <p className="mt-1 text-xs text-gray-500">Leave blank for now.</p>

                <div className="mt-5 flex justify-end gap-2">
                    <button type="button" className="px-3 py-1.5 text-sm text-gray-600" onClick={onClose}>Cancel</button>
                    <button type="submit" className="rounded bg-blue-600 px-3 py-1.5 text-sm text-white">Record</button>
                </div>
            </form>
        </div>
    );
}

function Fact({ label, value }) {
    return (
        <div>
            <dt className="text-gray-500">{label}</dt>
            <dd className="text-gray-900">{value ?? <span className="text-gray-400">not recorded</span>}</dd>
        </div>
    );
}
