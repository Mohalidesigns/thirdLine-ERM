import { Head, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import RatingBadge from '@thirdline/ui/Components/RatingBadge';

const CELL = {
    effective: { class: 'bg-green-500', label: 'Effective' },
    partially: { class: 'bg-yellow-500', label: 'Partially effective' },
    ineffective: { class: 'bg-red-500', label: 'Ineffective / gap' },
    na: { class: 'bg-gray-200', label: 'Not mapped' },
};

const LEGEND = ['effective', 'partially', 'ineffective', 'na'];

function coverageTone(coverage) {
    if (coverage >= 80) return 'text-green-600';
    if (coverage >= 50) return 'text-yellow-600';

    return 'text-red-600';
}

/**
 * The risk-control matrix (migration Phase 3.8: risk/rcsa/matrix.blade.php).
 *
 * NOT the `heatmap` widget the phase prompt suggests: that widget is
 * probability × consequence off the organisation's scoring profile, a
 * different grid from risks × controls that happens to share the word matrix.
 * See the module notes.
 *
 * The business-unit filter is a real filter now — the Blade page rendered the
 * select with no handler, so the controller's `business_unit_id` branch was
 * unreachable from the screen.
 */
export default function Matrix({ risks = [], controls = [], businessUnits = [], filters = {}, exportUrl }) {
    const filter = (businessUnitId) =>
        router.get(route('risk.rcsa.matrix'), businessUnitId ? { business_unit_id: businessUnitId } : {}, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });

    return (
        <AuthenticatedLayout title="Risk-Control Matrix">
            <Head title="Risk-Control Matrix" />

            <PageHeader
                title="Risk-Control Matrix"
                subtitle="Map risks to controls showing coverage and effectiveness"
                breadcrumbs={[{ label: 'RCSA', href: route('risk.rcsa.dashboard') }, { label: 'Risk-Control Matrix' }]}
                actions={
                    <>
                        <select
                            value={filters.business_unit_id ?? ''}
                            onChange={(e) => filter(e.target.value)}
                            className="border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-700"
                        >
                            <option value="">All Business Units</option>
                            {businessUnits.map((unit) => (
                                <option key={unit.id} value={unit.id}>{unit.name}</option>
                            ))}
                        </select>
                        <a href={exportUrl} className="btn-secondary inline-flex items-center gap-2 text-sm">
                            <span className="material-symbols-outlined text-lg">download</span> Export
                        </a>
                    </>
                }
            />

            <div className="bg-white rounded-xl border border-gray-200 p-4 mb-4">
                <div className="flex flex-wrap items-center gap-6 text-xs text-gray-600">
                    <span className="font-semibold">Legend:</span>
                    {LEGEND.map((key) => (
                        <span key={key} className="flex items-center gap-1">
                            <span className={`w-4 h-4 rounded inline-block ${CELL[key].class}`} /> {CELL[key].label}
                        </span>
                    ))}
                </div>
            </div>

            <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th className="sticky left-0 bg-white z-10 min-w-[220px]">Risk / Control</th>
                                {controls.map((control) => (
                                    <th key={control.id} className="text-center min-w-[100px]">
                                        <div className="text-[10px] leading-tight" title={control.name}>
                                            {control.code}
                                        </div>
                                    </th>
                                ))}
                                <th className="text-center">Coverage</th>
                            </tr>
                        </thead>
                        <tbody>
                            {risks.length === 0 && (
                                <tr>
                                    <td colSpan={controls.length + 2} className="text-center py-12">
                                        <span className="material-symbols-outlined text-4xl text-gray-300 mb-2 block">grid_on</span>
                                        <p className="text-sm text-gray-500">No risk-control mappings available</p>
                                    </td>
                                </tr>
                            )}
                            {risks.map((risk) => (
                                <tr key={risk.id}>
                                    <td className="sticky left-0 bg-white z-10 font-medium text-[#1A365D]">
                                        <div className="flex items-center gap-2">
                                            <RatingBadge rating={risk.residualRating?.toLowerCase()} />
                                            <span className="text-xs" title={risk.title}>{risk.title}</span>
                                        </div>
                                    </td>
                                    {risk.cells.map((cell) => (
                                        <td key={cell.controlId} className="text-center">
                                            <span
                                                className={`inline-block w-6 h-6 rounded ${CELL[cell.effectiveness].class}`}
                                                title={CELL[cell.effectiveness].label}
                                            />
                                        </td>
                                    ))}
                                    <td className={`text-center text-xs font-semibold ${coverageTone(risk.coverage)}`}>
                                        {risk.coverage}%
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
