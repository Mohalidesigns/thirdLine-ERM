import { Head, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import DataGrid from '@thirdline/ui/Components/DataGrid/DataGrid';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/** Approval history (migration Phase 2) — see App\Grids\Definitions\ApprovalsHistoryGrid. */
export default function History({ grid }) {
    const permissions = usePage().props.auth?.permissions ?? [];
    const dashboardUrl = tryRoute('risk.approvals.dashboard');

    return (
        <AuthenticatedLayout title="Approval History">
            <Head title="Approval History" />

            <PageHeader
                title="Approval History"
                subtitle="Historical record of all approval requests."
                actions={
                    permissions.includes('approval.view') && dashboardUrl && (
                        <a href={dashboardUrl} className="btn-secondary text-sm inline-flex items-center gap-2">
                            <span className="material-symbols-outlined text-lg">arrow_back</span> Back to Dashboard
                        </a>
                    )
                }
            />

            <DataGrid grid={grid} />
        </AuthenticatedLayout>
    );
}
