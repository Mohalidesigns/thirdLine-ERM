import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import { formatIncidentDateTime } from '@/Components/Bcms/dateDisplay';

/**
 * `docs/bcms/screens/dr-failover-failback-record.md` — a READ-ONLY evidence
 * assembly for a real DR invocation during this incident. There is no store
 * route on this screen (ADR 0020 §4): a real failover/failback is never
 * written into `bcms_dr_tests`, and every figure here is sourced from
 * `bcms_plan_activations`, `bcms_incident_log` and the post-incident
 * review's `quantitative_results` — never recomputed on the client.
 *
 * ONE PAGE, EVERY AFFECTED SYSTEM. The spec describes a per-system route;
 * this build assembles every DR system this incident's plan activation(s)
 * resolve to onto one page, because `bcms_plan_activations` already ties
 * each affected system back to this one incident — see
 * `IncidentPresenter::drInvocation()` for the reasoning.
 *
 * A FIGURE IS COMPUTED OR IT IS ABSENT. Every section below states "not yet
 * recorded" rather than a zero or a dash when the PIR has not supplied a
 * value — never `?? 0`.
 */
export default function DrInvocation({
    incident = {}, has_invocation: hasInvocation = false, pir = {}, systems = [],
    corrective_actions: correctiveActions = [], incident_url: incidentUrl, notification_log_url: notificationLogUrl,
}) {
    return (
        <AppLayout title={`DR invocation — ${incident.reference}`}>
            <Head title={`DR invocation — ${incident.reference}`} />

            <PageHeader
                title={`Real DR invocation — ${incident.reference} · ${incident.title}`}
                subtitle="This is not a scheduled DR test. It is the record of what happened during a live incident."
                actions={(
                    <Link href={incidentUrl} className="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">
                        Back to incident
                    </Link>
                )}
            />

            {!hasInvocation ? (
                <div className="rounded border border-slate-200 bg-white p-6 text-sm text-slate-700">
                    <p>No DR invocation is recorded against this incident.</p>
                    <Link href={incidentUrl} className="mt-2 inline-block text-blue-700 underline">Back to the incident</Link>
                </div>
            ) : (
                <>
                    {pir.exists && pir.is_draft && (
                        <div className="mb-4 rounded border border-sky-300 bg-sky-50 p-3 text-sm text-sky-900">
                            The post-incident review is draft — not yet finalised. The figures below may still change.
                        </div>
                    )}
                    {!pir.exists && (
                        <div className="mb-4 rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                            Not yet recorded — the actual outage, data loss, failback and integrity-verification figures
                            are captured in the post-incident review, created once the incident is closed.
                        </div>
                    )}

                    <div className="space-y-8">
                        {systems.map((system) => (
                            <SystemRecord key={system.uuid} system={system} pirExists={pir.exists} />
                        ))}
                    </div>

                    <section className="mt-8 rounded border border-slate-200 bg-white p-4">
                        <h2 className="text-sm font-semibold text-slate-800">Corrective actions</h2>
                        <p className="mb-3 text-xs text-slate-400">
                            Source: bcms_findings where incident_id matches and dr_test_id is absent — incident-wide;
                            there is no per-system column to filter this list further.
                        </p>
                        {correctiveActions.length === 0 ? (
                            <p className="text-sm text-slate-500">No finding raised from this incident yet.</p>
                        ) : (
                            <ul className="space-y-3">
                                {correctiveActions.map((f) => (
                                    <li key={f.uuid} className="rounded border border-slate-200 p-3 text-sm">
                                        <p className="font-medium">{f.reference} — {f.description}</p>
                                        <p className="text-xs text-slate-500">{f.status}</p>
                                        {f.actions.length > 0 && (
                                            <ul className="mt-1 text-xs text-slate-600">
                                                {f.actions.map((a) => (
                                                    <li key={a.uuid}>{a.reference} — {a.title} ({a.owner ?? 'unassigned'})</li>
                                                ))}
                                            </ul>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    <div className="mt-4 flex flex-wrap gap-3 text-sm">
                        <Link href={notificationLogUrl} className="text-blue-700 underline">View the regulatory notification log</Link>
                        {pir.url && <Link href={pir.url} className="text-blue-700 underline">View the post-incident review</Link>}
                    </div>
                </>
            )}
        </AppLayout>
    );
}

function SystemRecord({ system, pirExists }) {
    const rtoTargetMinutes = system.recovery.rto_target_hours != null ? system.recovery.rto_target_hours * 60 : null;
    const outage = system.recovery.outage_actual_minutes;
    const dataLoss = system.recovery.data_loss_actual_minutes;

    return (
        <section aria-labelledby={`dr-invocation-${system.uuid}`} className="rounded border border-slate-200 bg-white p-4">
            <h2 id={`dr-invocation-${system.uuid}`} className="text-base font-semibold text-slate-900">
                {system.name} — real invocation during this incident
            </h2>
            <Link href={system.dr_system_url} className="text-xs text-blue-700 underline">View this system's DR register / test history</Link>

            {/* 1. Authorisation */}
            <SubSection number={1} title="Authorisation" source="bcms_plan_activations">
                {system.authorisation === null ? (
                    <p className="text-sm text-slate-500">No plan activation is recorded for this system's runbook.</p>
                ) : (
                    <dl className="grid gap-2 text-sm sm:grid-cols-2">
                        <Field label="Authorised by" value={system.authorisation.activated_by ?? '—'} />
                        <Field label="Activated at" value={formatLocal(system.authorisation.activated_at)} />
                        <Field label="Runbook" value={system.authorisation.runbook_title ?? '—'} />
                        <Field label="Reason" value={system.authorisation.activation_reason ?? '—'} />
                    </dl>
                )}
            </SubSection>

            {/* 2. Timeline */}
            <SubSection number={2} title="Timeline" source="bcms_incident_log, filtered to entries mentioning this system">
                {system.timeline.length === 0 ? (
                    <p className="text-sm text-slate-500">No decision-log entry mentions this system yet.</p>
                ) : (
                    <ul className="divide-y divide-slate-100 text-sm">
                        {system.timeline.map((e) => (
                            <li key={e.id} className="flex gap-3 py-2">
                                <span className="w-32 shrink-0 font-mono text-xs text-slate-500">{formatLocal(e.logged_at)}</span>
                                <span className="w-24 shrink-0 rounded bg-slate-100 px-1.5 py-0.5 text-center text-[11px]">{e.entry_type}</span>
                                <span className="flex-1">{e.content}</span>
                                <span className="shrink-0 text-xs text-slate-400">{e.logged_by ?? '—'}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </SubSection>

            {/* 3. Actual outage vs RTO, data loss vs RPO */}
            <SubSection number={3} title="Actual outage vs RTO, and data loss vs RPO" source="The PIR's quantitative_results">
                {!pirExists ? (
                    <NotYetRecorded />
                ) : (
                    <dl className="grid gap-2 text-sm sm:grid-cols-2">
                        <Field label="Outage" value={outage != null
                            ? `${outage} min actual vs ${rtoTargetMinutes ?? '—'} min target`
                            : 'Not yet recorded'} />
                        <Field label="Data loss" value={dataLoss != null
                            ? `${dataLoss} min actual vs ${system.recovery.rpo_target_minutes ?? '—'} min target`
                            : 'Not yet recorded'} />
                        <Field label="How measured" value={system.recovery.measured_how ?? 'Not yet recorded'} />
                    </dl>
                )}
            </SubSection>

            {/* 4. Failback */}
            <SubSection number={4} title="Failback" source="The PIR's quantitative_results">
                {!pirExists ? (
                    <NotYetRecorded />
                ) : system.failback.occurred === true ? (
                    <dl className="grid gap-2 text-sm sm:grid-cols-2">
                        <Field label="Failed back at" value={formatLocal(system.failback.at)} />
                        <Field label="Anything lost at DR in the return" value={system.failback.data_lost_note ?? 'Not yet recorded'} />
                    </dl>
                ) : system.failback.occurred === false ? (
                    <p className="text-sm text-slate-700">
                        No failback recorded. This system is still running from {system.dr_site ?? 'the DR site'}.
                    </p>
                ) : (
                    <NotYetRecorded />
                )}
            </SubSection>

            {/* 5. Data-integrity verification */}
            <SubSection number={5} title="Data-integrity verification" source="The PIR's quantitative_results">
                {!pirExists || !system.integrity_verification ? <NotYetRecorded /> : (
                    <p className="text-sm text-slate-700">{system.integrity_verification}</p>
                )}
            </SubSection>

            {/* 6. Communications */}
            <SubSection number={6} title="Communications" source="bcms_incident_log, communication-type entries mentioning this system">
                {system.communications.length === 0 ? (
                    <p className="text-sm text-slate-500">No communication entry mentions this system yet.</p>
                ) : (
                    <ul className="divide-y divide-slate-100 text-sm">
                        {system.communications.map((e) => (
                            <li key={e.id} className="flex gap-3 py-2">
                                <span className="w-32 shrink-0 font-mono text-xs text-slate-500">{formatLocal(e.logged_at)}</span>
                                <span className="flex-1">{e.content}</span>
                                <span className="shrink-0 text-xs text-slate-400">{e.logged_by ?? '—'}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </SubSection>
        </section>
    );
}

function SubSection({ number, title, source, children }) {
    return (
        <section className="mt-4 border-t border-slate-100 pt-4">
            <h3 className="text-sm font-semibold text-slate-800">{number}. {title}</h3>
            {source && <p className="mb-2 text-xs text-slate-400">Source: {source}</p>}
            <div>{children}</div>
        </section>
    );
}

function NotYetRecorded() {
    return (
        <p className="text-sm text-slate-500">
            Not yet recorded — these figures are captured in the post-incident review, created once the incident is closed.
        </p>
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

function formatLocal(iso) {
    return formatIncidentDateTime(iso) ?? 'not yet';
}
