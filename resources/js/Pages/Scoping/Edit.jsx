import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EntityForm, { seedConfigured, toPayload } from './EntityForm';

/** Migration Phase 3.1: risk/scoping/edit.blade.php. */
export default function Edit({ entity, entityTypes = [], parentEntities = [], users = [], frameworks = [], appetiteLevels = [], appetiteCategories = [], schemas = {} }) {
    const { data, setData, put, processing, errors, transform } = useForm({
        entity_type_id: entity.entity_type_id ?? '',
        name: entity.name ?? '',
        parent_id: entity.parent_id ?? null,
        description: entity.description ?? '',
        owner_id: entity.owner_id ?? null,
        delegate_owner_id: entity.delegate_owner_id ?? null,
        status: entity.status ?? 'active',
        regulatory_frameworks: entity.regulatory_frameworks ?? [],
        risk_appetite_level: entity.risk_appetite_level ?? null,
        category_appetites: entity.category_appetites ?? {},
        configured_attributes: seedConfigured(schemas[entity.entity_type_id]),
    });

    useEffect(() => {
        if (data.entity_type_id !== '' && schemas[data.entity_type_id]) {
            setData('configured_attributes', seedConfigured(schemas[data.entity_type_id], data.configured_attributes));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data.entity_type_id]);

    transform((form) => toPayload(form, schemas));

    const submit = (e) => {
        e.preventDefault();
        put(route('risk.scoping.update', entity.id));
    };

    return (
        <AuthenticatedLayout title="Edit Entity">
            <Head title={`Edit ${entity.entity_code}`} />

            <PageHeader
                title={`Edit Entity: ${entity.name}`}
                subtitle={`Code: ${entity.entity_code}`}
                breadcrumbs={[
                    { label: 'Scoping', href: route('risk.scoping.dashboard') },
                    { label: 'Entity Register', href: route('risk.scoping.index') },
                    { label: entity.entity_code, href: route('risk.scoping.show', entity.id) },
                    { label: 'Edit' },
                ]}
            />

            <form onSubmit={submit} className="max-w-5xl">
                <EntityForm
                    {...{ data, setData, errors, entityTypes, parentEntities, users, frameworks, appetiteLevels, appetiteCategories, schemas }}
                    entityCode={entity.entity_code}
                    editing
                />

                <div className="flex items-center justify-between">
                    <Link href={route('risk.scoping.show', entity.id)} className="btn-secondary text-sm">Cancel</Link>
                    <button type="submit" disabled={processing} className="btn-primary inline-flex items-center gap-2 text-sm disabled:opacity-50">
                        <span className="material-symbols-outlined text-lg">save</span>
                        Update Entity
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
