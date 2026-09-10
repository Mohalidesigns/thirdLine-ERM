import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import { formDataFor, initialValues } from '@thirdline/ui/Components/DynamicForm';
import TreatmentForm, { EMPTY_MILESTONE } from './TreatmentForm';

/** Migration Phase 3.5: risk/treatments/edit.blade.php. */
export default function Edit({ plan, schema = null }) {
    const { data, setData, put, processing, errors, transform } = useForm({
        ...initialValues(schema),
        milestones: plan.milestones.length > 0
            ? plan.milestones.map(({ title, due_date: dueDate, responsible }) => ({
                title: title ?? '',
                due_date: dueDate ?? '',
                responsible: responsible ?? '',
            }))
            : [{ ...EMPTY_MILESTONE }],
    });

    transform(({ milestones, ...fields }) => ({
        ...formDataFor(schema, fields),
        milestones,
    }));

    const submit = (e) => {
        e.preventDefault();
        put(route('risk.treatments.update', plan.id));
    };

    return (
        <AuthenticatedLayout title="Edit Treatment Plan">
            <Head title="Edit Treatment Plan" />

            <PageHeader
                title="Edit Treatment Plan"
                subtitle={`Update treatment plan details for "${plan.title}"`}
                breadcrumbs={[
                    { label: 'Treatment Plans', href: route('risk.treatments.index') },
                    { label: plan.title, href: route('risk.treatments.show', plan.id) },
                    { label: 'Edit' },
                ]}
            />

            <form onSubmit={submit} className="max-w-5xl">
                <TreatmentForm schema={schema} data={data} setData={setData} errors={errors} />

                <div className="flex items-center justify-between">
                    <Link href={route('risk.treatments.show', plan.id)} className="btn-secondary text-sm">Cancel</Link>
                    <button type="submit" disabled={processing} className="btn-primary inline-flex items-center gap-2 text-sm disabled:opacity-50">
                        <span className="material-symbols-outlined text-lg">save</span>
                        Update Plan
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
