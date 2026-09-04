import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { formDataFor, initialValues } from '@/Components/DynamicForm';
import KriForm from './KriForm';

/** Migration Phase 4.1: risk/kri/create.blade.php. */
export default function Create({ risks = [], users = [], frequencies = [], directions = [], schema = null }) {
    const { data, setData, post, processing, errors, transform } = useForm({
        ...initialValues(schema),
        risk_id: '',
        kri_name: '',
        description: '',
        measurement_unit: '',
        measurement_frequency: 'monthly',
        data_source: '',
        kri_owner_id: '',
        formula: '',
        direction: 'higher_is_worse',
        green_threshold: '',
        red_threshold: '',
        target_value: '',
    });

    transform((form) => ({ ...form, ...formDataFor(schema, form) }));

    const submit = (e) => {
        e.preventDefault();
        post(route('risk.kri.store'));
    };

    return (
        <AuthenticatedLayout title="New KRI">
            <Head title="New KRI" />

            <PageHeader
                title="Create a Key Risk Indicator"
                subtitle="Define what is measured, how often, and the limits it is read against"
                breadcrumbs={[{ label: 'KRI Monitoring', href: route('risk.kri.index') }, { label: 'New KRI' }]}
            />

            <form onSubmit={submit} className="max-w-5xl">
                <KriForm
                    {...{ data, setData, errors, schema, risks, users, frequencies, directions }}
                    showRisk
                />

                <div className="flex items-center justify-between">
                    <Link href={route('risk.kri.index')} className="btn-secondary text-sm">Cancel</Link>
                    <button type="submit" disabled={processing} className="btn-primary inline-flex items-center gap-2 text-sm disabled:opacity-50">
                        <span className="material-symbols-outlined text-lg">save</span> Create KRI
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
