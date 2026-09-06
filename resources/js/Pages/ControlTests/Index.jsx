import { Head, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import DataGrid from '@thirdline/ui/Components/DataGrid/DataGrid';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/** The control test register (migration Phase 2) — see App\Grids\Definitions\ControlTestsGrid. */
export default function Index({ total, grid }) {
    const permissions = usePage().props.auth?.permissions ?? [];
    const createUrl = tryRoute('risk.control-tests.create');

    return (
        <AuthenticatedLayout title="Control Tests">
            <Head title="Control Tests" />

            <PageHeader
                title="All Control Tests"
                subtitle={`${total} ${total === 1 ? 'test' : 'tests'} scheduled or completed`}
                actions={
                    permissions.includes('control_test.create') && createUrl && (
                        <a href={createUrl} className="btn-primary text-sm inline-flex items-center gap-2">
                            <span className="material-symbols-outlined text-lg">add_circle</span> Schedule Test
                        </a>
                    )
                }
            />

            <DataGrid grid={grid} />
        </AuthenticatedLayout>
    );
}
