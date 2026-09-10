import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import PackSections from './PackSections';

/**
 * The PCI DSS 12.8 pack — FR-RPT-04.
 *
 * THE TILES LEAD WITH FAILURES, NOT WITH A PROVIDER COUNT. A QSA is not asking
 * how many TPSPs there are; they are asking which have no agreement, no
 * current attestation and no confirmed responsibility matrix.
 *
 * THE LAST TILE IS NOT A PCI FIGURE AND SAYS SO. `pci_in_scope` is a decision
 * somebody made per engagement, and "we have four TPSPs" is only true if
 * somebody looked at the other two hundred.
 */
export default function PciPack({ sections = [], summary = {}, provenance = {}, can = {} }) {
    const exportUrl = (format, section) => {
        const params = new URLSearchParams({ format });
        if (section) params.set('section', section);
        return `${route('tprm.reports.pci-pack.export')}?${params.toString()}`;
    };

    return (
        <AppLayout title="PCI DSS 12.8 pack">
            <Head title="PCI DSS 12.8 pack" />

            <PageHeader
                title="PCI DSS requirement 12.8 pack"
                subtitle="The five things a QSA asks for at every assessment: the TPSP list, the agreements, the due diligence, the monitoring log and the responsibility matrices."
            />

            <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <Tile
                    label="Without an acknowledgement clause"
                    value={summary.agreements_missing ?? 0}
                    tone={summary.agreements_missing ? 'critical' : null}
                    hint="12.8.2"
                />
                <Tile
                    label="No attestation on file"
                    value={summary.aoc_missing ?? 0}
                    tone={summary.aoc_missing ? 'critical' : null}
                    hint="12.8.4"
                />
                <Tile
                    label="Attestation over twelve months old"
                    value={summary.aoc_stale ?? 0}
                    tone={summary.aoc_stale ? 'warn' : null}
                    hint="12.8.4 requires monitoring at least annually"
                />
                <Tile
                    label="Matrices with unconfirmed rows"
                    value={summary.matrices_unconfirmed ?? 0}
                    tone={summary.matrices_unconfirmed ? 'warn' : null}
                    hint="12.8.5 — a vendor's view, not an agreed position"
                />
            </div>

            <div className="mb-4 rounded border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700">
                <strong>{summary.tpsps ?? 0}</strong> engagements are scoped in for PCI DSS.{' '}
                <strong>{summary.unscoped_personal_data_engagements ?? 0}</strong> other engagements process
                personal data and are scoped out. That second number is not a PCI figure — it is the question
                behind the first one, because &ldquo;we have {summary.tpsps ?? 0} service providers&rdquo; is only
                true if somebody looked at the rest.
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

            <p className="mt-4 text-xs text-gray-500">
                Prepared by {provenance.prepared_by ?? '—'}, {provenance.as_at_label}.
            </p>
        </AppLayout>
    );
}

function Tile({ label, value, hint, tone }) {
    const toneClass = tone === 'critical' ? 'text-red-600' : tone === 'warn' ? 'text-amber-600' : 'text-gray-900';

    return (
        <div className="card p-4">
            <div className="text-xs font-medium uppercase tracking-wide text-gray-500">{label}</div>
            <div className={`mt-1 text-2xl font-bold ${toneClass}`}>{value}</div>
            {hint && <div className="mt-1 text-xs text-gray-500">{hint}</div>}
        </div>
    );
}
