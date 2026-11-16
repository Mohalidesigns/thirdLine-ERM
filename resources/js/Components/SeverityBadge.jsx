const SEVERITY_STYLES = {
    Critical: 'bg-red-50 text-red-700 border-red-200 dark:bg-red-950/40 dark:text-red-400 dark:border-red-800',
    High: 'bg-orange-50 text-orange-700 border-orange-200 dark:bg-orange-950/40 dark:text-orange-400 dark:border-orange-800',
    Medium: 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/40 dark:text-amber-400 dark:border-amber-800',
    Low: 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-400 dark:border-emerald-800',
};

const SEVERITY_DOTS = {
    Critical: 'bg-red-500',
    High: 'bg-orange-500',
    Medium: 'bg-amber-500',
    Low: 'bg-emerald-500',
};

/** Severity pill with leading status dot. */
export default function SeverityBadge({ severity, className = '' }) {
    if (!severity) return <span className="text-gray-400">—</span>;

    const style = SEVERITY_STYLES[severity] ?? 'bg-gray-50 text-gray-700 border-gray-200';
    const dot = SEVERITY_DOTS[severity] ?? 'bg-gray-400';

    return (
        <span
            className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium border ${style} ${className}`}
        >
            <span className={`mr-1.5 h-1.5 w-1.5 rounded-full ${dot}`} aria-hidden="true" />
            {severity}
        </span>
    );
}
