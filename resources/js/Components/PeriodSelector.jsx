import { usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import Icon from '@thirdline/ui/Components/Icon';

const TYPES = [
    ['month', 'Month'],
    ['quarter', 'Qtr'],
    ['half', 'Half'],
    ['year', 'Year'],
];

function formatDate(value) {
    if (!value) return '';
    try {
        return new Date(value).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
    } catch {
        return value;
    }
}

/**
 * The reporting-period selector (WP-04). Every dashboard, register and score
 * on this platform is an "as at" view; this is the control that says as at
 * WHEN. It lives in the top bar because the period has to survive
 * navigation — following a link must not silently jump the user back to
 * today.
 *
 * Each choice is a plain link to GET risk/periods/select, which stores the
 * selection in the session and redirects back here. Plain, not an Inertia
 * visit: the page it returns to may still be Blade.
 */
export default function PeriodSelector() {
    const { period, auth } = usePage().props;
    const [open, setOpen] = useState(false);
    const box = useRef(null);
    const labelBtn = useRef(null);

    useEffect(() => {
        const onClick = (e) => box.current && !box.current.contains(e.target) && setOpen(false);
        const onKey = (e) => {
            if (e.key !== 'Escape') return;
            setOpen((was) => {
                if (was) labelBtn.current?.focus();
                return false;
            });
        };
        document.addEventListener('mousedown', onClick);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onClick);
            document.removeEventListener('keydown', onKey);
        };
    }, []);

    if (!period) return null;

    const current = typeof window !== 'undefined' ? window.location.pathname + window.location.search : '/';
    const link = (params) => {
        try {
            return route('risk.periods.select', { ...params, redirect: current });
        } catch {
            return '#';
        }
    };
    const canManage = (auth?.permissions || []).includes('period.view');
    let manageUrl = null;
    try {
        manageUrl = canManage ? route('risk.periods.index') : null;
    } catch {
        manageUrl = null;
    }

    const ring = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-primary)] focus-visible:ring-offset-2';

    return (
        <div ref={box} className="relative h-9 flex items-center gap-1 pl-2 pr-1 rounded-lg border border-gray-200 bg-gray-50" data-testid="period-selector">
            <a href={link({ direction: 'previous' })} className={`p-1.5 rounded hover:bg-white text-gray-500 hover:text-[var(--color-primary)] ${ring}`} title={`Previous ${period.type}`} aria-label="Previous period">
                <Icon name="chevron_left" className="w-4 h-4" strokeWidth={2} />
            </a>

            <button
                ref={labelBtn}
                type="button"
                onClick={() => setOpen(!open)}
                className={`px-2 py-1 text-sm font-semibold text-[var(--color-primary)] hover:bg-white rounded min-w-[120px] inline-flex items-center justify-center gap-1 ${ring}`}
                aria-haspopup="true"
                aria-expanded={open}
                aria-label={`Reporting period: ${period.name}`}
            >
                {period.name}
                {period.is_closed && (
                    <>
                        <Icon name="lock" className="w-3.5 h-3.5 text-gray-500" />
                        <span className="sr-only">Closed, values locked</span>
                    </>
                )}
            </button>

            <a href={link({ direction: 'next' })} className={`p-1.5 rounded hover:bg-white text-gray-500 hover:text-[var(--color-primary)] ${ring}`} title={`Next ${period.type}`} aria-label="Next period">
                <Icon name="chevron_right" className="w-4 h-4" strokeWidth={2} />
            </a>

            {open && (
                <div className="absolute right-0 top-full mt-2 w-64 bg-white rounded-lg shadow-xl border border-gray-200 z-50 p-3">
                    <p className="text-xs uppercase tracking-wide text-gray-500 font-semibold mb-2">Granularity</p>
                    <div className="grid grid-cols-4 gap-1 mb-3">
                        {TYPES.map(([type, label]) => (
                            <a
                                key={type}
                                href={link({ type })}
                                className={`text-center text-xs py-1 rounded border ${ring} ${
                                    period.type === type ? 'bg-[var(--color-primary)] text-white border-[var(--color-primary)]' : 'border-gray-200 text-gray-600 hover:bg-gray-50'
                                }`}
                            >
                                {label}
                            </a>
                        ))}
                    </div>
                    <a href={link({ direction: 'current' })} className={`block text-center text-xs py-1.5 rounded border border-gray-200 text-gray-600 hover:bg-gray-50 mb-2 ${ring}`}>
                        Jump to today
                    </a>
                    {manageUrl && (
                        <a href={manageUrl} className={`block text-center text-xs py-1.5 rounded bg-gray-50 text-[var(--color-primary)] font-medium hover:bg-gray-100 ${ring}`}>
                            Manage calendar &amp; close periods
                        </a>
                    )}
                    <p className="text-xs text-gray-500 mt-2 leading-snug">
                        {formatDate(period.start_date)} &ndash; {formatDate(period.end_date)}
                        {period.is_closed ? ' \u00b7 closed' : ''}
                    </p>
                </div>
            )}
        </div>
    );
}
