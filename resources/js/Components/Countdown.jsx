import { useEffect, useState } from 'react';

/**
 * A live countdown to a regulatory deadline.
 *
 * The deadline itself is computed server-side — this component only
 * renders the remaining time and never calculates a window of its own.
 */
function parts(seconds) {
    const abs = Math.abs(seconds);

    return {
        days: Math.floor(abs / 86400),
        hours: Math.floor((abs % 86400) / 3600),
        minutes: Math.floor((abs % 3600) / 60),
        seconds: Math.floor(abs % 60),
    };
}

export default function Countdown({ dueAt, label = 'Time remaining', compact = false }) {
    const target = dueAt ? new Date(dueAt).getTime() : null;
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        if (!target) return undefined;

        const timer = setInterval(() => setNow(Date.now()), 30_000);

        return () => clearInterval(timer);
    }, [target]);

    if (!target) {
        return <span className="text-sm text-gray-400">No deadline on file</span>;
    }

    const remaining = Math.floor((target - now) / 1000);
    const { days, hours, minutes } = parts(remaining);
    const overdue = remaining < 0;
    const urgent = !overdue && remaining < 6 * 3600;

    const tone = overdue
        ? 'text-red-600 font-bold'
        : urgent
          ? 'text-amber-600 font-bold'
          : 'text-emerald-600 font-semibold';

    const value = `${days > 0 ? `${days}d ` : ''}${hours}h ${minutes}m`;

    if (compact) {
        return (
            <span className={`font-mono text-xs tabular-nums ${tone}`}>
                {overdue ? `${value} over` : value}
            </span>
        );
    }

    return (
        <div>
            <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">
                {overdue ? 'Overdue by' : label}
            </p>
            <p className={`mt-1 font-mono text-2xl tabular-nums ${tone}`}>{value}</p>
        </div>
    );
}
