import { useEffect, useRef, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import Glyph from './Glyph';
import InfoStrip from './InfoStrip';
import useVisitFailures from '@/hooks/useVisitFailures';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * The "Monthly KRI returns" card on Kri/Dashboard (docs/ui/kri-cycle/03).
 *
 * The prop is requested on its own (`only: ['returnsCard']`), so the existing
 * dashboard never waits for the returns query. If the page arrived with the
 * prop already in it (the controller sends a closure, which a full visit
 * evaluates), the card renders at once; if not, it renders its final-height
 * skeleton and asks for it on mount.
 *
 * Nothing here is a number the server did not compute: a headline over an empty
 * set is a message, never "0 of 0".
 */
function Skeleton() {
    return (
        <div className="grid grid-cols-1 gap-6 md:grid-cols-12 md:items-center" aria-hidden="true">
            <div className="md:col-span-5"><div className="mb-3 h-8 w-3/4 animate-pulse rounded bg-gray-100" /><div className="h-2 w-full animate-pulse rounded bg-gray-100" /></div>
            <div className="md:col-span-4"><div className="h-14 w-full animate-pulse rounded bg-gray-100" /></div>
            <div className="md:col-span-3"><div className="h-11 w-full animate-pulse rounded bg-gray-100" /></div>
        </div>
    );
}

export default function ReturnsCard({ returnsCard }) {
    const permissions = usePage().props.auth?.permissions ?? [];
    const holidaysUrl = permissions.includes('holiday.manage') ? tryRoute('risk.holidays.index') : null;
    const { failure, clear } = useVisitFailures();
    const [loading, setLoading] = useState(false);
    const [slow, setSlow] = useState(false);
    const [announcement, setAnnouncement] = useState('');
    const timer = useRef(null);
    const debounce = useRef(null);
    const asked = useRef(false);
    const lastPeriod = useRef(null);

    const card = returnsCard ?? null;

    const request = (period) => {
        clear();
        router.get(route('risk.kri.dashboard'), period ? { period } : {}, {
            only: ['returnsCard'],
            preserveScroll: true,
            preserveState: true,
            replace: true,
            onStart: () => {
                setLoading(true);
                setSlow(false);
                timer.current = setTimeout(() => setSlow(true), 15000);
            },
            onFinish: () => {
                clearTimeout(timer.current);
                setLoading(false);
                setSlow(false);
            },
            onSuccess: (page) => {
                const c = page.props.returnsCard;

                if (c?.period && c.summary?.denominator > 0) {
                    setAnnouncement(`${c.period.label}: ${c.summary.submitted} of ${c.summary.denominator} departments submitted, ${c.summary.not_submitted} not submitted.`);
                }
            },
        });
    };

    useEffect(() => {
        if (!card && !asked.current) {
            asked.current = true;
            request(null);
        }

        return () => {
            clearTimeout(timer.current);
            clearTimeout(debounce.current);
        };
    }, []); // eslint-disable-line react-hooks/exhaustive-deps

    const onPeriod = (event) => {
        const value = event.target.value;

        lastPeriod.current = value;
        clearTimeout(debounce.current);
        // Native selects fire on each arrow press in some browsers: only the last value is requested.
        debounce.current = setTimeout(() => request(value), 300);
    };

    const periods = card?.periods ?? [];
    const summary = card?.summary ?? null;
    const notConfigured = card !== null && (card.cycleOpened === false || card.period === null);
    const nothingToCount = card !== null && !notConfigured && (summary?.denominator ?? 0) === 0;
    const pastMonth = card?.period && periods.length > 0 && card.period.id !== periods[0].id;
    const showBody = card !== null && !notConfigured && !nothingToCount && !loading;
    const pct = showBody && summary.denominator > 0 ? Math.round((summary.submitted / summary.denominator) * 100) : null;

    return (
        <section aria-labelledby="returns-card-title" aria-busy={loading || card === null} className="mb-6 rounded-xl border border-gray-200 bg-white p-5">
            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <h2 id="returns-card-title" className="text-sm font-semibold text-[#1A365D]">
                    Monthly KRI returns{card?.period ? `: ${card.period.label}` : ''}
                </h2>
                {(card === null || periods.length > 0) && (
                    <div className="flex items-center gap-2">
                        <label htmlFor="returns-card-month" className="text-xs font-medium text-gray-700">Month</label>
                        <select
                            id="returns-card-month"
                            disabled={card === null}
                            value={card?.period?.id ?? ''}
                            onChange={onPeriod}
                            className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-[#1A365D] focus:ring-2 focus:ring-[#1A365D]"
                        >
                            {card === null && <option value="">Loading</option>}
                            {periods.map((p) => <option key={p.id} value={p.id}>{p.label}</option>)}
                        </select>
                    </div>
                )}
            </div>

            <div role="status" className="sr-only">{announcement}</div>

            {failure && (
                <InfoStrip tone="error" role="alert" className="mb-3">
                    <p>Couldn&apos;t load the submission figures.</p>
                    <button type="button" onClick={() => request(lastPeriod.current)} className="btn-secondary mt-2 text-sm">Try again</button>
                </InfoStrip>
            )}

            {card?.scopeNote && <p className="mb-3 text-xs text-gray-700">{card.scopeNote}</p>}

            {pastMonth && !loading && (
                <InfoStrip className="mb-3">You are looking at a past month. Figures show where each return stands <strong>now</strong>, not where it stood at month end.</InfoStrip>
            )}

            {(card === null || loading) && !failure && (
                <>
                    <span className="sr-only">Loading submission figures</span>
                    <Skeleton />
                    {slow && <p role="status" className="mt-3 text-sm text-gray-700">Still loading. Your connection is slow. The rest of the dashboard is ready.</p>}
                </>
            )}

            {notConfigured && (
                <p className="text-sm text-gray-700">
                    No monthly KRI cycle has opened yet. The first request goes out on the first working day of the month.
                    {holidaysUrl && <> <Link href={holidaysUrl} className="font-medium text-[#1A365D] underline">Check the holiday list</Link>.</>}
                </p>
            )}

            {nothingToCount && !loading && (
                <p className="text-sm text-gray-700">
                    There are no returns to show for {card.period.label}. Either no department had KRIs due, or none are in your scope.
                </p>
            )}

            {showBody && (
                <div className="grid grid-cols-1 items-center gap-6 md:grid-cols-12">
                    <div className="md:col-span-5">
                        <p className="text-2xl text-[var(--color-text-primary)]">
                            Submitted <strong>{summary.submitted}</strong> of <strong>{summary.denominator}</strong> departments
                        </p>
                        <div className="mt-3 h-2 w-full rounded bg-gray-200" aria-hidden="true">
                            <div className="h-2 rounded bg-[#2D7D46]" style={{ width: `${pct}%` }} />
                        </div>
                        {summary.not_submitted === 0 && (
                            <p className="mt-2 flex items-center gap-1 text-sm font-medium text-[#2D7D46]"><Glyph name="check_circle" />Every department has submitted.</p>
                        )}
                    </div>

                    <dl className="grid grid-cols-3 gap-4 md:col-span-4 md:grid-cols-1 md:gap-2">
                        <div>
                            <dt className="text-xs font-medium text-gray-700">Not submitted</dt>
                            <dd className="text-xl font-bold text-[var(--color-text-primary)]">{summary.not_submitted}</dd>
                        </div>
                        <div>
                            <dt className="text-xs font-medium text-gray-700">Overdue</dt>
                            <dd className={`flex items-center gap-1 text-xl font-bold ${summary.overdue > 0 ? 'text-red-700' : 'text-[var(--color-text-primary)]'}`}>
                                {summary.overdue > 0 && <Glyph name="warning" />}{summary.overdue}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs font-medium text-gray-700">No risk champion</dt>
                            <dd className={`flex items-center gap-1 text-xl font-bold ${summary.no_champion > 0 ? 'text-amber-800' : 'text-[var(--color-text-primary)]'}`}>
                                {summary.no_champion > 0 && <Glyph name="warning" />}{summary.no_champion}
                            </dd>
                            {summary.no_champion > 0 && <dd className="text-xs text-gray-700">Nobody to email for these departments.</dd>}
                        </div>
                    </dl>

                    <div className="md:col-span-3 md:text-right">
                        <Link
                            href={card.drillDownUrl}
                            className={`${summary.not_submitted === 0 ? 'btn-secondary' : 'btn-primary'} inline-flex min-h-11 w-full items-center justify-center text-sm md:w-auto`}
                        >
                            {summary.not_submitted === 0 ? 'View all departments' : card.canRemind ? 'Review and send reminders' : 'See departments not submitted'}
                        </Link>
                    </div>
                </div>
            )}
        </section>
    );
}
