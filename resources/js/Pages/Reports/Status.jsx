import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import useReportStatus from '@/hooks/useReportStatus';
import tryRoute from '@/lib/tryRoute';

const humanise = (value) => (value ? String(value).replaceAll('_', ' ') : '—');

const shortDate = (value) =>
    value ? new Date(value).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';

/**
 * A generated report's progress (migration Phase 5.4).
 *
 * The Blade page ran its own `setInterval` + `fetch` loop with manual DOM
 * writes. That is `useReportStatus` now — see the note on that hook for why it
 * is not `useJobProgress`, which the phase prompt asked for and which cannot
 * work here: no JobRun is created for a report.
 *
 * Polling rather than holding the connection open, for the reason the original
 * comment gave: a long-running board pack should not tie up a PHP worker just
 * to report its own progress.
 */
export default function Status({ report }) {
    const running = ['queued', 'generating', 'processing'].includes(report.status);

    const polled = useReportStatus(report.id, { enabled: running });

    const percent = polled.percent ?? report.progress_pct ?? 0;
    const status = polled.status ?? report.status;
    const finished = status === 'completed';

    const downloadUrl = polled.downloadUrl ?? tryRoute('risk.reports.download', report.id);
    const libraryUrl = tryRoute('risk.reports.library');

    return (
        <AuthenticatedLayout title={report.name}>
            <Head title={report.name} />

            <PageHeader
                title={report.name}
                subtitle={`${humanise(report.report_type)} · as at ${shortDate(report.period_as_at)}${report.version ? ` · v${report.version}` : ''}`}
                actions={
                    libraryUrl && (
                        <Link href={libraryUrl} className="btn-secondary text-sm">
                            Back to library
                        </Link>
                    )
                }
            />

            <div className="bg-white rounded-xl border border-gray-200 p-6 max-w-2xl">
                <div className="flex items-center justify-between mb-3">
                    <StatusBadge status={status} />
                    {running && <span className="text-sm text-gray-500">{percent}%</span>}
                </div>

                {running && (
                    <>
                        <div className="w-full bg-gray-100 rounded-full h-2 overflow-hidden">
                            <div className="h-2 bg-[#1A365D] transition-all" style={{ width: `${Math.max(3, percent)}%` }} />
                        </div>
                        <p className="text-xs text-gray-500 mt-3">
                            {status === 'queued' ? 'Queued' : 'Generating'} — this page updates as the report is
                            assembled. You can leave it and come back to the library.
                        </p>
                    </>
                )}

                {finished && (
                    <div className="space-y-3">
                        <p className="text-sm text-gray-700">
                            Ready{report.size_for_humans ? ` · ${report.size_for_humans}` : ''}
                            {report.file_name ? ` · ${report.file_name}` : ''}
                        </p>
                        {downloadUrl && (
                            <a
                                href={downloadUrl}
                                className="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm hover:bg-[#2D4A7A] inline-flex items-center gap-2"
                            >
                                <span className="material-symbols-outlined text-lg">download</span> Download
                            </a>
                        )}
                    </div>
                )}

                {status === 'failed' && (
                    <div className="bg-red-50 border border-red-200 rounded-lg p-4 text-sm text-red-800">
                        <span className="font-semibold">This report could not be generated.</span>{' '}
                        {polled.errorMessage ?? report.error_message ?? 'No error was recorded.'}
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
