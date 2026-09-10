import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import EmptyState from '@thirdline/ui/Components/EmptyState';
import { naira, number } from '@/Components/Quantification/figures';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * The shipped scenario library (migration Phase 5.2).
 *
 * These templates are `config/quantification_library.php`, where every entry
 * carries a `source` and a test fails if one does not. That matters because a
 * template's mean and standard deviation become a log-normal severity, the
 * engine draws on it, the run produces an aggregate VaR, and that VaR becomes a
 * capital add-on in a regulatory submission — a parameter entering that chain
 * with no stated origin would reach a regulator with none.
 *
 * The card reads the CONFIG's vocabulary, which is the form's, and that is
 * correct here: an entry is converted on import. The register's own screens had
 * to be taught the difference.
 *
 * Until Phase 5.2 not one of these could be taken: `scenario_type` is NOT NULL
 * with no default and the import never set it, so every attempt ended in a 500.
 */
export default function Library({ libraryScenarios, canImport }) {
    const scenariosUrl = tryRoute('risk.quantification.scenarios');

    const importTemplate = (template) => {
        router.post(route('risk.quantification.library.import', template.id), {}, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout title="Scenario Library">
            <Head title="Scenario Library" />

            <PageHeader
                title="Nigerian Risk Scenario Library"
                subtitle="Pre-built loss scenarios calibrated for the Nigerian banking sector"
                actions={
                    scenariosUrl && (
                        <Link href={scenariosUrl} className="btn-secondary text-sm inline-flex items-center gap-2">
                            <span className="material-symbols-outlined text-lg">category</span> Scenario register
                        </Link>
                    )
                }
            />

            <div className="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-6 text-sm text-blue-900">
                An imported template is a <span className="font-semibold">starting point, not an assessment</span>. Its
                parameters come from the published source named on each card, not from this institution&apos;s own loss
                experience, and the scenario is created as a draft to be re-calibrated. The source travels into the
                scenario&apos;s description so the provenance stays with the record.
            </div>

            {libraryScenarios.length === 0 ? (
                <EmptyState
                    icon={<span className="material-symbols-outlined text-3xl text-gray-400">library_books</span>}
                    title="No pre-built scenarios available"
                    description="The shipped library is empty for this installation."
                />
            ) : (
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    {libraryScenarios.map((template) => (
                        <div key={template.id} className="bg-white rounded-xl border border-gray-200 p-5 flex flex-col hover:shadow-lg transition-shadow">
                            <div className="flex items-center justify-between mb-3">
                                <span className="badge bg-blue-100 text-blue-700">{template.risk_category ?? '—'}</span>
                                <span className="badge bg-gray-100 text-gray-600">{template.distribution_type ?? '—'}</span>
                            </div>

                            <h3 className="text-sm font-semibold text-[#1A365D] mb-2">{template.name}</h3>
                            <p className="text-xs text-gray-500 mb-4 flex-1">{template.description ?? ''}</p>

                            <div className="grid grid-cols-2 gap-2 text-xs mb-4">
                                <div className="bg-gray-50 p-2 rounded">
                                    <p className="text-gray-500">Mean loss</p>
                                    <p className="font-semibold">{naira(template.mean) ?? '—'}</p>
                                </div>
                                <div className="bg-gray-50 p-2 rounded">
                                    <p className="text-gray-500">Std dev</p>
                                    <p className="font-semibold">{naira(template.std_dev) ?? '—'}</p>
                                </div>
                                <div className="bg-gray-50 p-2 rounded">
                                    <p className="text-gray-500">Frequency</p>
                                    <p className="font-semibold">
                                        {template.frequency_per_year === null
                                            ? '—'
                                            : `${number(template.frequency_per_year, 2)}/year`}
                                    </p>
                                </div>
                                <div className="bg-gray-50 p-2 rounded">
                                    <p className="text-gray-500">Source</p>
                                    <p className="font-semibold">{template.source}</p>
                                </div>
                            </div>

                            {template.imported ? (
                                <Link
                                    href={route('risk.quantification.show-scenario', template.imported.id)}
                                    className="w-full px-4 py-2 border border-gray-300 rounded-lg text-xs font-semibold text-gray-700 hover:bg-gray-50 flex items-center justify-center gap-2"
                                >
                                    <span className="material-symbols-outlined text-sm">check_circle</span>
                                    In your register as {template.imported.scenario_reference}
                                </Link>
                            ) : (
                                canImport && (
                                    <button
                                        type="button"
                                        onClick={() => importTemplate(template)}
                                        className="w-full px-4 py-2 bg-[#1A365D] text-white rounded-lg text-xs font-semibold hover:bg-[#2D4A7A] flex items-center justify-center gap-2"
                                    >
                                        <span className="material-symbols-outlined text-sm">add_circle</span> Import scenario
                                    </button>
                                )
                            )}
                        </div>
                    ))}
                </div>
            )}
        </AuthenticatedLayout>
    );
}
