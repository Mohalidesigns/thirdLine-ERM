import { Head, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import DataGrid from '@thirdline/ui/Components/DataGrid/DataGrid';
import KpiCard from '@thirdline/ui/Components/KpiCard';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/** The near-miss register (migration Phase 2) — see App\Grids\Definitions\NearMissesGrid. */
export default function NearMisses({ totalNearMisses, openNearMisses, underReviewNearMisses, potentialLossAvoided, grid }) {
    const permissions = usePage().props.auth?.permissions ?? [];
    const createUrl = tryRoute('risk.loss-events.create-near-miss');

    return (
        <AuthenticatedLayout title="Near Misses">
            <Head title="Near Misses" />

            <PageHeader
                title="Near Miss Register"
                subtitle="Track near-miss events that could have resulted in operational losses"
                actions={
                    permissions.includes('loss_event.create') && createUrl && (
                        <a href={createUrl} className="btn-primary text-sm inline-flex items-center gap-2">
                            <span className="material-symbols-outlined text-lg">add</span> Log Near Miss
                        </a>
                    )
                }
            />

            <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <KpiCard title="Total Near Misses" value={totalNearMisses ?? 0} icon="warning" color="warning" />
                <KpiCard title="Open" value={openNearMisses ?? 0} icon="pending" color="info" />
                <KpiCard title="Under Review" value={underReviewNearMisses ?? 0} icon="rate_review" color="primary" />
                <KpiCard title="Potential Loss Avoided" value={potentialLossAvoided} icon="savings" color="success" />
            </div>

            <DataGrid grid={grid} />
        </AuthenticatedLayout>
    );
}
