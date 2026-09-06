import { Head, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import DataGrid from '@thirdline/ui/Components/DataGrid/DataGrid';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/** The issues register (migration Phase 2) — see App\Grids\Definitions\IssuesGrid. */
export default function Index({ grid }) {
    const permissions = usePage().props.auth?.permissions ?? [];
    const createUrl = tryRoute('risk.issues.create');

    return (
        <AuthenticatedLayout title="Issues Register">
            <Head title="Issues Register" />

            <PageHeader
                title="Issues Register"
                subtitle="Comprehensive register of all issues, findings, and audit observations"
                actions={
                    permissions.includes('issue.create') && createUrl && (
                        <a href={createUrl} className="btn-primary text-sm inline-flex items-center gap-2">
                            <span className="material-symbols-outlined text-lg">add</span> Log New Issue
                        </a>
                    )
                }
            />

            <DataGrid grid={grid} />
        </AuthenticatedLayout>
    );
}
