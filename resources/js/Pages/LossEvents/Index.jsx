import { Head, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DataGrid from '@/Components/DataGrid/DataGrid';
import PageHeader from '@/Components/PageHeader';
import tryRoute from '@/lib/tryRoute';

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
                            <span className="material-symbols-outlined text-lg">add</span> Log New Event
                        </a>
                    )
                }
            />

            <DataGrid grid={grid} />
        </AuthenticatedLayout>
    );
}
