import { Head, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import DataGrid from '@thirdline/ui/Components/DataGrid/DataGrid';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/** The KRI library (migration Phase 2) — see App\Grids\Definitions\KrisGrid. */
export default function Index({ total, activeBreachCount, grid }) {
    const permissions = usePage().props.auth?.permissions ?? [];
    const createUrl = tryRoute('risk.kri.create');
    const thresholdsUrl = tryRoute('risk.kri.thresholds');

    return (
        <AuthenticatedLayout title="KRI Library">
            <Head title="KRI Library" />

            <PageHeader
                title="Key Risk Indicators Library"
                subtitle={`${total} indicators configured · ${activeBreachCount} active breaches`}
                actions={
                    <>
                        {permissions.includes('kri.create') && createUrl && (
                            <a href={createUrl} className="btn-primary text-sm inline-flex items-center gap-2">
                                <span className="material-symbols-outlined text-lg">add_circle</span> New KRI
                            </a>
                        )}
                        {thresholdsUrl && (
                            <a href={thresholdsUrl} className="btn-secondary text-sm inline-flex items-center gap-2">
                                <span className="material-symbols-outlined text-lg">tune</span> Manage Thresholds
                            </a>
                        )}
                    </>
                }
            />

            <DataGrid grid={grid} />
        </AuthenticatedLayout>
    );
}
