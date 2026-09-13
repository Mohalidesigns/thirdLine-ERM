import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import DataGrid from '@thirdline/ui/Components/DataGrid/DataGrid';
import PageHeader from '@thirdline/ui/Components/PageHeader';

/**
 * TPRM AI usage report — `docs/tprm/screens/ai-usage.md`,
 * phase-11a-ai-contract.md §7.3.
 *
 * NO COST FIGURE ANYWHERE. Every "Cost" cell reads the fixed phrase because
 * every profile shipped in this deployment is self-hosted and unpriced.
 *
 * EVERY FIGURE ON THIS PAGE IS SERVER-COMPUTED (ruled 2026-09-11, frontend
 * deviation 2). An earlier version of this file computed `tone` and `share`
 * in the browser from raw counts; both are now read straight off
 * `AiUsageController@index`'s response (`month.token_cap_tone`,
 * `month.call_cap_tone`, each `byOutcome` row's own `share`) because tone is
 * a THRESHOLD JUDGEMENT (90% consumed), not arithmetic, and the settings
 * screen already computes its own server-side — two homes for that number
 * would diverge the day either one is tuned. `share` lives in
 * `UsageReporter::byOutcome()` so an export built on the same method can
 * never print a different percentage than this screen. Neither is ever
 * recomputed here, only formatted.
 *
 * THE RETENTION-WINDOW SUBSTITUTION IS DECLARED, NOT SILENT (ruled
 * 2026-09-11, frontend deviation 3). `AiUsageController` is right to
 * normalise an out-of-range `?month=` to the current month —
 * `TprmAiUsageEventsGrid` reads the same query parameter independently, and
 * an un-normalised URL would have the grid and the panels above it
 * disagreeing about which month is showing. What must not happen is
 * SILENCE: `requested_month` carries the out-of-range string the URL asked
 * for (null when nothing was asked for, or when what was asked for was
 * already valid), and `RequestedMonthBanner` below names it, says why it is
 * gone, and which month is showing instead — while the current month's real
 * report still renders beneath it. A blank "no data" page would hide a
 * perfectly good month behind a typo'd URL.
 */
export default function AiUsage({
    months = [],
    month = {},
    requested_month: requestedMonth = null,
    byService = [],
    byOutcome = [],
    grid,
    retentionMonths,
    tenant_ai_currently_enabled: tenantAiCurrentlyEnabled = false,
}) {
    const [navigating, setNavigating] = useState(false);
    const [pendingMonth, setPendingMonth] = useState(null);

    const changeMonth = (value) => {
        setPendingMonth(value);
        setNavigating(true);
        router.get(route('tprm.settings.ai.usage', { month: value }), {}, {
            preserveScroll: true,
            onFinish: () => setNavigating(false),
        });
    };

    const noActivity = month.call_count === 0;

    return (
        <AppLayout title="AI usage report">
            <Head title="AI usage report" />

            <PageHeader
                title="AI usage report"
                subtitle={`Calls to the on-premises model, by service and by outcome, for ${month.usage_month}. There is no cost figure on this report — the model runs on this institution's own hardware.`}
                actions={
                    <Link href={route('tprm.settings.ai')} className="btn-secondary">
                        AI settings
                    </Link>
                }
            />

            {requestedMonth && (
                <div className="mb-4 rounded border border-gray-200 bg-gray-50 p-4 text-sm text-gray-800">
                    Usage for {requestedMonth} is not available — records older than {retentionMonths} months are
                    not retained (<code>llm:prune-usage</code>). Showing {month.usage_month} instead.
                </div>
            )}

            <div className="mb-6 flex items-center gap-3">
                <label className="text-sm">
                    <span className="mb-1 block font-medium text-gray-700">Month</span>
                    <select
                        className="rounded border-gray-300 text-sm"
                        value={month.usage_month}
                        disabled={navigating}
                        onChange={(event) => changeMonth(event.target.value)}
                    >
                        {months.map((value) => (
                            <option key={value} value={value}>{value}</option>
                        ))}
                    </select>
                </label>
                {navigating && (
                    <span aria-live="polite" className="text-xs text-gray-500">
                        Loading {pendingMonth}…
                    </span>
                )}
            </div>

            <div className={navigating ? 'opacity-50 transition-opacity' : ''}>
                <CapTiles month={month} />

                {noActivity ? (
                    <EmptyMonthNotice
                        month={month.usage_month}
                        tenantAiCurrentlyEnabled={tenantAiCurrentlyEnabled}
                    />
                ) : (
                    <>
                        <ByServicePanel rows={byService} />
                        <ByOutcomePanel rows={byOutcome} />
                    </>
                )}

                <section className="card overflow-hidden">
                    <h2 className="p-4 pb-0 text-sm font-semibold text-gray-800">Recent calls</h2>
                    {grid && <div className="p-4"><DataGrid grid={grid} /></div>}
                </section>

                <p className="mt-4 text-xs text-gray-400">
                    Usage records older than {retentionMonths} months are not retained (<code>llm:prune-usage</code>).
                </p>
            </div>
        </AppLayout>
    );
}

function toneText(tone) {
    if (tone === 'critical') return 'at limit';
    if (tone === 'warn') return 'approaching limit';
    return null;
}

function CapTiles({ month }) {
    const tiles = [
        {
            label: 'Tokens used this month',
            value: month.total_tokens === null ? 'Not reported' : month.total_tokens.toLocaleString(),
            tone: month.token_cap_tone,
        },
        {
            label: 'Calls this month',
            value: month.call_count.toLocaleString(),
            tone: month.call_cap_tone,
        },
        {
            label: 'Token limit',
            value: month.token_cap === null ? 'No limit set' : month.token_cap.toLocaleString(),
            tone: null,
        },
        {
            label: 'Call limit',
            value: month.call_cap === null ? 'No limit set' : month.call_cap.toLocaleString(),
            tone: null,
        },
    ];

    return (
        <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
            {tiles.map((tile) => (
                <div key={tile.label} className="card p-4">
                    <p className="text-xs font-medium uppercase tracking-wide text-gray-500">{tile.label}</p>
                    <p className={`mt-1 text-2xl font-semibold ${
                        tile.tone === 'critical' ? 'text-red-700' : tile.tone === 'warn' ? 'text-amber-700' : 'text-gray-900'
                    }`}>
                        {tile.value}
                    </p>
                    {toneText(tile.tone) && (
                        <p className={`mt-0.5 text-xs font-medium ${tile.tone === 'critical' ? 'text-red-700' : 'text-amber-700'}`}>
                            {toneText(tile.tone)}
                        </p>
                    )}
                </div>
            ))}
        </div>
    );
}

function EmptyMonthNotice({ month, tenantAiCurrentlyEnabled }) {
    return (
        <div className="mb-6 rounded border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700">
            {tenantAiCurrentlyEnabled ? (
                <>
                    No AI calls were recorded for {month}, though AI is currently on for this tenant. If activity
                    was expected, check the breaker status and endpoint on the{' '}
                    <Link href={route('tprm.settings.ai')} className="underline">AI settings</Link> screen.
                </>
            ) : (
                <>
                    No AI calls were recorded for {month}. AI is currently off for this tenant — see{' '}
                    <Link href={route('tprm.settings.ai')} className="underline">AI settings</Link> to turn it on.
                </>
            )}
        </div>
    );
}

function InlineBar({ value, max }) {
    const width = max > 0 ? Math.max(2, Math.round((value / max) * 100)) : 0;

    return (
        <svg aria-hidden="true" width="80" height="10" className="inline-block align-middle">
            <rect x="0" y="1" width="80" height="8" className="fill-gray-100" />
            <rect x="0" y="1" width={(width / 100) * 80} height="8" className="fill-[color:var(--color-primary)]" />
        </svg>
    );
}

function ByServicePanel({ rows }) {
    const maxCalls = rows.reduce((max, row) => Math.max(max, row.calls), 0);

    return (
        <section className="card mb-6 overflow-hidden">
            <table className="w-full text-sm">
                <caption className="p-4 text-left text-sm font-semibold text-gray-800">By service</caption>
                <thead>
                    <tr className="border-t border-gray-200 bg-gray-50">
                        <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Service</th>
                        <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Calls</th>
                        <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Succeeded</th>
                        <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Refused/blocked</th>
                        <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Tokens</th>
                        <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Avg. duration</th>
                        <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Cost</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr key={row.service} className="border-t border-gray-100">
                            <th scope="row" className="px-4 py-2 text-left font-normal text-gray-700">{row.service}</th>
                            <td className="px-4 py-2">
                                <InlineBar value={row.calls} max={maxCalls} /> <span className="ml-1">{row.calls}</span>
                            </td>
                            <td className="px-4 py-2">{row.succeeded}</td>
                            <td className="px-4 py-2">{row.refused}</td>
                            <td className="px-4 py-2">
                                {row.tokens === null ? (
                                    'not reported by this backend'
                                ) : row.calls_missing_tokens > 0 ? (
                                    `${row.tokens.toLocaleString()} tokens · ${row.calls_missing_tokens} of ${row.calls} calls did not report a count`
                                ) : (
                                    row.tokens.toLocaleString()
                                )}
                            </td>
                            <td className="px-4 py-2">{(row.avg_duration_ms / 1000).toFixed(1)}s</td>
                            <td className="px-4 py-2 text-gray-500">Not priced — self-hosted endpoint</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </section>
    );
}

const OUTCOME_TONE = {
    Succeeded: 'emerald',
    Refused: 'grey',
    'Circuit open': 'amber',
    'Monthly limit reached': 'amber',
    'Endpoint unreachable': 'red',
    'Timed out': 'red',
    'Server error': 'red',
    'Response unreadable': 'amber',
};

function OutcomeBadge({ label }) {
    const tone = OUTCOME_TONE[label] ?? 'grey';
    const classes = {
        emerald: 'bg-emerald-100 text-emerald-800',
        amber: 'bg-amber-100 text-amber-800',
        red: 'bg-red-100 text-red-800',
        grey: 'bg-gray-100 text-gray-700',
    };

    return <span className={`inline-flex items-center rounded px-2 py-0.5 text-xs font-medium ${classes[tone]}`}>{label}</span>;
}

/**
 * `row.share` is a float in [0, 1] rounded to 4dp by
 * `UsageReporter::byOutcome()`, or `null` when the month's total call count
 * is 0 — never recomputed here, only formatted for display. `null` renders
 * as "—", never `0%`: a share of nothing is undefined, not zero.
 */
function ByOutcomePanel({ rows }) {
    const maxCount = rows.reduce((max, row) => Math.max(max, row.count), 0);

    return (
        <section className="card mb-6 overflow-hidden">
            <table className="w-full text-sm">
                <caption className="p-4 text-left text-sm font-semibold text-gray-800">By outcome</caption>
                <thead>
                    <tr className="border-t border-gray-200 bg-gray-50">
                        <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Outcome</th>
                        <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Count</th>
                        <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Share of month</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr key={row.outcome} className="border-t border-gray-100">
                            <th scope="row" className="px-4 py-2 text-left font-normal">
                                <OutcomeBadge label={row.label} />
                            </th>
                            <td className="px-4 py-2">
                                <InlineBar value={row.count} max={maxCount} /> <span className="ml-1">{row.count}</span>
                            </td>
                            <td className="px-4 py-2">
                                {row.share === null ? '—' : `${(row.share * 100).toFixed(1)}%`}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </section>
    );
}
