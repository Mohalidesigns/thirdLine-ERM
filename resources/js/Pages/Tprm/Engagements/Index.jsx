import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import DataGrid from '@thirdline/ui/Components/DataGrid/DataGrid';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * The Engagement Register — "the real working list" (TRD §11).
 *
 * Risk is assessed at the engagement, not the entity (TRD §5.1), so every
 * operational question — what is overdue, what is untiered, what is Critical —
 * is answered here rather than on the third-party register.
 */
export default function Index({ summary = {}, grid, can = {} }) {
    const intakeUrl = tryRoute('tprm.intake.create');

    const tiles = [
        { label: 'Engagements', value: summary.total ?? 0, hint: 'in the register' },
        { label: 'Critical tier', value: summary.critical ?? 0, hint: 'highest scrutiny', tone: 'critical' },
        { label: 'Not tiered', value: summary.untiered ?? 0, hint: 'no inherent assessment', tone: summary.untiered ? 'warn' : null },
        { label: 'Assessment overdue', value: summary.overdue ?? 0, hint: 'past the cadence', tone: summary.overdue ? 'critical' : null },
    ];

    return (
        <AppLayout title="Engagements">
            <Head title="Engagements" />

            <PageHeader
                title="Engagements"
                subtitle="A service bought from a third party. Risk is assessed here, not at the entity."
                actions={
                    can.create && intakeUrl && (
                        <a href={intakeUrl} className="btn-primary text-sm inline-flex items-center gap-2">
                            <span className="material-symbols-outlined text-lg">assignment_add</span> Raise an intake
                        </a>
                    )
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
