import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * Readiness, and the alert plan.
 *
 * THE LADDER PANEL IS A DEMO MOMENT. "Fourteen alerts, forty-six people, on
 * these days, through these channels" — shown before a single one has gone out,
 * which is only possible because the rows are materialised in advance. It is
 * the thing that makes somebody believe the countdown is real.
 *
 * THE GATE IS SHOWN EVEN WHEN IT DOES NOT GATE. A facilitator about to run an
 * exercise with four blocking items open should see that whether or not the
 * system will stop them — the difference between the two states is one sentence,
 * and hiding the count on an ungated exercise would be hiding the useful half.
 *
 * AN OVERRIDE IS NOT A COMPLETION AND DOES NOT LOOK LIKE ONE. It is amber, it
 * shows the reason, and it says the after-action report will carry it. A UI
 * that rendered "waived" as a green tick would let a bank run a DR failover
 * with no rollback plan and nothing on the screen to say anybody decided to.
 */
export default function Readiness({ occurrence = {}, tasks = [], gate = {}, ladder = {}, attendance = {}, can = {} }) {
    const [overriding, setOverriding] = useState(null);
    const override = useForm({ reason: '' });

    const done = tasks.filter((t) => t.status === 'complete' || t.status === 'waived').length;
    const pct = tasks.length === 0 ? null : Math.round((done / tasks.length) * 100);

    const statusChip = (t) => {
        const map = {
            complete: ['bg-green-100 text-green-800', 'complete'],
            waived: ['bg-amber-100 text-amber-900', 'overridden'],
            overdue: ['bg-red-100 text-red-800', 'overdue'],
            in_progress: ['bg-blue-100 text-blue-800', 'in progress'],
            open: ['bg-gray-100 text-gray-700', 'open'],
        };
        const [cls, label] = map[t.status] ?? map.open;

        return <span className={`rounded px-2 py-0.5 text-[11px] ${cls}`}>{label}</span>;
    };

    return (
        <AppLayout title={`Readiness — ${occurrence.title ?? 'Exercise'}`}>
            <Head title={`Readiness — ${occurrence.title ?? 'Exercise'}`} />

            <PageHeader
                title={occurrence.title ?? 'Exercise readiness'}
                subtitle={`${occurrence.type ?? ''}${occurrence.scheduled_date ? ` · ${occurrence.scheduled_date}` : ''}${occurrence.site ? ` · ${occurrence.site}` : ''}`}
                actions={(
                    <div className="flex flex-wrap gap-2">
                        <Link href={tryRoute('bcms.calendar.index')} className="btn-secondary text-sm">Calendar</Link>
                        {can.export && (
                            <a href={tryRoute('bcms.occurrences.deliveries.export', occurrence.uuid)} className="btn-secondary text-sm">
                                Delivery evidence
                            </a>
                        )}
                        {can.manage && (
                            <button
                                type="button"
                                className="btn-secondary text-sm"
                                onClick={() => router.post(tryRoute('bcms.occurrences.reminders.regenerate', occurrence.uuid), {}, { preserveScroll: true })}
                            >
                                Rebuild the alert plan
                            </button>
                        )}
                    </div>
                )}
            />

            {/* ---- The gate --------------------------------------------- */}

            <div className={`mb-6 rounded-lg border p-4 text-sm ${gate.allowed === false ? 'border-red-300 bg-red-50 text-red-900' : 'border-gray-200 bg-white text-gray-800'}`}>
                {gate.allowed === false ? (
                    <>
                        <p className="font-semibold">This exercise cannot start yet.</p>
                        <p className="mt-1">{gate.reason}</p>
                    </>
                ) : (
                    <p>{gate.reason ?? 'Every blocking readiness item is closed. This exercise can go ahead.'}</p>
                )}
            </div>

            <div className="grid gap-6 lg:grid-cols-[1fr_360px]">

                {/* ---- The checklist ------------------------------------ */}

                <div className="rounded-lg border border-gray-200 bg-white">
                    <div className="flex items-center justify-between border-b border-gray-100 px-5 py-4">
                        <h2 className="text-sm font-semibold text-gray-900">Readiness checklist</h2>
                        <p className="text-xs text-gray-600">
                            {pct === null ? 'No checklist' : `${done} of ${tasks.length} settled — ${pct}%`}
                        </p>
                    </div>

                    <ul className="divide-y divide-gray-100">
                        {tasks.length === 0 && (
                            <li className="px-5 py-8 text-center text-sm text-gray-600">
                                No readiness checklist has been generated for this exercise yet.
                            </li>
                        )}

                        {tasks.map((t) => (
                            <li key={t.id} className={`px-5 py-4 ${t.is_overdue ? 'bg-red-50' : ''}`}>
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm text-gray-900">
                                            {t.title}
                                            {t.is_blocking && (
                                                <span className="ml-2 rounded bg-gray-900 px-1.5 py-0.5 text-[10px] uppercase text-white">
                                                    blocking
                                                </span>
                                            )}
                                            {t.requires_evidence && (
                                                <span className="ml-1 rounded bg-blue-100 px-1.5 py-0.5 text-[10px] text-blue-800">
                                                    evidence
                                                </span>
                                            )}
                                        </p>
                                        <p className="mt-0.5 text-xs text-gray-500">
                                            {t.owner ?? 'Unassigned'}
                                            {t.due_date && ` · due ${t.due_date}`}
                                            {` · T${t.due_offset_days >= 0 ? '+' : ''}${t.due_offset_days}`}
                                        </p>
                                        {t.override_reason && (
                                            <p className="mt-1 rounded bg-amber-50 p-2 text-xs text-amber-900">
                                                <strong>Overridden{t.overridden_at ? ` on ${t.overridden_at}` : ''}:</strong>{' '}
                                                {t.override_reason} — the after-action report will carry this.
                                            </p>
                                        )}
                                    </div>

                                    <div className="flex items-center gap-3">
                                        {statusChip(t)}

                                        {can.facilitate && !['complete', 'waived'].includes(t.status) && (
                                            <button
                                                type="button"
                                                className="text-xs text-blue-700 hover:underline"
                                                onClick={() => router.post(tryRoute('bcms.readiness-tasks.complete', t.id), {}, { preserveScroll: true })}
                                            >
                                                Complete
                                            </button>
                                        )}

                                        {can.override && t.is_blocking && !['complete', 'waived'].includes(t.status) && (
                                            <button
                                                type="button"
                                                className="text-xs text-amber-700 hover:underline"
                                                onClick={() => setOverriding(t)}
                                            >
                                                Override
                                            </button>
                                        )}
                                    </div>
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>

                {/* ---- The alert plan ----------------------------------- */}

                <div className="space-y-4">
                    <div className="rounded-lg border border-gray-200 bg-white">
                        <div className="border-b border-gray-100 px-5 py-4">
                            <h2 className="text-sm font-semibold text-gray-900">The alert plan</h2>
                            <p className="mt-1 text-xs text-gray-600">
                                {ladder.total_recipients === null
                                    ? 'Nothing is planned yet — this exercise has no date to count down to.'
                                    : `${ladder.total_sends} alerts, ${ladder.total_recipients} recipients. ${ladder.sent} already sent.`}
                            </p>
                            {occurrence.unannounced && (
                                <p className="mt-1 text-xs text-amber-800">
                                    Unannounced: participants get no countdown. Only the facilitator&rsquo;s rungs are planned.
                                </p>
                            )}
                        </div>

                        <ul className="divide-y divide-gray-100 text-xs">
                            {(ladder.entries ?? []).map((e) => (
                                <li key={e.id} className={`px-4 py-2.5 ${e.status === 'voided' ? 'opacity-50' : ''}`}>
                                    <div className="flex items-baseline justify-between gap-2">
                                        <span className="font-mono text-gray-500">
                                            T{e.day_offset >= 0 ? '+' : ''}{e.day_offset}
                                        </span>
                                        <span className="flex-1 text-gray-900">{e.label}</span>
                                        <span className={`rounded px-1.5 py-0.5 text-[10px] ${
                                            e.status === 'sent' ? 'bg-green-100 text-green-800'
                                                : e.status === 'voided' ? 'bg-gray-100 text-gray-500'
                                                    : e.status === 'skipped' ? 'bg-amber-100 text-amber-800'
                                                        : 'bg-blue-50 text-blue-800'}`}>
                                            {e.status}
                                        </span>
                                    </div>
                                    <div className="mt-0.5 flex flex-wrap gap-x-3 text-[11px] text-gray-500">
                                        <span>{e.send_at_local}</span>
                                        <span>{e.channels.join(', ')}</span>
                                        <span>{e.mode}</span>
                                        <span>{e.recipient_count} people</span>
                                    </div>
                                    {e.skip_reason && (
                                        <p className="mt-1 text-[11px] text-amber-800">{e.skip_reason}</p>
                                    )}
                                </li>
                            ))}
                            {(ladder.entries ?? []).length === 0 && (
                                <li className="px-4 py-6 text-center text-gray-600">No alerts are planned.</li>
                            )}
                        </ul>
                    </div>

                    {/* ---- Attendance ---------------------------------- */}

                    <div className="rounded-lg border border-gray-200 bg-white p-5 text-xs">
                        <h2 className="mb-2 text-sm font-semibold text-gray-900">Attendance</h2>
                        <p className="text-gray-700">
                            {attendance.percentage === null
                                ? 'Nobody has been invited yet.'
                                : `${attendance.accepted} of ${attendance.invited} confirmed (${attendance.percentage}%).`}
                            {attendance.declined > 0 && ` ${attendance.declined} declined, each with a deputy.`}
                        </p>
                        {(attendance.outstanding ?? []).length > 0 && (
                            <details className="mt-2">
                                <summary className="cursor-pointer text-gray-600">
                                    {attendance.outstanding.length} have not answered
                                </summary>
                                <ul className="mt-1 space-y-0.5 text-gray-600">
                                    {attendance.outstanding.map((p) => <li key={p.user_id}>{p.name} ({p.role})</li>)}
                                </ul>
                            </details>
                        )}
                    </div>
                </div>
            </div>

            {/* ---- Override modal ---------------------------------------- */}

            {overriding && (
                <div className="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            override.post(tryRoute('bcms.readiness-tasks.override', overriding.id), {
                                preserveScroll: true,
                                onSuccess: () => { setOverriding(null); override.reset(); },
                            });
                        }}
                        className="w-full max-w-lg space-y-4 rounded-lg bg-white p-6 shadow-xl"
                    >
                        <h3 className="text-base font-semibold text-gray-900">Override a blocking item</h3>
                        <p className="rounded bg-amber-50 p-3 text-xs text-amber-900">
                            &ldquo;{overriding.title}&rdquo; is blocking because the exercise is not meaningful without
                            it. Overriding lets the exercise go ahead anyway. The reason below is recorded against the
                            exercise and appears in the after-action report — this is a decision, not a dismissal.
                        </p>
                        <label className="block text-sm">
                            <span className="text-gray-700">Why is this being overridden?</span>
                            <textarea
                                rows={3} required minLength={10}
                                className="mt-1 w-full rounded border-gray-300 text-sm"
                                value={override.data.reason}
                                onChange={(e) => override.setData('reason', e.target.value)}
                            />
                            {override.errors.reason && <span className="text-xs text-red-600">{override.errors.reason}</span>}
                        </label>
                        <div className="flex gap-2">
                            <button type="submit" className="btn-primary text-sm" disabled={override.processing}>
                                Record the override
                            </button>
                            <button type="button" className="btn-secondary text-sm" onClick={() => setOverriding(null)}>
                                Cancel
                            </button>
                        </div>
                    </form>
                </div>
            )}
        </AppLayout>
    );
}
