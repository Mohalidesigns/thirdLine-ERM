import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import { formDataFor, initialValues } from '@thirdline/ui/Components/DynamicForm';
import ControlForm from './ControlForm';

/** Migration Phase 3.4: risk/controls/create.blade.php. */
export default function Create({ businessUnits = [], users = [], risks = [], options = {}, schema = null }) {
    const { data, setData, post, processing, errors, transform } = useForm({
        name: '',
        description: '',
        control_type: '',
        control_nature: '',
        frequency: '',
        owner_id: '',
        business_unit_id: '',
        effectiveness_rating: '',
        status: 'active',
        risk_ids: [],
        configured_attributes: initialValues(schema ?? { sections: [] }),
    });

    transform(({ configured_attributes: configured, ...rest }) => ({
        ...rest,
        ...(schema ? formDataFor(schema, configured ?? {}) : {}),
    }));

    const submit = (e) => {
        e.preventDefault();
        post(route('risk.controls.store'));
    };

    return (
        <AuthenticatedLayout title="New Control">
            <Head title="New Control" />

            <PageHeader
                title="Add a Control"
                subtitle="Define a control and, optionally, the risks it mitigates"
                breadcrumbs={[
                    { label: 'Controls', href: route('risk.controls.index') },
                    { label: 'New Control' },
                ]}
            />

            <form onSubmit={submit} className="max-w-5xl">
                <ControlForm {...{ data, setData, errors, businessUnits, users, risks, options, schema }} showRiskLinks />

                <div className="flex items-center justify-between">
                    <Link href={route('risk.controls.index')} className="btn-secondary text-sm">Cancel</Link>
                    <button type="submit" disabled={processing} className="btn-primary inline-flex items-center gap-2 text-sm disabled:opacity-50">
                        <span className="material-symbols-outlined text-lg">save</span> Create Control
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
