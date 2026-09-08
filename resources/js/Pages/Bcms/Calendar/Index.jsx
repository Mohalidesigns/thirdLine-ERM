import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import YearHeatGrid from '@/Components/Bcms/YearHeatGrid';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * The resilience calendar — the module's centrepiece.
 *
 * EIGHT VIEWS OF ONE CALENDAR, chosen on the server so that only the shape
 * being drawn comes down the wire. The year grid is a board artefact and
 * answers "are we clustering everything into Q4"; the month and agenda are the
 * BC team's working views; the Gantt shows the ladder; the compliance view is
 * the one a regulator's question is answered from.
 *
 * `needs_scheduling` HAS A VIEW OF ITS OWN because it is invisible on every
 * date-ranged view by definition. An obligation nobody can see is one nobody
 * places, and the whole point of that status is that a gap stays visible.
 *
 * RESCHEDULING ASKS FOR A REASON BEFORE IT ASKS FOR A DATE. The justification
 * field is not optional and the modal says why: regulators care as much about
 * what you moved as what you ran.
 */
const VIEWS = [
    ['year', 'Year'],
    ['month', 'Month'],
    ['week', 'Week'],
    ['agenda', 'Agenda'],
    ['gantt', 'Gantt'],
    ['compliance', 'Compliance'],
    ['mine', 'My calendar'],
    ['unscheduled', 'Needs scheduling'],
];

export default function Index({
    view = 'year', year, range = {}, filters = {}, options = {}, status_colours = {},
    year_grid = null, occurrences = [], gantt = [], compliance = [],
    ics_url = null, scope_note = null, can = {},
}) {
    const [selected, setSelected] = useState(null);
    const [rescheduling, setRescheduling] = useState(null);

    const go = (params) => router.get(
        tryRoute('bcms.calendar.index'),
        { view, year, ...filters, ...params },
        { preserveState: true, preserveScroll: true, replace: true },
    );

    const reschedule = useForm({ scheduled_date: '', justification: '', accept_conflicts: false });
    const cancel = useForm({ reason: '', waiver: false });

    const submitReschedule = (e) => {
        e.preventDefault();
        reschedule.post(tryRoute('bcms.occurrences.reschedule', rescheduling.uuid), {
            preserveScroll: true,
            onSuccess: () => { setRescheduling(null); reschedule.reset(); },
        });
    };

    const statusChip = (status) => (
        <span
            className="rounded px-2 py-0.5 text-[11px] font-medium text-white"
            style={{ background: status_colours[status] ?? '#94a3b8' }}
        >
            {status.replace(/_/g, ' ')}
        </span>
    );

    return (
        <AppLayout title="Resilience calendar">
            <Head title="Resilience calendar" />

            <PageHeader
                title="Resilience calendar"
                subtitle="The year of testing: declared as a rhythm, generated as dates, and governed when it moves."
                actions={(
                    <div className="flex flex-wrap gap-2">
                        <Link href={tryRoute('bcms.exercise-programmes.index')} className="btn-secondary text-sm">
                            Programmes
                        </Link>
                        {ics_url && (
                            <a href={ics_url} className="btn-secondary text-sm" title="Subscribe in Outlook or Google Calendar">
                                Subscribe (ICS)
                            </a>
                        )}
                    </div>
                )}
            />

            {/* ---- View switcher and year ------------------------------- */}

            <div className="mb-4 flex flex-wrap items-center gap-2">
                {VIEWS.map(([value, label]) => (
                    <button
                        key={value}
                        type="button"
                        onClick={() => go({ view: value })}
                        className={`rounded px-3 py-1.5 text-xs ${view === value ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'}`}
                    >
                        {label}
                    </button>
                ))}

                <span className="ml-auto flex items-center gap-2 text-xs">
                    <button type="button" className="rounded bg-gray-100 px-2 py-1" onClick={() => go({ year: year - 1 })}>&larr;</button>
                    <span className="font-mono text-sm">{year}</span>
                    <button type="button" className="rounded bg-gray-100 px-2 py-1" onClick={() => go({ year: year + 1 })}>&rarr;</button>
                </span>
            </div>

            {/* ---- Filters ---------------------------------------------- */}

            <div className="mb-6 flex flex-wrap gap-2 text-xs">
                {[
                    ['business_unit_id', 'All units', options.business_units, 'name'],
                    ['site_id', 'All sites', options.sites, 'name'],
                    ['exercise_type_id', 'All types', options.types, 'name'],
                ].map(([key, blank, list, label]) => (
                    <select
                        key={key}
                        className="rounded border-gray-300 text-xs"
                        value={filters[key] ?? ''}
                        onChange={(e) => go({ [key]: e.target.value || undefined })}
                    >
                        <option value="">{blank}</option>
                        {(list ?? []).map((o) => <option key={o.id} value={o.id}>{o[label]}</option>)}
                    </select>
                ))}

                <select
                    className="rounded border-gray-300 text-xs"
                    value={filters.status ?? ''}
                    onChange={(e) => go({ status: e.target.value || undefined })}
                >
                    <option value="">Any status</option>
                    {(options.statuses ?? []).map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
                </select>

                <select
                    className="rounded border-gray-300 text-xs"
                    value={filters.ladder_level ?? ''}
                    onChange={(e) => go({ ladder_level: e.target.value || undefined })}
                >
                    <option value="">Any ISO 22398 level</option>
                    {(options.ladder_levels ?? []).map((l) => <option key={l.value} value={l.value}>{l.label}</option>)}
                </select>

                <select
                    className="rounded border-gray-300 text-xs"
                    value={filters.criticality_tier ?? ''}
                    onChange={(e) => go({ criticality_tier: e.target.value || undefined })}
                >
                    <option value="">Any process criticality</option>
                    {[1, 2, 3].map((t) => <option key={t} value={t}>Tier {t} and above</option>)}
                </select>
            </div>

            {scope_note && (
                <p className="mb-4 rounded border border-gray-200 bg-gray-50 p-3 text-xs text-gray-700">{scope_note}</p>
            )}

            {/* ---- Year grid -------------------------------------------- */}

            {view === 'year' && year_grid && (
                <div className="space-y-6">
                    <div className="rounded-lg border border-gray-200 bg-white p-6">
                        <div className="mb-4 flex flex-wrap items-baseline justify-between gap-3">
                            <h2 className="text-sm font-semibold text-gray-900">Testing density, {year}</h2>
                            <p className="text-xs text-gray-600">
                                {year_grid.count} exercises
                                {year_grid.peak_month && ` · busiest month ${year_grid.peak_month.label} with ${year_grid.peak_month.total}`}
                                {year_grid.unscheduled > 0 && (
                                    <button
                                        type="button"
                                        className="ml-2 text-amber-700 underline"
                                        onClick={() => go({ view: 'unscheduled' })}
                                    >
                                        {year_grid.unscheduled} still need a date
                                    </button>
                                )}
                            </p>
                        </div>

                        <YearHeatGrid
                            grid={year_grid}
                            colours={status_colours}
                            onSelectMonth={(month) => go({
                                view: 'month',
                                from: `${year}-${String(month).padStart(2, '0')}-01`,
                                to: `${year}-${String(month).padStart(2, '0')}-28`,
                            })}
                        />
                    </div>

                    <div className="rounded-lg border border-gray-200 bg-white p-6 text-xs text-gray-700">
                        <h3 className="mb-2 text-sm font-semibold text-gray-900">The year the generator had to work with</h3>
                        <p>
                            {year_grid.blackouts?.working_days} working days after weekends and{' '}
                            {year_grid.blackouts?.blocked_days} blacked-out days — month-end close, salary week, the CBN
                            returns window, year-end and public holidays.
                        </p>
                        {(year_grid.blackouts?.unresolved ?? []).length > 0 && (
                            <ul className="mt-2 space-y-1 text-amber-800">
                                {year_grid.blackouts.unresolved.map((u) => (
                                    <li key={u.name}><strong>{u.name}</strong> — {u.reason}</li>
                                ))}
                            </ul>
                        )}
                    </div>
                </div>
            )}

            {/* ---- Gantt ------------------------------------------------ */}

            {view === 'gantt' && (
                <div className="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                    <table className="min-w-full divide-y divide-gray-200 text-sm">
                        <thead className="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th className="px-4 py-3 text-left">Exercise</th>
                                <th className="px-4 py-3 text-left">ISO 22398 level</th>
                                <th className="px-4 py-3 text-left">Unit</th>
                                <th className="px-4 py-3 text-right">Per year</th>
                                <th className="px-4 py-3 text-left">Placed</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {gantt.length === 0 && (
                                <tr><td colSpan={5} className="px-4 py-8 text-center text-gray-600">No exercises defined for {year}.</td></tr>
                            )}
                            {gantt.map((row) => (
                                <tr key={row.definition_id}>
                                    <td className="px-4 py-3 font-medium text-gray-900">{row.name}</td>
                                    <td className="px-4 py-3 text-xs text-gray-600">{row.ladder_label}</td>
                                    <td className="px-4 py-3 text-xs text-gray-600">{row.business_unit ?? 'Organisation-wide'}</td>
                                    <td className="px-4 py-3 text-right font-mono text-xs">{row.frequency_per_year}</td>
                                    <td className="px-4 py-3 text-xs">
                                        {row.dates.length === 0
                                            ? <span className="text-amber-700">not generated</span>
                                            : row.dates.join(' · ')}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {/* ---- Compliance ------------------------------------------- */}

            {view === 'compliance' && (
                <div className="space-y-3">
                    {compliance.length === 0 && (
                        <div className="rounded-lg border border-dashed border-gray-300 bg-white p-8 text-center text-sm text-gray-600">
                            No exercises are defined for {year}.
                        </div>
                    )}
                    {compliance.map((group) => (
                        <div key={group.label} className="rounded-lg border border-gray-200 bg-white p-5">
                            <div className="flex flex-wrap items-baseline justify-between gap-3">
                                <h3 className="text-sm font-semibold text-gray-900">{group.label}</h3>
                                <p className="text-sm">
                                    <span className="font-mono">{group.required}</span> required ·{' '}
                                    <span className="font-mono text-green-700">{group.completed}</span> completed ·{' '}
                                    <span className="font-mono">{group.planned}</span> planned
                                    {group.overdue > 0 && <> · <span className="font-mono text-red-700">{group.overdue} overdue</span></>}
                                </p>
                            </div>
                            <p className="mt-1 text-xs text-gray-600">
                                {group.definitions.join(', ')}
                                {group.shortfall > 0 && (
                                    <span className="ml-2 text-red-700">
                                        {group.shortfall} short of the required cadence.
                                    </span>
                                )}
                            </p>
                        </div>
                    ))}
                </div>
            )}

            {/* ---- Occurrence lists (month / week / agenda / mine / unscheduled) --- */}

            {['month', 'week', 'agenda', 'mine', 'unscheduled'].includes(view) && (
                <div className="grid gap-6 lg:grid-cols-[1fr_320px]">
                    <div className="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                        <table className="min-w-full divide-y divide-gray-200 text-sm">
                            <thead className="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th className="px-4 py-3 text-left">Date</th>
                                    <th className="px-4 py-3 text-left">Exercise</th>
                                    <th className="px-4 py-3 text-left">Unit / site</th>
                                    <th className="px-4 py-3 text-left">Status</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {occurrences.length === 0 && (
                                    <tr>
                                        <td colSpan={4} className="px-4 py-8 text-center text-gray-600">
                                            {view === 'unscheduled'
                                                ? 'Every occurrence has a date. Nothing is waiting to be scheduled.'
                                                : 'Nothing in this period.'}
                                        </td>
                                    </tr>
                                )}
                                {occurrences.map((o) => (
                                    <tr
                                        key={o.id}
                                        onClick={() => setSelected(o)}
                                        className={`cursor-pointer hover:bg-gray-50 ${o.is_overdue ? 'bg-red-50' : ''}`}
                                    >
                                        <td className="px-4 py-3 font-mono text-xs">
                                            {o.scheduled_date ?? <span className="text-amber-700">no date</span>}
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className="font-medium text-gray-900">{o.title}</span>
                                            <span className="block text-xs text-gray-500">
                                                {o.type}
                                                {o.mandatory && ' · mandatory'}
                                                {o.unannounced && ' · unannounced'}
                                                {o.reschedule_count > 0 && ` · moved ${o.reschedule_count}×`}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-xs text-gray-600">
                                            {o.business_unit ?? 'Organisation-wide'}{o.site ? ` · ${o.site}` : ''}
                                        </td>
                                        <td className="px-4 py-3">{statusChip(o.status)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {/* ---- Right panel ----------------------------------- */}

                    <div className="rounded-lg border border-gray-200 bg-white p-5 text-sm">
                        {!selected && <p className="text-gray-600">Select an exercise to see its detail.</p>}

                        {selected && (
                            <div className="space-y-3">
                                <div>
                                    <h3 className="text-sm font-semibold text-gray-900">{selected.title}</h3>
                                    <p className="text-xs text-gray-600">{selected.type} · {selected.ladder_label}</p>
                                </div>

                                <dl className="space-y-1.5 text-xs">
                                    <div className="flex justify-between gap-2"><dt className="text-gray-500">Date</dt><dd>{selected.scheduled_date ?? 'not scheduled'}</dd></div>
                                    <div className="flex justify-between gap-2"><dt className="text-gray-500">Status</dt><dd>{statusChip(selected.status)}</dd></div>
                                    <div className="flex justify-between gap-2"><dt className="text-gray-500">Owner</dt><dd>{selected.owner ?? '—'}</dd></div>
                                    <div className="flex justify-between gap-2"><dt className="text-gray-500">Facilitator</dt><dd>{selected.facilitator ?? '—'}</dd></div>
                                    <div className="flex justify-between gap-2"><dt className="text-gray-500">Site</dt><dd>{selected.site ?? '—'}</dd></div>
                                    {selected.reschedule_count > 0 && (
                                        <div className="flex justify-between gap-2">
                                            <dt className="text-gray-500">Moved</dt>
                                            <dd>{selected.reschedule_count}× (originally {selected.originally_scheduled_date})</dd>
                                        </div>
                                    )}
                                </dl>

                                {/*
                                    Phase 5 fills these two. They are declared now so that the
                                    panel's shape does not change when the reminder ladder lands.
                                */}
                                <div className="rounded border border-dashed border-gray-300 p-3 text-xs text-gray-500">
                                    Readiness checklist and the T-10 reminder ladder appear here once Phase 5 lands.
                                    {selected.blocking_tasks_open > 0 && (
                                        <span className="block text-amber-700">{selected.blocking_tasks_open} blocking tasks open.</span>
                                    )}
                                </div>

                                {can.schedule && selected.scheduled_date && (
                                    <div className="flex gap-2">
                                        <button
                                            type="button"
                                            className="btn-secondary text-xs"
                                            onClick={() => { setRescheduling(selected); reschedule.setData('scheduled_date', selected.scheduled_date); }}
                                        >
                                            Reschedule
                                        </button>
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                </div>
            )}

            {/* ---- Reschedule modal ------------------------------------- */}

            {rescheduling && (
                <div className="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
                    <form onSubmit={submitReschedule} className="w-full max-w-lg space-y-4 rounded-lg bg-white p-6 shadow-xl">
                        <h3 className="text-base font-semibold text-gray-900">Move &ldquo;{rescheduling.title}&rdquo;</h3>

                        <p className="rounded bg-amber-50 p-3 text-xs text-amber-900">
                            Regulators care as much about what you moved as what you ran. The reason below is recorded
                            against this exercise, and if it is inside its minimum notice period the move goes to the
                            programme owner for approval instead of happening now.
                        </p>

                        <div className="grid gap-3 sm:grid-cols-2">
                            <label className="text-sm">
                                <span className="text-gray-700">Currently</span>
                                <input type="text" readOnly value={rescheduling.scheduled_date ?? ''} className="mt-1 w-full rounded border-gray-200 bg-gray-50 text-sm" />
                            </label>
                            <label className="text-sm">
                                <span className="text-gray-700">New date</span>
                                <input
                                    type="date" required
                                    className="mt-1 w-full rounded border-gray-300 text-sm"
                                    value={reschedule.data.scheduled_date}
                                    onChange={(e) => reschedule.setData('scheduled_date', e.target.value)}
                                />
                            </label>
                        </div>

                        <label className="block text-sm">
                            <span className="text-gray-700">Why is it moving?</span>
                            <textarea
                                rows={3} required minLength={10}
                                className="mt-1 w-full rounded border-gray-300 text-sm"
                                value={reschedule.data.justification}
                                onChange={(e) => reschedule.setData('justification', e.target.value)}
                            />
                            {reschedule.errors.justification && (
                                <span className="text-xs text-red-600">{reschedule.errors.justification}</span>
                            )}
                        </label>

                        <label className="flex items-center gap-2 text-xs text-gray-700">
                            <input
                                type="checkbox"
                                checked={reschedule.data.accept_conflicts}
                                onChange={(e) => reschedule.setData('accept_conflicts', e.target.checked)}
                            />
                            Move it even if the new date clashes with another exercise
                        </label>

                        <div className="flex gap-2">
                            <button type="submit" className="btn-primary text-sm" disabled={reschedule.processing}>Move it</button>
                            <button type="button" className="btn-secondary text-sm" onClick={() => setRescheduling(null)}>Cancel</button>
                        </div>
                    </form>
                </div>
            )}
        </AppLayout>
    );
}
