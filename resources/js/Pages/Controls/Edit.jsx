import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import { formDataFor, initialValues } from '@thirdline/ui/Components/DynamicForm';
import ControlForm from './ControlForm';

/** Migration Phase 3.4: risk/controls/edit.blade.php. */
export default function Edit({ control, businessUnits = [], users = [], options = {}, schema = null }) {
    const { data, setData, put, processing, errors, transform } = useForm({
        name: control.name ?? '',
        description: control.description ?? '',
        control_type: control.control_type ?? '',
        control_nature: control.control_nature ?? '',
        frequency: control.frequency ?? '',
        owner_id: control.owner_id ?? '',
        business_unit_id: control.business_unit_id ?? '',
        effectiveness_rating: control.effectiveness_rating ?? '',
        status: control.status ?? 'active',
        configured_attributes: initialValues(schema ?? { sections: [] }),
    });

    transform(({ configured_attributes: configured, ...rest }) => ({
        ...rest,
        ...(schema ? formDataFor(schema, configured ?? {}) : {}),
    }));

    const submit = (e) => {
        e.preventDefault();
        put(route('risk.controls.update', control.id));
    };

    return (
        <AuthenticatedLayout title={`Edit ${control.control_code}`}>
            <Head title={`Edit ${control.control_code}`} />

            <PageHeader
                title={`Edit ${control.control_code}`}
                subtitle={control.name}
                breadcrumbs={[
                    { label: 'Controls', href: route('risk.controls.index') },
                    { label: control.control_code, href: route('risk.controls.show', control.id) },
                    { label: 'Edit' },
                ]}
            />

            <form onSubmit={submit} className="max-w-5xl">
                {/* Risk mappings are absent here, as they were on the Blade edit
                    form: they are managed from the control's own page, where
                    unlinking is possible too. */}
                <ControlForm {...{ data, setData, errors, businessUnits, users, options, schema }} />

                <div className="flex items-center justify-between">
                    <Link href={route('risk.controls.show', control.id)} className="btn-secondary text-sm">Cancel</Link>
                    <button type="submit" disabled={processing} className="btn-primary inline-flex items-center gap-2 text-sm disabled:opacity-50">
                        <span className="material-symbols-outlined text-lg">save</span> Save Changes
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
