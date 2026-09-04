import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { formDataFor, initialValues } from '@/Components/DynamicForm';
import KriForm from './KriForm';

/**
 * Migration Phase 4.1: risk/kri/edit.blade.php.
 *
 * The risk is not editable, exactly as it was: update() never accepted
 * `risk_id`, so offering it would be a field that never saves.
 */
export default function Edit({ kri, risks = [], users = [], frequencies = [], directions = [], schema = null }) {
    const { data, setData, put, processing, errors, transform } = useForm({
        ...initialValues(schema),
        kri_name: kri.kri_name ?? '',
        description: kri.description ?? '',
        measurement_unit: kri.measurement_unit ?? '',
        measurement_frequency: kri.measurement_frequency ?? 'monthly',
        data_source: kri.data_source ?? '',
        kri_owner_id: kri.kri_owner_id ?? '',
        formula: kri.formula ?? '',
        direction: kri.direction ?? 'higher_is_worse',
        green_threshold: kri.green_threshold ?? '',
        red_threshold: kri.red_threshold ?? '',
        target_value: kri.target_value ?? '',
        is_active: Boolean(kri.is_active),
    });

    transform((form) => ({ ...form, ...formDataFor(schema, form) }));

    const submit = (e) => {
        e.preventDefault();
        put(route('risk.kri.update', kri.id));
    };

    return (
        <AuthenticatedLayout title="Edit KRI">
            <Head title="Edit KRI" />

            <PageHeader
                title="Edit Key Risk Indicator"
                subtitle={kri.kri_name}
                breadcrumbs={[
                    { label: 'KRI Monitoring', href: route('risk.kri.index') },
                    { label: kri.code ?? kri.kri_name, href: route('risk.kri.show', kri.id) },
                    { label: 'Edit' },
                ]}
            />

            <form onSubmit={submit} className="max-w-5xl">
                <KriForm
                    {...{ data, setData, errors, schema, risks, users, frequencies, directions }}
                    showActive
                />

                <div className="flex items-center justify-between">
                    <Link href={route('risk.kri.show', kri.id)} className="btn-secondary text-sm">Cancel</Link>
                    <button type="submit" disabled={processing} className="btn-primary inline-flex items-center gap-2 text-sm disabled:opacity-50">
                        <span className="material-symbols-outlined text-lg">save</span> Update KRI
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
