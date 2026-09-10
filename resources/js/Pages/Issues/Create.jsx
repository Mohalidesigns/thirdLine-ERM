import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import DynamicForm, { formDataFor, initialValues } from '@thirdline/ui/Components/DynamicForm';
import PageHeader from '@thirdline/ui/Components/PageHeader';

/**
 * Migration Phase 4.4: risk/issues/create.blade.php.
 *
 * WP-05 TASK 2 — the whole form comes from the Issue object type, as the Blade
 * page's `<x-dynamic-form type="Issue">` did. Writing the fields out by hand
 * here would put a second definition of "what an issue form contains" beside
 * FormFieldRegistry's, and a field a tenant added through the builder would
 * render nowhere while the controller went on saving it.
 */
export default function Create({ schema = null }) {
    const { data, setData, post, processing, errors, transform } = useForm({
        ...initialValues(schema),
    });

    transform((form) => formDataFor(schema, form));

    const submit = (e) => {
        e.preventDefault();
        post(route('risk.issues.store'));
    };

    return (
        <AuthenticatedLayout title="Raise Issue">
            <Head title="Raise Issue" />

            <PageHeader
                title="Raise an Issue"
                subtitle="A finding, who owns it, and by when it must be fixed"
                breadcrumbs={[{ label: 'Issues', href: route('risk.issues.index') }, { label: 'Raise' }]}
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
                    <Link href={route('risk.issues.index')} className="btn-secondary text-sm">Cancel</Link>
                    <button type="submit" disabled={processing} className="btn-primary inline-flex items-center gap-2 text-sm disabled:opacity-50">
                        <span className="material-symbols-outlined text-lg">save</span> Raise Issue
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
