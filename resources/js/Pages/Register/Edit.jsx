import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import RiskForm, { seedConfigured, toPayload } from './RiskForm';

/** Migration Phase 3.2: risk/register/edit.blade.php. */
export default function Edit({
    risk,
    categories = [],
    businessUnits = [],
    processes = [],
    users = [],
    options = {},
    schema = null,
}) {
    const { data, setData, put, processing, errors, transform } = useForm({
        title: risk.title ?? '',
        description: risk.description ?? '',
        category_id: risk.category_id ?? '',
        business_unit_id: risk.business_unit_id ?? '',
        process_id: risk.process_id ?? '',
        risk_owner_id: risk.risk_owner_id ?? '',
        risk_steward_id: risk.risk_steward_id ?? '',
        identified_by: risk.identified_by ?? '',
        date_identified: risk.date_identified ?? '',
        risk_source: risk.risk_source ?? '',
        inherent_likelihood: risk.inherent_likelihood ?? '',
        impact_financial: risk.impact_financial ?? '',
        impact_operational: risk.impact_operational ?? '',
        impact_reputational: risk.impact_reputational ?? '',
        impact_regulatory: risk.impact_regulatory ?? '',
        treatment_strategy: risk.treatment_strategy ?? '',
        risk_velocity: risk.risk_velocity ?? '',
        review_frequency: risk.review_frequency ?? '',
        financial_exposure: risk.financial_exposure ?? '',
        regulatory_tags: risk.regulatory_tags ?? [],
        notes: risk.notes ?? '',
        status: risk.status ?? 'active',
        configured_attributes: seedConfigured(schema),
    });

    transform((form) => toPayload(form, schema));

    const submit = (e) => {
        e.preventDefault();
        put(route('risk.register.update', risk.id));
    };

    return (
        <AuthenticatedLayout title={`Edit ${risk.risk_code}`}>
            <Head title={`Edit ${risk.risk_code}`} />

            <PageHeader
                title={`Edit ${risk.risk_code}`}
                subtitle={risk.title}
                breadcrumbs={[
                    { label: 'Risk Register', href: route('risk.register.index') },
                    { label: risk.risk_code, href: route('risk.register.show', risk.id) },
                    { label: 'Edit' },
                ]}
            />

            <form onSubmit={submit} className="max-w-5xl">
                {/* risk_type is deliberately absent: UpdateRiskRequest does not
                    accept it, exactly as the Blade edit form did not offer it. */}
                <RiskForm
                    {...{ data, setData, errors, categories, businessUnits, processes, users, options, schema }}
                    statusRequired
                />

                <div className="flex items-center justify-between">
                    <Link href={route('risk.register.show', risk.id)} className="btn-secondary text-sm">Cancel</Link>
                    <button type="submit" disabled={processing} className="btn-primary inline-flex items-center gap-2 text-sm disabled:opacity-50">
                        <span className="material-symbols-outlined text-lg">save</span>
                        Save Changes
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
