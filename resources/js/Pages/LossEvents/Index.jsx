import { Head, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import DataGrid from '@thirdline/ui/Components/DataGrid/DataGrid';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';
import Icon from '@thirdline/ui/Components/Icon';

/** The loss event register (migration Phase 2) — see App\Grids\Definitions\LossEventsGrid. */
export default function Index({ total, grid }) {
    const permissions = usePage().props.auth?.permissions ?? [];
    const createUrl = tryRoute('risk.loss-events.create');

    return (
        <AuthenticatedLayout title="Loss Event Register">
            <Head title="Loss Event Register" />

            <PageHeader
                title="Loss Event Register"
                subtitle={`${total} events recorded · Comprehensive register of all operational loss events`}
                actions={
                    permissions.includes('loss_event.create') && createUrl && (
                        <a href={createUrl} className="btn-primary text-sm inline-flex items-center gap-2">
                            <Icon name="add" className="w-4 h-4 shrink-0" strokeWidth={2} /> Log New Event
                        </a>
                    )
                }
            />

            <DataGrid grid={grid} />
        </AuthenticatedLayout>
    );
}
