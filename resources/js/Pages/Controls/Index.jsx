import { Head, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import DataGrid from '@thirdline/ui/Components/DataGrid/DataGrid';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/** The control library (migration Phase 2) — see App\Grids\Definitions\ControlsGrid. */
export default function Index({ total, grid }) {
    const permissions = usePage().props.auth?.permissions ?? [];
    const createUrl = tryRoute('risk.controls.create');

    return (
        <AuthenticatedLayout title="Control Library">
            <Head title="Control Library" />

            <PageHeader
                title="Control Library"
                subtitle={`${total} controls registered · Manage organizational risk controls`}
                actions={
                    permissions.includes('control.create') && createUrl && (
                        // Plain anchor: the create form is Blade until Phase 3.
                        <a href={createUrl} className="btn-primary text-sm inline-flex items-center gap-2">
                            <span className="material-symbols-outlined text-lg">add_circle</span> New Control
                        </a>
                    )
                }
            />

            <DataGrid grid={grid} />
        </AuthenticatedLayout>
    );
}
