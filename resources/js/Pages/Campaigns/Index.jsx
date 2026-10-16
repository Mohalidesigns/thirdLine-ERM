import { Head, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import DataGrid from '@thirdline/ui/Components/DataGrid/DataGrid';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';
import Icon from '@thirdline/ui/Components/Icon';

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
                            <Icon name="add_circle" className="w-4 h-4 shrink-0" /> New Campaign
                        </a>
                    )
                }
            />

            <DataGrid grid={grid} />
        </AuthenticatedLayout>
    );
}
