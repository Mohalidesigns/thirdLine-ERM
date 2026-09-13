import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import AiDraftButton from '@thirdline/ui/Components/AiDraftButton';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import { formDataFor, initialValues } from '@thirdline/ui/Components/DynamicForm';
import TreatmentForm, { EMPTY_MILESTONE } from './TreatmentForm';

/** Migration Phase 3.5: risk/treatments/create.blade.php. */
export default function Create({ schema = null, canDraftWithAi = false }) {
    const { data, setData, post, processing, errors, transform } = useForm({
        ...initialValues(schema),
        milestones: [{ ...EMPTY_MILESTONE }],
    });

    transform(({ milestones, ...fields }) => ({
        ...formDataFor(schema, fields),
        milestones,
    }));

    const submit = (e) => {
        e.preventDefault();
        post(route('risk.treatments.store'));
    };

    return (
        <AuthenticatedLayout title="Create Treatment Plan">
            <Head title="Create Treatment Plan" />

            <PageHeader
                title="Create Treatment Plan"
                subtitle="Define a treatment plan to address an identified risk"
                breadcrumbs={[
                    { label: 'Treatment Plans', href: route('risk.treatments.index') },
                    { label: 'Create Plan' },
                ]}
            />

            {canDraftWithAi && (
                <div className="max-w-5xl">
                    <AiDraftButton
                        title="AI Treatment Plan Builder"
                        blurb="Describe the plan in plain English — the model drafts objectives, scope and outcomes and prefills the form."
                        placeholder='e.g. "Reduce single-obligor concentration in oil & gas portfolio over 12 months"'
                        endpoint={route('risk.ai.tools.treatment-description')}
                        payload={() => ({
                            title: data.treatment_title || null,
                            treatment_type: data.treatment_type || null,
                            risk_id: data.risk_id || null,
                        })}
                        onDraft={(draft) => {
                            setData((current) => ({
                                ...current,
                                // The drafted title only fills an empty box —
                                // the model is told to refine what is there,
                                // and overwriting a title somebody typed is
                                // not refining it.
                                treatment_title: current.treatment_title || draft.title,
                                treatment_description: draft.description,
                            }));
                        }}
                    />
                </div>
            )}

            <form onSubmit={submit} className="max-w-5xl">
                <TreatmentForm schema={schema} data={data} setData={setData} errors={errors} />

                <div className="flex items-center justify-between">
                    <Link href={route('risk.treatments.index')} className="btn-secondary text-sm">Cancel</Link>
                    <button type="submit" disabled={processing} className="btn-primary inline-flex items-center gap-2 text-sm disabled:opacity-50">
                        <span className="material-symbols-outlined text-lg">save</span>
                        Create Plan
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
