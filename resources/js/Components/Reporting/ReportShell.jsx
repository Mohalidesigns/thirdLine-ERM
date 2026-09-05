import { Link } from '@inertiajs/react';
import tryRoute from '@/lib/tryRoute';

/**
 * The frame the on-screen reports share (migration Phase 5.4).
 *
 * Heading, the download actions, and a print button. The downloads are REAL
 * server-rendered documents — `?download=1&format=pdf|xlsx` on the report's own
 * route, through DocumentRenderer — not the browser's print dialog, and they
 * are plain <a> elements rather than Inertia <Link>s for that reason: an
 * Inertia visit expects a page back, and these return a file.
 *
 * The PDF templates under resources/views/reports/pdf are untouched by this
 * phase (criterion 5), so nothing here reaches into them.
 */
export default function ReportShell({ title, subtitle, routeName, formats = ['pdf', 'xlsx'], extraQuery = {}, actions = null, children }) {
    const FORMAT_LABELS = { pdf: ['picture_as_pdf', 'Download PDF'], xlsx: ['table_view', 'Excel'], csv: ['csv', 'CSV'] };

    const downloadUrl = (format) => tryRoute(routeName, { download: 1, format, ...extraQuery });

    return (
        <>
            <div className="flex items-start justify-between mb-6 print:hidden">
                <div>
                    <h1 className="text-xl font-bold text-[#1A365D]">{title}</h1>
                    {subtitle && <p className="text-sm text-gray-500 mt-1">{subtitle}</p>}
                </div>

                <div className="flex gap-2">
                    {actions}

                    {formats.map((format) => {
                        const url = downloadUrl(format);
                        const [icon, label] = FORMAT_LABELS[format] ?? ['download', format];

                        return url ? (
                            <a
                                key={format}
                                href={url}
                                className={
                                    format === 'pdf'
                                        ? 'px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm hover:bg-[#2D4A7A] flex items-center gap-2'
                                        : 'px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2'
                                }
                            >
                                <span className="material-symbols-outlined text-lg">{icon}</span> {label}
                            </a>
                        ) : null;
                    })}

                    <button
                        type="button"
                        onClick={() => window.print()}
                        className="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2"
                    >
                        <span className="material-symbols-outlined text-lg">print</span> Print
                    </button>
                </div>
            </div>

            {children}
        </>
    );
}

/** A link back to the report library, used by the report pages' action slots. */
export function LibraryLink() {
    const url = tryRoute('risk.reports.library');

    return url ? (
        <Link href={url} className="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2">
            <span className="material-symbols-outlined text-lg">folder</span> Library
        </Link>
    ) : null;
}
