import { Head, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DataGrid from '@/Components/DataGrid/DataGrid';
import PageHeader from '@/Components/PageHeader';
import tryRoute from '@/lib/tryRoute';

/**
 * The emerging risk register (migration Phase 2) — see
 * App\Grids\Definitions\EmergingRisksGrid. "Mark reviewed today" and "Remove
 * from register" are bulk actions inside the grid.
 */
export default function Index({ grid }) {
    const permissions = usePage().props.auth?.permissions ?? [];
    const radarUrl = tryRoute('risk.ai.radar');
    const createUrl = tryRoute('risk.emerging.create');

    return (
        <AuthenticatedLayout title="Emerging Risk Register">
            <Head title="Emerging Risk Register" />

            <PageHeader
                title="Emerging Risk Register"
                subtitle="Risks on the horizon that are not yet in the register proper."
                actions={
                    <>
                        {radarUrl && (
                            <a href={radarUrl} className="btn-secondary text-sm inline-flex items-center gap-2">
                                <span className="material-symbols-outlined text-lg">radar</span> View radar
                            </a>
                        )}
                        {permissions.includes('risk.create') && createUrl && (
                            <a href={createUrl} className="btn-primary text-sm inline-flex items-center gap-2">
                                <span className="material-symbols-outlined text-lg">add</span> Add entry
                            </a>
                        )}
                    </>
                }
            />

            <DataGrid grid={grid} />
        </AuthenticatedLayout>
    );
}
