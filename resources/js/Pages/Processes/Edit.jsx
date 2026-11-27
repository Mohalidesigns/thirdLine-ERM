import { Head, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import ProcessForm, { cleanProcessPayload } from '@/Components/Processes/ProcessForm';

/**
 * Edit Business Process or Sub-Process (ADR 0028 Phase 2, process-create-edit.md).
 */
export default function Edit({ process, options = {} }) {
    const form = useForm({
        level: process?.level === 'sub_process' ? 'sub_process' : 'process',
        parent_id: process?.parent_id ? String(process.parent_id) : '',
        process_group_id: process?.process_group_id ? String(process.process_group_id) : '',
        business_unit_id: process?.business_unit_id ? String(process.business_unit_id) : '',
        code: process?.code ?? '',
        apqc_code: process?.apqc_code ?? '',
        name: process?.name ?? '',
        description: process?.description ?? '',
        owner_id: process?.owner_id ? String(process.owner_id) : '',
        criticality: process?.criticality ?? '',
        is_active: Boolean(process?.is_active ?? true),
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        form.transform((data) => cleanProcessPayload(data));
        form.put(route('risk.processes.update', process.id));
    };

    return (
        <AuthenticatedLayout title={`Edit ${process.code}`}>
            <Head title={`Edit ${process.code} — Process Catalogue`} />

            <PageHeader
                title={`Edit ${process.code} — ${process.name}`}
                subtitle="Update process metadata, hierarchy assignment, owner, or ERM criticality."
                breadcrumbs={[
                    { label: 'Process Catalogue', href: route('risk.processes.index') },
                    { label: process.code, href: route('risk.processes.show', process.id) },
                    { label: 'Edit' },
                ]}
            />

            <div className="max-w-3xl">
                <ProcessForm
                    mode="edit"
                    process={process}
                    options={options}
                    form={form}
                    onSubmit={handleSubmit}
                    cancelHref={route('risk.processes.show', process.id)}
                />
            </div>
        </AuthenticatedLayout>
    );
}
