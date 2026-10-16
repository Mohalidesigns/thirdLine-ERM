import { Head, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import DataGrid from '@thirdline/ui/Components/DataGrid/DataGrid';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';
import Icon from '@thirdline/ui/Components/Icon';

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
                                <Icon name="add_circle" className="w-4 h-4 shrink-0" /> New KRI
                            </a>
                        )}
                        {thresholdsUrl && (
                            <a href={thresholdsUrl} className="btn-secondary text-sm inline-flex items-center gap-2">
                                <Icon name="tune" className="w-4 h-4 shrink-0" /> Manage Thresholds
                            </a>
                        )}
                    </>
                }
            />

            <DataGrid grid={grid} />
        </AuthenticatedLayout>
    );
}
