import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import EntityForm, { seedConfigured, toPayload } from './EntityForm';

/** Migration Phase 3.1: risk/scoping/create.blade.php. */
export default function Create({ entityTypes = [], parentEntities = [], users = [], frameworks = [], appetiteLevels = [], appetiteCategories = [], schemas = {} }) {
    const { data, setData, post, processing, errors, transform } = useForm({
        entity_type_id: '',
        name: '',
        parent_id: null,
        description: '',
        owner_id: null,
        delegate_owner_id: null,
        status: 'active',
        regulatory_frameworks: [],
        risk_appetite_level: null,
        category_appetites: {},
        configured_attributes: {},
    });

    // The configured fields belong to the chosen type; seed them as it changes.
    useEffect(() => {
        if (data.entity_type_id !== '' && schemas[data.entity_type_id]) {
            setData('configured_attributes', seedConfigured(schemas[data.entity_type_id], data.configured_attributes));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data.entity_type_id]);

    transform((form) => toPayload(form, schemas));

    const submit = (e) => {
        e.preventDefault();
        post(route('risk.scoping.store'));
    };

    return (
        <AuthenticatedLayout title="Create Entity">
            <Head title="Create Entity" />

            <PageHeader
                title="Create New Entity"
                subtitle="Define a new organizational entity within the risk management scope"
                breadcrumbs={[
                    { label: 'Scoping', href: route('risk.scoping.dashboard') },
                    { label: 'Entity Register', href: route('risk.scoping.index') },
                    { label: 'Create Entity' },
                ]}
            />

            <form onSubmit={submit} className="max-w-5xl">
                <EntityForm {...{ data, setData, errors, entityTypes, parentEntities, users, frameworks, appetiteLevels, appetiteCategories, schemas }} />

                <div className="flex items-center justify-between">
                    <Link href={route('risk.scoping.index')} className="btn-secondary text-sm">Cancel</Link>
                    <button type="submit" disabled={processing} className="btn-primary inline-flex items-center gap-2 text-sm disabled:opacity-50">
                        <span className="material-symbols-outlined text-lg">save</span>
                        Create Entity
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
