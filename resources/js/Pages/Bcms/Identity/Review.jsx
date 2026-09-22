import { useEffect, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';

/**
 * The sync change review queue — docs/bcms/screens/identity-change-review.md.
 *
 * EVERY ACTION URL IS A SERVER-BUILT PROP. `{change}` is a numeric child
 * nested under `{run}` (`->scopeBindings()`); nothing here ever assembles a
 * `bcms.identity.changes.decide` URL from an `id` (`ModuleActionUrlRouteKeyTest`)
 * — `decide_url`/`bulk_decide_url` arrive from `IdentityPresenter`.
 *
 * A `requires_ack` ROW'S CHECKBOX IS DISABLED, UNCONDITIONALLY. Acknowledging
 * a call-tree or audience impact is always a deliberate, individual click on
 * that row's own "Acknowledge and approve" button — never a side effect of a
 * bulk selection someone else made pending rows for.
 *
 * GATE 2 DEFECT 2: `changes` IS A SERVER-PAGED LIST, NEVER THE WHOLE RUN. At
 * 5,000+ contacts a full-run prop is multi-megabyte and breaks the 100 kbps
 * DoD (§6). `kind` is the primary filter, `decision` the secondary one
 * (default `pending`, so superseded rows are absent unless asked for) — both
 * live in the URL's query string, and every navigation that changes either,
 * or turns a page, is a server round-trip with `only: ['changes', 'filters']`
 * so the run/tile counts already on screen are never re-fetched for a page
 * turn. The five tile counts are a separate cheap aggregate query, unaffected
 * by which page or which decision-view is open (`IdentityPresenter::runShow`).
 */

const KIND_LABEL = { joiner: 'Joiner', leaver: 'Leaver', mover: 'Mover', contact_change: 'Contact change' };
const KIND_CHIP_TONE = {
    joiner: 'bg-gray-100 text-gray-700',
    leaver: 'bg-amber-100 text-amber-800',
    mover: 'bg-blue-100 text-blue-700',
    contact_change: 'bg-slate-100 text-slate-700',
};
const TILE_TONE = {
    joiner: 'text-gray-800',
    leaver: 'text-amber-700',
    mover: 'text-gray-800',
    contact_change: 'text-blue-700',
};
const TRIGGER_LABEL = { scheduled_full: 'Scheduled — full', scheduled_delta: 'Scheduled — delta', manual: 'Manual' };
const KINDS = ['joiner', 'leaver', 'mover', 'contact_change'];

function fmt(dt) {
    return dt ? new Date(dt).toLocaleString() : '—';
}

/** The impact sentence shape from `BrokenBranchAnalyser::headline()` (§2), worded from the row's own kind rather than the pre-built headline, which does not vary its verb for a mover. */
function impactLines(change) {
    if (!change.impact) return [];
    const lines = [];
    const verb = change.kind === 'mover' ? 'is moving' : 'left';

    (change.impact.call_trees ?? []).forEach((ct) => {
        if (ct.is_named_deputy) {
            lines.push(`${change.subject_name} is a named deputy on an approved call tree.`);
        } else if (ct.tree_name) {
            lines.push(`${change.subject_name} ${verb}; they were Tier ${ct.tier} in the ${ct.tree_name} tree with ${ct.downstream_blocked_count} downstream staff.`);
        }
    });

    (change.impact.saved_groups ?? []).forEach((g) => {
        if (g.reason === 'static_member') lines.push(`Static member of the saved group "${g.group_name}".`);
        else if (g.reason === 'would_empty_dynamic_group') lines.push(`Would take the dynamic group "${g.group_name}" to zero members.`);
        else if (g.reason === 'unresolvable_rule') lines.push(`Member of the saved group "${g.group_name}", whose rule could not be resolved.`);
    });

    return lines;
}

function totalDownstream(change) {
    return (change.impact?.call_trees ?? []).reduce((sum, ct) => sum + (ct.downstream_blocked_count ?? 0), 0);
}

function decisionDisplay(change) {
    if (change.decision === 'approved') {
        if (change.applied_at) return { label: `Approved · applied ${fmt(change.applied_at)}`, tone: 'bg-emerald-100 text-emerald-800' };
        if (change.apply_error_class) return { label: `Approved, not yet applied — ${change.apply_error_class}`, tone: 'bg-amber-100 text-amber-800' };
        return { label: 'Approved', tone: 'bg-emerald-100 text-emerald-800' };
    }
    if (change.decision === 'auto_applied') return { label: 'Auto-applied', tone: 'bg-emerald-100 text-emerald-800' };
    if (change.decision === 'rejected') return { label: 'Rejected', tone: 'bg-gray-100 text-gray-700' };
    if (change.decision === 'superseded') return { label: 'Superseded', tone: 'bg-gray-100 text-gray-500' };
    return { label: 'Pending review', tone: 'bg-slate-100 text-slate-700' };
}

function fieldLabel(field) {
    return field.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function decodePaginatorLabel(label) {
    if (label == null) return '';
    return String(label)
        .replace(/&laquo;/g, '«')
        .replace(/&raquo;/g, '»')
        .replace(/&amp;/g, '&')
        .replace(/&hellip;/g, '…')
        .replace(/<[^>]*>/g, '')
        .trim();
}

export default function Review({
    connector,
    connector_settings_url: connectorSettingsUrl,
    run = null,
    runs = [],
    bulk_decide_url: bulkDecideUrl = null,
    filters = { kind: 'all', decision: 'pending' },
    tile_counts: tileCounts = { joiner: 0, leaver: 0, mover: 0, contact_change: 0, needs_ack: 0 },
    pending_total: pendingTotal = 0,
    superseded_count: supersededCount = 0,
    decided_count: decidedCount = 0,
    changes = null,
}) {
    const { flash } = usePage().props;

    const [selected, setSelected] = useState(() => new Set());
    const [decidingIds, setDecidingIds] = useState(() => new Set());
    const [bulkInFlight, setBulkInFlight] = useState(null); // 'approved' | 'rejected' | null

    const rows = changes?.data ?? [];
    const isPendingView = filters.decision === 'pending';

    // A dirty selection belongs to one page of one kind/decision view — a
    // fresh page (kind switch, decision switch, or a page turn) starts clean
    // rather than carrying stale ids that no longer appear on screen.
    useEffect(() => {
        setSelected(new Set());
    }, [filters.kind, filters.decision, changes?.current_page]);

    /**
     * Every kind/decision switch is a server round-trip that reloads only
     * `changes`/`filters` (identity-change-review.md §6) — the run, the
     * tile counts and the runs list already on screen are not re-fetched for
     * what is, from the reviewer's point of view, just turning to a
     * different slice of the same run.
     */
    const goTo = (params) => {
        if (!run) return;
        router.get(
            run.review_url,
            { kind: filters.kind, decision: filters.decision, ...params },
            { preserveState: true, preserveScroll: true, only: ['changes', 'filters'] },
        );
    };

    const goToPage = (url) => {
        if (!url) return;
        router.visit(url, { preserveState: true, preserveScroll: true, only: ['changes', 'filters'] });
    };

    const selectableRows = rows.filter((c) => !c.requires_ack && c.is_actionable);
    const allSelectableSelected = selectableRows.length > 0 && selectableRows.every((c) => selected.has(c.id));

    const toggleSelectAll = () => {
        setSelected((prev) => {
            const next = new Set(prev);
            if (allSelectableSelected) {
                selectableRows.forEach((c) => next.delete(c.id));
            } else {
                selectableRows.forEach((c) => next.add(c.id));
            }
            return next;
        });
    };

    const toggleRow = (change) => {
        if (change.requires_ack) return; // unconditional — see file header
        setSelected((prev) => {
            const next = new Set(prev);
            if (next.has(change.id)) next.delete(change.id); else next.add(change.id);
            return next;
        });
    };

    const decideOne = (change, decision) => {
        setDecidingIds((prev) => new Set(prev).add(change.id));
        router.post(change.decide_url, { decision }, {
            preserveScroll: true,
            onFinish: () => setDecidingIds((prev) => { const n = new Set(prev); n.delete(change.id); return n; }),
        });
    };

    const decideBulk = (decision) => {
        const ids = [...selected];
        if (ids.length === 0 || !bulkDecideUrl) return;
        setBulkInFlight(decision);
        router.post(bulkDecideUrl, { decision, change_ids: ids }, {
            preserveScroll: true,
            onSuccess: () => setSelected(new Set()),
            onFinish: () => setBulkInFlight(null),
        });
    };

    const switchRun = (uuid) => {
        const target = runs.find((r) => r.uuid === uuid);
        if (target) router.get(target.review_url, {}, { preserveScroll: false });
    };

    const bulkLabel = (base, decision) => {
        if (bulkInFlight === decision) return decision === 'approved' ? `Approving ${selected.size}…` : `Rejecting ${selected.size}…`;
        return `${base} ${selected.size} selected`;
    };

    if (run === null) {
        return (
            <AppLayout title="Directory sync — review changes">
                <Head title="Directory sync — review changes" />
                <PageHeader title="Directory sync — review changes"
                    actions={<Link href={connectorSettingsUrl} className="btn-secondary text-sm">Connector settings</Link>} />
                <p className="rounded-lg border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500">
                    No sync has run yet. Configure and run the connector from BCMS settings, then come back here to
                    review what it finds.{' '}
                    <Link href={connectorSettingsUrl} className="underline">BCMS settings</Link>.
                </p>
            </AppLayout>
        );
    }

    return (
        <AppLayout title="Directory sync — review changes">
            <Head title="Directory sync — review changes" />

            <PageHeader
                title="Directory sync — review changes"
                subtitle={`${connector?.name ?? 'Connector'} · run ${fmt(run.started_at)} · ${run.trigger_label ?? TRIGGER_LABEL[run.trigger] ?? run.trigger}`}
                actions={(
                    <div className="flex items-center gap-2">
                        <Link href={connectorSettingsUrl} className="btn-secondary text-sm">Connector settings</Link>
                        {runs.length > 0 && (
                            <select className="form-select text-sm" value={run.uuid} onChange={(e) => switchRun(e.target.value)}
                                aria-label="Choose a run to review">
                                {runs.map((r) => (
                                    <option key={r.uuid} value={r.uuid}>
                                        {fmt(r.started_at)} — {r.status_label ?? r.status} ({r.pending_count} pending)
                                    </option>
                                ))}
                            </select>
                        )}
                    </div>
                )}
            />

            {run.status === 'running' && (
                <div className="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-800">
                    This sync is still running — {run.directory_objects_read} read so far. Come back once it
                    finishes; changes cannot be reviewed mid-run.
                </div>
            )}

            {run.status !== 'running' && (
                <>
                    {run.status === 'failed' && (
                        <div className="mb-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900">
                            This run failed — {run.error_class}. Changes staged before the failure are still listed
                            below and can be reviewed; nothing after the failure was read.
                        </div>
                    )}
                    {run.status === 'partial' && (
                        <div className="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                            This run completed with some pages unread — {run.error_class} partway through. What was
                            read is below; a later run will pick up the rest.
                        </div>
                    )}

                    {flash?.success && (
                        <div className="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900">{flash.success}</div>
                    )}
                    {flash?.error && (
                        <div className="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-900">{flash.error}</div>
                    )}

                    {supersededCount > 0 && (
                        <p className="mb-4 text-sm text-gray-500">
                            {supersededCount} change(s) from this run were superseded by a later sync before anyone
                            reviewed them and are hidden.{' '}
                            {filters.decision === 'superseded' ? (
                                <button type="button" className="underline" onClick={() => goTo({ decision: 'pending' })}>
                                    Back to the pending queue
                                </button>
                            ) : (
                                <button type="button" className="underline" onClick={() => goTo({ decision: 'superseded' })}>
                                    Show superseded
                                </button>
                            )}
                        </p>
                    )}

                    <div className="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-5" role="group" aria-label="Change counts">
                        {KINDS.map((k) => (
                            <Tile key={k} label={`${KIND_LABEL[k]}s`} value={tileCounts[k]} tone={TILE_TONE[k]} />
                        ))}
                        <Tile label="Needs acknowledgement" value={tileCounts.needs_ack} tone="text-red-700" />
                    </div>

                    {pendingTotal === 0 && filters.decision === 'pending' ? (
                        decidedCount === 0 && supersededCount === 0 ? (
                            <p className="rounded-lg border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500">
                                This sync found no changes — the roster already matched the directory.
                            </p>
                        ) : (
                            <p className="rounded-lg border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500">
                                Every change from this run has been decided.{' '}
                                {decidedCount > 0 && (
                                    <button type="button" className="underline" onClick={() => goTo({ decision: 'decided' })}>
                                        Show decided changes
                                    </button>
                                )}
                            </p>
                        )
                    ) : (
                        <>
                            <div role="tablist" aria-label="Change kind" className="mb-3 flex flex-wrap gap-2">
                                {['all', ...KINDS].map((k) => {
                                    const count = k === 'all'
                                        ? pendingTotal
                                        : tileCounts[k];
                                    const label = k === 'all' ? 'All' : `${KIND_LABEL[k]}s`;
                                    return (
                                        <button key={k} type="button" role="tab" aria-selected={filters.kind === k}
                                            aria-label={`${label}, ${count}`}
                                            onClick={() => goTo({ kind: k })}
                                            className={`rounded-md px-3 py-1.5 text-sm ${filters.kind === k ? 'bg-[var(--color-primary)] text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'}`}>
                                            {label}
                                            <span className="ml-2 text-xs opacity-75" aria-hidden="true">{count}</span>
                                        </button>
                                    );
                                })}
                            </div>

                            {!isPendingView && (
                                <p className="mb-3 text-sm text-gray-500">
                                    Viewing {filters.decision} changes.{' '}
                                    <button type="button" className="underline" onClick={() => goTo({ decision: 'pending' })}>
                                        Back to the pending queue
                                    </button>
                                </p>
                            )}

                            {isPendingView && bulkDecideUrl && (
                                <div className="sticky top-0 z-10 mb-3 flex flex-wrap items-center gap-3 rounded-lg border border-gray-200 bg-white p-3 shadow-sm">
                                    <label className="flex items-center gap-2 text-sm text-gray-700">
                                        <input type="checkbox" className="form-checkbox"
                                            checked={allSelectableSelected} onChange={toggleSelectAll}
                                            disabled={selectableRows.length === 0} />
                                        Select all pending in this view (acknowledgement-required rows are not included)
                                    </label>
                                    <span aria-live="polite" className="text-sm text-gray-600">{selected.size} selected</span>
                                    <button type="button" className="btn-secondary text-sm"
                                        disabled={selected.size === 0 || bulkInFlight !== null}
                                        onClick={() => decideBulk('approved')}>
                                        {bulkLabel('Approve', 'approved')}
                                    </button>
                                    <button type="button" className="btn-secondary text-sm"
                                        disabled={selected.size === 0 || bulkInFlight !== null}
                                        onClick={() => decideBulk('rejected')}>
                                        {bulkLabel('Reject', 'rejected')}
                                    </button>
                                </div>
                            )}

                            <div className="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                                <table className="data-table">
                                    <caption className="sr-only">
                                        Changes from this run, {changes?.total ?? rows.length} {filters.decision}
                                    </caption>
                                    <thead>
                                        <tr>
                                            {isPendingView && <th scope="col" className="w-10" />}
                                            <th scope="col">Person</th>
                                            <th scope="col">Kind</th>
                                            <th scope="col">Before → After</th>
                                            <th scope="col">Call-tree impact</th>
                                            <th scope="col">Decision</th>
                                            {isPendingView && <th scope="col" />}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {rows.length === 0 && (
                                            <tr>
                                                <td colSpan={isPendingView ? 7 : 5} className="py-8 text-center text-sm text-gray-400">
                                                    No changes in this view.
                                                </td>
                                            </tr>
                                        )}
                                        {rows.map((change) => (
                                            <ChangeRow key={change.id} change={change}
                                                showCheckbox={isPendingView}
                                                selected={selected.has(change.id)}
                                                onToggle={() => toggleRow(change)}
                                                deciding={decidingIds.has(change.id)}
                                                onDecide={(decision) => decideOne(change, decision)} />
                                        ))}
                                    </tbody>
                                </table>

                                {changes?.links?.length > 3 && (
                                    <ChangesPagination links={changes.links} meta={changes} onNavigate={goToPage} />
                                )}
                            </div>
                        </>
                    )}
                </>
            )}
        </AppLayout>
    );
}

function Tile({ label, value, tone }) {
    return (
        <div className="rounded-lg border border-gray-200 bg-white p-4">
            <p className="text-xs uppercase tracking-wider text-gray-500">{label}</p>
            <p className={`mt-1 text-2xl font-semibold ${tone}`}>{value ?? 0}</p>
        </div>
    );
}

/**
 * Paginates `changes` with an Inertia partial reload (`only:
 * ['changes','filters']`, identity-change-review.md §6) rather than the
 * shared `@thirdline/ui` `Pagination`, which always issues a full `<Link>`
 * navigation — a page turn here must not re-fetch the run, the tile counts
 * or the runs list a reviewer already has on screen.
 */
function ChangesPagination({ links, meta, onNavigate }) {
    return (
        <div className="flex items-center justify-between border-t border-gray-100 px-4 py-3">
            <div className="text-sm text-gray-500">
                {meta && (
                    <span>Showing {meta.from || 0} to {meta.to || 0} of {meta.total || 0} results</span>
                )}
            </div>
            <nav className="flex items-center gap-1">
                {links.map((link, index) => (
                    <button
                        key={index}
                        type="button"
                        disabled={!link.url}
                        onClick={() => onNavigate(link.url)}
                        className={`rounded-md px-3 py-1.5 text-sm transition-colors ${
                            link.active
                                ? 'bg-[var(--color-primary)] font-semibold text-white'
                                : link.url
                                    ? 'text-gray-600 hover:bg-gray-100'
                                    : 'cursor-not-allowed text-gray-300'
                        }`}
                    >
                        {decodePaginatorLabel(link.label)}
                    </button>
                ))}
            </nav>
        </div>
    );
}

function ChangeRow({ change, showCheckbox, selected, onToggle, deciding, onDecide }) {
    const lines = impactLines(change);
    const decision = decisionDisplay(change);
    const downstream = totalDownstream(change);

    const before = change.before ?? {};
    const after = change.after ?? {};
    const fields = [...new Set([...Object.keys(before), ...Object.keys(after)])];

    return (
        <tr className={change.requires_ack ? 'border-l-4 border-l-red-500' : ''}>
            {showCheckbox && (
                <td>
                    {change.requires_ack ? (
                        <input type="checkbox" disabled aria-describedby={`ack-note-${change.id}`} className="form-checkbox" />
                    ) : (
                        <input type="checkbox" className="form-checkbox" checked={selected} onChange={onToggle}
                            aria-label={`Select: ${change.subject_name}, ${KIND_LABEL[change.kind].toLowerCase()}`} />
                    )}
                </td>
            )}
            <td>
                <span className="text-sm text-gray-900">{change.subject_name}</span>
                {change.kind === 'joiner' && (
                    <span className="ml-2 rounded bg-gray-100 px-1.5 py-0.5 text-[11px] text-gray-500">
                        {change.contact_id ? 'existing contact' : 'new'}
                    </span>
                )}
                {change.requires_ack && (
                    <span className="ml-2 rounded bg-red-100 px-1.5 py-0.5 text-[11px] text-red-800">Needs acknowledgement</span>
                )}
            </td>
            <td>
                <span className={`badge ${KIND_CHIP_TONE[change.kind]}`}>{KIND_LABEL[change.kind]}</span>
            </td>
            <td>
                {fields.length === 0 ? (
                    <span className="text-xs text-gray-400">No field changes</span>
                ) : (
                    <ul className="space-y-0.5 text-xs text-gray-700">
                        {fields.map((f) => (
                            <li key={f}>
                                <span className="text-gray-500">{fieldLabel(f)}:</span>{' '}
                                <span className="text-gray-500 line-through">{String(before[f] ?? '—')}</span>{' → '}
                                <span className="text-gray-900">{String(after[f] ?? '—')}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </td>
            <td className="max-w-xs">
                {lines.length === 0 ? (
                    <span className="text-xs text-gray-400">No call-tree or audience impact</span>
                ) : (
                    <ul className="space-y-1 text-xs text-gray-800">
                        {lines.map((line, i) => <li key={i}>{line}</li>)}
                    </ul>
                )}
                {change.requires_ack && change.is_actionable && (
                    <p id={`ack-note-${change.id}`} className="mt-1 text-[11px] text-gray-500">
                        Requires individual acknowledgement — use the row&rsquo;s own Approve button
                    </p>
                )}
            </td>
            <td><span className={`badge ${decision.tone}`}>{decision.label}</span></td>
            {showCheckbox && (
                <td className="text-right">
                    {change.is_actionable && (
                        deciding ? (
                            <span className="text-xs text-gray-500">Deciding…</span>
                        ) : (
                            <div className="flex justify-end gap-2">
                                <button type="button" className="text-xs text-red-700 hover:underline"
                                    aria-label={`Reject: ${change.subject_name}`}
                                    onClick={() => onDecide('rejected')}>
                                    Reject
                                </button>
                                <button type="button" className="text-xs text-[var(--color-primary)] hover:underline"
                                    aria-label={change.requires_ack ? `Acknowledge and approve: ${change.subject_name}` : `Approve: ${change.subject_name}`}
                                    onClick={() => onDecide('approved')}>
                                    {change.requires_ack ? 'Acknowledge and approve' : 'Approve'}
                                </button>
                                {change.requires_ack && downstream > 0 && (
                                    <span className="text-[11px] text-gray-500">This will re-parent {downstream} people&rsquo;s call-tree branch.</span>
                                )}
                            </div>
                        )
                    )}
                </td>
            )}
        </tr>
    );
}
