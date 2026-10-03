import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';

/**
 * `docs/bcms/screens/dr-test-record.md` — the index. THIS IS FOR A TEST THAT
 * WAS PLANNED AND RAN — a real invocation is never written here, and there is
 * no field on this form that accepts an incident.
 */
export default function Index({ system = {}, tests = [], unpaired_failover: unpairedFailover, test_types: testTypes = [], can = {}, store_url: storeUrl, register_url: registerUrl }) {
    const [recording, setRecording] = useState(false);

    return (
        <AppLayout title={`Test history — ${system.name}`}>
            <Head title={`Test history — ${system.name}`} />

            <PageHeader
                title={`Test history — ${system.name}`}
                actions={(
                    <div className="flex gap-2">
                        <Link href={registerUrl} className="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">Register</Link>
                        {can.record && (
                            <button type="button" className="btn-primary text-sm" onClick={() => setRecording((v) => !v)}>Record a test</button>
                        )}
                    </div>
                )}
            />

            {unpairedFailover && (
                <div role="region" aria-label="Unpaired failover" className="mb-4 rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                    A failover with no recorded failback is half a test. Failback is a distinct type — record it once
                    failback runs.
                </div>
            )}

            {recording && <RecordForm storeUrl={storeUrl} testTypes={testTypes} onDone={() => setRecording(false)} />}

            <div className="card">
                <div className="overflow-x-auto">
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>RTO actual / target</th>
                                <th>RPO actual / target</th>
                                <th>Met objectives</th>
                                <th>Rollback</th>
                                <th>Source</th>
                            </tr>
                        </thead>
                        <tbody>
                            {tests.length === 0 && (
                                <tr><td colSpan={7} className="py-12 text-center text-sm text-gray-500">
                                    No DR tests recorded for this system. A tested system is one with at least one
                                    row here — an untested recovery tier is a claim, not a fact.
                                </td></tr>
                            )}
                            {tests.map((t) => {
                                const rtoTargetMinutes = system.rto_target_hours != null ? system.rto_target_hours * 60 : null;
                                const rtoBreached = rtoTargetMinutes != null && t.rto_actual_minutes != null && t.rto_actual_minutes > rtoTargetMinutes;
                                const rpoBreached = system.rpo_target_minutes != null && t.rpo_actual_minutes != null && t.rpo_actual_minutes > system.rpo_target_minutes;
                                return (
                                    <tr key={t.id}>
                                        <td><Link href={t.show_url} className="cell-title">{t.test_date}</Link></td>
                                        <td className="text-xs">{t.test_type_label}</td>
                                        <td className={`font-mono text-xs ${rtoBreached ? 'text-rose-700' : ''}`}>
                                            {t.rto_actual_minutes ?? '—'}m / {rtoTargetMinutes ?? '—'}m
                                        </td>
                                        <td className={`font-mono text-xs ${rpoBreached ? 'text-rose-700' : ''}`}>
                                            {t.rpo_actual_minutes ?? '—'}m / {system.rpo_target_minutes ?? '—'}m
                                        </td>
                                        <td className="text-xs">
                                            {t.met_objectives === null ? 'awaiting review' : t.met_objectives ? 'met' : 'not met'}
                                        </td>
                                        <td className="text-xs">{t.rollback_required ? 'Rollback required' : ''}</td>
                                        <td className="cell-muted text-xs">{t.source}</td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}

function RecordForm({ storeUrl, testTypes, onDone }) {
    const form = useForm({ test_type: testTypes[0]?.value ?? 'tabletop', test_date: '', rto_actual_minutes: '', rpo_actual_minutes: '', rollback_required: false, notes: '' });

    const submit = (e) => {
        e.preventDefault();
        form.post(storeUrl, { onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className="mb-6 grid gap-3 rounded border border-slate-200 bg-white p-4 sm:grid-cols-3">
            <div>
                <label htmlFor="test_type" className="block text-xs font-medium text-slate-600">Test type</label>
                <select id="test_type" className="form-select mt-1 w-full" value={form.data.test_type}
                    onChange={(e) => form.setData('test_type', e.target.value)}>
                    {testTypes.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                </select>
            </div>
            <div>
                <label htmlFor="test_date" className="block text-xs font-medium text-slate-600">Test date</label>
                <input id="test_date" type="date" required className="form-input mt-1 w-full" value={form.data.test_date}
                    onChange={(e) => form.setData('test_date', e.target.value)} />
            </div>
            <div>
                <label htmlFor="rollback" className="mt-6 flex items-center gap-2 text-xs font-medium text-slate-600">
                    <input id="rollback" type="checkbox" className="form-checkbox" checked={form.data.rollback_required}
                        onChange={(e) => form.setData('rollback_required', e.target.checked)} />
                    Rollback required
                </label>
            </div>
            <div>
                <label htmlFor="rto_actual" className="block text-xs font-medium text-slate-600">Actual RTO (minutes)</label>
                <input id="rto_actual" type="number" min="0" className="form-input mt-1 w-full" value={form.data.rto_actual_minutes}
                    onChange={(e) => form.setData('rto_actual_minutes', e.target.value)} />
            </div>
            <div>
                <label htmlFor="rpo_actual" className="block text-xs font-medium text-slate-600">Actual RPO (minutes)</label>
                <input id="rpo_actual" type="number" min="0" className="form-input mt-1 w-full" value={form.data.rpo_actual_minutes}
                    onChange={(e) => form.setData('rpo_actual_minutes', e.target.value)} />
            </div>
            <div className="sm:col-span-3">
                <label htmlFor="notes" className="block text-xs font-medium text-slate-600">Notes</label>
                <textarea id="notes" rows={2} className="form-textarea mt-1 w-full" value={form.data.notes}
                    onChange={(e) => form.setData('notes', e.target.value)} />
            </div>
            <div className="sm:col-span-3 flex gap-2">
                <button type="submit" disabled={form.processing} className="rounded bg-slate-800 px-3 py-1.5 text-sm text-white">Record</button>
                <button type="button" onClick={onDone} className="rounded border border-slate-300 px-3 py-1.5 text-sm">Cancel</button>
            </div>
        </form>
    );
}
