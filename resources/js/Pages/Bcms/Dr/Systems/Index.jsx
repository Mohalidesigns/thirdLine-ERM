import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { formatIncidentDateTime } from '@/Components/Bcms/dateDisplay';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * `docs/bcms/screens/dr-system-register.md` — which systems are due a test
 * and are not getting one, whose recovery targets do not match what the
 * business needs, and whose backups have not been verified recently enough
 * to trust.
 */
export default function Index({ systems = [], summary = {}, can = {}, options = {}, store_url: storeUrl, filters = {} }) {
    const [adding, setAdding] = useState(false);
    const [search, setSearch] = useState(filters.search ?? '');
    const [mismatchesOnly, setMismatchesOnly] = useState(!!filters.mismatches_only);
    const [overdueOnly, setOverdueOnly] = useState(!!filters.overdue_only);

    const applyFilters = (next = {}) => {
        const params = { search, mismatches_only: mismatchesOnly, overdue_only: overdueOnly, ...next };
        router.get(tryRoute('bcms.dr-systems.index'), params, { preserveState: true, preserveScroll: true, replace: true });
    };

    return (
        <AppLayout title="IT disaster recovery register">
            <Head title="IT disaster recovery register" />

            <PageHeader
                title="IT disaster recovery register"
                subtitle="What can fail over, how fast, and whether that has ever been proven."
                actions={can.manage && (
                    <button type="button" className="btn-primary text-sm" onClick={() => setAdding((v) => !v)}>Add a system</button>
                )}
            />

            {adding && <SystemForm storeUrl={storeUrl} options={options} onDone={() => setAdding(false)} />}

            <div className="filter-bar">
                <div className="filter-bar-inner">
                    <div className="filter-group min-w-[220px]">
                        <label className="filter-label">Search</label>
                        <input className="filter-input" aria-label="Search" value={search} onChange={(e) => setSearch(e.target.value)}
                            onKeyDown={(e) => { if (e.key === 'Enter') applyFilters(); }} />
                    </div>
                    <label className="flex items-center gap-1.5 text-sm">
                        <input type="checkbox" className="form-checkbox" checked={mismatchesOnly}
                            onChange={(e) => { setMismatchesOnly(e.target.checked); applyFilters({ mismatches_only: e.target.checked }); }} />
                        Tier mismatches only
                    </label>
                    <label className="flex items-center gap-1.5 text-sm">
                        <input type="checkbox" className="form-checkbox" checked={overdueOnly}
                            onChange={(e) => { setOverdueOnly(e.target.checked); applyFilters({ overdue_only: e.target.checked }); }} />
                        Overdue only
                    </label>
                </div>
            </div>

            <p className="mb-3 text-sm text-slate-700">
                {summary.total ?? 0} systems · {summary.mismatches ?? 0} tier mismatches · {summary.overdue ?? 0} overdue
                for their next test · {summary.backup_stale ?? 0} with a backup not verified recently.
            </p>

            <div className="card">
                <div className="overflow-x-auto">
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Application</th>
                                <th>Tier</th>
                                <th>RTO / RPO target</th>
                                <th>Strategy</th>
                                <th>Last test</th>
                                <th>Next test due</th>
                                <th>Backup verified</th>
                                <th>Tier mismatch</th>
                            </tr>
                        </thead>
                        <tbody>
                            {systems.length === 0 && (
                                <tr><td colSpan={9} className="py-12 text-center text-sm text-gray-500">
                                    {search || mismatchesOnly || overdueOnly
                                        ? 'No systems match this filter.'
                                        : "No DR systems recorded yet. Add the first one, or check that this deployment's IT DR data hasn't been left in a spreadsheet instead."}
                                </td></tr>
                            )}
                            {systems.map((s) => (
                                <tr key={s.uuid}>
                                    <td>
                                        <Link href={s.tests_url} className="cell-title">{s.name}</Link>
                                        {s.has_no_runbook && <span className="mt-1 block rounded bg-amber-50 px-1.5 py-0.5 text-[10px] text-amber-800">No runbook</span>}
                                    </td>
                                    <td className="cell-muted">
                                        {s.application ?? '—'}
                                        {s.application_retired && <span className="block text-[10px] text-amber-700">application retired</span>}
                                    </td>
                                    <td>{s.recovery_tier ?? <span className="text-gray-400">not set</span>}</td>
                                    <td className="font-mono text-xs">
                                        {s.rto_target_hours ?? '—'}h / {s.rpo_target_minutes ?? '—'}m
                                    </td>
                                    <td className="text-xs">{s.dr_strategy?.replace(/_/g, ' ') ?? '—'}</td>
                                    <td className={`text-xs ${s.last_test_met_objectives === false ? 'text-rose-700' : ''}`}>
                                        {s.last_test_date
                                            ? `${s.last_test_rto_actual_minutes ?? '—'}m / ${s.rto_target_hours ? s.rto_target_hours * 60 : '—'}m target`
                                            : 'never tested'}
                                    </td>
                                    <td className={s.is_overdue ? 'text-rose-700' : ''}>{s.next_test_due ?? 'not set'}</td>
                                    <td className={s.backup_stale ? 'text-rose-700' : ''}>{s.backup_verified_at ? formatIncidentDateTime(s.backup_verified_at) : 'never'}</td>
                                    <td className="max-w-xs text-xs">
                                        {s.tier_mismatch && <span className="text-rose-700">{s.tier_mismatch}</span>}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}

function SystemForm({ storeUrl, options, onDone }) {
    const form = useForm({
        name: '', application_id: '', recovery_tier: '', rto_target_hours: '', rpo_target_minutes: '',
        dr_strategy: '', dr_site_id: '', failover_runbook_plan_id: '', backup_frequency: '', replication_type: '',
    });

    const submit = (e) => {
        e.preventDefault();
        form.post(storeUrl, { preserveScroll: true, onSuccess: () => { form.reset(); onDone(); } });
    };

    return (
        <form onSubmit={submit} className="mb-6 grid gap-3 rounded border border-slate-200 bg-white p-4 sm:grid-cols-3">
            <div className="sm:col-span-3">
                <label htmlFor="dr_name" className="block text-xs font-medium text-slate-600">Name</label>
                <input id="dr_name" required className="form-input mt-1 w-full" value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)} />
            </div>
            <div>
                <label htmlFor="dr_app" className="block text-xs font-medium text-slate-600">Application</label>
                <select id="dr_app" className="form-select mt-1 w-full" value={form.data.application_id}
                    onChange={(e) => form.setData('application_id', e.target.value)}>
                    <option value="">Not linked</option>
                    {(options.applications ?? []).map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                </select>
            </div>
            <div>
                <label htmlFor="dr_tier" className="block text-xs font-medium text-slate-600">Recovery tier</label>
                <input id="dr_tier" type="number" min="1" max="10" className="form-input mt-1 w-full" value={form.data.recovery_tier}
                    onChange={(e) => form.setData('recovery_tier', e.target.value)} />
            </div>
            <div>
                <label htmlFor="dr_strategy" className="block text-xs font-medium text-slate-600">DR strategy</label>
                <select id="dr_strategy" className="form-select mt-1 w-full" value={form.data.dr_strategy}
                    onChange={(e) => form.setData('dr_strategy', e.target.value)}>
                    <option value="">Not set</option>
                    {(options.dr_strategies ?? []).map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
                </select>
            </div>
            <div>
                <label htmlFor="dr_rto" className="block text-xs font-medium text-slate-600">RTO target (hours)</label>
                <input id="dr_rto" type="number" step="0.1" min="0" className="form-input mt-1 w-full" value={form.data.rto_target_hours}
                    onChange={(e) => form.setData('rto_target_hours', e.target.value)} />
            </div>
            <div>
                <label htmlFor="dr_rpo" className="block text-xs font-medium text-slate-600">RPO target (minutes)</label>
                <input id="dr_rpo" type="number" min="0" className="form-input mt-1 w-full" value={form.data.rpo_target_minutes}
                    onChange={(e) => form.setData('rpo_target_minutes', e.target.value)} />
            </div>
            <div>
                <label htmlFor="dr_site" className="block text-xs font-medium text-slate-600">DR site</label>
                <select id="dr_site" className="form-select mt-1 w-full" value={form.data.dr_site_id}
                    onChange={(e) => form.setData('dr_site_id', e.target.value)}>
                    <option value="">Not set</option>
                    {(options.sites ?? []).map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                </select>
            </div>
            <div className="sm:col-span-2">
                <label htmlFor="dr_runbook" className="block text-xs font-medium text-slate-600">Failover runbook (plan)</label>
                <select id="dr_runbook" className="form-select mt-1 w-full" value={form.data.failover_runbook_plan_id}
                    onChange={(e) => form.setData('failover_runbook_plan_id', e.target.value)}>
                    <option value="">No runbook linked</option>
                    {(options.plans ?? []).map((p) => <option key={p.id} value={p.id}>{p.title}</option>)}
                </select>
            </div>
            <div className="sm:col-span-3 flex gap-2">
                <button type="submit" disabled={form.processing} className="rounded bg-slate-800 px-3 py-1.5 text-sm text-white">Add system</button>
                <button type="button" onClick={onDone} className="rounded border border-slate-300 px-3 py-1.5 text-sm">Cancel</button>
            </div>
        </form>
    );
}
