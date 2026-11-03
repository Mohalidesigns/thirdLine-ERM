import { Fragment, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import EmptyState from '@thirdline/ui/Components/EmptyState';
import Modal from '@thirdline/ui/Components/Modal';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import FailureBanner from '@/Components/Kri/FailureBanner';
import Glyph from '@/Components/Kri/Glyph';
import InfoStrip from '@/Components/Kri/InfoStrip';
import NoticeHistory from '@/Components/Kri/NoticeHistory';
import { ReturnStatusBadge } from '@/Components/Kri/Badges';
import useMediaQuery from '@/hooks/useMediaQuery';
import useVisitFailures from '@/hooks/useVisitFailures';
import { NOTICE_STATUS, formatDayShort, formatLagosClock, plural, tierWords } from '@/lib/kri-format';

const CAP = 50;
const ROLE_ORDER = ['risk_champion', 'team_lead', 'unit_head', 'group_head'];
const ROLE_PLURAL = { risk_champion: 'risk champion', team_lead: 'team lead', unit_head: 'unit head', group_head: 'group head' };
const FILTERS = [
    ['not_submitted', 'Not submitted'],
    ['submitted', 'Submitted'],
    ['all', 'All'],
];

/** Why a row cannot be reminded, as visible text (never only a tooltip). */
function blockedReason(row) {
    if (row.status === 'submitted') return 'Submitted';
    if (row.reminded_today) return 'Reminded today';

    return null;
}

function lastReminderLine(row) {
    const last = row.last_reminder;

    if (!last) return 'No reminder yet';

    return `${formatDayShort(last.sent_on)} by ${last.sent_by ? last.sent_by.name : 'the scheduler'}, reached ${tierWords(last.tier).toLowerCase()}, ${(NOTICE_STATUS[last.status] ?? last.status).toLowerCase()}`;
}

function Champions({ row }) {
    if (!row.has_champion) {
        return <span className="inline-flex items-center gap-1 text-amber-800"><Glyph name="warning" className="!h-4 !w-4" />No risk champion</span>;
    }

    return (
        <>
            {row.champions.map((c, i) => (
                <span key={c.id}>{i > 0 && ', '}<span title={c.email}>{c.name}</span>{!c.is_active && ' (inactive)'}</span>
            ))}
        </>
    );
}

function Issues({ row }) {
    return (
        <>
            {row.open_kri_issues} open
            {row.overdue_kri_issues > 0 && <span className="font-semibold text-red-700"> {'·'} <Glyph name="warning" className="!h-4 !w-4" />{row.overdue_kri_issues} overdue</span>}
        </>
    );
}

function Reminders({ row, reasonId }) {
    const reason = blockedReason(row);

    return (
        <>
            <span>{row.reminder_count} sent</span>
            <span className="block text-xs text-gray-700">{lastReminderLine(row)}</span>
            {reason && <span id={reasonId} className="block text-xs font-medium text-gray-800">{reason}</span>}
        </>
    );
}

function Kris({ row }) {
    return row.kris_due > 0 ? <>{row.kris_completed} of {row.kris_due} have a value</> : <>{'—'}</>;
}

function StatusCell({ row }) {
    return (
        <>
            <ReturnStatusBadge status={row.display_status} />
            <span className="mt-1 block text-xs text-gray-700">
                {row.days_overdue ? `${row.days_overdue} ${plural(row.days_overdue, 'day')} overdue` : `Due ${formatDayShort(row.due_on)}`}
            </span>
        </>
    );
}

export default function ReturnsIndex({ period, periods = [], filter, summary, rows = [], canRemind = false, scopeNote = null }) {
    const page = usePage();
    const flash = page.props.flash ?? {};
    const isDesktop = useMediaQuery('(min-width: 1024px)');
    const { failure, clear } = useVisitFailures();

    const [selected, setSelected] = useState(() => new Set());
    const [expanded, setExpanded] = useState(() => new Set());
    const [history, setHistory] = useState({});
    const [confirming, setConfirming] = useState(false);
    const [sending, setSending] = useState(false);
    const [slow, setSlow] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const [updatedAt, setUpdatedAt] = useState(() => formatLagosClock());
    const [results, setResults] = useState(null);
    const [sendProblem, setSendProblem] = useState(null);
    const [attempted, setAttempted] = useState(false);
    const resultsHeading = useRef(null);
    const selectAll = useRef(null);
    const sendButton = useRef(null);
    const slowTimer = useRef(null);

    const eligible = useMemo(() => rows.filter((r) => blockedReason(r) === null), [rows]);
    const eligibleIds = useMemo(() => new Set(eligible.map((r) => r.return_id)), [eligible]);

    // A reload can change who is eligible: never keep a selection that no longer is.
    useEffect(() => {
        setSelected((prev) => {
            const next = new Set([...prev].filter((id) => eligibleIds.has(id)));

            return next.size === prev.size ? prev : next;
        });
    }, [eligibleIds]);

    const count = selected.size;
    const allSelected = eligible.length > 0 && count === Math.min(eligible.length, CAP);

    useEffect(() => {
        if (selectAll.current) selectAll.current.indeterminate = count > 0 && !allSelected;
    }, [count, allSelected]);

    useEffect(() => {
        if (results && resultsHeading.current) resultsHeading.current.focus();
    }, [results]);

    const toggle = (id) => {
        setSelected((prev) => {
            const next = new Set(prev);

            if (next.has(id)) next.delete(id);
            else if (next.size < CAP) next.add(id);

            return next;
        });
    };

    const toggleAll = () => {
        setSelected(allSelected || count > 0 ? new Set() : new Set(eligible.slice(0, CAP).map((r) => r.return_id)));
    };

    const toggleRow = (id) => {
        setExpanded((prev) => {
            const next = new Set(prev);

            if (next.has(id)) next.delete(id);
            else next.add(id);

            return next;
        });
    };

    const rememberHistory = useCallback((id, notices) => setHistory((h) => ({ ...h, [id]: notices })), []);

    const visit = (extra) => router.get(route('risk.kri.returns.index'), { period: period?.id, filter, ...extra }, { preserveScroll: true, replace: true });

    const refresh = (only = ['rows', 'summary']) => {
        setRefreshing(true);
        router.reload({
            only,
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                setHistory({}); // full histories fetched earlier may be stale now
                setUpdatedAt(formatLagosClock());
            },
            onFinish: () => setRefreshing(false),
        });
    };

    const send = () => {
        setSending(true);
        setSlow(false);
        setAttempted(true);
        setSendProblem(null);
        clear();
        slowTimer.current = setTimeout(() => setSlow(true), 15000);

        router.post(route('risk.kri.returns.remind'), { return_ids: [...selected] }, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (p) => {
                const list = p.props.flash?.reminderResults ?? null;
                const queued = list ? list.filter((r) => r.outcome === 'queued').length : null;

                setResults({ list, queued, skipped: list ? list.length - queued : null, message: p.props.flash?.success ?? p.props.flash?.warning ?? null });
                setSelected(new Set());
                setAttempted(false);
                setUpdatedAt(formatLagosClock());
            },
            onError: () => setSendProblem("Something in your selection isn't valid. Refresh and select again."),
            onFinish: () => {
                clearTimeout(slowTimer.current);
                setSending(false);
                setSlow(false);
                setConfirming(false);
            },
        });
    };

    const selectedRows = rows.filter((r) => selected.has(r.return_id));
    const anyNoChampion = selectedRows.some((r) => !r.has_champion);
    const empty = rows.length === 0;
    const connectionLost = attempted && failure?.kind === 'network';

    const monthSelect = (
        <div>
            <label htmlFor="returns-month" className="mb-1 block text-xs font-medium text-gray-700">Month</label>
            <select
                id="returns-month"
                value={period?.id ?? ''}
                onChange={(e) => visit({ period: e.target.value })}
                className="min-h-11 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-[#1A365D] focus:ring-2 focus:ring-[#1A365D]"
            >
                {periods.map((p) => <option key={p.id} value={p.id}>{p.label}</option>)}
            </select>
        </div>
    );

    const checkbox = (row) => {
        const reason = blockedReason(row);
        const id = `pick-${row.return_id}`;

        return (
            <input
                id={id}
                type="checkbox"
                checked={selected.has(row.return_id)}
                disabled={reason !== null || (!selected.has(row.return_id) && count >= CAP)}
                onChange={() => toggle(row.return_id)}
                aria-describedby={reason ? `reason-${row.return_id}` : undefined}
                className="h-5 w-5 rounded border-gray-500 text-[#1A365D] focus:ring-2 focus:ring-[#1A365D]"
            />
        );
    };

    const historyRow = (row) => expanded.has(row.return_id) && (
        <NoticeHistory
            returnId={row.return_id}
            department={row.department.name}
            preview={row.notices}
            total={row.notices_total ?? row.notices.length}
            historyUrl={route('risk.kri.returns.notices', row.return_id)}
            cache={history}
            onLoaded={rememberHistory}
        />
    );

    const historyButton = (row) => (
        <button
            type="button"
            onClick={() => toggleRow(row.return_id)}
            aria-expanded={expanded.has(row.return_id)}
            aria-controls={`history-${row.return_id}`}
            className="btn-secondary min-h-11 text-sm lg:min-h-0"
        >
            {expanded.has(row.return_id) ? 'Hide' : 'Show'}<span className="sr-only"> reminder history for {row.department.name}</span>
            <span aria-hidden="true"> history</span>
        </button>
    );

    return (
        <AuthenticatedLayout title="KRI Returns">
            <Head title="KRI Returns" />

            <PageHeader
                title={`KRI returns: ${period?.label ?? ''}`}
                subtitle="Where each department's return stands now"
                breadcrumbs={[
                    { label: 'KRI Monitoring', href: route('risk.kri.index') },
                    { label: 'Dashboard', href: route('risk.kri.dashboard') },
                    { label: 'Returns' },
                ]}
            />

            <div className="mb-4 flex flex-wrap items-end gap-4">
                {monthSelect}
                <nav aria-label="Filter returns" className="inline-flex rounded-lg border border-gray-300 bg-white p-1">
                    {FILTERS.map(([value, label]) => (
                        filter === value
                            ? <span key={value} aria-current="page" className="rounded-md bg-[#1A365D] px-3 py-2 text-sm font-medium text-white">{label}</span>
                            : <Link key={value} href={route('risk.kri.returns.index', { period: period?.id, filter: value })} preserveScroll className="rounded-md px-3 py-2 text-sm font-medium text-[#1A365D] hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-[#1A365D]">{label}</Link>
                    ))}
                </nav>
            </div>

            {scopeNote && <InfoStrip className="mb-4">{scopeNote}</InfoStrip>}

            <FailureBanner
                failure={connectionLost ? { kind: 'network', message: "We couldn't confirm whether the reminders were sent." } : failure}
                onRetry={connectionLost ? () => { clear(); setAttempted(false); refresh(['rows']); } : (failure?.kind === 'session' ? send : undefined)}
                retryLabel={connectionLost ? 'Check now' : undefined}
                onDismiss={clear}
            />
            {sendProblem && <InfoStrip tone="error" role="alert" className="mb-4">{sendProblem}</InfoStrip>}

            {results && (
                <section role="status" className="mb-4 rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-gray-900">
                    <h2 ref={resultsHeading} tabIndex={-1} className="text-sm font-semibold focus:outline-none">
                        {results.list ? `Reminders: ${results.queued} queued, ${results.skipped} skipped` : (results.message ?? 'Reminders sent')}
                    </h2>
                    {results.list && (
                        <ul className="mt-2 space-y-1">
                            {results.list.map((r) => (
                                <li key={r.return_id}>
                                    {r.department}:{' '}
                                    {r.outcome === 'queued' && `queued, reaches ${tierWords(r.tier).toLowerCase()}.`}
                                    {r.outcome === 'already_reminded_today' && 'skipped, it was already reminded today.'}
                                    {r.outcome === 'submitted' && 'skipped, its return has been submitted.'}
                                    {r.outcome === 'out_of_scope' && 'skipped, it is outside your departments.'}
                                </li>
                            ))}
                        </ul>
                    )}
                    <p className="mt-2 text-gray-800">Queued means the email will go out shortly. Open a department&apos;s history to see each person&apos;s outcome, then use Refresh.</p>
                    <button type="button" onClick={() => setResults(null)} className="btn-secondary mt-2 text-sm">Dismiss</button>
                </section>
            )}

            {summary.denominator > 0 && (
                <dl className="mb-4 grid grid-cols-2 gap-x-6 gap-y-2 rounded-lg bg-white p-4 text-sm sm:grid-cols-5">
                    {[
                        ['Departments', summary.denominator],
                        ['Submitted', summary.submitted],
                        ['Not submitted', summary.not_submitted],
                        ['Overdue', summary.overdue],
                        ['No risk champion', summary.no_champion],
                    ].map(([label, value]) => (
                        <div key={label}><dt className="text-xs font-medium text-gray-700">{label}</dt><dd className="text-lg font-bold text-[var(--color-text-primary)]">{value}</dd></div>
                    ))}
                </dl>
            )}

            {canRemind ? (
                <div className={`z-10 mb-4 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-lg border border-gray-200 bg-white p-3 shadow-sm ${isDesktop ? 'sticky top-0' : 'sticky bottom-0 pb-[max(0.75rem,env(safe-area-inset-bottom))]'}`}>
                    <label className="flex min-h-11 items-center gap-2 text-sm text-gray-800">
                        <input ref={selectAll} type="checkbox" checked={allSelected} disabled={eligible.length === 0} onChange={toggleAll} className="h-5 w-5 rounded border-gray-500 text-[#1A365D] focus:ring-2 focus:ring-[#1A365D]" />
                        Select all that can be reminded
                    </label>
                    <p aria-live="polite" className="text-sm font-medium text-gray-900">{count} selected</p>
                    <button
                        ref={sendButton}
                        type="button"
                        aria-disabled={count === 0}
                        onClick={() => { if (count > 0) setConfirming(true); }}
                        aria-describedby="send-help"
                        className={`btn-primary min-h-11 text-sm ${count === 0 ? 'opacity-50' : ''}`}
                    >
                        Send reminder
                    </button>
                    <span id="send-help" className="text-xs text-gray-700">
                        {count === 0 ? 'Select at least one department.' : count >= CAP ? `${CAP} is the most you can remind at once.` : ''}
                    </span>
                    <button type="button" onClick={() => refresh()} aria-busy={refreshing} className="btn-secondary min-h-11 text-sm lg:ml-auto">
                        {refreshing ? 'Refreshing…' : 'Refresh delivery status'}
                    </button>
                    <span role="status" className="text-xs text-gray-700">Updated {updatedAt} Lagos time</span>
                </div>
            ) : (
                <p className="mb-4 text-sm text-gray-700">You can see returns but not send reminders.</p>
            )}

            {empty && summary.denominator === 0 && (
                <div className="rounded-xl border border-gray-200 bg-white">
                    <EmptyState title={`There are no returns for ${period?.label ?? 'this month'}`} description="Either no department had KRIs due, or none are in your scope." />
                </div>
            )}
            {empty && summary.denominator > 0 && filter === 'not_submitted' && (
                <div className="rounded-xl border border-gray-200 bg-white">
                    <EmptyState
                        title={`Every department has submitted for ${period?.label}`}
                        description="Nothing is waiting on a reminder."
                        actionLabel="Show all departments"
                        actionHref={route('risk.kri.returns.index', { period: period?.id, filter: 'all' })}
                    />
                </div>
            )}
            {empty && summary.denominator > 0 && filter === 'submitted' && (
                <div className="rounded-xl border border-gray-200 bg-white"><EmptyState title={`No department has submitted yet for ${period?.label}`} /></div>
            )}

            {!empty && isDesktop && (
                <div className="overflow-x-auto rounded-xl border border-gray-200 bg-white">
                    <table className="data-table w-full">
                        <caption className="sr-only">Departments and the state of their KRI returns for {period?.label}</caption>
                        <thead>
                            <tr>
                                {canRemind && <th scope="col"><span className="sr-only">Select</span></th>}
                                <th scope="col">Department</th>
                                <th scope="col">Status</th>
                                <th scope="col">KRIs</th>
                                <th scope="col">Risk champions</th>
                                <th scope="col">Reminders</th>
                                <th scope="col">KRI issues</th>
                                <th scope="col"><span className="sr-only">History</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <Fragment key={row.return_id}>
                                    <tr>
                                        {canRemind && (
                                            <td>
                                                <label htmlFor={`pick-${row.return_id}`} className="sr-only">Select {row.department.name}</label>
                                                {checkbox(row)}
                                            </td>
                                        )}
                                        <th scope="row" className="text-left font-medium">
                                            <Link href={row.view_url} className="text-[#1A365D] underline">{row.department.name}</Link>
                                            {row.department.code && <span className="block font-mono text-xs font-normal text-gray-600">{row.department.code}</span>}
                                        </th>
                                        <td><StatusCell row={row} /></td>
                                        <td className="text-sm"><Kris row={row} /></td>
                                        <td className="text-sm"><Champions row={row} /></td>
                                        <td className="text-sm"><Reminders row={row} reasonId={`reason-${row.return_id}`} /></td>
                                        <td className="text-sm"><Issues row={row} /></td>
                                        <td>{historyButton(row)}</td>
                                    </tr>
                                    {expanded.has(row.return_id) && (
                                        <tr id={`history-${row.return_id}`}><td colSpan={canRemind ? 8 : 7} className="p-0">{historyRow(row)}</td></tr>
                                    )}
                                </Fragment>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {!empty && !isDesktop && (
                <ul aria-label={`Departments and the state of their KRI returns for ${period?.label}`} className="space-y-3">
                    {rows.map((row) => (
                        <li key={row.return_id} className="rounded-xl border border-gray-200 bg-white p-4">
                            <div className="flex items-start gap-3">
                                {canRemind && (
                                    <div className="flex h-11 w-11 items-center justify-center">
                                        <label htmlFor={`pick-${row.return_id}`} className="sr-only">Select {row.department.name}</label>
                                        {checkbox(row)}
                                    </div>
                                )}
                                <h2 className="pt-2 text-base font-semibold"><Link href={row.view_url} className="text-[#1A365D] underline">{row.department.name}</Link></h2>
                            </div>
                            <dl className="mt-2 space-y-2 text-sm text-gray-800">
                                <div><dt className="text-xs font-medium text-gray-700">Status</dt><dd><StatusCell row={row} /></dd></div>
                                <div><dt className="text-xs font-medium text-gray-700">KRIs</dt><dd><Kris row={row} /></dd></div>
                                <div><dt className="text-xs font-medium text-gray-700">Risk champions</dt><dd><Champions row={row} /></dd></div>
                                <div><dt className="text-xs font-medium text-gray-700">Reminders</dt><dd><Reminders row={row} reasonId={`reason-${row.return_id}`} /></dd></div>
                                <div><dt className="text-xs font-medium text-gray-700">KRI issues</dt><dd><Issues row={row} /></dd></div>
                            </dl>
                            <div className="mt-3 w-full [&>button]:w-full">{historyButton(row)}</div>
                            {expanded.has(row.return_id) && <div id={`history-${row.return_id}`} className="mt-3">{historyRow(row)}</div>}
                        </li>
                    ))}
                </ul>
            )}

            <Modal show={confirming} maxWidth="2xl" onClose={() => { if (!sending) setConfirming(false); }} closeable={!sending}>
                <div className="p-6">
                    <h2 className="text-lg font-semibold text-gray-900">Send a reminder to {count} {plural(count, 'department')}?</h2>

                    <div className="mt-4 overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <caption className="sr-only">Departments that will be reminded and who each reminder reaches</caption>
                            <thead>
                                <tr className="text-gray-700">
                                    <th scope="col" className="py-1 pr-3 font-medium">Department</th>
                                    <th scope="col" className="py-1 pr-3 font-medium">Reminder</th>
                                    <th scope="col" className="py-1 font-medium">Will reach</th>
                                </tr>
                            </thead>
                            <tbody>
                                {selectedRows.map((row) => {
                                    const next = row.reminder_count + 1;
                                    const reached = ROLE_ORDER.slice(0, row.next_tier);
                                    const missing = reached.filter((role) => (row.contact_counts?.[role] ?? 0) === 0);

                                    return (
                                        <tr key={row.return_id} className="border-t border-gray-100 align-top text-gray-900">
                                            <th scope="row" className="py-2 pr-3 font-medium">{row.department.name}</th>
                                            <td className="py-2 pr-3">Reminder {next}</td>
                                            <td className="py-2">
                                                {tierWords(row.next_tier)}
                                                {row.next_tier === 4 && next > 4 && ' (the highest level; every further reminder goes to the same four levels)'}
                                                {missing.map((role) => (
                                                    <span key={role} className="mt-1 block text-amber-800">
                                                        {role === 'risk_champion'
                                                            ? 'No risk champion is set, so risk champions will not receive this.'
                                                            : `No ${ROLE_PLURAL[role]} who can receive email is set at this level.`}
                                                    </span>
                                                ))}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>

                    <p className="mt-4 text-sm text-gray-800">Each department can be reminded once a day. Emails are queued and go out shortly. Who receives each one is worked out at the moment it is sent, so contact changes you make now still apply.</p>
                    {anyNoChampion && (
                        <p className="mt-2 text-sm text-gray-800">A reminder to a department with no risk champion still counts as that department&apos;s next reminder and uses its one reminder for today.</p>
                    )}
                    {slow && <p role="status" className="mt-2 text-sm text-gray-800">Still working. Your connection is slow. Don&apos;t close this page.</p>}

                    <div className="mt-6 flex justify-end gap-3">
                        <button type="button" data-autofocus onClick={() => setConfirming(false)} disabled={sending} className="btn-secondary min-h-11 text-sm">Cancel</button>
                        <button type="button" onClick={send} disabled={sending} aria-busy={sending} className="btn-primary min-h-11 text-sm">
                            {sending ? 'Sending…' : `Send ${count} ${plural(count, 'reminder')}`}
                        </button>
                    </div>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
