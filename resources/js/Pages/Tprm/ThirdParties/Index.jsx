import { Head, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import DataGrid from '@thirdline/ui/Components/DataGrid/DataGrid';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * The Third-Party Register — see App\Grids\Definitions\TprmThirdPartiesGrid.
 *
 * This list IS the CBN Cyber Framework Appendix II §1.4 artefact (FR-TPR-08),
 * which is why the saved views, faceted filters and export come from the
 * shared grid rather than a bespoke table: the export has to reconcile
 * row-for-row to what is on screen (AC-13), and one query behind both is how
 * that stays true.
 */
export default function Index({ summary = {}, grid, can = {} }) {
    const createUrl = tryRoute('tprm.third-parties.create');
    const intakeUrl = tryRoute('tprm.intake.create');

    const tiles = [
        { label: 'Registered', value: summary.total ?? 0, hint: 'third parties' },
        { label: 'Active', value: summary.active ?? 0, hint: 'in an active relationship' },
        { label: 'Critical tier', value: summary.critical ?? 0, hint: 'with a Critical engagement', tone: 'critical' },
        { label: 'No owner', value: summary.unassigned ?? 0, hint: 'no relationship owner', tone: summary.unassigned ? 'warn' : null },
    ];

    return (
        <AppLayout title="Third-Party Register">
            <Head title="Third-Party Register" />

            <PageHeader
                title="Third-Party Register"
                subtitle="Every third party the institution deals with. This register is the CBN Cyber Framework Appendix II §1.4 artefact."
                actions={
                    <div className="flex items-center gap-2">
                        {can.create && createUrl && (
                            <a href={createUrl} className="btn-secondary text-sm inline-flex items-center gap-2">
                                <span className="material-symbols-outlined text-lg">add</span> Register a third party
                            </a>
                        )}
                        {can.create && intakeUrl && (
                            <a href={intakeUrl} className="btn-primary text-sm inline-flex items-center gap-2">
                                <span className="material-symbols-outlined text-lg">assignment_add</span> Raise an intake
                            </a>
                        )}
                    </div>
                }
            />

            <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                {tiles.map((tile) => (
                    <div key={tile.label} className="card p-4">
                        <p className="text-xs font-medium uppercase tracking-wide text-gray-500">{tile.label}</p>
                        <p className={`mt-1 text-2xl font-semibold ${
                            tile.tone === 'critical' ? 'text-red-700' : tile.tone === 'warn' ? 'text-amber-700' : 'text-gray-900'
                        }`}>
                            {tile.value}
                        </p>
                        <p className="mt-0.5 text-xs text-gray-500">{tile.hint}</p>
                    </div>
                ))}
            </div>

            <DataGrid grid={grid} />
        </AppLayout>
    );
}
