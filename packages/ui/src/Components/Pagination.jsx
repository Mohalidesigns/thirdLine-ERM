import { Link } from '@inertiajs/react';

// VAPT-055: Laravel paginator labels contain the HTML entities &laquo;/&raquo;.
// Decode just those to their characters and render as plain text so we never
// feed server strings through dangerouslySetInnerHTML (a stored-XSS footgun the
// moment a custom paginator or label rewrite is introduced).
function decodeLabel(label) {
    if (label == null) return '';
    return String(label)
        .replace(/&laquo;/g, '«')
        .replace(/&raquo;/g, '»')
        .replace(/&amp;/g, '&')
        .replace(/&hellip;/g, '…')
        .replace(/<[^>]*>/g, '')
        .trim();
}

export default function Pagination({ links, meta }) {
    if (!links || links.length <= 3) return null;

    return (
        <div className="flex items-center justify-between px-4 py-3 border-t border-gray-100">
            <div className="text-sm text-gray-500">
                {meta && (
                    <span>
                        Showing {meta.from || 0} to {meta.to || 0} of {meta.total || 0} results
                    </span>
                )}
            </div>
            <nav className="flex items-center gap-1">
                {links.map((link, index) => (
                    <Link
                        key={index}
                        href={link.url || '#'}
                        className={`px-3 py-1.5 text-sm rounded-md transition-colors ${
                            link.active
                                ? 'bg-[var(--color-primary)] text-white font-semibold'
                                : link.url
                                    ? 'text-gray-600 hover:bg-gray-100'
                                    : 'text-gray-300 cursor-not-allowed'
                        }`}
                        preserveScroll
                    >
                        {decodeLabel(link.label)}
                    </Link>
                ))}
            </nav>
        </div>
    );
}
