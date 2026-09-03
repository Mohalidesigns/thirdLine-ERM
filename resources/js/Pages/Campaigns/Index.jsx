import { Head, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DataGrid from '@/Components/DataGrid/DataGrid';
import PageHeader from '@/Components/PageHeader';
import tryRoute from '@/lib/tryRoute';

/** The campaign register (migration Phase 2) — see App\Grids\Definitions\CampaignsGrid. */
export default function Index({ total, grid }) {
    const permissions = usePage().props.auth?.permissions ?? [];
    const createUrl = tryRoute('risk.campaigns.create');

    return (
        <AuthenticatedLayout title="All Campaigns">
            <Head title="All Campaigns" />

            <PageHeader
                title="All Assessment Campaigns"
                subtitle={`${total} ${total === 1 ? 'campaign' : 'campaigns'} registered`}
                actions={
                    permissions.includes('campaign.create') && createUrl && (
                        <a href={createUrl} className="btn-primary text-sm inline-flex items-center gap-2">
                            <span className="material-symbols-outlined text-lg">add_circle</span> New Campaign
                        </a>
                    )
                }
            />

            <DataGrid grid={grid} />
        </AuthenticatedLayout>
    );
}
