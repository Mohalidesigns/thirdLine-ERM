import { Head, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import LibraryForm, { cleanLibraryPayload } from '@/Components/RiskLibrary/LibraryForm';

/**
 * Create Master Risk Library definition (v1) (ADR 0028 Phase 1).
 */
export default function Create({ options = {} }) {
    const form = useForm({
        code: '',
        title: '',
        description: '',
        category_id: '',
        risk_source: '',
        basel_l1_event_type: '',
        basel_l2_event_type: '',
        typical_causes: [],
        typical_consequences: [],
        suggested_controls: [],
        suggested_kris: [],
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        form.transform((data) =>
            cleanLibraryPayload(data, { includeCode: true, includeChangeSummary: false }),
        );
        form.post(route('risk.library.store'));
    };

    return (
        <AuthenticatedLayout title="New Library Risk">
            <Head title="New Library Risk — Risk Library" />

            <PageHeader
                title="New Library Risk"
                subtitle="Author a canonical master risk definition (v1) for reuse across entities and processes."
                breadcrumbs={[
                    { label: 'Risk Library', href: route('risk.library.index') },
                    { label: 'New Library Risk' },
                ]}
            />

            <LibraryForm
                mode="create"
                options={options}
                form={form}
                onSubmit={handleSubmit}
                cancelHref={route('risk.library.index')}
            />
        </AuthenticatedLayout>
    );
}
