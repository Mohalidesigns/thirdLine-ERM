import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import { toLocalInput, localInputToIso } from '@/Components/Bcms/dateInput';
import { formatIncidentDateTime } from '@/Components/Bcms/dateDisplay';
import AwarenessField, { awarenessDefault, isAwarenessLate } from '@/Components/Bcms/AwarenessField';

const KIND_TONE = {
    initial: 'bg-slate-200 text-slate-800',
    intermediate: 'bg-blue-100 text-blue-800',
    final: 'bg-emerald-100 text-emerald-800',
    supplementary: 'bg-amber-100 text-amber-900',
};

/**
 * `docs/bcms/screens/incident-notification-log.md` — a register, not a
 * composer. "Record as submitted" — nothing here transmits anything to a
 * regulator (ADR 0020 §2 point 4).
 */
export default function NotificationLog({
    incident = {}, obligations = [], notification_kinds: kinds = [], can = {},
    record_url: recordUrl, classify_url: classifyUrl, export_url: exportUrl, crisis_room_url: crisisRoomUrl,
}) {
    const [classifying, setClassifying] = useState(false);

    return (
        <AppLayout title={`Regulatory notifications — ${incident.reference}`}>
            <Head title={`Regulatory notifications — ${incident.reference}`} />

            <PageHeader
                title={`Regulatory notifications — ${incident.reference}`}
                subtitle={incident.title}
                actions={(
                    <div className="flex gap-2">
                        <Link href={crisisRoomUrl} className="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">
                            Crisis room
                        </Link>
                        {can.export && (
                            <a href={exportUrl} className="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">Export</a>
                        )}
                    </div>
                )}
            />

            {can.manage && (
                <div className="mb-4">
                    <button type="button" onClick={() => setClassifying((v) => !v)} className="text-sm text-blue-700 underline">
                        Classify a new obligation
                    </button>
                    {classifying && <ClassifyForm classifyUrl={classifyUrl} incident={incident} onDone={() => setClassifying(false)} />}
                </div>
            )}

            {obligations.length === 0 && (
                <p className="rounded border border-dashed border-slate-300 p-6 text-sm text-slate-600">
                    No regulatory obligation has been classified for this incident yet. If that is wrong,
                    classify one above or return to the crisis room to answer the reportability questions.
                </p>
            )}

            <div className="space-y-4">
                {obligations.map((o) => (
                    <ObligationCard key={`${o.regulator}-${o.basis_clause_ref}`} obligation={o} kinds={kinds}
                        canNotify={can.notify} recordUrl={recordUrl} />
                ))}
            </div>
        </AppLayout>
    );
}

function ObligationCard({ obligation, kinds, canNotify, recordUrl }) {
    const fulfilled = !obligation.is_open;
    const openRow = obligation.submissions.find((s) => !s.submitted_at);
    // A kind counts as "used" only once it is actually SUBMITTED — the still-open
    // row `classify()` creates for `initial` must keep offering "Initial
    // notification" in the dropdown below, or a user could never complete the
    // one row that matters most. Once submitted, a non-repeating kind drops
    // out of the list exactly as before.
    const usedKinds = new Set(obligation.submissions.filter((s) => s.submitted_at).map((s) => s.kind));
    const availableKinds = kinds.filter((k) => k.repeats || !usedKinds.has(k.value));

    const form = useForm({ regulator: obligation.regulator, kind: availableKinds[0]?.value ?? 'initial', reference: '', content_snapshot: {} });

    const submit = (e) => {
        e.preventDefault();
        form.post(recordUrl, { preserveScroll: true, onSuccess: () => form.reset('reference') });
    };

    return (
        <section aria-label={`${obligation.regulator_label} — ${obligation.basis_clause_ref}`}
            className={`rounded border p-4 ${fulfilled ? 'border-emerald-300 bg-emerald-50/40' : openRow?.is_overdue ? 'border-rose-400 bg-rose-50' : 'border-slate-200 bg-white'}`}>
            <header className="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                <div>
                    <h2 className="text-sm font-semibold text-slate-800">{obligation.regulator_label}</h2>
                    <p className="text-xs text-slate-500">{obligation.basis_clause_ref}</p>
                </div>
                {fulfilled ? (
                    <span className="rounded bg-emerald-100 px-2 py-0.5 text-xs text-emerald-800">
                        Fulfilled — last submission {obligation.submissions.at(-1)?.kind_label} on {formatIncidentDateTime(obligation.submissions.at(-1)?.submitted_at) ?? '—'}
                    </span>
                ) : openRow?.is_overdue ? (
                    <span className="rounded bg-rose-100 px-2 py-0.5 text-xs font-medium text-rose-800">Overdue</span>
                ) : null}
            </header>

            {openRow?.is_overdue && (
                <p className="mb-2 rounded bg-rose-100 p-2 text-xs text-rose-900">
                    This obligation went overdue at {formatIncidentDateTime(openRow.due_at) ?? '—'}.
                </p>
            )}

            <table className="data-table mb-3 text-xs">
                <thead>
                    <tr>
                        <th>Kind</th>
                        <th>Due at</th>
                        <th>Submitted at</th>
                        <th>By</th>
                        <th>Reference</th>
                        <th />
                    </tr>
                </thead>
                <tbody>
                    {obligation.submissions.map((s) => (
                        <SubmissionRow key={s.id} submission={s} />
                    ))}
                </tbody>
            </table>

            {canNotify && (
                <form onSubmit={submit} className="flex flex-wrap items-end gap-2 rounded border border-dashed border-slate-300 p-2">
                    <div>
                        <label htmlFor={`kind-${obligation.regulator}`} className="block text-[10px] font-medium text-slate-600">Kind</label>
                        <select id={`kind-${obligation.regulator}`} className="form-select text-xs" value={form.data.kind}
                            onChange={(e) => form.setData('kind', e.target.value)}>
                            {availableKinds.map((k) => <option key={k.value} value={k.value}>{k.label}</option>)}
                        </select>
                    </div>
                    <div>
                        <label htmlFor={`ref-${obligation.regulator}`} className="block text-[10px] font-medium text-slate-600">Reference</label>
                        <input id={`ref-${obligation.regulator}`} className="form-input text-xs" value={form.data.reference}
                            onChange={(e) => form.setData('reference', e.target.value)} />
                    </div>
                    <button type="submit" disabled={form.processing}
                        aria-label={`Record a submission — ${obligation.regulator_label} ${form.data.kind}`}
                        className="rounded bg-slate-800 px-3 py-1.5 text-xs text-white">
                        Record as submitted
                    </button>
                    {Object.values(form.errors).map((err, i) => <p key={i} role="alert" className="w-full text-xs text-rose-700">{err}</p>)}
                </form>
            )}
        </section>
    );
}

function SubmissionRow({ submission }) {
    const [open, setOpen] = useState(false);

    return (
        <>
            <tr>
                <td><span className={`rounded px-1.5 py-0.5 ${KIND_TONE[submission.kind] ?? ''}`}>{submission.kind_label}{submission.sequence > 1 ? ` #${submission.sequence}` : ''}</span></td>
                <td>{formatIncidentDateTime(submission.due_at) ?? '—'}</td>
                <td className={!submission.submitted_at ? (submission.is_overdue ? 'text-rose-700' : 'text-amber-700') : ''}>
                    {formatIncidentDateTime(submission.submitted_at) ?? '— open —'}
                </td>
                <td>{submission.submitted_by ?? '—'}</td>
                <td className="font-mono">{submission.reference ?? (submission.submitted_at ? 'not yet issued' : '—')}</td>
                <td>
                    <button type="button" className="text-blue-700 underline" onClick={() => setOpen((v) => !v)}>
                        {open ? 'Hide' : 'View content'}
                    </button>
                </td>
            </tr>
            {open && (
                <tr>
                    <td colSpan={6} className="bg-slate-50 text-xs">
                        {submission.content_snapshot ? (
                            <pre className="whitespace-pre-wrap">{JSON.stringify(submission.content_snapshot, null, 2)}</pre>
                        ) : (
                            <span className="text-slate-500">Content not recorded for this submission</span>
                        )}
                    </td>
                </tr>
            )}
        </>
    );
}

function ClassifyForm({ classifyUrl, incident, onDone }) {
    // ADR 0020 Amendment 2 rules 5-7, same `AwarenessField` `CrisisRoom.jsx`
    // uses — this form is the OTHER place an officer answers a reportability
    // question `yes` (code-review defect 4, found a second time on this
    // screen), and the two must not drift: default to `detected_at`, falling
    // back to `declared_at`, never "now"; a reason only when moved later.
    const defaultAt = awarenessDefault(incident);
    const form = useForm({
        question: 'cbn',
        awareness_at: defaultAt ? toLocalInput(new Date(defaultAt)) : '',
        awareness_reason: '',
    });
    const late = isAwarenessLate(form.data.awareness_at, defaultAt);

    const submit = (e) => {
        e.preventDefault();
        form.transform((data) => ({
            question: data.question,
            answer: 'yes',
            awareness_at: data.awareness_at ? localInputToIso(data.awareness_at) : undefined,
            ...(late ? { awareness_reason: data.awareness_reason } : {}),
        }));
        form.post(classifyUrl, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className="mt-2 flex flex-wrap items-end gap-2 rounded border border-slate-200 bg-white p-3">
            <div>
                <label htmlFor="classify-question" className="block text-xs font-medium text-slate-600">Regulator</label>
                <select id="classify-question" className="form-select text-sm" value={form.data.question}
                    onChange={(e) => form.setData('question', e.target.value)}>
                    <option value="cbn">CBN</option>
                    <option value="personal_data">NDPC (personal data)</option>
                </select>
            </div>
            <AwarenessField
                value={form.data.awareness_at} onChange={(v) => form.setData('awareness_at', v)}
                reasonValue={form.data.awareness_reason} onReasonChange={(v) => form.setData('awareness_reason', v)}
                defaultAt={defaultAt} errors={form.errors} idPrefix="classify-awareness"
                label="When was this first known? (defaults to when the incident was detected)"
            />
            <button type="submit" disabled={form.processing} className="rounded bg-slate-800 px-3 py-1.5 text-xs text-white">Classify</button>
        </form>
    );
}
