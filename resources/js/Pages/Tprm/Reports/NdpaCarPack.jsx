import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import PackSections from './PackSections';

/**
 * The NDPA Compliance Audit Return evidence pack — FR-RPT-03.
 *
 * THE COUNTDOWN IS THE FIRST THING ON THE PAGE. GAID Art. 10(7)–(10) sets 31
 * March and a late-filing penalty of 50% of the filing fee. A pack that can be
 * generated but that nobody remembers to generate is the same as no pack.
 *
 * IT SAYS PLAINLY THAT NOTHING IS FILED FROM HERE. The module never writes to
 * a regulator, and a screen headed with a statutory deadline is exactly where
 * somebody might assume otherwise.
 */
export default function NdpaCarPack({ sections = [], countdown = {}, provenance = {}, can = {} }) {
    const days = countdown.days_remaining ?? 0;
    const urgent = days <= 45;

    const exportUrl = (format, section) => {
        const params = new URLSearchParams({ format });
        if (section) params.set('section', section);
        return `${route('tprm.reports.ndpa-car.export')}?${params.toString()}`;
    };

    const gaps = sections.filter((section) => section.coverage !== 'complete');

    return (
        <AppLayout title="NDPA Compliance Audit Return">
            <Head title="NDPA Compliance Audit Return" />

            <PageHeader
                title="NDPA Compliance Audit Return — third-party evidence"
                subtitle="What a data protection officer takes into the return. Nothing here is submitted to the NDPC; the module never writes to a regulator."
            />

            <div
                className={`mb-6 rounded border p-4 ${
                    urgent ? 'border-red-200 bg-red-50 text-red-900' : 'border-gray-200 bg-gray-50 text-gray-800'
                }`}
            >
                <div className="text-sm font-semibold">
                    {countdown.overdue_today
                        ? `The ${countdown.filing_year} return is due today.`
                        : `${days} ${days === 1 ? 'day' : 'days'} to the ${countdown.filing_year} filing deadline.`}
                </div>
                <div className="mt-1 text-xs">
                    Due {countdown.deadline} under GAID Art. 10(7)–(10). Late filing carries a penalty of 50% of
                    the filing fee.
                </div>
            </div>

            <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <Tile label="Sections" value={sections.length} hint="processor inventory through to measures" />
                <Tile
                    label="Sections with gaps"
                    value={gaps.length}
                    tone={gaps.length ? 'warn' : null}
                    hint="each names what it could not answer"
                />
                <Tile
                    label="Rows across the pack"
                    value={sections.reduce((sum, section) => sum + (section.row_count ?? 0), 0)}
                    hint="every section combined"
                />
                <Tile label="Prepared by" value={provenance.prepared_by ?? '—'} hint={provenance.as_at_label} />
            </div>

            {can.export && (
                <div className="card mb-4 flex flex-wrap items-center gap-3 p-4">
                    <span className="text-sm font-medium text-gray-700">Export the whole pack</span>
                    <a className="btn-primary" href={exportUrl('xlsx')}>XLSX workbook</a>
                    <a className="btn-secondary" href={exportUrl('pdf')}>Branded PDF</a>
                    <span className="text-xs text-gray-500">CSV holds one section, offered per row below.</span>
                </div>
            )}

            <PackSections sections={sections} exportUrl={exportUrl} canExport={can.export} />
        </AppLayout>
    );
}

function Tile({ label, value, hint, tone }) {
    const toneClass = tone === 'warn' ? 'text-amber-600' : 'text-gray-900';

    return (
        <div className="card p-4">
            <div className="text-xs font-medium uppercase tracking-wide text-gray-500">{label}</div>
            <div className={`mt-1 text-2xl font-bold ${toneClass}`}>{value}</div>
            {hint && <div className="mt-1 text-xs text-gray-500">{hint}</div>}
        </div>
    );
}
