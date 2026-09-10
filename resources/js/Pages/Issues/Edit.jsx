import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import DynamicForm, { formDataFor, initialValues } from '@thirdline/ui/Components/DynamicForm';
import PageHeader from '@thirdline/ui/Components/PageHeader';

/**
 * Migration Phase 4.4: risk/issues/edit.blade.php.
 *
 * `recommended_action` is omitted from the schema server-side, as it was: the
 * recommendation is what the finding said, not something the owner revises
 * while remediating it.
 */
export default function Edit({ issue, schema = null }) {
    const { data, setData, put, processing, errors, transform } = useForm({
        ...initialValues(schema),
    });

    transform((form) => formDataFor(schema, form));

    const submit = (e) => {
        e.preventDefault();
        put(route('risk.issues.update', issue.id));
    };

    return (
        <AuthenticatedLayout title="Amend Issue">
            <Head title="Amend Issue" />

            <PageHeader
                title="Amend Issue"
                subtitle={issue.reference}
                breadcrumbs={[
                    { label: 'Issues', href: route('risk.issues.index') },
                    { label: issue.reference, href: route('risk.issues.show', issue.id) },
                    { label: 'Amend' },
                ]}
            />

            <form onSubmit={submit} className="max-w-5xl">
                <div className="bg-white rounded-xl border border-gray-200 p-6 mb-6">
                    <div className="flex items-center gap-2 mb-6">
                        <span className="material-symbols-outlined text-[#D4AF37]">bug_report</span>
                        <h2 className="text-base font-bold text-[#1A365D]">Issue Details</h2>
                    </div>

                    <DynamicForm
                        schema={schema}
                        values={data}
                        errors={errors}
                        onChange={(code, value) => setData(code, value)}
                    />
                </div>

                <div className="flex items-center justify-between">
                    <Link href={route('risk.issues.show', issue.id)} className="btn-secondary text-sm">Cancel</Link>
                    <button type="submit" disabled={processing} className="btn-primary inline-flex items-center gap-2 text-sm disabled:opacity-50">
                        <span className="material-symbols-outlined text-lg">save</span> Save Changes
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
