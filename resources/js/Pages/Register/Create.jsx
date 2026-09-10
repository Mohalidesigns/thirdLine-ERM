import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import AiDraftButton from '@thirdline/ui/Components/AiDraftButton';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import RiskForm, { seedConfigured, toPayload } from './RiskForm';

/** Migration Phase 3.2: risk/register/create.blade.php. */
export default function Create({
    categories = [],
    businessUnits = [],
    processes = [],
    users = [],
    options = {},
    schema = null,
    canDraftWithAi = false,
}) {
    const { data, setData, post, processing, errors, transform } = useForm({
        title: '',
        description: '',
        category_id: '',
        business_unit_id: '',
        process_id: '',
        risk_owner_id: '',
        risk_steward_id: '',
        identified_by: '',
        date_identified: new Date().toISOString().slice(0, 10),
        risk_source: '',
        risk_type: '',
        inherent_likelihood: '',
        impact_financial: '',
        impact_operational: '',
        impact_reputational: '',
        impact_regulatory: '',
        treatment_strategy: '',
        risk_velocity: '',
        review_frequency: '',
        financial_exposure: '',
        regulatory_tags: [],
        notes: '',
        status: '',
        configured_attributes: seedConfigured(schema),
    });

    transform((form) => toPayload(form, schema));

    const submit = (e) => {
        e.preventDefault();
        post(route('risk.register.store'));
    };

    const nameOf = (rows, id) => rows.find((row) => String(row.id) === String(id))?.name ?? null;

    return (
        <AuthenticatedLayout title="New Risk">
            <Head title="New Risk" />

            <PageHeader
                title="Register a New Risk"
                subtitle="Identify, score and classify a risk for the register"
                breadcrumbs={[
                    { label: 'Risk Register', href: route('risk.register.index') },
                    { label: 'New Risk' },
                ]}
            />

            {canDraftWithAi && (
                <div className="max-w-5xl">
                    <AiDraftButton
                        title="AI Risk Statement Builder"
                        blurb="Describe the scenario in plain English — the model drafts a board-ready Cause → Event → Consequence statement and prefills the form."
                        placeholder="e.g. core banking outage during month-end settlement"
                        endpoint={route('risk.ai.tools.risk-statement')}
                        payload={() => ({
                            category: nameOf(categories, data.category_id),
                            business_unit: nameOf(businessUnits, data.business_unit_id),
                        })}
                        onDraft={(draft) => {
                            setData((current) => ({
                                ...current,
                                title: draft.title,
                                description:
                                    `Cause: ${draft.cause}\nEvent: ${draft.event}\nConsequence: ${draft.consequence}` +
                                    `\n\n${draft.description}`,
                            }));
                        }}
                    />
                </div>
            )}

            <form onSubmit={submit} className="max-w-5xl">
                <RiskForm
                    {...{ data, setData, errors, categories, businessUnits, processes, users, options, schema }}
                    showRiskType
                />

                <div className="flex items-center justify-between">
                    <Link href={route('risk.register.index')} className="btn-secondary text-sm">Cancel</Link>
                    <button type="submit" disabled={processing} className="btn-primary inline-flex items-center gap-2 text-sm disabled:opacity-50">
                        <span className="material-symbols-outlined text-lg">save</span>
                        Create Risk
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
