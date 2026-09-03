import { Head, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DataGrid from '@/Components/DataGrid/DataGrid';
import PageHeader from '@/Components/PageHeader';
import tryRoute from '@/lib/tryRoute';

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
                            <span className="material-symbols-outlined text-lg">add_circle</span> New Questionnaire
                        </a>
                    )
                }
            />

            <DataGrid grid={grid} />
        </AuthenticatedLayout>
    );
}
