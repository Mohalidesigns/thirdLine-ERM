import { Head, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import DataGrid from '@thirdline/ui/Components/DataGrid/DataGrid';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';
import Icon from '@thirdline/ui/Components/Icon';

/** The questionnaire library (migration Phase 2) — see App\Grids\Definitions\QuestionnairesGrid. */
export default function Index({ total, grid }) {
    const permissions = usePage().props.auth?.permissions ?? [];
    const createUrl = tryRoute('risk.questionnaires.create');

    return (
        <AuthenticatedLayout title="Questionnaires">
            <Head title="Questionnaires" />

            <PageHeader
                title="Questionnaire Library"
                subtitle={`${total} ${total === 1 ? 'questionnaire' : 'questionnaires'}`}
                actions={
                    permissions.includes('questionnaire.create') && createUrl && (
                        <a href={createUrl} className="btn-primary text-sm inline-flex items-center gap-2">
                            <Icon name="add_circle" className="w-4 h-4 shrink-0" /> New Questionnaire
                        </a>
                    )
                }
            />

            <DataGrid grid={grid} />
        </AuthenticatedLayout>
    );
}
