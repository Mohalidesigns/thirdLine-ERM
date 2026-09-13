import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * The exercise programme dashboard, and the definition wizard.
 *
 * THE WIZARD'S LIVE PREVIEW IS THE FEATURE. "This will create 4 occurrences and
 * 56 notifications across the year" is what turns a frequency box into a
 * decision somebody can take, and it runs the real generator on the server —
 * a preview computed by a simpler algorithm would be a promise the product
 * then breaks the first time a blackout moves something.
 *
 * THE LADDER COVERAGE MATRIX IS THE DASHBOARD'S CENTREPIECE. Process down the
 * side, ISO 22398 rung across the top, and a blank row for an activity nobody
 * has ever tested. It is the question a management review is supposed to ask
 * and usually cannot.
 *
 * NAMED DISTINCTLY FROM PHASE 1'S PROGRAMME SCREEN, deliberately: that one is
 * the BCMS itself — scope, objectives, policy. This is one year of testing.
 */
const LADDER = ['orientation', 'tabletop', 'walkthrough', 'drill', 'functional', 'full_scale'];

export default function Programme({
    programme = {}, summary = {}, definitions = [], coverage = [], advisor = {}, exercise_types = [], can = {},
}) {
    const [wizard, setWizard] = useState(false);
    const [step, setStep] = useState(1);
    const [preview, setPreview] = useState(null);
    const [previewing, setPreviewing] = useState(false);

    const form = useForm({
        exercise_type_id: '', name: '', business_unit_id: '', site_id: '', process_ids: [],
        frequency_per_year: 2, distribution_mode: 'even', preferred_window: {},
        duration_minutes: 120, lead_time_days: 10, min_notice_days: 3,
        daily_reminder_enabled: true, unannounced: false, mandatory: false,
        readiness_gating: false, status: 'active',
    });

    const type = exercise_types.find((t) => String(t.id) === String(form.data.exercise_type_id));

    // The live sentence. Debounced, because it re-runs the generator server-side
    // and a keystroke per run would be a query storm for a number that changes
    // once a second at most.
    useEffect(() => {
        if (!wizard || !form.data.exercise_type_id) return undefined;

        const handle = setTimeout(() => {
            setPreviewing(true);

            window.axios.post(tryRoute('bcms.exercise-definitions.preview', programme.uuid), {
                exercise_type_id: form.data.exercise_type_id,
                frequency_per_year: form.data.frequency_per_year,
                distribution_mode: form.data.distribution_mode,
                preferred_window: form.data.preferred_window,
                duration_minutes: form.data.duration_minutes,
                lead_time_days: form.data.lead_time_days,
                daily_reminder_enabled: form.data.daily_reminder_enabled,
                unannounced: form.data.unannounced,
                business_unit_id: form.data.business_unit_id || null,
                process_ids: form.data.process_ids,
            })
                .then((r) => setPreview(r.data))
                .catch(() => setPreview(null))
                .finally(() => setPreviewing(false));
        }, 400);

        return () => clearTimeout(handle);
    }, [
        wizard, form.data.exercise_type_id, form.data.frequency_per_year, form.data.distribution_mode,
        form.data.duration_minutes, form.data.lead_time_days, form.data.daily_reminder_enabled,
        form.data.unannounced, form.data.business_unit_id, form.data.process_ids, programme.uuid,
    ]);

    const pickType = (id) => {
        const t = exercise_types.find((x) => String(x.id) === String(id));

        form.setData((d) => ({
            ...d,
            exercise_type_id: id,
            name: d.name || (t?.name ?? ''),
            frequency_per_year: t?.default_frequency_per_year ?? d.frequency_per_year,
            duration_minutes: t?.default_duration_minutes ?? d.duration_minutes,
            lead_time_days: t?.default_lead_time_days ?? d.lead_time_days,
        }));
    };

    const submit = (e) => {
        e.preventDefault();
        form.post(tryRoute('bcms.exercise-definitions.store', programme.uuid), {
            onSuccess: () => { form.reset(); setWizard(false); setStep(1); setPreview(null); },
        });
    };

    return (
        <AppLayout title={programme.name}>
            <Head title={programme.name} />

            <PageHeader
                title={programme.name}
                subtitle={`${programme.year} exercise programme · ${programme.status}`}
                actions={(
                    <div className="flex flex-wrap gap-2">
                        <Link href={tryRoute('bcms.exercise-programmes.index')} className="btn-secondary text-sm">Programmes</Link>
                        <Link href={`${tryRoute('bcms.calendar.index')}?year=${programme.year}`} className="btn-secondary text-sm">Calendar</Link>
                        {can.manage && (
                            <button
                                type="button"
                                className="btn-secondary text-sm"
                                onClick={() => router.post(tryRoute('bcms.exercise-programmes.generate', programme.uuid), {}, { preserveScroll: true })}
                            >
                                Generate the year
                            </button>
                        )}
                        {can.approve && programme.status === 'draft' && (
                            <button
                                type="button"
                                className="btn-primary text-sm"
                                onClick={() => router.post(tryRoute('bcms.exercise-programmes.approve', programme.uuid), {}, { preserveScroll: true })}
                            >
                                Approve
                            </button>
                        )}
                        {can.manage && (
                            <button type="button" className="btn-primary text-sm" onClick={() => setWizard((v) => !v)}>
                                Add an exercise
                            </button>
                        )}
                    </div>
                )}
            />

            {/* ---- Delivery ---------------------------------------------- */}

            <div className="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-6">
                {[
                    { label: 'Planned', value: summary.planned },
                    { label: 'Completed', value: summary.completed },
                    { label: 'In progress', value: summary.in_progress },
                    { label: 'Overdue', value: summary.overdue, alarm: true },
                    { label: 'Need a date', value: summary.needs_scheduling, alarm: true },
                    {
                        label: 'Delivered',
                        value: summary.completion_rate == null ? '—' : `${summary.completion_rate}%`,
                        note: summary.completion_rate == null ? 'nothing planned' : null,
                    },
                ].map((t) => (
                    <div key={t.label} className={`rounded-lg border p-4 ${t.alarm && t.value > 0 ? 'border-red-300 bg-red-50' : 'border-gray-200 bg-white'}`}>
                        <p className="text-[11px] uppercase tracking-wide text-gray-500">{t.label}</p>
                        <p className="mt-1 font-mono text-2xl text-gray-900">{t.value ?? 0}</p>
                        {t.note && <p className="text-[10px] text-gray-500">{t.note}</p>}
                    </div>
                ))}
            </div>

            {/* ---- The advisor's computed gaps --------------------------- */}

            <div className="mb-6 rounded-lg border border-gray-200 bg-white p-6">
                <div className="flex flex-wrap items-baseline justify-between gap-3">
                    <h2 className="text-sm font-semibold text-gray-900">Where the programme is thin</h2>
                    {can.manage && advisor.available && (
                        <button
                            type="button"
                            className="btn-secondary text-xs"
                            onClick={() => router.post(tryRoute('bcms.exercise-programmes.advise', programme.uuid), {}, { preserveScroll: true })}
                        >
                            Ask the advisor to propose a programme
                        </button>
                    )}
                </div>

                <p className="mt-2 text-sm text-gray-800">{advisor.gaps?.headline}</p>

                {!advisor.available && advisor.reason && (
                    <p className="mt-2 text-xs text-gray-500">
                        {advisor.reason} The gaps above are computed from your own records and do not need it.
                    </p>
                )}

                {(advisor.gaps?.unmet_cadences ?? []).length > 0 && (
                    <table className="mt-4 min-w-full text-xs">
                        <thead className="text-left text-[11px] uppercase tracking-wide text-gray-500">
                            <tr><th className="py-1">Regulatory cadence</th><th>Required</th><th>Declared</th><th>Short by</th></tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {advisor.gaps.unmet_cadences.map((u) => (
                                <tr key={u.clause}>
                                    <td className="py-1.5">{u.exercise_type_name} <span className="text-gray-500">({u.clause})</span></td>
                                    <td className="font-mono">{u.required_per_year}</td>
                                    <td className="font-mono">{u.declared_per_year}</td>
                                    <td className="font-mono text-red-700">{u.shortfall}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>

            {/* ---- The wizard -------------------------------------------- */}

            {wizard && can.manage && (
                <form onSubmit={submit} className="mb-6 rounded-lg border border-gray-300 bg-white p-6">
                    <div className="mb-4 flex gap-2 text-xs">
                        {['Type & objectives', 'Scope', 'Frequency', 'Alerting'].map((label, i) => (
                            <button
                                key={label}
                                type="button"
                                onClick={() => setStep(i + 1)}
                                className={`rounded px-3 py-1.5 ${step === i + 1 ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-700'}`}
                            >
                                {i + 1}. {label}
                            </button>
                        ))}
                    </div>

                    {step === 1 && (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <label className="text-sm">
                                <span className="text-gray-700">Exercise type</span>
                                <select
                                    className="mt-1 w-full rounded border-gray-300 text-sm"
                                    value={form.data.exercise_type_id}
                                    onChange={(e) => pickType(e.target.value)}
                                    required
                                >
                                    <option value="">Choose…</option>
                                    {exercise_types.map((t) => (
                                        <option key={t.id} value={t.id}>{t.name} — {t.ladder_label}</option>
                                    ))}
                                </select>
                                {type?.cadence_clause_ref && (
                                    <span className="mt-1 block text-xs text-amber-800">
                                        Regulatory cadence: {type.default_frequency_per_year}× a year ({type.cadence_clause_ref}).
                                    </span>
                                )}
                            </label>
                            <label className="text-sm">
                                <span className="text-gray-700">Name</span>
                                <input
                                    type="text" required
                                    className="mt-1 w-full rounded border-gray-300 text-sm"
                                    value={form.data.name}
                                    onChange={(e) => form.setData('name', e.target.value)}
                                />
                            </label>
                        </div>
                    )}

                    {step === 2 && (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <label className="text-sm">
                                <span className="text-gray-700">Business unit</span>
                                <input
                                    type="number"
                                    placeholder="Leave blank for organisation-wide"
                                    className="mt-1 w-full rounded border-gray-300 text-sm"
                                    value={form.data.business_unit_id}
                                    onChange={(e) => form.setData('business_unit_id', e.target.value)}
                                />
                            </label>
                            <label className="text-sm">
                                <span className="text-gray-700">Processes tested (ids, comma separated)</span>
                                <input
                                    type="text"
                                    className="mt-1 w-full rounded border-gray-300 text-sm"
                                    value={form.data.process_ids.join(',')}
                                    onChange={(e) => form.setData('process_ids', e.target.value.split(',').map((v) => v.trim()).filter(Boolean).map(Number))}
                                />
                                <span className="mt-1 block text-xs text-gray-500">
                                    An exercise bound to no process cannot be credited to one on the coverage matrix.
                                </span>
                            </label>
                        </div>
                    )}

                    {step === 3 && (
                        <div className="grid gap-4 sm:grid-cols-3">
                            <label className="text-sm">
                                <span className="text-gray-700">Times per year</span>
                                <input
                                    type="number" min="1" max="52" required
                                    className="mt-1 w-full rounded border-gray-300 text-sm"
                                    value={form.data.frequency_per_year}
                                    onChange={(e) => form.setData('frequency_per_year', Number(e.target.value))}
                                />
                            </label>
                            <label className="text-sm">
                                <span className="text-gray-700">Spread</span>
                                <select
                                    className="mt-1 w-full rounded border-gray-300 text-sm"
                                    value={form.data.distribution_mode}
                                    onChange={(e) => form.setData('distribution_mode', e.target.value)}
                                >
                                    <option value="even">Evenly across the year</option>
                                    <option value="quarter_end">Near each quarter end</option>
                                    <option value="month_specific">In named months</option>
                                    <option value="manual">Placed by hand</option>
                                </select>
                            </label>
                            <label className="text-sm">
                                <span className="text-gray-700">Duration (minutes)</span>
                                <input
                                    type="number" min="15" max="10080" required
                                    className="mt-1 w-full rounded border-gray-300 text-sm"
                                    value={form.data.duration_minutes}
                                    onChange={(e) => form.setData('duration_minutes', Number(e.target.value))}
                                />
                            </label>
                        </div>
                    )}

                    {step === 4 && (
                        <div className="grid gap-4 sm:grid-cols-3">
                            <label className="text-sm">
                                <span className="text-gray-700">Countdown starts (days before)</span>
                                <input
                                    type="number" min="0" max="90" required
                                    className="mt-1 w-full rounded border-gray-300 text-sm"
                                    value={form.data.lead_time_days}
                                    onChange={(e) => form.setData('lead_time_days', Number(e.target.value))}
                                />
                            </label>
                            <label className="text-sm">
                                <span className="text-gray-700">Minimum notice to move it (days)</span>
                                <input
                                    type="number" min="0" max="90" required
                                    className="mt-1 w-full rounded border-gray-300 text-sm"
                                    value={form.data.min_notice_days}
                                    onChange={(e) => form.setData('min_notice_days', Number(e.target.value))}
                                />
                                <span className="mt-1 block text-xs text-gray-500">
                                    Inside this window a move needs the programme owner&rsquo;s approval.
                                </span>
                            </label>
                            <div className="space-y-2 text-sm">
                                {[
                                    ['mandatory', 'Mandatory — cannot be cancelled without a waiver'],
                                    ['unannounced', 'Unannounced — participants get no countdown'],
                                    ['daily_reminder_enabled', 'Send the daily countdown'],
                                ].map(([key, label]) => (
                                    <label key={key} className="flex items-start gap-2 text-xs text-gray-700">
                                        <input
                                            type="checkbox"
                                            checked={form.data[key]}
                                            onChange={(e) => form.setData(key, e.target.checked)}
                                        />
                                        {label}
                                    </label>
                                ))}
                                {form.errors.unannounced && <p className="text-xs text-red-600">{form.errors.unannounced}</p>}
                            </div>
                        </div>
                    )}

                    {/* ---- The live sentence ---------------------------- */}

                    <div className="mt-5 rounded border border-gray-200 bg-gray-50 p-4 text-sm">
                        {previewing && <p className="text-gray-500">Working it out…</p>}

                        {!previewing && !preview && <p className="text-gray-500">Choose an exercise type to see what this will create.</p>}

                        {!previewing && preview && (
                            <>
                                <p className="text-gray-900">
                                    This will create <strong>{preview.log.placed}</strong> occurrences
                                    {preview.log.needs_scheduling > 0 && (
                                        <> and <strong className="text-amber-700">{preview.log.needs_scheduling}</strong> that need a date by hand</>
                                    )}
                                    {' '}and about <strong>{preview.notification_estimate.total}</strong> notifications across the year.
                                </p>
                                <p className="mt-1 text-xs text-gray-600">{preview.notification_estimate.basis}</p>
                                <p className="mt-1 text-xs text-gray-600">
                                    {preview.log.working_days} working days available after blackouts.{' '}
                                    {preview.occurrences.filter((o) => o.date).map((o) => o.date).join(' · ')}
                                </p>
                                {preview.cadence_warnings.map((w, i) => (
                                    <p key={i} className="mt-2 rounded bg-amber-50 p-2 text-xs text-amber-900">{w.message}</p>
                                ))}
                                {preview.ladder_warnings.map((w, i) => (
                                    <p key={i} className="mt-2 rounded bg-amber-50 p-2 text-xs text-amber-900">{w.message}</p>
                                ))}
                            </>
                        )}
                    </div>

                    <div className="mt-4 flex gap-2">
                        <button type="submit" className="btn-primary text-sm" disabled={form.processing}>Add the exercise</button>
                        <button type="button" className="btn-secondary text-sm" onClick={() => { setWizard(false); setStep(1); }}>Cancel</button>
                    </div>
                </form>
            )}

            {/* ---- Definitions ------------------------------------------- */}

            <div className="mb-6 overflow-x-auto rounded-lg border border-gray-200 bg-white">
                <table className="min-w-full divide-y divide-gray-200 text-sm">
                    <thead className="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th className="px-4 py-3 text-left">Exercise</th>
                            <th className="px-4 py-3 text-left">Level</th>
                            <th className="px-4 py-3 text-right">Per year</th>
                            <th className="px-4 py-3 text-right">Generated</th>
                            <th className="px-4 py-3 text-left">Last generation</th>
                            <th className="px-4 py-3" />
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {definitions.length === 0 && (
                            <tr><td colSpan={6} className="px-4 py-8 text-center text-gray-600">No exercises defined yet.</td></tr>
                        )}
                        {definitions.map((d) => (
                            <tr key={d.id}>
                                <td className="px-4 py-3">
                                    <span className="font-medium text-gray-900">{d.name}</span>
                                    <span className="block text-xs text-gray-500">
                                        {d.type} · {d.business_unit ?? 'Organisation-wide'}
                                        {d.mandatory && ' · mandatory'}
                                        {d.unannounced && ' · unannounced'}
                                    </span>
                                </td>
                                <td className="px-4 py-3 text-xs text-gray-600">{d.ladder_label}</td>
                                <td className="px-4 py-3 text-right font-mono text-xs">{d.frequency_per_year}</td>
                                <td className="px-4 py-3 text-right font-mono text-xs">{d.occurrence_count}</td>
                                <td className="px-4 py-3 text-xs text-gray-600">
                                    {!d.generation_log && <span className="text-amber-700">never generated</span>}
                                    {d.generation_log && (
                                        <>
                                            {d.generation_log.placed} placed
                                            {d.generation_log.shifted > 0 && `, ${d.generation_log.shifted} shifted`}
                                            {d.generation_log.needs_scheduling > 0 && (
                                                <span className="text-amber-700">, {d.generation_log.needs_scheduling} unplaced</span>
                                            )}
                                        </>
                                    )}
                                </td>
                                <td className="px-4 py-3 text-right">
                                    {can.manage && (
                                        <button
                                            type="button"
                                            className="text-xs text-blue-700 hover:underline"
                                            onClick={() => router.post(tryRoute('bcms.exercise-definitions.generate', d.uuid), {}, { preserveScroll: true })}
                                        >
                                            Generate
                                        </button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {/* ---- Ladder coverage matrix -------------------------------- */}

            <div className="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                <div className="border-b border-gray-100 px-4 py-3">
                    <h2 className="text-sm font-semibold text-gray-900">Ladder coverage</h2>
                    <p className="text-xs text-gray-600">
                        Which activities have been proven at which rung of the ISO 22398 ladder, and when. A blank row
                        is a process nobody has ever exercised.
                    </p>
                </div>
                <table className="min-w-full divide-y divide-gray-200 text-xs">
                    <thead className="bg-gray-50 text-[11px] uppercase tracking-wide text-gray-500">
                        <tr>
                            <th className="px-4 py-3 text-left">Process</th>
                            <th className="px-4 py-3 text-left">Tier</th>
                            {LADDER.map((l) => <th key={l} className="px-3 py-3 text-center">{l.replace('_', ' ')}</th>)}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {coverage.map((row) => (
                            <tr key={row.process_id} className={row.never_exercised ? 'bg-red-50' : undefined}>
                                <td className="px-4 py-2">
                                    <span className="font-medium text-gray-900">{row.code}</span>
                                    <span className="block text-gray-500">{row.name}</span>
                                </td>
                                <td className="px-4 py-2">{row.tier ?? '—'}</td>
                                {LADDER.map((l) => {
                                    const cell = row.levels[l] ?? {};

                                    return (
                                        <td key={l} className="px-3 py-2 text-center">
                                            {cell.successful > 0
                                                ? <span className="text-green-700" title={cell.last_at ?? ''}>{cell.successful}</span>
                                                : <span className="text-gray-300">·</span>}
                                        </td>
                                    );
                                })}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
