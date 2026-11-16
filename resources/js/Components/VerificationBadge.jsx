const STYLES = {
    verified: 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-400 dark:border-emerald-800',
    unverified: 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/40 dark:text-amber-400 dark:border-amber-800',
    draft: 'bg-gray-100 text-gray-700 border-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-700',
};

const TITLES = {
    verified: 'Confirmed against the statutory requirement — valid for regulatory filings.',
    unverified: 'Pending confirmation against regulatory reference.',
    draft: 'Draft record pending review.',
};

export default function VerificationBadge({ status, className = '' }) {
    if (!status) return null;

    const style = STYLES[status] ?? STYLES.draft;

    return (
        <span
            className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium border ${style} ${className}`}
            title={TITLES[status] ?? ''}
        >
            {status === 'verified' ? 'Verified' : status === 'draft' ? 'Draft' : 'Unverified'}
        </span>
    );
}
