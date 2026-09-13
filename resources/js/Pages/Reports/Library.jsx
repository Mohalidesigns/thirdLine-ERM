import { Head, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import DataGrid from '@thirdline/ui/Components/DataGrid/DataGrid';
import InputError from '@thirdline/ui/Components/InputError';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import { CsrfField } from '@thirdline/ui/lib/nativeForm';
import tryRoute from '@thirdline/ui/lib/tryRoute';

const humanise = (type) => type.split('_').map((w) => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');

/**
 * The report library (migration Phase 2) — see
 * App\Grids\Definitions\ReportsLibraryGrid. The generate form posts NATIVELY:
 * its redirect lands on the Blade progress page (risk.reports.status), which
 * an Inertia visit cannot follow until Phase 3.
 */
export default function Library({ types, today, grid }) {
    const { auth, errors = {} } = usePage().props;
    const permissions = auth?.permissions ?? [];
    const sectionsUrl = tryRoute('risk.reports.board-pack.sections');
    const queueUrl = tryRoute('risk.reports.queue');

    return (
        <AuthenticatedLayout title="Report Library">
            <Head title="Report Library" />

            <PageHeader
                title="Report Library"
                subtitle="Every generated document, kept as it was produced. Downloading an old report returns that report — not a fresh run against today's data."
                actions={
                    permissions.includes('report.view') && sectionsUrl && (
                        <a href={sectionsUrl} className="btn-secondary text-sm inline-flex items-center gap-2">
                            <span className="material-symbols-outlined text-lg">tune</span> Board pack sections
                        </a>
                    )
                }
            />

            {permissions.includes('report.generate') && queueUrl && (
                <div className="bg-white rounded-xl border border-gray-200 p-5 mb-6">
                    <h2 className="text-sm font-semibold text-[#1A365D] mb-4">Generate a report</h2>
                    <form method="POST" action={queueUrl} className="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <CsrfField />
                        <div>
                            <label htmlFor="report_type" className="block text-xs font-medium text-gray-600 mb-1">Report</label>
                            <select id="report_type" name="report_type" required className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                                {types.map((type) => (
                                    <option key={type} value={type}>{humanise(type)}</option>
                                ))}
                            </select>
                            <InputError message={errors.report_type} className="mt-1" />
                        </div>
                        <div>
                            <label htmlFor="format" className="block text-xs font-medium text-gray-600 mb-1">Format</label>
                            <select id="format" name="format" className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                                <option value="pdf">PDF</option>
                                <option value="xlsx">Excel workbook (.xlsx)</option>
                                <option value="csv">CSV</option>
                            </select>
                            <p className="text-[10px] text-gray-500 mt-1">Board packs are always PDF.</p>
                            <InputError message={errors.format} className="mt-1" />
                        </div>
                        <div>
                            <label htmlFor="as_at" className="block text-xs font-medium text-gray-600 mb-1">Position as at</label>
                            <input type="date" id="as_at" name="as_at" defaultValue={today} className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" />
                            <InputError message={errors.as_at} className="mt-1" />
                        </div>
                        <div className="flex items-end">
                            <button type="submit" className="btn-primary w-full text-sm">Generate</button>
                        </div>
                    </form>
                </div>
            )}

            <DataGrid grid={grid} />
        </AuthenticatedLayout>
    );
}
