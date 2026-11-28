import { Head, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import ProcessForm, { cleanProcessPayload } from '@/Components/Processes/ProcessForm';

/**
 * Create Business Process or Sub-Process (ADR 0028 Phase 2, process-create-edit.md).
 */
export default function Create({ context = {}, options = {} }) {
    const initialLevel = context.default_level === 'sub_process' ? 'sub_process' : 'process';
    const initialParentId = context.default_parent_id ? String(context.default_parent_id) : '';

    // Pre-fill process_group_id / business_unit_id from parent when launched via "+ Add Sub-Process"
    const defaultParent = initialParentId
        ? (options.parentProcesses ?? []).find((p) => String(p.id) === initialParentId)
        : null;

    const form = useForm({
        level: initialLevel,
        parent_id: initialParentId,
        process_group_id: defaultParent?.process_group_id
            ? String(defaultParent.process_group_id)
            : '',
        business_unit_id: defaultParent?.business_unit_id
            ? String(defaultParent.business_unit_id)
            : '',
        entity_id: context.default_entity_id ? String(context.default_entity_id) : '',
        code: '',
        apqc_code: '',
        name: '',
        description: '',
        owner_id: '',
        criticality: '',
        is_active: true,
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        form.transform((data) =>
            cleanProcessPayload(data, { includeEntityId: Boolean(context.default_entity_id) }),
        );
        form.post(route('risk.processes.store'));
    };

    return (
        <AuthenticatedLayout title="New Process">
            <Head title="New Process — Process Catalogue" />

            <PageHeader
                title={form.data.level === 'sub_process' ? 'New Sub-Process' : 'New Process'}
                subtitle="Register a business process or sub-process in your APQC PCF-aligned ERM hierarchy."
                breadcrumbs={[
                    { label: 'Process Catalogue', href: route('risk.processes.index') },
                    { label: 'New Process' },
                ]}
            />

            <div className="max-w-3xl">
                <ProcessForm
                    mode="create"
                    options={options}
                    form={form}
                    onSubmit={handleSubmit}
                    cancelHref={route('risk.processes.index')}
                />
            </div>
        </AuthenticatedLayout>
    );
}
