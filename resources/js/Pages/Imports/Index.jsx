import { Head, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import DataGrid from '@thirdline/ui/Components/DataGrid/DataGrid';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/** The import history (migration Phase 2) — see App\Grids\Definitions\DataImportsGrid. */
export default function Index({ total, grid }) {
    const permissions = usePage().props.auth?.permissions ?? [];
    const createUrl = tryRoute('risk.imports.create');

    return (
        <AuthenticatedLayout title="Data Imports">
            <Head title="Data Imports" />

            <PageHeader
                title="Data Import History"
                subtitle={`${total} ${total === 1 ? 'import' : 'imports'} recorded`}
                actions={
                    permissions.includes('import.create') && createUrl && (
                        <a href={createUrl} className="btn-primary text-sm inline-flex items-center gap-2">
                            New Import
                        </a>
                    )
                }
            />

            <DataGrid grid={grid} />
        </AuthenticatedLayout>
    );
}
