import { Link } from '@inertiajs/react';

export default function PageHeader({ title, subtitle, actions, breadcrumbs }) {
    return (
        <div className="page-header">
            <div>
                {breadcrumbs && breadcrumbs.length > 0 && (
                    <nav className="flex items-center gap-1.5 text-sm text-gray-500 mb-1">
                        {breadcrumbs.map((crumb, idx) => (
                            <span key={idx} className="flex items-center gap-1.5">
                                {idx > 0 && (
                                    <svg className="w-3.5 h-3.5 text-gray-400" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                                    </svg>
                                )}
                                {crumb.href ? (
                                    <Link href={crumb.href} className="hover:text-[var(--color-primary)] transition-colors">
                                        {crumb.label}
                                    </Link>
                                ) : (
                                    <span className="text-gray-700 font-medium">{crumb.label}</span>
                                )}
                            </span>
                        ))}
                    </nav>
                )}
                <h2 className="page-title">{title}</h2>
                {subtitle && <p className="text-sm text-gray-500 mt-1">{subtitle}</p>}
            </div>
            {actions && <div className="flex gap-2 flex-shrink-0">{actions}</div>}
        </div>
    );
}
