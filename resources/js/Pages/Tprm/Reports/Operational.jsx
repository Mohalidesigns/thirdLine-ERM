import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';

/**
 * The operational reports hub — FR-RPT-07.
 *
 * IT NAMES WHAT IT IS NOT SHOWING YOU. Each report reads data behind its own
 * permission, so two people open this page and see different lists. A hub that
 * silently showed the shorter one would leave somebody believing a report does
 * not exist when it is only that they cannot see it.
 */
export default function Operational({ reports = [], withheld = [], can = {} }) {
    return (
        <AppLayout title="Operational reports">
            <Head title="Operational reports" />

            <PageHeader
                title="Operational reports"
                subtitle="The eight standing reports, each exportable to XLSX, CSV and a branded PDF."
            />

            <div className="grid gap-4 md:grid-cols-2">
                {reports.map((report) => (
                    <Link
                        key={report.key}
                        href={report.url}
                        className="card block p-4 transition hover:border-indigo-300"
                    >
                        <div className="text-sm font-semibold text-gray-800">{report.title}</div>
                        <p className="mt-1 text-xs text-gray-600">{report.description}</p>
                        <p className="mt-2 text-xs text-gray-400">Reads data behind {report.permission}</p>
                    </Link>
                ))}
            </div>

            {reports.length === 0 && (
                <div className="card p-10 text-center text-sm text-gray-500">
                    You can open this page but hold none of the permissions the eight reports read. That is a
                    permissions question rather than a reporting one.
                </div>
            )}

            {withheld.length > 0 && (
                <div className="mt-6 rounded border border-gray-200 bg-gray-50 p-4 text-xs text-gray-600">
                    <strong>Not shown to you:</strong> {withheld.join(', ')}. Each reads data behind a permission
                    you do not hold, so it is named here rather than left off the page — a shorter list with no
                    explanation reads as a report that does not exist.
                </div>
            )}

            {!can.export && (
                <p className="mt-4 text-xs text-gray-500">
                    You can read these reports. Producing a file from one needs the export permission.
                </p>
            )}
        </AppLayout>
    );
}
