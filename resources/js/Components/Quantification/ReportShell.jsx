import { Link } from '@inertiajs/react';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * The frame the four quantification reports share (migration Phase 5.2).
 *
 * Heading, "as of" date, print action, a way back to the report library, and
 * the yellow band a report shows when it has no assessment to report on —
 * which is a state each of them has to be able to say out loud rather than
 * fill with zeroes.
 *
 * The print button is `window.print()`, exactly as the Blade views did it. The
 * PDF templates under resources/views/reports/pdf are a different renderer and
 * Phase 5's criterion 5 requires them untouched, so nothing here reaches for
 * them.
 */
export default function ReportShell({ title, subtitle, asOf = null, notice = null, children }) {
    const libraryUrl = tryRoute('risk.quantification.reports');

    const asOfLabel = asOf
        ? new Date(asOf).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
        : null;

    return (
        <>
            <div className="flex items-center justify-between mb-6 print:hidden">
                <div>
                    <h1 className="text-2xl font-bold text-[#1A365D]">{title}</h1>
                    <p className="text-sm text-gray-500 mt-1">
                        {subtitle}
                        {asOfLabel && <> &middot; as of {asOfLabel}</>}
                    </p>
                </div>
                <div className="flex gap-2">
                    <button
                        type="button"
                        onClick={() => window.print()}
                        className="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] flex items-center gap-2"
                    >
                        <span className="material-symbols-outlined text-lg">print</span> Print
                    </button>
                    {libraryUrl && (
                        <Link
                            href={libraryUrl}
                            className="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50"
                        >
                            Back
                        </Link>
                    )}
                </div>
            </div>

            {notice && (
                <div className="bg-yellow-50 border border-yellow-200 rounded-xl p-4 mb-6 text-sm text-yellow-800">
                    {notice}
                </div>
            )}

            {children}
        </>
    );
}
