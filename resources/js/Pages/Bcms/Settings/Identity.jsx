import { useEffect, useMemo, useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import FormSection from '@thirdline/ui/Components/FormSection';
import FormField from '@thirdline/ui/Components/FormField';

/**
 * The Entra connector — docs/bcms/screens/identity-connector.md.
 *
 * NOTHING SYNCS UNTIL TEST CONNECTION SUCCEEDS. The connector is created
 * `is_active = false` (ADR 0018 §2.2 item 5); this screen never exposes an
 * "Active" toggle of its own — a successful Test connection is what flips it.
 *
 * THE SECRET IS WRITE-ONLY. The value is never fetched, never pre-filled;
 * the reveal is always empty when it opens, and a blank submit leaves the
 * stored secret untouched (the controller's own rule, restated here in copy).
 *
 * THE RUNS POLL MIRRORS `CallTrees/Live.jsx`: a small JSON document, five
 * seconds, and it stops the instant the latest run is no longer `running` —
 * this run lives in `bcms_identity_sync_runs`, not the `JobRun` table
 * `useJobProgress` is bound to.
 */

const EDITABLE_ROWS = [
    { field: 'full_name', label: 'Full name' },
    { field: 'employee_id', label: 'Employee ID' },
    { field: 'title', label: 'Title' },
    { field: 'business_unit_id', label: 'Business unit' },
    { field: 'site_id', label: 'Site' },
    { field: 'email', label: 'Email' },
    { field: 'mobile_primary', label: 'Mobile (primary)' },
];

const STATUS_TONE = {
    running: 'bg-slate-100 text-slate-700',
    success: 'bg-emerald-100 text-emerald-800',
    partial: 'bg-amber-100 text-amber-800',
    failed: 'bg-red-100 text-red-800',
};

const TRIGGER_LABEL = {
    scheduled_full: 'Scheduled — full',
    scheduled_delta: 'Scheduled — delta',
    manual: 'Manual',
};

/** entra-attribute -> bcms-field becomes bcms-field -> entra-attribute for the table's own row order. */
function invertMap(map) {
    const out = {};
    Object.entries(map || {}).forEach(([entra, bcmsField]) => {
        out[bcmsField] = entra;
    });
    return out;
}

function daysUntil(dateString) {
    if (!dateString) return null;
    const ms = new Date(`${dateString}T00:00:00Z`).getTime() - new Date().setUTCHours(0, 0, 0, 0);
    return Math.round(ms / 86400000);
}

export default function Identity({
    connector,
    attribute_map_defaults: attributeMapDefaults = {},
    never_synced_fields: neverSyncedFields = [],
    declared_scope: declaredScope = [],
    roster_summary: rosterSummary,
    runs: initialRuns = [],
    update_url: updateUrl,
    test_url: testUrl,
    sync_url: syncUrl,
    runs_status_url: runsStatusUrl,
    settings_index_url: settingsIndexUrl,
}) {
    const { flash } = usePage().props;

    const [runs, setRuns] = useState(initialRuns);
    const [editingSecret, setEditingSecret] = useState(false);
    const [lastAction, setLastAction] = useState(null); // 'save' | 'test' | 'save_test' | 'sync'
    const [testing, setTesting] = useState(false);
    const [syncing, setSyncing] = useState(false);
    // "Sync now" QUEUES a job (SyncBcmsIdentityJob) rather than running one
    // inline — no run row exists until a worker picks it up. This screen
    // polls from the moment it queues, not only once a `running` row exists,
    // so the gap between "queued" and "started" is not a dead screen.
    const [syncQueued, setSyncQueued] = useState(false);

    const defaultsByField = useMemo(() => invertMap(attributeMapDefaults), [attributeMapDefaults]);
    const currentByField = useMemo(
        () => invertMap(connector?.attribute_map ?? attributeMapDefaults),
        [connector, attributeMapDefaults],
    );

    const form = useForm({
        name: connector?.name ?? '',
        directory_tenant_id: connector?.directory_tenant_id ?? '',
        client_id: connector?.client_id ?? '',
        client_secret: '',
        token_base_url: connector?.token_base_url ?? 'https://login.microsoftonline.com',
        graph_base_url: connector?.graph_base_url ?? 'https://graph.microsoft.com/v1.0',
        directory_filter: connector?.directory_filter ?? '',
        sync_schedule: connector?.sync_schedule ?? 'nightly',
        auto_apply_policy: connector?.auto_apply_policy ?? 'none',
        credential_expires_on: connector?.credential_expires_on ?? '',
        map: currentByField,
    });

    const topRun = runs[0] ?? null;
    const isRunning = topRun?.status === 'running';
    const isFailed = topRun?.status === 'failed';

    // The poll stops the instant a tick returns a terminal status (§6) — a
    // tab left open after an unattended nightly sync must not keep polling.
    // It also runs while a click has just queued a sync but no `running` row
    // exists yet (the job has not been picked up by a worker) — otherwise the
    // screen sits static between "queued" and the row actually appearing.
    useEffect(() => {
        if (!isRunning && !syncQueued) return undefined;

        let cancelled = false;

        const id = window.setInterval(() => {
            fetch(runsStatusUrl, { headers: { Accept: 'application/json' } })
                .then((r) => (r.ok ? r.json() : null))
                .then((data) => {
                    if (cancelled || !data) return;
                    if (data.run && data.run.uuid !== topRun?.uuid) {
                        setRuns((prev) => [data.run, ...prev]);
                        setSyncQueued(false);
                    } else if (data.run) {
                        setRuns((prev) => [data.run, ...prev.slice(1)]);
                    }
                })
                .catch(() => {});
        }, 5000);

        return () => {
            cancelled = true;
            window.clearInterval(id);
        };
    }, [isRunning, syncQueued, runsStatusUrl, topRun?.uuid]);

    const setMapField = (field, entraAttribute) => {
        form.setData('map', { ...form.data.map, [field]: entraAttribute });
    };

    const buildPayload = (data) => {
        const { map, ...rest } = data;
        const mapEntries = Object.entries(map);
        const isAllDefault = mapEntries.every(([field, entra]) => defaultsByField[field] === entra);

        return {
            ...rest,
            directory_filter: rest.directory_filter === '' ? null : rest.directory_filter,
            credential_expires_on: rest.credential_expires_on === '' ? null : rest.credential_expires_on,
            client_secret: rest.client_secret === '' ? null : rest.client_secret,
            attribute_map: isAllDefault ? null : Object.fromEntries(mapEntries.map(([field, entra]) => [entra, field])),
        };
    };

    const save = (onSuccess) => {
        form.transform(buildPayload).put(updateUrl, {
            preserveScroll: true,
            onSuccess: () => {
                setEditingSecret(false);
                form.setData('client_secret', '');
                onSuccess?.();
            },
        });
    };

    const handleSave = (event) => {
        event.preventDefault();
        setLastAction('save');
        save();
    };

    const handleTest = () => {
        const runTest = () => {
            setTesting(true);
            router.post(testUrl, {}, {
                preserveScroll: true,
                onFinish: () => setTesting(false),
            });
        };

        if (form.isDirty) {
            setLastAction('save_test');
            save(runTest);
        } else {
            setLastAction('test');
            runTest();
        }
    };

    const handleSync = () => {
        setLastAction('sync');
        setSyncing(true);
        router.post(syncUrl, {}, {
            onSuccess: () => setSyncQueued(true),
            onFinish: () => setSyncing(false),
        });
    };

    const expiresDays = daysUntil(connector?.credential_expires_on);
    const expiringSoon = connector?.health?.credential_expiring_soon === true;
    const expiringUrgent = expiringSoon && expiresDays !== null && expiresDays <= 7;
    // Derived from the live `runs` list, not `connector.health.consecutive_
    // failures` — that figure is a one-time snapshot from when the page
    // loaded, and the poll keeps `runs` current but never re-fetches health,
    // so trusting the snapshot after a poll tick would show a stale count
    // the moment a newly-polled run is itself a failure.
    const consecutiveFailures = useMemo(() => {
        let count = 0;
        for (const r of runs) {
            if (r.status !== 'failed') break;
            count++;
        }
        return count;
    }, [runs]);

    const testDisabledReason = connector === null ? 'Save the connector first.' : null;
    const syncDisabledReason = connector === null
        ? 'Save the connector first.'
        : isRunning || syncQueued
            ? 'A sync is already running.'
            : !connector.is_active
                ? 'Test the connection first.'
                : null;

    return (
        <AppLayout title="Identity sync">
            <Head title="Identity sync" />

            <PageHeader
                title="Identity sync (Microsoft Entra ID)"
                subtitle="Reads your organisation's Entra ID directory to keep the contact roster and call trees current. Read-only — nothing is ever written back to Entra."
                actions={(
                    <Link href={settingsIndexUrl} className="btn-secondary text-sm">BCMS settings</Link>
                )}
            />

            <div className="space-y-4">
                {connector === null && (
                    <Banner tone="slate">
                        No identity connector is configured. Fill in the fields below and save, then run{' '}
                        <strong>Test connection</strong> before the first sync — nothing syncs until a connector is
                        both saved and tested.
                    </Banner>
                )}

                {connector !== null && !connector.is_active && (
                    <Banner tone="amber">
                        This connector is saved but not active. Nothing syncs — including the nightly schedule —
                        until <strong>Test connection</strong> succeeds at least once.
                    </Banner>
                )}

                {connector !== null && connector.credential_expires_on !== null && expiringSoon && (
                    <Banner tone={expiringUrgent ? 'red' : 'amber'}>
                        The app registration&rsquo;s client secret expires on{' '}
                        <strong>{connector.credential_expires_on}</strong> ({expiresDays} day{expiresDays === 1 ? '' : 's'}).
                        Rotate it in Entra, then use <strong>Set / rotate secret</strong> below before it lapses — a
                        lapsed secret freezes the roster silently; nothing on this screen will look wrong until the
                        next sync fails.
                    </Banner>
                )}

                {syncQueued && !isRunning && (
                    <Banner tone="slate">
                        <span aria-live="polite">
                            A sync has been queued and will start shortly. This page checks every 5 seconds and
                            updates once it starts.
                        </span>
                    </Banner>
                )}

                {isRunning && (
                    <Banner tone="slate">
                        <span aria-live="polite">
                            A sync is running — started {topRun.started_at ? new Date(topRun.started_at).toLocaleString() : 'moments ago'}.
                            This page checks every 5 seconds and updates when it finishes.
                        </span>
                    </Banner>
                )}

                {!isRunning && !syncQueued && isFailed && (
                    <Banner tone="red">
                        The last sync failed at {topRun.finished_at ? new Date(topRun.finished_at).toLocaleString() : '—'} — {topRun.error_class ?? 'unknown error'}.{' '}
                        {consecutiveFailures} consecutive failure{consecutiveFailures === 1 ? '' : 's'}. Nothing was
                        applied to the roster.
                    </Banner>
                )}
            </div>

            <form onSubmit={handleSave} className="form-page mt-4">
                <FormSection title="Connection configuration">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <FormField label="Connector name" htmlFor="identity-name" required error={form.errors.name}>
                            <input id="identity-name" className="form-input" value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)} />
                        </FormField>

                        <FormField label="Directory tenant ID" htmlFor="identity-tenant-id" required error={form.errors.directory_tenant_id}
                            hint="The Entra tenant GUID — plain text, not treated as a secret.">
                            <input id="identity-tenant-id" className="form-input" value={form.data.directory_tenant_id}
                                onChange={(e) => form.setData('directory_tenant_id', e.target.value)} />
                        </FormField>

                        <FormField label="Client (application) ID" htmlFor="identity-client-id" required error={form.errors.client_id}
                            hint="Not a secret.">
                            <input id="identity-client-id" className="form-input" value={form.data.client_id}
                                onChange={(e) => form.setData('client_id', e.target.value)} />
                        </FormField>

                        <FormField label="Graph base URL" htmlFor="identity-graph-url" required error={form.errors.graph_base_url}>
                            <input id="identity-graph-url" className="form-input" value={form.data.graph_base_url}
                                onChange={(e) => form.setData('graph_base_url', e.target.value)} />
                        </FormField>

                        <FormField label="Token base URL" htmlFor="identity-token-url" required error={form.errors.token_base_url}>
                            <input id="identity-token-url" className="form-input" value={form.data.token_base_url}
                                onChange={(e) => form.setData('token_base_url', e.target.value)} />
                        </FormField>

                        <FormField label="Attribute filter" htmlFor="identity-filter" error={form.errors.directory_filter}
                            hint="This filters by user attribute, not by group. Filtering by Entra group membership needs a wider permission this connector deliberately does not request — see Declared permissions below.">
                            <input id="identity-filter" className="form-input" value={form.data.directory_filter}
                                onChange={(e) => form.setData('directory_filter', e.target.value)}
                                placeholder="e.g. accountEnabled eq true" />
                        </FormField>

                        <FormField label="Credential expires on" htmlFor="identity-expires" error={form.errors.credential_expires_on}
                            hint={connector?.credential_expires_on
                                ? 'Entra does not report this to us — copy it from the app registration each time you rotate the secret.'
                                : 'No expiry date is recorded for the current secret. Entra does not report this to us — copy it from the app registration each time you rotate the secret.'}>
                            <input id="identity-expires" type="date" className="form-input" value={form.data.credential_expires_on ?? ''}
                                onChange={(e) => form.setData('credential_expires_on', e.target.value)} />
                        </FormField>

                        <FormField label="Sync schedule" htmlFor="identity-schedule" error={form.errors.sync_schedule}
                            hint={form.data.sync_schedule === 'nightly_plus_delta'
                                ? 'Delta catches attribute changes every 15 minutes but never re-resolves who reports to whom — the nightly full run is what keeps the org chart correct.'
                                : undefined}>
                            <select id="identity-schedule" className="form-select" value={form.data.sync_schedule}
                                onChange={(e) => form.setData('sync_schedule', e.target.value)}>
                                <option value="nightly">Nightly only</option>
                                <option value="nightly_plus_delta">Nightly + 15-minute delta</option>
                                <option value="manual">Manual only</option>
                            </select>
                        </FormField>

                        <FormField label="Auto-apply policy" htmlFor="identity-auto-apply" error={form.errors.auto_apply_policy}
                            hint="A change that would break a call tree or empty a saved audience always needs a person's acknowledgement, whichever option is chosen here.">
                            <select id="identity-auto-apply" className="form-select" value={form.data.auto_apply_policy}
                                onChange={(e) => form.setData('auto_apply_policy', e.target.value)}>
                                <option value="none">Review everything</option>
                                <option value="safe_only">Auto-apply changes with no call-tree or audience impact</option>
                            </select>
                        </FormField>
                    </div>

                    <SecretField
                        hasSecret={connector?.has_client_secret === true}
                        editing={editingSecret}
                        onOpen={() => setEditingSecret(true)}
                        onCancel={() => { setEditingSecret(false); form.setData('client_secret', ''); }}
                        value={form.data.client_secret}
                        onChange={(v) => form.setData('client_secret', v)}
                        error={form.errors.client_secret}
                    />

                    <div className="form-actions">
                        {lastAction === 'save' && flash?.success && (
                            <span className="text-sm text-emerald-700">{flash.success}</span>
                        )}
                        {lastAction === 'save' && flash?.error && (
                            <span className="text-sm text-red-700">{flash.error}</span>
                        )}
                        <button type="submit" className="btn-primary text-sm" disabled={form.processing}>
                            {form.processing && lastAction !== 'save_test' ? 'Saving…' : 'Save connector'}
                        </button>
                    </div>
                </FormSection>

                <FormSection title="Declared Graph permissions">
                    <p className="text-sm text-gray-700">
                        This connector requests <strong>{declaredScope.join(', ')}</strong> only. It cannot write to your
                        directory — there is no code path that calls a write endpoint, and this is enforced, not
                        merely promised. The most recent <strong>Test connection</strong> result below states any
                        wider scope your app registration also grants.
                    </p>

                    <div className="flex flex-wrap items-center gap-3">
                        {testDisabledReason && <p className="text-sm text-gray-500">{testDisabledReason}</p>}
                        <button type="button" className="btn-secondary text-sm"
                            onClick={handleTest} disabled={connector === null || testing}>
                            {testing ? 'Testing…' : form.isDirty ? 'Save & test connection' : 'Test connection'}
                        </button>
                    </div>

                    {(lastAction === 'test' || lastAction === 'save_test') && !testing && flash?.success && (
                        <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900">
                            {flash.success}
                        </div>
                    )}
                    {(lastAction === 'test' || lastAction === 'save_test') && !testing && flash?.error && (
                        <div className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-900">
                            {flash.error}
                        </div>
                    )}
                </FormSection>

                <FormSection title="Attribute mapping"
                    description="How Entra fields map to BCMS fields, with the shipped defaults marked.">
                    <table className="data-table">
                        <caption className="sr-only">How Entra fields map to BCMS fields, with the shipped defaults marked</caption>
                        <thead>
                            <tr>
                                <th scope="col">BCMS field</th>
                                <th scope="col">Entra attribute</th>
                            </tr>
                        </thead>
                        <tbody>
                            {EDITABLE_ROWS.map((row) => {
                                const value = form.data.map[row.field] ?? '';
                                const isDefault = defaultsByField[row.field] === value;
                                return (
                                    <tr key={row.field}>
                                        <th scope="row" className="text-left font-normal text-gray-700">{row.label}</th>
                                        <td>
                                            <div className="flex items-center gap-2">
                                                <input className="form-input" value={value}
                                                    aria-label={`Entra attribute for ${row.label}`}
                                                    onChange={(e) => setMapField(row.field, e.target.value)} />
                                                {isDefault && (
                                                    <span className="rounded bg-gray-100 px-1.5 py-0.5 text-[11px] text-gray-500">default</span>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })}
                            <tr>
                                <th scope="row" className="text-left font-normal text-gray-700">Manager</th>
                                <td>
                                    <p className="text-sm text-gray-600">manager (relationship, not an attribute)</p>
                                    <p className="text-xs text-gray-500">Always the reporting edge Entra returns.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row" className="text-left font-normal text-gray-700">Active</th>
                                <td>
                                    <p className="text-sm text-gray-600">accountEnabled</p>
                                    <p className="text-xs text-gray-500">Always drives deactivation — a leaver is never re-included by relabelling this field.</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </FormSection>

                <FormSection title="Never synced"
                    description="These fields are never written by any sync, even if Entra holds a value for them. A directory listing a mobile number is not the same fact as a person agreeing to be texted on it.">
                    <table className="data-table">
                        <caption className="sr-only">Fields no sync ever writes, and why</caption>
                        <thead>
                            <tr><th scope="col">Field</th></tr>
                        </thead>
                        <tbody>
                            {neverSyncedFields.map((field) => (
                                <tr key={field}><td className="font-mono text-xs text-gray-600">{field}</td></tr>
                            ))}
                        </tbody>
                    </table>
                </FormSection>

                <FormSection title="Schedule and last runs">
                    <table className="data-table">
                        <caption className="sr-only">The last 10 sync runs for this connector</caption>
                        <thead>
                            <tr>
                                <th scope="col">Trigger</th>
                                <th scope="col">Started</th>
                                <th scope="col">Finished</th>
                                <th scope="col">Status</th>
                                <th scope="col">Counts</th>
                                <th scope="col" />
                            </tr>
                        </thead>
                        <tbody>
                            {runs.length === 0 && (
                                <tr><td colSpan={6} className="py-6 text-center text-sm text-gray-500">No runs yet.</td></tr>
                            )}
                            {runs.map((run, index) => (
                                <tr key={run.uuid}>
                                    <td className="text-sm text-gray-700">{TRIGGER_LABEL[run.trigger] ?? run.trigger}</td>
                                    <td className="text-sm text-gray-500">{run.started_at ? new Date(run.started_at).toLocaleString() : '—'}</td>
                                    <td className="text-sm text-gray-500">{run.finished_at ? new Date(run.finished_at).toLocaleString() : '—'}</td>
                                    <td>
                                        <span className={`badge ${STATUS_TONE[run.status] ?? ''}`}
                                            {...(index === 0 && isRunning ? { 'aria-live': 'polite' } : {})}>
                                            {run.status_label ?? run.status}
                                        </span>
                                    </td>
                                    <td className="text-sm text-gray-700">
                                        {run.status === 'failed'
                                            ? run.error_class
                                            : `${run.joiner_count}J / ${run.leaver_count}L / ${run.mover_count}M / ${run.contact_change_count}C`}
                                    </td>
                                    <td className="text-right">
                                        {(run.status === 'success' || run.status === 'partial') && (
                                            <Link href={run.review_url} className="text-xs text-[var(--color-primary)] hover:underline">
                                                Review changes ({run.pending_count} pending)
                                            </Link>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>

                    <div className="flex flex-wrap items-center gap-3">
                        {syncDisabledReason && <p className="text-sm text-gray-500">{syncDisabledReason}</p>}
                        {connector?.is_active && !isRunning && runs.every((r) => r.status !== 'success' && r.status !== 'partial') && (
                            <p className="text-sm text-amber-700">
                                This will be the first sync. Expect the roster and every downstream call tree to
                                change — review the results before relying on either.
                            </p>
                        )}
                        <button type="button" className="btn-primary text-sm"
                            onClick={handleSync} disabled={syncDisabledReason !== null || syncing}>
                            {syncing ? 'Starting…' : 'Sync now'}
                        </button>
                    </div>
                    {lastAction === 'sync' && flash?.error && (
                        <p className="text-sm text-red-700">{flash.error}</p>
                    )}
                </FormSection>

                <FormSection title="Roster summary">
                    {rosterSummary === null ? (
                        <p className="text-sm text-gray-500">No contacts have been synced yet — the roster summary will appear once the first sync completes.</p>
                    ) : (
                        <dl className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                            <RosterFigure label="Entra contacts" value={rosterSummary.by_source.entra} />
                            <RosterFigure label="Manual contacts" value={rosterSummary.by_source.manual} />
                            <RosterFigure label="Unmatched departments" value={rosterSummary.unmatched_departments} />
                            <RosterFigure label="No manager edge" value={rosterSummary.no_manager_edge} />
                            <RosterFigure label="Consent not requested" value={rosterSummary.consent_not_requested} />
                            <RosterFigure label="Total contacts" value={rosterSummary.total_contacts} />
                        </dl>
                    )}
                </FormSection>
            </form>
        </AppLayout>
    );
}

function Banner({ tone, children }) {
    const tones = {
        slate: 'border-slate-200 bg-slate-50 text-slate-800',
        amber: 'border-amber-200 bg-amber-50 text-amber-900',
        red: 'border-red-200 bg-red-50 text-red-900',
    };
    return (
        <div className={`rounded-lg border p-4 text-sm ${tones[tone]}`}>
            {children}
        </div>
    );
}

function RosterFigure({ label, value }) {
    return (
        <div>
            <dt className="text-xs uppercase tracking-wide text-gray-500">{label}</dt>
            <dd className="font-mono text-xl text-gray-900">{value ?? 0}</dd>
        </div>
    );
}

function SecretField({ hasSecret, editing, onOpen, onCancel, value, onChange, error }) {
    if (!editing) {
        return (
            <div className="flex items-center gap-3 rounded-lg border border-gray-200 bg-gray-50 p-3">
                <p className="text-sm text-gray-700">
                    {hasSecret ? 'A client secret is set.' : 'No client secret is set yet.'}
                </p>
                <button type="button" className="btn-secondary text-sm" onClick={onOpen}>
                    {hasSecret ? 'Rotate secret' : 'Set client secret'}
                </button>
            </div>
        );
    }

    return (
        <div className="space-y-2 rounded-lg border border-gray-200 bg-gray-50 p-3">
            <FormField label={hasSecret ? 'New client secret' : 'Client secret'} htmlFor="identity-client-secret" error={error}
                hint="Leave blank to keep the current secret. You cannot see the current secret to compare it — rotating replaces it outright.">
                <input id="identity-client-secret" type="password" className="form-input" value={value} autoComplete="new-password"
                    onChange={(e) => onChange(e.target.value)} />
            </FormField>
            <div className="flex gap-2">
                <button type="button" className="btn-secondary text-sm" onClick={onCancel}>Cancel</button>
            </div>
        </div>
    );
}
