import { Head, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DataGrid from '@/Components/DataGrid/DataGrid';
import PageHeader from '@/Components/PageHeader';
import tryRoute from '@/lib/tryRoute';

/** The assessments register (migration Phase 2) — see App\Grids\Definitions\RiskAssessmentsGrid. */
export default function Index({ total, grid }) {
    const permissions = usePage().props.auth?.permissions ?? [];
    const createUrl = tryRoute('risk.assessments.create');

    return (
        <AuthenticatedLayout title="Risk Assessments">
            <Head title="Risk Assessments" />

            <PageHeader
                title="Risk Assessments"
                subtitle={`${total} assessments · Multi-dimensional risk scoring and tracking`}
                actions={
                    permissions.includes('assessment.create') && createUrl && (
                        <a href={createUrl} className="btn-primary text-sm inline-flex items-center gap-2">
                            <span className="material-symbols-outlined text-lg">add_circle</span> New Assessment
                        </a>
                    )
                }
            />

            <DataGrid grid={grid} />
        </AuthenticatedLayout>
    );
}
