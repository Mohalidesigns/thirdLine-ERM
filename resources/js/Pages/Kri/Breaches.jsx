import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import DataGrid from '@thirdline/ui/Components/DataGrid/DataGrid';
import KpiCard from '@thirdline/ui/Components/KpiCard';
import PageHeader from '@thirdline/ui/Components/PageHeader';

/**
 * The KRI breach register (migration Phase 2) — see
 * App\Grids\Definitions\KriBreachesGrid. The controller opens the grid on
 * status=active so the default stays the work list, not the archive.
 */
export default function Breaches({ activeBreaches, redBreaches, amberBreaches, avgDaysInBreach, mttrDays, unacknowledged, grid }) {
    return (
        <AuthenticatedLayout title="KRI Breaches">
            <Head title="KRI Breaches" />

            <PageHeader title="Active KRI Breaches" subtitle={`${activeBreaches} active breaches requiring attention`} />

            <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <KpiCard title="Red Breaches" value={redBreaches ?? 0} icon="error" color="danger" subtitle="Open - immediate action required" />
                <KpiCard title="Amber Warnings" value={amberBreaches ?? 0} icon="warning" color="warning" subtitle="Open - approaching threshold" />
                <KpiCard title="Avg. Days Open" value={avgDaysInBreach ?? 0} icon="schedule" color="info" subtitle={`${unacknowledged ?? 0} awaiting acknowledgement`} />
                {/* Mean time to resolve is computed from the resolved rows in
                    the breach register; before WP-04 it could not be produced. */}
                <KpiCard
                    title="Mean Time to Resolve"
                    value={mttrDays !== null && mttrDays !== undefined ? `${mttrDays} d` : 'n/a'}
                    icon="timer"
                    color="success"
                    subtitle={mttrDays !== null && mttrDays !== undefined ? 'Across resolved breaches' : 'No breach resolved yet'}
                />
            </div>

            <DataGrid grid={grid} />
        </AuthenticatedLayout>
    );
}
