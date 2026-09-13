import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * The annual exercise programmes — clause 8.5's mandatory record, one row a year.
 *
 * DELIVERY IS SHOWN AGAINST THE APPROVED PLAN, not against today's definitions.
 * `total_planned` is frozen at approval precisely so that "we said twelve and
 * ran nine" is answerable; recomputing it would make every programme look
 * perfectly delivered.
 */
export default function Programmes({ programmes = [], can = {} }) {
    const [creating, setCreating] = useState(false);
    const create = useForm({ year: new Date().getFullYear() + 1, name: '' });

    return (
        <AppLayout title="Exercise programmes">
            <Head title="Exercise programmes" />

            <PageHeader
                title="Exercise programmes"
                subtitle="One year of testing per row: what was committed to, and what was delivered."
                actions={(
                    <div className="flex gap-2">
                        <Link href={tryRoute('bcms.calendar.index')} className="btn-secondary text-sm">Calendar</Link>
                        {can.manage && (
                            <button type="button" className="btn-primary text-sm" onClick={() => setCreating((v) => !v)}>
                                New programme
                            </button>
                        )}
                    </div>
                )}
            />

            {creating && can.manage && (
                <form
                    onSubmit={(e) => { e.preventDefault(); create.post(tryRoute('bcms.exercise-programmes.store')); }}
                    className="mb-6 flex flex-wrap items-end gap-3 rounded-lg border border-gray-200 bg-white p-6"
                >
                    <label className="text-sm">
                        <span className="text-gray-700">Year</span>
                        <input
                            type="number" min="2000" max="2100" required
                            className="mt-1 w-32 rounded border-gray-300 text-sm"
                            value={create.data.year}
                            onChange={(e) => create.setData('year', e.target.value)}
                        />
                    </label>
                    <label className="flex-1 text-sm">
                        <span className="text-gray-700">Name</span>
                        <input
                            type="text" required placeholder="Annual exercise programme 2027"
                            className="mt-1 w-full rounded border-gray-300 text-sm"
                            value={create.data.name}
                            onChange={(e) => create.setData('name', e.target.value)}
                        />
                        {create.errors.name && <span className="text-xs text-red-600">{create.errors.name}</span>}
                    </label>
                    <button type="submit" className="btn-primary text-sm" disabled={create.processing}>Create</button>
                    <button type="button" className="btn-secondary text-sm" onClick={() => setCreating(false)}>Cancel</button>
                </form>
            )}

            <div className="space-y-4">
                {programmes.length === 0 && (
                    <div className="rounded-lg border border-dashed border-gray-300 bg-white p-8 text-center text-sm text-gray-600">
                        No exercise programmes yet. Create one for next year, declare the exercises, and generate the calendar.
                    </div>
                )}

                {programmes.map((p) => (
                    <div key={p.id} className="rounded-lg border border-gray-200 bg-white p-6">
                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <Link href={tryRoute('bcms.exercise-programmes.show', p.uuid)} className="text-sm font-semibold text-gray-900 hover:underline">
                                    {p.name}
                                </Link>
                                <p className="mt-1 text-xs text-gray-500">
                                    {p.year} · {p.definition_count} exercises defined
                                    {p.approver && ` · approved by ${p.approver} on ${p.approved_at}`}
                                </p>
                            </div>
                            <span className={`rounded px-2 py-1 text-xs ${p.status === 'approved' || p.status === 'active'
                                ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700'}`}>
                                {p.status}
                            </span>
                        </div>

                        <div className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-5">
                            {[
                                { label: 'Planned', value: p.summary.planned },
                                { label: 'Completed', value: p.summary.completed },
                                { label: 'Overdue', value: p.summary.overdue, alarm: true },
                                { label: 'Need a date', value: p.summary.needs_scheduling, alarm: true },
                                {
                                    label: 'Delivered',
                                    value: p.summary.completion_rate == null ? '—' : `${p.summary.completion_rate}%`,
                                    note: p.summary.completion_rate == null ? 'nothing planned yet' : null,
                                },
                            ].map((t) => (
                                <div key={t.label} className={`rounded border p-3 ${t.alarm && t.value > 0 ? 'border-red-300 bg-red-50' : 'border-gray-200'}`}>
                                    <p className="text-[11px] uppercase tracking-wide text-gray-500">{t.label}</p>
                                    <p className="mt-0.5 font-mono text-xl text-gray-900">{t.value}</p>
                                    {t.note && <p className="text-[10px] text-gray-500">{t.note}</p>}
                                </div>
                            ))}
                        </div>

                        {p.summary.total_reschedules > 0 && (
                            <p className="mt-3 text-xs text-amber-800">
                                Exercises in this programme have been moved {p.summary.total_reschedules} times between them.
                            </p>
                        )}
                    </div>
                ))}
            </div>
        </AppLayout>
    );
}
