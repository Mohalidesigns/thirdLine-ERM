import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import TrendChart from '@/Components/TrendChart';
import { BAND_SERIES, SELECT, bandFor, bandSeriesData } from './format';

/**
 * The risk heat map (migration Phase 5.1: risk/analysis/heatmap.blade.php).
 *
 * ON NOT USING THE `heatmap` WIDGET. The phase prompt says these pages should
 * be thin wrappers around `Widget`. The widget genuinely renders this grid —
 * profile-driven, period-aware — but `Widget` is driven by a payload envelope
 * from `WidgetPayloadPresenter`, which requires a PERSISTED `WidgetDefinition`
 * and routes `risk.widgets.payload` by its key. Using it here would mean
 * seeding dashboard widget rows per tenant to render a page that is not a
 * dashboard, and would still not carry this page's business-unit and category
 * filters or the risks listed inside each cell. The widget stays where it
 * belongs, on dashboards. Recorded in the module notes.
 *
 * The grid's SHAPE, axis labels and band colours all come from the
 * organisation's scoring profile. A 4×6 tenant renders 4×6.
 */
function Cell({ cell, bands }) {
    const [open, setOpen] = useState(false);
    const band = bandFor(cell.score, bands);
    const background = band?.color ?? '#e5e7eb';

    return (
        <td className="p-1 align-top">
            <button
                type="button"
                onClick={() => cell.count > 0 && setOpen(!open)}
                disabled={cell.count === 0}
                className={`w-full min-h-[52px] rounded-lg border border-white/40 text-white text-sm font-semibold transition ${
                    cell.count > 0 ? 'hover:opacity-90 cursor-pointer' : 'opacity-30 cursor-default'
                }`}
                style={{ backgroundColor: background }}
                title={`${band?.label ?? 'Unbanded'} · score ${cell.score} · ${cell.count} risk${cell.count === 1 ? '' : 's'}`}
                aria-label={`${cell.count} risks at likelihood ${cell.likelihood}, impact ${cell.impact}`}
            >
                {cell.count > 0 ? cell.count : ''}
            </button>

            {open && cell.risks.length > 0 && (
                <ul className="mt-1 space-y-0.5 text-left">
                    {cell.risks.map((risk) => (
                        <li key={risk.id}>
                            <Link href={risk.url} className="block text-[11px] text-[#1A365D] hover:underline truncate" title={risk.title}>
                                {risk.code}
                            </Link>
                        </li>
                    ))}
                    {cell.count > cell.risks.length && (
                        <li className="text-[11px] text-gray-400">+{cell.count - cell.risks.length} more</li>
                    )}
                </ul>
            )}
        </td>
    );
}

export default function Heatmap({
    cells = [],
    grid,
    bandCounts = {},
    movement,
    viewType = 'inherent',
    total = 0,
    filters = {},
    businessUnits = [],
    categories = [],
}) {
    const apply = (patch) =>
        router.get(route('risk.analysis.heatmap'), { view_type: viewType, ...filters, ...patch }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });

    // Rows come down highest-likelihood first, which is how a heat map reads.
    const rows = [];
    for (let i = 0; i < cells.length; i += grid.cols) {
        rows.push(cells.slice(i, i + grid.cols));
    }

    return (
        <AuthenticatedLayout title="Risk Heat Map">
            <Head title="Risk Heat Map" />

            <PageHeader
                title="Risk Heat Map"
                subtitle={`${total} active risk${total === 1 ? '' : 's'}, plotted on your ${grid.rows}×${grid.cols} matrix`}
                breadcrumbs={[{ label: 'Analysis' }, { label: 'Heat Map' }]}
            />

            <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                {Object.entries(bandCounts).map(([code, band]) => (
                    <div key={code} className="bg-white rounded-xl border border-gray-200 p-4">
                        <div className="flex items-center gap-2">
                            <span className="w-2.5 h-2.5 rounded-full" style={{ backgroundColor: band.color ?? '#9ca3af' }} />
                            <span className="text-xs text-gray-500">{band.label}</span>
                        </div>
                        <p className="text-2xl font-bold text-[#1A365D] mt-1">{band.count}</p>
                    </div>
                ))}
            </div>

            <div className="bg-white rounded-xl border border-gray-200 p-5 mb-6">
                <div className="flex flex-wrap items-end gap-4 mb-5">
                    <div className="w-48">
                        <label htmlFor="view_type" className="text-xs text-gray-500">Basis</label>
                        <select id="view_type" value={viewType} onChange={(e) => apply({ view_type: e.target.value })} className={SELECT}>
                            <option value="inherent">Inherent</option>
                            <option value="residual">Residual</option>
                        </select>
                    </div>
                    <div className="w-56">
                        <label htmlFor="business_unit_id" className="text-xs text-gray-500">Business Unit</label>
                        <select
                            id="business_unit_id"
                            value={filters.business_unit_id ?? ''}
                            onChange={(e) => apply({ business_unit_id: e.target.value || null })}
                            className={SELECT}
                        >
                            <option value="">All units</option>
                            {businessUnits.map((unit) => <option key={unit.id} value={unit.id}>{unit.name}</option>)}
                        </select>
                    </div>
                    <div className="w-56">
                        <label htmlFor="category_id" className="text-xs text-gray-500">Category</label>
                        <select
                            id="category_id"
                            value={filters.category_id ?? ''}
                            onChange={(e) => apply({ category_id: e.target.value || null })}
                            className={SELECT}
                        >
                            <option value="">All categories</option>
                            {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                        </select>
                    </div>
                </div>

                <div className="overflow-x-auto">
                    <table className="w-full">
                        <tbody>
                            {rows.map((row, index) => (
                                <tr key={index}>
                                    <th scope="row" className="w-32 pr-3 text-right text-xs text-gray-500 font-normal align-middle">
                                        {grid.likelihoodLabels?.[row[0].likelihood] ?? row[0].likelihood}
                                    </th>
                                    {row.map((cell) => (
                                        <Cell key={`${cell.likelihood}-${cell.impact}`} cell={cell} bands={grid.bands} />
                                    ))}
                                </tr>
                            ))}
                            <tr>
                                <th />
                                {(rows[0] ?? []).map((cell) => (
                                    <th key={cell.impact} scope="col" className="pt-2 text-xs text-gray-500 font-normal">
                                        {grid.impactLabels?.[cell.impact] ?? cell.impact}
                                    </th>
                                ))}
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p className="text-xs text-gray-400 mt-3">
                    Likelihood down the side, impact across. Click a cell to list the risks in it.
                </p>
            </div>

            <div className="bg-white rounded-xl border border-gray-200 p-5">
                <h3 className="text-sm font-semibold text-[#1A365D]">Risk Movement</h3>
                {/* Point-in-time since Phase 5.1: each quarter is scored as the
                    register stood THEN, so a risk that moved between bands shows
                    as moved. Before that, every quarter used today's score. */}
                <p className="text-xs text-gray-400 mb-4">
                    Counts per band at the end of each quarter, scored as the register stood then
                </p>
                <TrendChart data={bandSeriesData(movement)} series={BAND_SERIES} />
            </div>
        </AuthenticatedLayout>
    );
}
