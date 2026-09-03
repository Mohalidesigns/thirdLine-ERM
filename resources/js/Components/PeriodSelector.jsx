import { useState } from 'react';

const PRESETS = [
    { key: 'this_month', label: 'This Month' },
    { key: 'last_month', label: 'Last Month' },
    { key: 'this_quarter', label: 'This Quarter' },
    { key: 'last_quarter', label: 'Last Quarter' },
    { key: 'ytd', label: 'YTD' },
    { key: 'last_12_months', label: 'Last 12 Months' },
];

/**
 * Global dashboard period selector: preset ranges, a month-to-month picker
 * ("January to June"), and an exact date range. Emits URL-ready params via
 * onChange — the caller pushes them through Inertia so the selection
 * persists in the query string.
 */
export default function PeriodSelector({ period = {}, onChange }) {
    const [mode, setMode] = useState(period.from_month ? 'months' : (period.preset ? 'preset' : (period.from ? 'dates' : 'preset')));
    const [fromMonth, setFromMonth] = useState(period.from_month || '');
    const [toMonth, setToMonth] = useState(period.to_month || '');
    const [fromDate, setFromDate] = useState(period.from || '');
    const [toDate, setToDate] = useState(period.to || '');

    const applyMonths = () => {
        if (fromMonth && toMonth) onChange({ from_month: fromMonth, to_month: toMonth });
    };

    const applyDates = () => {
        if (fromDate && toDate) onChange({ from: fromDate, to: toDate });
    };

    return (
        <div className="card mb-6">
            <div className="card-body">
                <div className="flex flex-wrap items-center gap-2">
                    {PRESETS.map(preset => (
                        <button
                            key={preset.key}
                            type="button"
                            onClick={() => { setMode('preset'); onChange({ preset: preset.key }); }}
                            className={`px-3 py-1.5 rounded-lg text-xs font-medium transition-colors ${
                                period.preset === preset.key
                                    ? 'bg-[var(--color-primary)] text-white'
                                    : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                            }`}
                        >
                            {preset.label}
                        </button>
                    ))}

                    <span className="mx-1 h-5 w-px bg-gray-200 hidden sm:block"></span>

                    <button
                        type="button"
                        onClick={() => setMode(mode === 'months' ? 'preset' : 'months')}
                        className={`px-3 py-1.5 rounded-lg text-xs font-medium transition-colors ${
                            mode === 'months' || period.from_month
                                ? 'bg-[var(--color-primary)] text-white'
                                : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                        }`}
                    >
                        Month range
                    </button>
                    <button
                        type="button"
                        onClick={() => setMode(mode === 'dates' ? 'preset' : 'dates')}
                        className={`px-3 py-1.5 rounded-lg text-xs font-medium transition-colors ${
                            mode === 'dates' || (period.from && !period.from_month && !period.preset)
                                ? 'bg-[var(--color-primary)] text-white'
                                : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                        }`}
                    >
                        Exact dates
                    </button>
                </div>

                {mode === 'months' && (
                    <div className="flex flex-wrap items-end gap-3 mt-3 pt-3 border-t border-gray-100">
                        <div>
                            <label className="filter-label">From month</label>
                            <input type="month" value={fromMonth} onChange={e => setFromMonth(e.target.value)} className="filter-input" />
                        </div>
                        <div>
                            <label className="filter-label">To month</label>
                            <input type="month" value={toMonth} onChange={e => setToMonth(e.target.value)} className="filter-input" />
                        </div>
                        <button type="button" onClick={applyMonths} disabled={!fromMonth || !toMonth} className="btn-primary text-sm disabled:opacity-50">
                            Apply
                        </button>
                    </div>
                )}

                {mode === 'dates' && (
                    <div className="flex flex-wrap items-end gap-3 mt-3 pt-3 border-t border-gray-100">
                        <div>
                            <label className="filter-label">From</label>
                            <input type="date" value={fromDate} onChange={e => setFromDate(e.target.value)} className="filter-input" />
                        </div>
                        <div>
                            <label className="filter-label">To</label>
                            <input type="date" value={toDate} onChange={e => setToDate(e.target.value)} className="filter-input" />
                        </div>
                        <button type="button" onClick={applyDates} disabled={!fromDate || !toDate} className="btn-primary text-sm disabled:opacity-50">
                            Apply
                        </button>
                    </div>
                )}

                <p className="text-xs text-gray-400 mt-3">
                    Active period: <span className="font-medium text-gray-600">{period.label}</span> — all widgets below reflect this range.
                </p>
            </div>
        </div>
    );
}
