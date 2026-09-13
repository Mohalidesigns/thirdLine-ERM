import { Head, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import DataGrid from '@thirdline/ui/Components/DataGrid/DataGrid';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/** The circular register (migration Phase 2) — see App\Grids\Definitions\RegulatoryCircularsGrid. */
export default function Circulars({ grid }) {
    const permissions = usePage().props.auth?.permissions ?? [];
    const createUrl = tryRoute('risk.regulatory.create-circular');

    return (
        <AuthenticatedLayout title="Regulatory Circulars">
            <Head title="Regulatory Circulars" />

            <PageHeader
                title="Regulatory Circulars"
                subtitle="Circulars issued by the regulators, and where the institution stands on each."
                actions={
                    permissions.includes('regulatory.manage') && createUrl && (
                        <a href={createUrl} className="btn-primary text-sm inline-flex items-center gap-2">
                            Record Circular
                        </a>
                    )
                }
            />

            <DataGrid grid={grid} />
        </AuthenticatedLayout>
    );
}
