import { Link } from '@inertiajs/react';

export default function EmptyState({ icon, title, description, actionLabel, actionHref, onAction }) {
    return (
        <div className="text-center py-12 px-6">
            <div className="inline-flex items-center justify-center w-16 h-16 rounded-full bg-gray-100 mb-4">
                {icon || (
                    <svg className="w-8 h-8 text-gray-400" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
                    </svg>
                )}
            </div>
            <h3 className="text-sm font-semibold text-gray-800 mb-1">{title}</h3>
            {description && <p className="text-sm text-gray-500 mb-4 max-w-sm mx-auto">{description}</p>}
            {actionLabel && actionHref && (
                <Link href={actionHref} className="btn-primary inline-flex items-center gap-2 text-sm">
                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    {actionLabel}
                </Link>
            )}
            {actionLabel && onAction && !actionHref && (
                <button onClick={onAction} className="btn-primary inline-flex items-center gap-2 text-sm">
                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    {actionLabel}
                </button>
            )}
        </div>
    );
}
