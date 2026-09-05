import { Head, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import { SELECT } from './format';

/**
 * Shared-control concentration (Phase 5.1: risk/analysis/correlation.blade.php).
 *
 * The route name stays `risk.analysis.correlation` — it is linked from the nav
 * and from saved bookmarks — but nothing on this page is a correlation, and an
 * earlier work package removed the "coefficient" and "significance" columns
 * that claimed otherwise. See AnalysisController::correlation()'s docblock for
 * what was taken out and why: a correlation between two risks needs a time
 * series of paired observations and a coefficient with a p-value and an n, and
 * this product collects none of those.
 *
 * ON NOT USING THE `network` WIDGET. The phase prompt asks for this to be a
 * `network` widget. That resolver draws the anchor NODE's neighbourhood in the
 * object graph — two hops, capped at 60 nodes — which is a different question
 * from "of the larger of these two risks' control sets, what share carries
 * both". Rendering the matrix as a neighbourhood graph would lose the only
 * number the page exists to report. Same shape of mistake as 3.8's "Matrix =
 * widget type heatmap"; recorded in the module notes.
 */
function cellTone(value) {
    if (value === null || value === undefined) return 'bg-gray-50 text-gray-300';
    if (value >= 75) return 'bg-red-100 text-red-800 font-semibold';
    if (value >= 50) return 'bg-orange-100 text-orange-800';
    if (value >= 25) return 'bg-yellow-50 text-yellow-800';
    if (value > 0) return 'bg-green-50 text-green-700';
    return 'bg-gray-50 text-gray-400';
}

export default function SharedControls({
    pairs = [],
    matrix = { labels: [], data: [] },
    categories = [],
    selectedCategoryId = null,
    riskCount = 0,
}) {
    const filter = (categoryId) =>
        router.get(route('risk.analysis.correlation'), categoryId ? { category_id: categoryId } : {}, {
            preserveState: true,
            preserveScroll: true,
        });

    return (
        <AuthenticatedLayout title="Shared Control Analysis">
            <Head title="Shared Control Analysis" />

            <PageHeader
                title="Shared Control Analysis"
                subtitle="Two risks that lean on the same controls fail together when those controls fail"
                breadcrumbs={[{ label: 'Analysis' }, { label: 'Shared Controls' }]}
                actions={
                    <div className="w-64">
                        <label htmlFor="category_id" className="sr-only">Category</label>
                        <select
                            id="category_id"
                            value={selectedCategoryId ?? ''}
                            onChange={(e) => filter(e.target.value)}
                            className={SELECT}
                        >
                            <option value="">All categories</option>
                            {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                        </select>
                    </div>
                }
            />

            <div className="bg-white rounded-xl border border-gray-200 overflow-hidden mb-6">
                <div className="px-5 py-4 border-b border-gray-100">
                    <h3 className="text-sm font-semibold text-[#1A365D]">Most Concentrated Pairs</h3>
                    <p className="text-xs text-gray-400 mt-0.5">
                        Shared controls as a share of the larger of the two control sets. 100% means one risk’s entire
                        control set is also carrying the other.
                    </p>
                </div>
                <div className="overflow-x-auto">
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th>Risk A</th><th>Risk B</th><th>Shared</th><th>Controls A</th><th>Controls B</th><th>Overlap</th>
                            </tr>
                        </thead>
                        <tbody>
                            {pairs.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-center py-10 text-sm text-gray-400">
                                        {riskCount < 2
                                            ? 'At least two risks with mapped controls are needed to find a shared one.'
                                            : 'No two risks share a control.'}
                                    </td>
                                </tr>
                            )}
                            {pairs.map((pair, index) => (
                                <tr key={index}>
                                    <td className="font-mono text-xs">{pair.risk_a}</td>
                                    <td className="font-mono text-xs">{pair.risk_b}</td>
                                    <td className="text-sm font-semibold">{pair.shared_controls}</td>
                                    <td className="text-sm text-gray-500">{pair.controls_a}</td>
                                    <td className="text-sm text-gray-500">{pair.controls_b}</td>
                                    <td>
                                        <span className={`badge ${cellTone(pair.overlap_pct)}`}>{pair.overlap_pct}%</span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <div className="bg-white rounded-xl border border-gray-200 p-5">
                <h3 className="text-sm font-semibold text-[#1A365D]">Overlap Matrix</h3>
                {/* The diagonal is null, not 100: a risk trivially shares every
                    control with itself, and drawing that cell only ever existed
                    to complete the look of a correlation matrix. */}
                <p className="text-xs text-gray-400 mb-4">Top risks by score. The diagonal is deliberately blank.</p>

                {matrix.labels.length === 0 ? (
                    <EmptyState
                        icon={<span className="material-symbols-outlined text-3xl text-gray-400">grid_on</span>}
                        title="Nothing to compare"
                        description="Map controls to at least two risks to see where they overlap."
                    />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-xs">
                            <thead>
                                <tr>
                                    <th />
                                    {matrix.labels.map((label) => (
                                        <th key={label} scope="col" className="p-1 font-mono font-normal text-gray-500">{label}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {matrix.labels.map((rowLabel, i) => (
                                    <tr key={rowLabel}>
                                        <th scope="row" className="pr-2 text-right font-mono font-normal text-gray-500 whitespace-nowrap">
                                            {rowLabel}
                                        </th>
                                        {matrix.labels.map((colLabel, j) => (
                                            <td key={colLabel} className="p-0.5">
                                                <div
                                                    className={`rounded px-2 py-2 text-center ${cellTone(matrix.data?.[i]?.[j])}`}
                                                    title={i === j ? '' : `${rowLabel} and ${colLabel} share ${matrix.data?.[i]?.[j] ?? 0}% of the larger control set`}
                                                >
                                                    {i === j ? '' : `${matrix.data?.[i]?.[j] ?? 0}%`}
                                                </div>
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
