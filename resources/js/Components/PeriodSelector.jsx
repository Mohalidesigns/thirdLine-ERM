import { usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

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

    useEffect(() => {
        const onClick = (e) => box.current && !box.current.contains(e.target) && setOpen(false);
        const onKey = (e) => e.key === 'Escape' && setOpen(false);
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

    return (
        <div ref={box} className="relative flex items-center gap-1 pl-3 pr-1 py-1 rounded-lg border border-gray-200 bg-gray-50" data-testid="period-selector">
            <a href={link({ direction: 'previous' })} className="p-1 rounded hover:bg-white text-gray-500 hover:text-[var(--color-primary)]" title={`Previous ${period.type}`} aria-label="Previous period">
                <span className="material-symbols-outlined text-[18px] leading-none">chevron_left</span>
            </a>

            <button type="button" onClick={() => setOpen(!open)} className="px-2 py-0.5 text-xs font-semibold text-[var(--color-primary)] hover:bg-white rounded min-w-[104px] inline-flex items-center justify-center gap-1" aria-haspopup="true" aria-expanded={open}>
                {period.name}
                {period.is_closed && <span className="material-symbols-outlined text-[13px] text-gray-400" title="Closed — values locked">lock</span>}
            </button>

            <a href={link({ direction: 'next' })} className="p-1 rounded hover:bg-white text-gray-500 hover:text-[var(--color-primary)]" title={`Next ${period.type}`} aria-label="Next period">
                <span className="material-symbols-outlined text-[18px] leading-none">chevron_right</span>
            </a>

            {open && (
                <div className="absolute right-0 top-full mt-2 w-64 bg-white rounded-xl shadow-xl border border-gray-200 z-50 p-3">
                    <p className="text-[10px] uppercase tracking-wide text-gray-400 font-semibold mb-2">Granularity</p>
                    <div className="grid grid-cols-4 gap-1 mb-3">
                        {TYPES.map(([type, label]) => (
                            <a
                                key={type}
                                href={link({ type })}
                                className={`text-center text-[11px] py-1 rounded border ${
                                    period.type === type ? 'bg-[var(--color-primary)] text-white border-[var(--color-primary)]' : 'border-gray-200 text-gray-600 hover:bg-gray-50'
                                }`}
                            >
                                {label}
                            </a>
                        ))}
                    </div>
                    <a href={link({ direction: 'current' })} className="block text-center text-[11px] py-1.5 rounded border border-gray-200 text-gray-600 hover:bg-gray-50 mb-2">
                        Jump to today
                    </a>
                    {manageUrl && (
                        <a href={manageUrl} className="block text-center text-[11px] py-1.5 rounded bg-gray-50 text-[var(--color-primary)] font-medium hover:bg-gray-100">
                            Manage calendar &amp; close periods
                        </a>
                    )}
                    <p className="text-[10px] text-gray-400 mt-2 leading-snug">
                        {formatDate(period.start_date)} – {formatDate(period.end_date)}
                        {period.is_closed ? ' · closed' : ''}
                    </p>
                </div>
            )}
        </div>
    );
}
